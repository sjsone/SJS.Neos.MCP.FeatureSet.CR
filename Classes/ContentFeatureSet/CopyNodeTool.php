<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\BooleanSchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;

class CopyNodeTool extends Tool
{
    use Trait\ContentRepositoryTool;

    public function __construct()
    {
        parent::__construct(
            name: 'copy_node',
            description: 'Copies a node (and optionally its children) to a new parent location. For document nodes, newTitle and newUriPathSegment are required. For content nodes, the copy works directly.',
            inputSchema: new ObjectSchema(properties: [
                'sourceNodeAddress' => (new ObjectSchema(
                    description: 'The node address of the node to copy',
                    properties: [
                        'contentRepositoryId' => new StringSchema(),
                        'workspaceName' => new StringSchema(),
                        'dimensionSpacePoint' => new ObjectSchema(),
                        'aggregateId' => new StringSchema(),
                    ]
                ))->required(),
                'targetParentAggregateId' => (new StringSchema(
                    description: 'NodeAggregateId of the parent where the copy should be placed'
                ))->required(),
                'newTitle' => new StringSchema(
                    description: 'REQUIRED if source is a Document — the title for the new page'
                ),
                'newUriPathSegment' => new StringSchema(
                    description: 'REQUIRED if source is a Document — the URI path segment for the new page'
                ),
                'targetWorkspace' => new StringSchema(
                    description: 'Target workspace name. Defaults to source node\'s workspace'
                ),
                'recursive' => new BooleanSchema(
                    description: 'Whether to recursively copy child nodes',
                    default: true
                ),
            ]),
            annotations: new Annotations(
                title: 'Copy Node'
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $sourceNodeAddress = $this->parseSourceNodeAddress($input);
        $targetParentAggregateId = $this->parseTargetParentAggregateId($input);
        $newTitle = $this->parseOptionalString($input, 'newTitle');
        $newUriPathSegment = $this->parseOptionalString($input, 'newUriPathSegment');
        $recursive = $this->parseRecursive($input);

        $targetWorkspaceName = $this->resolveTargetWorkspaceName($sourceNodeAddress, $input);
        $this->validateWorkspaceName($targetWorkspaceName);

        $contentRepository = $this->getContentRepository($serverContext);

        $sourceNode = $this->getNode($contentRepository, $sourceNodeAddress->workspaceName, $sourceNodeAddress);

        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            throw new \InvalidArgumentException('Could not resolve node type for source node');
        }

        $isDocument = $nodeType->isOfType('Neos.Neos:Document');

        if ($isDocument) {
            if ($newTitle === null || $newTitle === '') {
                throw new \InvalidArgumentException('Cannot duplicate document: newTitle is required');
            }
            if ($newUriPathSegment === null || $newUriPathSegment === '') {
                throw new \InvalidArgumentException('Cannot duplicate document: newUriPathSegment is required');
            }
            $this->checkUriPathConflict(
                $contentRepository,
                $targetWorkspaceName,
                $sourceNodeAddress->dimensionSpacePoint,
                $targetParentAggregateId,
                $newUriPathSegment
            );
        }

        $properties = iterator_to_array($sourceNode->properties->getIterator());

        if ($isDocument) {
            $properties['title'] = $newTitle;
            $properties['uriPathSegment'] = $newUriPathSegment;
        }

        $newNodeAggregateId = NodeAggregateId::create();
        $originDimensionSpacePoint = OriginDimensionSpacePoint::fromDimensionSpacePoint(
            $sourceNodeAddress->dimensionSpacePoint
        );

        $command = CreateNodeAggregateWithNode::create(
            workspaceName: $targetWorkspaceName,
            nodeAggregateId: $newNodeAggregateId,
            nodeTypeName: $sourceNode->nodeTypeName,
            originDimensionSpacePoint: $originDimensionSpacePoint,
            parentNodeAggregateId: $targetParentAggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray($properties),
        );

        if ($isDocument && $newUriPathSegment !== null) {
            $command = $command->withNodeName(NodeName::fromString($newUriPathSegment));
        }

        $contentRepository->handle($command);

        $childrenCopied = 0;
        if ($recursive) {
            $sourceGraph = $contentRepository->getContentGraph($sourceNodeAddress->workspaceName);
            $sourceSubGraph = $sourceGraph->getSubgraph(
                $sourceNodeAddress->dimensionSpacePoint,
                VisibilityConstraints::default()
            );

            $subtree = $sourceSubGraph->findSubtree(
                $sourceNodeAddress->aggregateId,
                FindSubtreeFilter::create()
            );

            if ($subtree !== null) {
                $childrenCopied = $this->copyChildrenRecursively(
                    $contentRepository,
                    $subtree,
                    $newNodeAggregateId,
                    $targetWorkspaceName,
                    $sourceNodeAddress->dimensionSpacePoint,
                    $originDimensionSpacePoint
                );
            }
        }

        $newNodeAddress = NodeAddress::create(
            contentRepositoryId: $sourceNodeAddress->contentRepositoryId,
            workspaceName: $targetWorkspaceName,
            dimensionSpacePoint: $sourceNodeAddress->dimensionSpacePoint,
            aggregateId: $newNodeAggregateId,
        );

        return Content::structuredWithFallback([
            'newNodeAddress' => $newNodeAddress,
            'newNodeAggregateId' => (string) $newNodeAggregateId,
            'childrenCopied' => $childrenCopied,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseSourceNodeAddress(array $input): NodeAddress
    {
        $nodeAddressArray = $input['sourceNodeAddress'] ?? [];
        return NodeAddress::fromArray($nodeAddressArray);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseTargetParentAggregateId(array $input): NodeAggregateId
    {
        $value = $input['targetParentAggregateId'] ?? '';
        return NodeAggregateId::fromString($value);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseOptionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string");
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $input
     */
    private function parseRecursive(array $input): bool
    {
        return (bool)($input['recursive'] ?? true);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function resolveTargetWorkspaceName(NodeAddress $sourceNodeAddress, array $input): WorkspaceName
    {
        $targetWorkspace = $input['targetWorkspace'] ?? null;
        if ($targetWorkspace !== null && \is_string($targetWorkspace)) {
            return WorkspaceName::fromString($targetWorkspace);
        }
        return $sourceNodeAddress->workspaceName;
    }

    private function checkUriPathConflict(
        ContentRepository $contentRepository,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        NodeAggregateId $targetParentAggregateId,
        string $newUriPathSegment
    ): void {
        $graph = $contentRepository->getContentGraph($workspaceName);
        $subGraph = $graph->getSubgraph(
            $dimensionSpacePoint,
            VisibilityConstraints::default()
        );

        $nodeName = NodeName::fromString($newUriPathSegment);
        $existing = $subGraph->findNodeByPath($nodeName, $targetParentAggregateId);
        if ($existing !== null) {
            throw new \InvalidArgumentException(
                "Cannot duplicate: a page with URI path segment '{$newUriPathSegment}' already exists under the target parent"
            );
        }
    }

    private function copyChildrenRecursively(
        ContentRepository $contentRepository,
        Subtree $sourceSubtree,
        NodeAggregateId $newParentAggregateId,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $dimensionSpacePoint,
        OriginDimensionSpacePoint $originDimensionSpacePoint
    ): int {
        $childrenCopied = 0;

        $targetGraph = $contentRepository->getContentGraph($targetWorkspaceName);
        $targetSubGraph = $targetGraph->getSubgraph(
            $dimensionSpacePoint,
            VisibilityConstraints::default()
        );

        foreach ($sourceSubtree->children as $childSubtree) {
            $childNode = $childSubtree->node;

            if ($childNode->classification->isTethered()) {
                if ($childSubtree->children->count() > 0 && $childNode->name !== null) {
                    $autoCreatedNode = $targetSubGraph->findNodeByPath(
                        $childNode->name,
                        $newParentAggregateId
                    );

                    if ($autoCreatedNode !== null) {
                        $childrenCopied += $this->copyChildrenRecursively(
                            $contentRepository,
                            $childSubtree,
                            $autoCreatedNode->aggregateId,
                            $targetWorkspaceName,
                            $dimensionSpacePoint,
                            $originDimensionSpacePoint
                        );
                    }
                }
            } else {
                $newChildAggregateId = NodeAggregateId::create();
                $childProperties = iterator_to_array($childNode->properties->getIterator());

                $command = CreateNodeAggregateWithNode::create(
                    workspaceName: $targetWorkspaceName,
                    nodeAggregateId: $newChildAggregateId,
                    nodeTypeName: $childNode->nodeTypeName,
                    originDimensionSpacePoint: $originDimensionSpacePoint,
                    parentNodeAggregateId: $newParentAggregateId,
                    initialPropertyValues: PropertyValuesToWrite::fromArray($childProperties),
                );

                if ($childNode->name !== null) {
                    $command = $command->withNodeName($childNode->name);
                }

                $contentRepository->handle($command);
                $childrenCopied++;

                if ($childSubtree->children->count() > 0) {
                    $childrenCopied += $this->copyChildrenRecursively(
                        $contentRepository,
                        $childSubtree,
                        $newChildAggregateId,
                        $targetWorkspaceName,
                        $dimensionSpacePoint,
                        $originDimensionSpacePoint
                    );
                }
            }
        }

        return $childrenCopied;
    }
}

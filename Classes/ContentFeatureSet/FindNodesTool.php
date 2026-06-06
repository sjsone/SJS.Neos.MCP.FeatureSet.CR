<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use Neos\Neos\Service\UserService;
use Psr\Log\LoggerInterface;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\IntegerSchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;

class FindNodesTool extends Tool
{
    use Trait\ContentRepositoryTool;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    public function __construct()
    {
        parent::__construct(
            name: 'find_nodes',
            description: 'Searches across the site for nodes matching type, property values, or full-text. Returns lightweight summaries including node addresses for follow-up calls.',
            inputSchema: new ObjectSchema(properties: [
                'nodeType' => new StringSchema(description: 'Filter by NodeType, e.g. Neos.Demo:Content.Text'),
                'searchTerm' => new StringSchema(description: 'Search term to match against text properties of nodes'),
                'propertyFilters' => new ObjectSchema(
                    description: 'Key/value pairs for exact property matching, e.g. {"title": "Welcome"}'
                ),
                'scope' => new ObjectSchema(
                    description: 'Node address to limit search to a specific subgraph. If omitted, searches all documents.',
                    properties: [
                        'contentRepositoryId' => new StringSchema(),
                        'workspaceName' => new StringSchema(),
                        'dimensionSpacePoint' => new ObjectSchema(),
                        'aggregateId' => new StringSchema(),
                    ]
                ),
                'workspace' => new StringSchema(description: "Workspace name. Defaults to user's personal workspace."),
                'limit' => new IntegerSchema(description: 'Maximum number of results to return', default: 50),
            ]),
            annotations: new Annotations(
                title: 'Find Nodes',
                readOnlyHint: true
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $searchTerm = $input['searchTerm'] ?? null;
        $propertyFilters = $input['propertyFilters'] ?? null;

        if ($searchTerm === null && $propertyFilters === null) {
            throw new \InvalidArgumentException('Provide at least one search criterion: searchTerm or propertyFilters');
        }

        $nodeTypeName = isset($input['nodeType']) ? NodeTypeName::fromString($input['nodeType']) : null;
        $limit = max(1, $input['limit'] ?? 50);

        $contentRepository = $this->getContentRepository($serverContext);
        $nodeTypeManager = $contentRepository->getNodeTypeManager();

        $workspaceName = $this->resolveWorkspaceName($serverContext, $input);

        $graph = $contentRepository->getContentGraph($workspaceName);

        $subtreeFilter = null;
        if ($nodeTypeName !== null) {
            $subtreeFilter = FindSubtreeFilter::create(nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                NodeTypeNames::with($nodeTypeName)
            ));
        }

        $results = [];

        $scopeAddress = $input['scope'] ?? null;
        if ($scopeAddress !== null) {
            $nodeAddress = NodeAddress::fromArray($scopeAddress);
            $subGraph = $graph->getSubgraph(
                $nodeAddress->dimensionSpacePoint,
                VisibilityConstraints::default()
            );
            $subtree = $subGraph->findSubtree(
                entryNodeAggregateId: $nodeAddress->aggregateId,
                filter: $subtreeFilter
            );
            if ($subtree !== null) {
                $this->walkSubtree($subtree, $results, $nodeTypeName, $searchTerm, $propertyFilters, $limit, $nodeTypeManager);
            }
        } else {
            $rootNodeAggregates = $graph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create());
            foreach ($rootNodeAggregates as $rootNodeAggregate) {
                if (count($results) >= $limit) {
                    break;
                }

                foreach ($rootNodeAggregate->occupiedDimensionSpacePoints as $originDsp) {
                    if (count($results) >= $limit) {
                        break;
                    }

                    $dimensionSpacePoint = DimensionSpacePoint::fromArray($originDsp->coordinates);
                    $subGraph = $graph->getSubgraph(
                        $dimensionSpacePoint,
                        VisibilityConstraints::default()
                    );
                    $subtree = $subGraph->findSubtree(
                        entryNodeAggregateId: $rootNodeAggregate->nodeAggregateId,
                        filter: $subtreeFilter
                    );
                    if ($subtree !== null) {
                        $this->walkSubtree($subtree, $results, $nodeTypeName, $searchTerm, $propertyFilters, $limit, $nodeTypeManager);
                    }
                }
            }
        }

        return Content::structuredWithFallback($results);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function resolveWorkspaceName(ServerContext $serverContext, array $input): WorkspaceName
    {
        if (isset($input['workspace']) && is_string($input['workspace'])) {
            return WorkspaceName::fromString($input['workspace']);
        }

        $httpRequest = $serverContext->request->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest(request: $httpRequest)->contentRepositoryId;

        $user = $this->userService->getBackendUser();
        if ($user === null) {
            throw new \InvalidArgumentException('Could not get backend user');
        }

        return $this->workspaceService->getPersonalWorkspaceForUser(
            contentRepositoryId: $contentRepositoryId,
            userId: $user->getId()
        )->workspaceName;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @param array<string, string>|null $propertyFilters
     */
    private function walkSubtree(
        Subtree $subtree,
        array &$results,
        ?NodeTypeName $nodeTypeName,
        ?string $searchTerm,
        ?array $propertyFilters,
        int $limit,
        NodeTypeManager $nodeTypeManager
    ): void {
        if (count($results) >= $limit) {
            return;
        }

        $node = $subtree->node;

        $matchedProperty = $this->checkNodeMatch($node, $nodeTypeName, $searchTerm, $propertyFilters, $nodeTypeManager);
        if ($matchedProperty !== null) {
            $results[] = $this->buildResult($node, $matchedProperty, $nodeTypeManager);
        }

        foreach ($subtree->children as $child) {
            if (count($results) >= $limit) {
                break;
            }
            $this->walkSubtree($child, $results, $nodeTypeName, $searchTerm, $propertyFilters, $limit, $nodeTypeManager);
        }
    }

    /**
     * Checks whether a node matches all specified criteria.
     *
     * @param array<string, string>|null $propertyFilters
     * @return string|null The matched property name, or null if the node does not match
     */
    private function checkNodeMatch(
        Node $node,
        ?NodeTypeName $nodeTypeName,
        ?string $searchTerm,
        ?array $propertyFilters,
        NodeTypeManager $nodeTypeManager
    ): ?string {
        $matchedProperty = null;

        // Check node type
        if ($nodeTypeName !== null) {
            $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
            if ($nodeType === null || !$nodeType->isOfType($nodeTypeName)) {
                return null;
            }
        }

        // Check propertyFilters (exact match)
        if ($propertyFilters !== null) {
            foreach ($propertyFilters as $key => $value) {
                if (!is_string($key) || $node->getProperty($key) !== $value) {
                    return null;
                }
            }
        }

        // Check searchTerm (case-insensitive)
        if ($searchTerm !== null) {
            $lowerSearchTerm = mb_strtolower($searchTerm);
            foreach ($node->properties as $propertyName => $propertyValue) {
                if (is_string($propertyValue) && str_contains(mb_strtolower($propertyValue), $lowerSearchTerm)) {
                    $matchedProperty = $propertyName;
                    break;
                }
            }
            if ($matchedProperty === null) {
                return null;
            }
        }

        return $matchedProperty;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResult(Node $node, ?string $matchedProperty, NodeTypeManager $nodeTypeManager): array
    {
        $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);

        // Title: first non-empty from title, headline, header
        $title = $node->getProperty('title');
        if (empty($title)) {
            $title = $node->getProperty('headline');
        }
        if (empty($title)) {
            $title = $node->getProperty('header');
        }

        $isDocument = $nodeType !== null && $nodeType->isOfType('Neos.Neos:Document');
        $isContentCollection = $nodeType !== null && $nodeType->isOfType('Neos.Neos:ContentCollection');
        $isContent = $nodeType !== null && $nodeType->isOfType('Neos.Neos:Content');

        return [
            'nodeAddress' => NodeAddress::fromNode($node),
            'nodeTypeName' => (string) $node->nodeTypeName,
            'name' => (string) ($node->name ?? ''),
            'title' => $title,
            'isDocument' => $isDocument,
            'isContentCollection' => $isContentCollection,
            'isContent' => $isContent,
            'matchedProperty' => $matchedProperty,
        ];
    }
}

<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\Service\UserService;
use Psr\Log\LoggerInterface;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use Neos\Flow\Annotations as Flow;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\ContentTreeTool\ContentTreeNode;

class ContentTreeTool extends Tool
{
    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected UserService $userService;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    public function __construct()
    {
        parent::__construct(
            name: 'content_tree',
            description: 'Returns the content tree of a page node',
            inputSchema: new ObjectSchema(properties: [
                "node_address" => (new ObjectSchema(
                    description: "The node_address returned from other tools",
                    properties: [
                        "contentRepositoryId" => (new StringSchema())->required(),
                        "workspaceName" => (new StringSchema())->required(),
                        "dimensionSpacePoint" => (new ObjectSchema())->required(),
                        "aggregateId" => (new StringSchema())->required()
                    ]
                ))->required(),
                // "node_type_filter" => new ArraySchema(
                //     description: "List of the NodeTypes to filter",
                //     items: new StringSchema(),
                //     default: [
                //         'Neos.Neos:ContentCollection',
                //         'Neos.Neos:Content'
                //     ]
                // )
            ]),
            annotations: new Annotations(
                title: 'Content Tree',
                readOnlyHint: true
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ActionRequest $actionRequest, array $input): Content
    {
        $nodeAddressArray = $input["node_address"];

        $nodeTypeFilter = $input["node_type_filter"] ?? null;


        $nodeAddress = NodeAddress::fromArray($nodeAddressArray);

        $httpRequest = $actionRequest->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest(request: $httpRequest)->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get(contentRepositoryId: $contentRepositoryId);

        $user = $this->userService->getBackendUser();
        if ($user === null) {
            throw new \InvalidArgumentException("Could not get backend user");
        }

        $userWorkspace = $this->workspaceService->getPersonalWorkspaceForUser(contentRepositoryId: $contentRepositoryId, userId: $user->getId());

        $graph = $contentRepository->getContentGraph(workspaceName: $userWorkspace->workspaceName);
        $subGraph = $graph->getSubgraph(dimensionSpacePoint: $nodeAddress->dimensionSpacePoint, visibilityConstraints: VisibilityConstraints::default());


        $subtreeFilter = FindSubtreeFilter::create(nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
            nodeTypeNames: NodeTypeNames::fromArray([
                NodeTypeName::fromString('Neos.Neos:ContentCollection'),
                NodeTypeName::fromString('Neos.Neos:Content'),
            ])
        ));

        $subtree = $subGraph->findSubtree(entryNodeAggregateId: $nodeAddress->aggregateId, filter: $subtreeFilter);
        if ($subtree === null) {
            throw new \InvalidArgumentException("Could not find subtree using aggregateId in nodeAddress");
        }

        $subtreeForJson = $this->subtreeToJson($subtree, $nodeTypeFilter);


        return Content::structuredWithFallback($subtreeForJson->toArray());
    }

    /**
     * @param array<string> $nodeTypeFilter
     */
    public function subtreeToJson(Subtree $subtree, ?array $nodeTypeFilter): ContentTreeNode
    {
        $children = [];

        foreach ($subtree->children as $childSubtree) {
            $children[(string) $childSubtree->node->name] = $this->subtreeToJson($childSubtree, $nodeTypeFilter);
        }

        return new ContentTreeNode(
            $subtree->node->workspaceName,
            NodeAddress::fromNode($subtree->node),
            $subtree->node->name ?? NodeName::fromString(""),
            $subtree->node->aggregateId,
            $subtree->node->nodeTypeName,
            $subtree->node->timestamps,
            iterator_to_array($subtree->node->properties->getIterator()),
            $children,
        );

    }
}

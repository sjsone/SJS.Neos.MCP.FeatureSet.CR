<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\Trait;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;

trait ContentRepositoryTool
{
    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    protected function getContentRepository(ActionRequest $actionRequest): ContentRepository
    {
        $httpRequest = $actionRequest->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest(request: $httpRequest)->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get(contentRepositoryId: $contentRepositoryId);

        return $contentRepository;
    }

    protected function getNode(ContentRepository $contentRepository, WorkspaceName $workspaceName, NodeAddress $nodeAddress): Node
    {
        $graph = $contentRepository->getContentGraph(workspaceName: $workspaceName);
        $subGraph = $graph->getSubgraph(dimensionSpacePoint: $nodeAddress->dimensionSpacePoint, visibilityConstraints: VisibilityConstraints::default());
        $node = $subGraph->findNodeById(nodeAggregateId: $nodeAddress->aggregateId);
        if ($node === null) {
            throw new \InvalidArgumentException("Could not get Node with 'workspaceName' and 'nodeAddress'");
        }

        return $node;
    }

    protected function validateNodeAddress(NodeAddress $nodeAddress): void
    {
        $this->validateWorkspaceName(workspaceName: $nodeAddress->workspaceName);
    }

    protected function validateWorkspaceName(WorkspaceName $workspaceName): void
    {
        if ($workspaceName->equals(WorkspaceName::forLive())) {
            throw new \InvalidArgumentException('Updating nodes on Live workspace is currently disabled.');
        }
    }

    /**
     * @param array<string,mixed> $input
     */
    protected function retrieveNodeAddress(array $input): NodeAddress
    {
        $nodeAddressArray = $input["node_address"] ?? [];
        return NodeAddress::fromArray($nodeAddressArray);
    }

    /**
     * @param array<string,mixed> $input
     */
    protected function retrieveNodeTypeName(array $input): NodeTypeName
    {
        $nodeTypeNameString = $input["node_type_name"] ?? "";
        return NodeTypeName::fromString($nodeTypeNameString);
    }
}
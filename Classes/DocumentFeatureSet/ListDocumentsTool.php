<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Log\LoggerInterface;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;

class ListDocumentsTool extends Tool implements ToolConstructor
{
    use Trait\ContentRepositoryTool;

    #[Flow\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;

    #[Flow\Inject(name: "SJS.Flow.MCP:MCPLogger", lazy: false)]
    protected LoggerInterface $logger;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            featureSet: $featureSet,
            name: 'list_documents',
            description: 'Lists all documents of the site',
            inputSchema: new ObjectSchema(properties: [
                'nodeType' => new StringSchema(description: "What NodeTypes should be filtered for", default: "Neos.Demo:Document"),
                // 'dimensions' => new ObjectSchema()...
            ]),
            annotations: new Annotations(
                title: 'List Documents',
                readOnlyHint: true
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return Tool\Content
     */
    public function run(ServerContext $serverContext, array $input): Content
    {

        $contentRepository = $this->getContentRepository($serverContext);

        $graph = $contentRepository->getContentGraph(WorkspaceName::forLive());

        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($serverContext->request);

        $nodeTypeManager = $contentRepository->getNodeTypeManager();

        $filterNodeType = $input['nodeType'] ?? 'Neos.Demo:Document';

        if ($nodeTypeManager->getNodeType($filterNodeType) === null) {
            $this->logger->error("Unknown NodeType: $filterNodeType");
            $filterNodeType = 'Neos.Demo:Document';
        }

        $resources = [];

        $nodeTypes = $nodeTypeManager->getSubNodeTypes(NodeTypeName::fromString($filterNodeType));
        foreach ($nodeTypes as $nodeType) {
            $nodeAggregates = $graph->findNodeAggregatesByType($nodeType->name);

            foreach ($nodeAggregates as $nodeAggregate) {
                foreach ($nodeAggregate->occupiedDimensionSpacePoints as $spacePoint) {
                    $hash = "{$spacePoint->hash}__{$nodeAggregate->nodeAggregateId}";
                    if (\array_key_exists($hash, $resources)) {
                        continue;
                    }

                    $node = $nodeAggregate->getNodeByOccupiedDimensionSpacePoint($spacePoint);
                    $nodeAddress = NodeAddress::fromNode($node);

                    $resources[$hash] = [
                        'uri' => $nodeUriBuilder->uriFor($nodeAddress),
                        'aggregateId' => $node->aggregateId,
                        'title' => $node->getProperty("title") ?? "",
                        'uriPathSegment' => $node->getProperty("uriPathSegment"),
                        'nodeName' => $node->name,
                        'nodeAddress' => $nodeAddress
                    ];
                }
            }
        }

        return Content::structuredWithFallback($resources);
    }
}

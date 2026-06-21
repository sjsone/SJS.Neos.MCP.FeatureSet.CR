<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use Psr\Log\LoggerInterface;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;

class ResolveNodePathTool extends Tool implements ToolConstructor
{
    use Trait\ContentRepositoryTool;

    #[Flow\Inject(name: "SJS.Flow.MCP:MCPLogger", lazy: false)]
    protected LoggerInterface $logger;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'resolve_node_path',
            description: 'Resolves a human-readable URL path (e.g. /en/about/team or /about.html) to a document node. Returns the node address and metadata, or null if no match is found. Handles partial matches gracefully.',
            inputSchema: new ObjectSchema(properties: [
                'path' => (new StringSchema(
                    description: "URL path to resolve, e.g. '/en/about/team' or '/about.html'. Leading slash and file extension are optional."
                ))->required(),
                'workspace' => new StringSchema(
                    description: "Workspace name. Defaults to 'live'."
                ),
            ]),
            annotations: new Annotations(
                title: 'Resolve Node Path',
                readOnlyHint: true
            ),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $rawPath = $input['path'] ?? '';

        // Normalize: strip leading/trailing slashes and .html suffix
        $uriPath = \trim(\trim($rawPath, '/'));
        if (\str_ends_with($uriPath, '.html')) {
            $uriPath = \substr($uriPath, 0, -5);
        }

        $contentRepository = $this->getContentRepository($serverContext);

        $siteDetection = SiteDetectionResult::fromRequest(
            $serverContext->request->getHttpRequest()
        );

        $documentUriPathFinder = $contentRepository->projectionState(
            DocumentUriPathFinder::class
        );

        // Try exact match against the URI path projection for each dimension space point.
        // The projection stores URI paths WITHOUT the dimension prefix (e.g. "features"
        // not "en/features"). The dimension prefix is handled by the dimension resolver.
        // O(number of DSPs) — typically 2-5 — instead of O(all documents).
        $variationGraph = $contentRepository->getVariationGraph();
        $matches = [];

        foreach ($variationGraph->getDimensionSpacePoints() as $point) {
            // Derive the dimension URI prefix for this DSP (e.g. "en" for en_US, "de" for de)
            $dimensionSegments = [];
            foreach ($point->coordinates as $coordValue) {
                $dimensionSegments[] = \explode('_', $coordValue, 2)[0];
            }
            $dimensionPrefix = \implode('/', $dimensionSegments);

            // Strip the dimension prefix from the URI path if present
            $uriPathWithoutDimension = $uriPath;
            if ($dimensionPrefix !== '' && \str_starts_with($uriPath, $dimensionPrefix . '/')) {
                $uriPathWithoutDimension = \substr($uriPath, \strlen($dimensionPrefix) + 1);
            } elseif ($uriPath === $dimensionPrefix) {
                $uriPathWithoutDimension = '';
            }

            try {
                $nodeInfo = $documentUriPathFinder->getEnabledBySiteNodeNameUriPathAndDimensionSpacePointHash(
                    $siteDetection->siteNodeName,
                    $uriPathWithoutDimension,
                    $point->hash,
                );
            } catch (\Neos\Neos\FrontendRouting\Exception\NodeNotFoundException) {
                continue;
            }

            $matches[] = [
                'nodeAddress' => NodeAddress::create(
                    $contentRepository->id,
                    WorkspaceName::forLive(),
                    $point,
                    $nodeInfo->getNodeAggregateId(),
                )->jsonSerialize(),
                'nodeTypeName' => $nodeInfo->getNodeTypeName()->value,
                'uriPath' => $nodeInfo->getUriPath(),
                'dimensions' => $point->coordinates,
                'matchType' => 'exact',
            ];
        }

        if (empty($matches)) {
            return Content::text("No document found for path: {$input['path']}");
        }

        return Content::structuredWithFallback(['matches' => $matches]);
    }
}

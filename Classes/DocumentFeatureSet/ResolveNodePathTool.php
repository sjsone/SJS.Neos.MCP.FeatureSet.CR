<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
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

    #[Flow\Inject]
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
        $workspaceName = isset($input['workspace']) && $input['workspace'] !== ''
            ? WorkspaceName::fromString($input['workspace'])
            : WorkspaceName::forLive();

        $segments = $this->normalizePathSegments($rawPath);

        $contentRepository = $this->getContentRepository($serverContext);
        $graph = $contentRepository->getContentGraph($workspaceName);

        // Find all root document node aggregates to discover dimension space points
        $rootFilter = FindRootNodeAggregatesFilter::create(
            nodeTypeName: NodeTypeName::fromString('Neos.Neos:Document')
        );
        $rootAggregates = $graph->findRootNodeAggregates($rootFilter);

        // Collect unique dimension space points from all root aggregates
        $dimensionSpacePoints = [];
        foreach ($rootAggregates as $aggregate) {
            foreach ($aggregate->occupiedDimensionSpacePoints as $originPoint) {
                $dsp = $originPoint->toDimensionSpacePoint();
                $dimensionSpacePoints[$dsp->hash] = $dsp;
            }
        }

        $matches = [];

        foreach ($dimensionSpacePoints as $spacePoint) {
            $subgraph = $graph->getSubgraph(
                $spacePoint,
                VisibilityConstraints::default()
            );

            // Find the root document node in this subgraph via coverage check
            $rootNode = $this->findRootNodeInSubgraph($rootAggregates, $subgraph, $spacePoint);

            if ($rootNode === null) {
                continue;
            }

            $result = $this->resolvePathInSubgraph($subgraph, $rootNode, $segments);

            if ($result !== null) {
                $matches[] = $result;
            }
        }

        if (empty($matches)) {
            return Content::text("No document found for path: {$input['path']}");
        }

        return Content::structuredWithFallback($matches);
    }

    /**
     * Normalize a URL path into segments.
     *
     * Examples:
     *   '/en/about/team' -> ['en', 'about', 'team']
     *   '/about.html'    -> ['about']
     *   '/'              -> []
     *
     * @return array<string>
     */
    private function normalizePathSegments(string $path): array
    {
        $path = \ltrim($path, '/');

        if (\str_ends_with($path, '.html')) {
            $path = \substr($path, 0, -5);
        }

        $segments = \array_filter(
            \explode('/', $path),
            fn(string $s): bool => $s !== ''
        );

        return \array_values($segments);
    }

    /**
     * Find the root document node from the root aggregates that exists
     * in the given subgraph dimension space point.
     */
    private function findRootNodeInSubgraph(
        iterable $rootAggregates,
        ContentSubgraphInterface $subgraph,
        DimensionSpacePoint $spacePoint
    ): ?Node {
        foreach ($rootAggregates as $aggregate) {
            if (!$aggregate->coversDimensionSpacePoint($spacePoint)) {
                continue;
            }

            $originPoint = $aggregate->getOccupationByCovered($spacePoint);
            try {
                $node = $aggregate->getNodeByOccupiedDimensionSpacePoint($originPoint);
                $accessibleNode = $subgraph->findNodeById($node->aggregateId);
                if ($accessibleNode !== null) {
                    return $accessibleNode;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    \sprintf(
                        'Could not access root document node for dimension %s: %s',
                        $spacePoint->hash,
                        $e->getMessage()
                    )
                );
            }
        }

        return null;
    }

    /**
     * Resolve path segments in a specific dimension's subgraph.
     *
     * Tries two matching strategies:
     * 1. Root node's uriPathSegment matches the first segment (standard case)
     * 2. Root node's segment is skipped; children are matched against the first segment
     *
     * @param array<string> $segments
     * @return array<string, mixed>|null
     */
    private function resolvePathInSubgraph(
        ContentSubgraphInterface $subgraph,
        Node $rootNode,
        array $segments
    ): ?array {
        if (empty($segments)) {
            return $this->buildNodeResult($subgraph, $rootNode, 'exact');
        }

        // Strategy 1: root's uriPathSegment is the first segment
        $result = $this->matchSegments($subgraph, $rootNode, $segments, 0);
        if ($result !== null) {
            return $this->buildNodeResult($subgraph, $result['node'], $result['matchType']);
        }

        // Strategy 2: skip root's segment and try children directly
        $result = $this->matchChildren($subgraph, $rootNode, $segments, 0);
        if ($result !== null) {
            return $this->buildNodeResult($subgraph, $result['node'], $result['matchType']);
        }

        return null;
    }

    /**
     * Try to match the current node's uriPathSegment against the segment
     * at the given offset. If matched and more segments remain, recurses
     * into children. Returns the deepest match (exact or partial).
     *
     * @param array<string> $segments
     * @return array<string, mixed>|null
     */
    private function matchSegments(
        ContentSubgraphInterface $subgraph,
        Node $node,
        array $segments,
        int $offset
    ): ?array {
        if ($offset >= \count($segments)) {
            return null;
        }

        $uriPathSegment = $node->getProperty('uriPathSegment');

        if (!\is_string($uriPathSegment) || $uriPathSegment !== $segments[$offset]) {
            return null;
        }

        $newOffset = $offset + 1;

        if ($newOffset >= \count($segments)) {
            // All segments consumed — exact match at this node
            return [
                'node' => $node,
                'matchType' => 'exact',
                'matchedCount' => $newOffset,
            ];
        }

        // More segments remain — try matching through children
        $childResult = $this->matchChildren($subgraph, $node, $segments, $newOffset);

        if ($childResult !== null) {
            return $childResult;
        }

        // No child could match the remaining segment(s)
        // Return this node as the deepest partial match
        return [
            'node' => $node,
            'matchType' => 'partial',
            'matchedCount' => $newOffset,
        ];
    }

    /**
     * Try to match remaining segments against children of the given parent node.
     *
     * @param array<string> $segments
     * @return array<string, mixed>|null
     */
    private function matchChildren(
        ContentSubgraphInterface $subgraph,
        Node $parent,
        array $segments,
        int $offset
    ): ?array {
        $children = $subgraph->findChildNodes(
            $parent->aggregateId,
            FindChildNodesFilter::create()
        );

        $bestPartial = null;

        foreach ($children as $child) {
            $result = $this->matchSegments($subgraph, $child, $segments, $offset);

            if ($result === null) {
                continue;
            }

            if ($result['matchType'] === 'exact') {
                return $result;
            }

            if (
                $bestPartial === null
                || $result['matchedCount'] > $bestPartial['matchedCount']
            ) {
                $bestPartial = $result;
            }
        }

        return $bestPartial;
    }

    /**
     * Build the result array for a matched node.
     *
     * @return array<string, mixed>
     */
    private function buildNodeResult(
        ContentSubgraphInterface $subgraph,
        Node $node,
        string $matchType
    ): array {
        $nodeAddress = NodeAddress::fromNode($node);

        return [
            'nodeAddress' => $nodeAddress,
            'nodeTypeName' => $node->nodeTypeName->value,
            'name' => $node->name?->value ?? '',
            'title' => $node->getProperty('title') ?? '',
            'uriPathSegment' => $node->getProperty('uriPathSegment') ?? '',
            'urlPath' => $this->buildUrlPath($subgraph, $node),
            'dimensions' => $node->dimensionSpacePoint->coordinates,
            'matchType' => $matchType,
            'workspaceName' => $node->workspaceName->value,
        ];
    }

    /**
     * Build the full URL path for a node by walking up the parent hierarchy
     * and collecting uriPathSegment values.
     */
    private function buildUrlPath(
        ContentSubgraphInterface $subgraph,
        Node $node
    ): string {
        $segments = [];
        $currentId = $node->aggregateId;

        while (true) {
            $currentNode = $subgraph->findNodeById($currentId);
            if ($currentNode === null) {
                break;
            }

            $segment = $currentNode->getProperty('uriPathSegment');
            if (\is_string($segment) && $segment !== '') {
                array_unshift($segments, $segment);
            }

            $parent = $subgraph->findParentNode($currentId);
            if ($parent === null) {
                break;
            }
            $currentId = $parent->aggregateId;
        }

        if (empty($segments)) {
            return '/';
        }

        return '/' . \implode('/', $segments);
    }
}

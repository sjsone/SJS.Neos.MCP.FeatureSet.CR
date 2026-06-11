<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;

class GetNodeAggregateInfoTool extends Tool implements ToolConstructor
{
    use Trait\ContentRepositoryTool;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'get_node_aggregate_info',
            description: 'Returns comprehensive information about a node aggregate: all occupied dimension space points, properties per variant, shadowing relationships, child nodes, and entity references. Use this to understand the full picture of a node across all dimensions.',
            inputSchema: new ObjectSchema(properties: [
                "node_address" => (new ObjectSchema(
                    description: "The node address of any node in the aggregate to inspect",
                    properties: [
                        "contentRepositoryId" => new StringSchema(),
                        "workspaceName" => new StringSchema(),
                        "dimensionSpacePoint" => new ObjectSchema(),
                        "aggregateId" => new StringSchema()
                    ]
                ))->required(),
            ]),
            annotations: new Annotations(
                title: 'Get Node Aggregate Info',
                readOnlyHint: true
            ),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        /** @var array<string,mixed> $nodeAddressArray */
        $nodeAddressArray = $input["node_address"];
        $nodeAddress = NodeAddress::fromArray($nodeAddressArray);

        $contentRepository = $this->getContentRepository($serverContext);

        $graph = $contentRepository->getContentGraph($nodeAddress->workspaceName);
        $aggregate = $graph->findNodeAggregateById($nodeAddress->aggregateId);
        if ($aggregate === null) {
            throw new \InvalidArgumentException("Node aggregate not found");
        }

        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($aggregate->nodeTypeName);
        $variationGraph = $contentRepository->getVariationGraph();

        $classification = [
            'isDocument' => $nodeType?->isOfType(NodeTypeName::fromString('Neos.Neos:Document')) ?? false,
            'isContentCollection' => $nodeType?->isOfType(NodeTypeName::fromString('Neos.Neos:ContentCollection')) ?? false,
            'isContent' => $nodeType?->isOfType(NodeTypeName::fromString('Neos.Neos:Content')) ?? false,
        ];

        $occupiedDimensionSpacePoints = $this->buildOccupiedDimensionSpacePoints(
            aggregate: $aggregate,
            variationGraph: $variationGraph,
            nodeTypeProperties: $nodeType?->getProperties() ?? []
        );

        $namedChildNodes = $this->buildChildNodes(
            aggregate: $aggregate,
            contentRepository: $contentRepository,
            nodeAddress: $nodeAddress
        );

        $references = $this->buildReferences(
            aggregate: $aggregate,
            nodeTypeProperties: $nodeType?->getProperties() ?? []
        );

        $result = [
            'aggregateId' => $aggregate->nodeAggregateId->value,
            'nodeTypeName' => $aggregate->nodeTypeName->value,
            'classification' => $classification,
            'workspaceName' => $aggregate->workspaceName->value,
            'nodeName' => $aggregate->nodeName?->value,
            'occupiedDimensionSpacePoints' => $occupiedDimensionSpacePoints,
            'namedChildNodes' => $namedChildNodes,
            'references' => $references,
        ];

        return Content::structuredWithFallback($result);
    }

    /**
     * @param array<string,mixed> $nodeTypeProperties
     * @return array<int,array<string,mixed>>
     */
    protected function buildOccupiedDimensionSpacePoints(
        NodeAggregate $aggregate,
        InterDimensionalVariationGraph $variationGraph,
        array $nodeTypeProperties,
    ): array {
        $occupiedDspList = [];

        foreach ($aggregate->occupiedDimensionSpacePoints as $originDsp) {
            $dsp = $originDsp->toDimensionSpacePoint();
            $node = $aggregate->getNodeByOccupiedDimensionSpacePoint($originDsp);

            // Use serialized property values for reliable JSON output
            $serializedProperties = [];
            foreach ($node->properties->serialized() as $propertyName => $serializedValue) {
                $serializedProperties[(string) $propertyName] = $serializedValue->value;
            }

            // Build property type map from the NodeType configuration
            $propertyTypes = [];
            foreach ($nodeTypeProperties as $propName => $propConfig) {
                $propertyTypes[(string) $propName] = $propConfig['type'] ?? 'string';
            }

            // Shadowing relationships via the interdimensional variation graph
            $generalizations = $variationGraph->getIndexedGeneralizations($dsp);
            $specializations = $variationGraph->getIndexedSpecializations($dsp);

            $isShadowedBy = null;
            if ($generalizations->count() > 0) {
                $isShadowedBy = [];
                foreach ($generalizations as $generalization) {
                    $isShadowedBy[] = $generalization->coordinates;
                }
            }

            $shadows = null;
            if ($specializations->count() > 0) {
                $shadows = [];
                foreach ($specializations as $specialization) {
                    $shadows[] = $specialization->coordinates;
                }
            }

            $occupiedDspList[] = [
                'coordinates' => $dsp->coordinates,
                'originDimensionSpacePoint' => $originDsp->coordinates,
                'nodeAddress' => NodeAddress::fromNode($node)->jsonSerialize(),
                'name' => $node->name?->value ?? '',
                'properties' => $serializedProperties,
                'propertyTypes' => $propertyTypes,
                'isShadowedBy' => $isShadowedBy,
                'shadows' => $shadows,
            ];
        }

        return $occupiedDspList;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function buildChildNodes(
        NodeAggregate $aggregate,
        \Neos\ContentRepository\Core\ContentRepository $contentRepository,
        NodeAddress $nodeAddress,
    ): array {
        $graph = $contentRepository->getContentGraph($nodeAddress->workspaceName);
        $subgraph = $graph->getSubgraph(
            $nodeAddress->dimensionSpacePoint,
            VisibilityConstraints::default()
        );

        $childrenByAggregateId = [];
        foreach ($aggregate->getNodes() as $aggregateNode) {
            $childNodes = $subgraph->findChildNodes(
                $aggregateNode->aggregateId,
                FindChildNodesFilter::create()
            );
            foreach ($childNodes as $childNode) {
                $childAggregateId = $childNode->aggregateId->value;
                if (!isset($childrenByAggregateId[$childAggregateId])) {
                    $childSubgraph = $graph->getSubgraph(
                        $childNode->dimensionSpacePoint,
                        VisibilityConstraints::default()
                    );
                    $grandchildCount = $childSubgraph->countChildNodes(
                        $childNode->aggregateId,
                        CountChildNodesFilter::create()
                    );

                    $childrenByAggregateId[$childAggregateId] = [
                        'nodeAddress' => NodeAddress::fromNode($childNode)->jsonSerialize(),
                        'nodeTypeName' => $childNode->nodeTypeName->value,
                        'name' => $childNode->name?->value,
                        'childCount' => $grandchildCount,
                    ];
                }
            }
        }

        return $childrenByAggregateId;
    }

    /**
     * @param array<string,mixed> $nodeTypeProperties
     * @return array<string,array<string,mixed>>
     */
    protected function buildReferences(
        NodeAggregate $aggregate,
        array $nodeTypeProperties,
    ): array {
        $references = [];

        foreach ($nodeTypeProperties as $propertyName => $propertyConfig) {
            $propertyType = $propertyConfig['type'] ?? 'string';
            // Only consider non-scalar types (class/interface references)
            if (!\class_exists($propertyType) && !interface_exists($propertyType)) {
                continue;
            }

            // Look through all dimension variants for this property value
            foreach ($aggregate->getNodes() as $node) {
                if (!isset($node->properties[$propertyName])) {
                    continue;
                }
                $propertyValue = $node->properties[$propertyName];
                if (!\is_object($propertyValue)) {
                    continue;
                }
                // Check if it looks like an entity reference with an __identifier
                $identifier = null;
                if (isset($propertyValue->__identifier)) {
                    $identifier = $propertyValue->__identifier;
                } elseif (\method_exists($propertyValue, 'jsonSerialize')) {
                    $serialized = $propertyValue->jsonSerialize();
                    if (\is_array($serialized) && isset($serialized['__identifier'])) {
                        $identifier = $serialized['__identifier'];
                    }
                }

                if ($identifier !== null) {
                    $references[$propertyName] = [
                        'propertyType' => $propertyType,
                        '__identifier' => $identifier,
                    ];
                }
                // Once found for one dimension variant, move to next property
                break;
            }
        }

        return $references;
    }
}

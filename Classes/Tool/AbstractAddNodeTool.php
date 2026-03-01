<?php
declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\Tool;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\References;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Mvc\ActionRequest;
use SJS\Neos\MCP\Domain\MCP\Tool;
use SJS\Neos\MCP\Domain\MCP\Tool\Annotations;
use SJS\Neos\MCP\Domain\MCP\Tool\Content;
use SJS\Neos\MCP\JsonSchema\ObjectSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;
use SJS\Neos\MCP\JsonSchema\StringSchema;


abstract class AbstractAddNodeTool extends Tool
{
    use Trait\ContentRepositoryTool;

    protected string $requiredNodeTypeName = "";


    protected static function createInputSchema(): ObjectSchema
    {
        return new ObjectSchema(properties: [
            "node_address" => (new ObjectSchema(
                description: "The node_address returned from other tools",
                properties: [
                    "contentRepositoryId" => new StringSchema(),
                    "workspaceName" => new StringSchema(),
                    "dimensionSpacePoint" => new ObjectSchema(),
                    "aggregateId" => new StringSchema()
                ]
            ))->required(),
            "node_type_name" => (new StringSchema(description: "NodeType of the to-be-created Node"))->required(),
            "parent_node_aggregate_id" => (new StringSchema(description: "NodeAggregateId of the parent Node"))->required(),
            "node_properties" => new ObjectSchema(description: "The properties for the Node. The NodeType should be read first to make sure the properties are set correctly"),
        ]);
    }

    public function run(ActionRequest $actionRequest, array $input): Content
    {
        $nodeAddress = $this->retrieveNodeAddress($input);
        $nodeTypeName = $this->retrieveNodeTypeName($input);
        $parentNodeAggregateId = $this->retrieveParentNodeAggregateId($input);
        $properties = $this->retrieveProperties($input);

        $this->validateNodeAddress($nodeAddress);

        $contentRepository = $this->getContentRepository($actionRequest);

        $this->validateNodeType($contentRepository, $nodeTypeName);

        $newNodeAggregateId = NodeAggregateId::create();
        $command = $this->createCommand(
            newNodeAggregateId: $newNodeAggregateId,
            nodeAddress: $nodeAddress,
            nodeTypeName: $nodeTypeName,
            parentNodeAggregateId: $parentNodeAggregateId,
            properties: $properties,
        );

        $contentRepository->handle(command: $command);
        return Content::text("Created Node with nodeAggregateId: {$newNodeAggregateId}");
    }

    protected function retrieveParentNodeAggregateId(array $input): NodeAggregateId
    {
        $parentNodeAggregateIdString = $input["parent_node_aggregate_id"] ?? "";
        return NodeAggregateId::fromString($parentNodeAggregateIdString);
    }

    protected function retrieveProperties(array $input): ?PropertyValuesToWrite
    {
        $propertiesArray = $input["node_properties"] ?? null;
        if ($propertiesArray === null) {
            return null;
        }

        return PropertyValuesToWrite::fromArray($propertiesArray);
    }

    protected function createCommand(NodeAggregateId $newNodeAggregateId, NodeAddress $nodeAddress, NodeTypeName $nodeTypeName, NodeAggregateId $parentNodeAggregateId, ?PropertyValuesToWrite $properties): CommandInterface
    {
        return CreateNodeAggregateWithNode::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $newNodeAggregateId,
            nodeTypeName: $nodeTypeName,
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromDimensionSpacePoint($nodeAddress->dimensionSpacePoint),
            parentNodeAggregateId: $parentNodeAggregateId,
            initialPropertyValues: $properties,
        );
    }

    protected function validateNodeType(ContentRepository $contentRepository, NodeTypeName $nodeTypeName)
    {
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            throw new \InvalidArgumentException("Unknown NodeType '$nodeTypeName'");
        }

        if (!$nodeType->isOfType(NodeTypeName::fromString($this->requiredNodeTypeName))) {
            throw new \InvalidArgumentException("NodeType '$nodeTypeName' is not of type '{$this->requiredNodeTypeName}'");
        }
    }
}
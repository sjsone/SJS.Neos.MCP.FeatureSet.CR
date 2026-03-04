<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Neos\MCP\FeatureSet\CR\Trait;
use SJS\Flow\MCP\JsonSchema\AnySchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use Neos\Flow\Annotations as Flow;

class UpdateContentTool extends Tool
{

    use Trait\ContentRepositoryTool;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function __construct()
    {
        parent::__construct(
            name: 'update_content',
            description: 'Updates properties on an existing content node',
            inputSchema: new ObjectSchema(properties: [
                "node_address" => (new ObjectSchema(
                    description: "The node_address returned from other tools",
                    properties: [
                        "contentRepositoryId" => new StringSchema(),
                        "workspaceName" => new StringSchema(),
                        "dimensionSpacePoint" => new ObjectSchema(),
                        "aggregateId" => new StringSchema()
                    ]
                ))->required(),
                "property_name" => (new StringSchema(description: "The property name to update"))->required(),
                "property_value" => (new AnySchema(
                    description: "The value of the property. Can be either a string for simple values or an object for more complex ones like images and other entities",
                    options: [
                        new StringSchema(),
                        new ObjectSchema()
                    ]
                ))->required(),
            ]),
            annotations: new Annotations(
                title: 'Update Content',
                idempotentHint: true
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ActionRequest $actionRequest, array $input): Content
    {
        $propertyName = $this->retrievePropertyName($input);
        $propertyValue = $this->retrievePropertyValue($input);
        $nodeAddress = $this->retrieveNodeAddress($input);

        $this->validateNodeAddress($nodeAddress);
        $workspaceName = $nodeAddress->workspaceName;

        $contentRepository = $this->getContentRepository($actionRequest);

        $node = $this->getNode($contentRepository, $workspaceName, $nodeAddress);
        $this->validateNode($node, $contentRepository, $propertyName);

        $nodeType = $this->getNodeType($node, contentRepository: $contentRepository);
        $this->validateNodeType(nodeType: $nodeType, propertyName: $propertyName);

        $propertyValue = $this->cleanupPropertyValue(nodeType: $nodeType, propertyName: $propertyName, propertyValue: $propertyValue);

        $command = $this->createCommand(
            nodeAddress: $nodeAddress,
            propertyName: $propertyName,
            propertyValue: $propertyValue,
        );

        $contentRepository->handle($command);

        return Content::text("Property updated");
    }

    /**
     * @param array<string,mixed> $input
     */
    protected function retrievePropertyName(array $input): string
    {
        $propertyName = $input["property_name"];
        if (!\is_string($propertyName)) {
            throw new \InvalidArgumentException("property_name must be string");
        }
        return $propertyName;
    }

    /**
     * @param array<string,mixed> $input
     */
    protected function retrievePropertyValue(array $input): mixed
    {
        $propertyValue = $input["property_value"];
        if (!\is_string($propertyValue)) {
            throw new \InvalidArgumentException("property_value must be string");
        }
        return $propertyValue;
    }

    protected function validateNode(?Node $node, ContentRepository $contentRepository, string $propertyName): void
    {
        if ($node === null) {
            throw new \InvalidArgumentException('Could not find node.');
        }
    }

    protected function getNodeType(Node $node, ContentRepository $contentRepository): NodeType
    {
        $nodeTypeName = $node->nodeTypeName;
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            throw new \Exception('Node found but NodeType is null. ');
        }

        return $nodeType;
    }

    protected function validateNodeType(NodeType $nodeType, string $propertyName): void
    {
        if (!$nodeType->hasProperty($propertyName)) {
            throw new \InvalidArgumentException("Node of type {$nodeType->name} does not have property with name: '$propertyName'");
        }
    }

    protected function cleanupPropertyValue(NodeType $nodeType, string $propertyName, mixed $propertyValue): mixed
    {
        $propertyType = $nodeType->getPropertyType($propertyName);

        if ($propertyType === "string" && \is_string($propertyValue)) {
            $propertyValue = str_replace("\\\"", "\"", $propertyValue);
            $propertyValue = str_replace("<\/p>", "</p>", $propertyValue);
        }

        if (class_exists($propertyType) || interface_exists($propertyType)) {
            if (!\is_array($propertyValue) || !isset($propertyValue["__flow_object_type"]) || !isset($propertyValue["__identifier"])) {
                throw new \InvalidArgumentException("The property $propertyName is expected to be of type an '$propertyType' entity. So the value is expected to be an object of {__flow_object_type:\"\", __identifier:\"\"}");
            }

            $identifier = $propertyValue["__identifier"];
            if (!\is_string($identifier)) {
                throw new \InvalidArgumentException("__identifier must be a string");
            }

            $flowObjectType = $propertyValue["__flow_object_type"];
            if (!\is_string($flowObjectType)) {
                throw new \InvalidArgumentException("__flow_object_type must be a string");
            }

            if (!class_exists($flowObjectType) && !interface_exists($flowObjectType)) {
                throw new \InvalidArgumentException("__flow_object_type must be an existing FQCN");

            }

            $propertyValue = $this->persistenceManager->getObjectByIdentifier($identifier, $flowObjectType);
        }

        return $propertyValue;
    }

    protected function createCommand(NodeAddress $nodeAddress, string $propertyName, mixed $propertyValue): CommandInterface
    {
        return SetNodeProperties::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromDimensionSpacePoint($nodeAddress->dimensionSpacePoint),
            propertyValues: PropertyValuesToWrite::fromArray([
                $propertyName => $propertyValue
            ])
        );
    }
}

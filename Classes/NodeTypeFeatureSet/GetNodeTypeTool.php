<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\NodeTypeFeatureSet;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;


class GetNodeTypeTool extends Tool
{
    use Trait\ContentRepositoryTool;

    public function __construct()
    {
        parent::__construct(
            name: 'get_node_type',
            description: 'Returns full configuration for a specific NodeType',
            inputSchema: new ObjectSchema(properties: [
                'name' => (new StringSchema(description: 'The NodeType name, e.g. Neos.Demo:Document'))->required(),
            ]),
            annotations: new Annotations(
                title: 'Get Node Type',
                readOnlyHint: true
            )
        );
    }

    public function run(ActionRequest $actionRequest, array $input): Content
    {
        $contentRepository = $this->getContentRepository($actionRequest);

        $nodeTypeName = $this->retrieveNodeTypeName($input);
        $nodeType = $this->getNodeType($contentRepository, $nodeTypeName);

        $fullConfig = $nodeType->getFullConfiguration();

        return Content::structured($fullConfig)->addText(json_encode($fullConfig));
    }

    public function retrieveNodeTypeName(array $input): NodeTypeName
    {
        $nodeTypeNameAsString = $input['name'] ?? null;
        $nodeTypeName = NodeTypeName::fromString($nodeTypeNameAsString);
        return $nodeTypeName;
    }

    public function getNodeType(ContentRepository $contentRepository, NodeTypeName $nodeTypeName): NodeType
    {
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            throw new \InvalidArgumentException("Could not find NodeType '{$nodeTypeName}'");
        }
        return $nodeType;
    }
}

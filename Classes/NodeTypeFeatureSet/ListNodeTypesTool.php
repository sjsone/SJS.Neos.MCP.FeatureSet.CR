<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\NodeTypeFeatureSet;

use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Neos\MCP\FeatureSet\CR\Trait;


class ListNodeTypesTool extends Tool implements ToolConstructor
{
    use Trait\ContentRepositoryTool;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'list_node_types',
            description: 'Lists all available NodeTypes',
            inputSchema: new ObjectSchema(),
            annotations: new Annotations(
                title: 'List Node Types',
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
        $contentRepository = $this->getContentRepository($serverContext);

        $nodeTypes = [];
        foreach ($contentRepository->getNodeTypeManager()->getNodeTypes(true) as $nodeType) {
            $name = (string) $nodeType->name;
            $nodeTypes[$name] = [
                'name' => $name,
                'label' => $nodeType->getLabel(),
                'abstract' => $nodeType->isAbstract(),
                'final' => $nodeType->isFinal(),
                'description' => $nodeType->getConfiguration('description') ?? '',
                'superTypes' => \array_map(fn($st) => (string) $st->name, $nodeType->getDeclaredSuperTypes()),
            ];
        }

        return Content::structuredWithFallback($nodeTypes);
    }
}

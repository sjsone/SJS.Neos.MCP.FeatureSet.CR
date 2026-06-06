<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;

class RemoveContentTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'remove_content',
            description: 'Removes a content node',
            inputSchema: new ObjectSchema(),
            annotations: new Annotations(
                title: 'Remove Content',
                destructiveHint: true
            )
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        // TODO: implement
        return Content::text('Not yet implemented.');
    }
}

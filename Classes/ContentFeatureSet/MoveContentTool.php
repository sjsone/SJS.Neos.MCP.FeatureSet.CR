<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\Flow\Mvc\ActionRequest;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;

class MoveContentTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'move_content',
            description: 'Moves a content node to a different position or parent',
            inputSchema: new ObjectSchema(),
            annotations: new Annotations(
                title: 'Move Content'
            )
        );
    }

    public function run(ActionRequest $actionRequest, array $input): Content
    {
        // MoveNodeAggregate::create(

        // )

        // TODO: implement
        return Content::text('Not yet implemented.');
    }
}

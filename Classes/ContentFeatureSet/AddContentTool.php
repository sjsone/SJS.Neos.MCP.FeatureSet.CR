<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet;

use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Neos\MCP\FeatureSet\CR\Tool\AbstractAddNodeTool;

class AddContentTool extends AbstractAddNodeTool
{
    protected string $requiredNodeTypeName = "Neos.Neos:Content";

    public function __construct()
    {
        parent::__construct(
            name: 'add_content',
            description: 'Adds a new content node as a child of a given parent node. Be aware of the eventual consistency so let some seconds pass before reading again to make sure the new node is there.',
            inputSchema: self::createInputSchema(),
            annotations: new Annotations(
                title: 'Add Content'
            )
        );
    }

}

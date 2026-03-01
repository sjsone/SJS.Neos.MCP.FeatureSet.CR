<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet;

use SJS\Neos\MCP\Domain\MCP\Tool\Annotations;
use SJS\Neos\MCP\FeatureSet\CR\Tool\AbstractAddNodeTool;

class AddDocumentTool extends AbstractAddNodeTool
{
    protected string $requiredNodeTypeName = "Neos.Neos:Document";

    public function __construct()
    {
        parent::__construct(
            name: 'add_document',
            description: 'Adds a new document node as a child of a given parent document node. Be aware of eventual consistency so let some seconds pass before reading again.',
            inputSchema: self::createInputSchema(),
            annotations: new Annotations(
                title: 'Add Document',
            )
        );
    }
}

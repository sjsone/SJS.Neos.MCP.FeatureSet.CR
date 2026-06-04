<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;
use SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet\AddDocumentTool;
use SJS\Neos\MCP\FeatureSet\CR\DocumentFeatureSet\ListDocumentsTool;

#[Flow\Scope("singleton")]
class DocumentFeatureSet extends AbstractFeatureSet
{
    public function initialize(): void
    {
        $this->addTool(ListDocumentsTool::class);
        $this->addTool(AddDocumentTool::class);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    public function toolsCall(string $toolName, array $arguments): Content
    {
        return $this->catchCRExceptions(fn() => parent::toolsCall($toolName, $arguments));
    }
}

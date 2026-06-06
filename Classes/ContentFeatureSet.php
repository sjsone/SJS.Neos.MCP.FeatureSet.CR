<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\AddContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\ContentTreeTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\CopyNodeTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\FindNodesTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\GetNodeAggregateInfoTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\MoveContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\RemoveContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\UpdateContentTool;

#[Flow\Scope("singleton")]
class ContentFeatureSet extends AbstractFeatureSet
{
    public function initialize(): void
    {
        $this->addTool(ContentTreeTool::class);
        $this->addTool(UpdateContentTool::class);
        $this->addTool(AddContentTool::class);
        $this->addTool(MoveContentTool::class);
        $this->addTool(RemoveContentTool::class);
        $this->addTool(FindNodesTool::class);
        $this->addTool(CopyNodeTool::class);
        $this->addTool(GetNodeAggregateInfoTool::class);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    public function toolsCall(string $toolName, array $arguments): Content
    {
        return $this->catchCRExceptions(fn() => parent::toolsCall($toolName, $arguments));
    }
}

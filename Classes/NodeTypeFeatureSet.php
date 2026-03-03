<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;
use SJS\Neos\MCP\FeatureSet\CR\NodeTypeFeatureSet\GetNodeTypeTool;
use SJS\Neos\MCP\FeatureSet\CR\NodeTypeFeatureSet\ListNodeTypesTool;

#[Flow\Scope("singleton")]
class NodeTypeFeatureSet extends AbstractFeatureSet
{
    public function initialize(): void
    {
        $this->addTool(ListNodeTypesTool::class);
        $this->addTool(GetNodeTypeTool::class);
    }
}

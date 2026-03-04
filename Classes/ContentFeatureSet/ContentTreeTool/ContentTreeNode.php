<?php

declare(strict_types=1);


namespace SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\ContentTreeTool;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;


#[Flow\Proxy(enabled: false)]
class ContentTreeNode implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $properties
     * @param array<ContentTreeNode> $children
     */
    public function __construct(
        public readonly WorkspaceName $workspaceName,
        public readonly NodeAddress $nodeAddress,
        public readonly NodeName $name,
        public readonly NodeAggregateId $aggregateId,
        public readonly NodeTypeName $nodeTypeName,
        public readonly Timestamps $timestamps,
        public readonly array $properties,
        public readonly array $children,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            "workspaceName" => $this->workspaceName,
            "nodeAddress" => $this->nodeAddress,
            "name" => $this->name,
            "aggregateId" => $this->aggregateId,
            "nodeTypeName" => $this->nodeTypeName,
            "timestamps" => $this->timestamps,
            "properties" => $this->properties,
            "children" => $this->children,
        ];
    }
}
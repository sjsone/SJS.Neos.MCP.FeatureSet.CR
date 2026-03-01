<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR;

use Neos\Flow\Annotations as Flow;
use SJS\Neos\MCP\Domain\MCP\Tool\Content;
use SJS\Neos\MCP\FeatureSet\AbstractFeatureSet;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\AddContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\ContentTreeTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\MoveContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\RemoveContentTool;
use SJS\Neos\MCP\FeatureSet\CR\ContentFeatureSet\UpdateContentTool;
use Neos\ContentRepository\Core\SharedModel\Exception as CRException;

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
    }

    public function toolsCall(string $toolName, array $arguments): mixed
    {
        try {
            return parent::toolsCall($toolName, $arguments);
        } catch (CRException\PropertyCannotBeSet $e) {
            return Content::text($e->getMessage());
        } catch (CRException\PropertyTypeIsInvalid $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeTypeNotFound $e) {
            return Content::text($e->getMessage());
        } catch (CRException\ReferenceCannotBeSet $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeTypeIsOfTypeRoot $e) {
            return Content::text($e->getMessage());
        } catch (CRException\DimensionSpacePointIsNotYetOccupied $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePoint $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeAggregateDoesCurrentlyNotCoverDimensionSpacePointSet $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeAggregateDoesCurrentlyNotOccupyDimensionSpacePoint $e) {
            return Content::text($e->getMessage());
        } catch (CRException\NodeConstraintException $e) {
            return Content::text($e->getMessage());
        }
    }
}

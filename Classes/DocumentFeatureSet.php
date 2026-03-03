<?php

declare(strict_types=1);

namespace SJS\Neos\MCP\FeatureSet\CR;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;
use Neos\ContentRepository\Core\SharedModel\Exception as CRException;
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

    public function toolsCall(string $toolName, array $arguments): Content
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

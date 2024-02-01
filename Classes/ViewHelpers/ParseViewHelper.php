<?php

declare(strict_types=1);

namespace Gaya\SolrStructuredRelation\ViewHelpers;

use Gaya\SolrStructuredRelation\Utility\SolrBinaryUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

final class ParseViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    public function initializeArguments()
    {
        $this->registerArgument('value', 'string', 'base64 string encoded from a SOLR_STRUCTURED_RELATION cObject during Solr indexing');
        $this->registerArgument('multiValue', 'bool', 'Indicates if the value comes from Solr multivalued field', false, true);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $value = $renderChildrenClosure();
        if (empty($value)) {
            return $arguments['multiValue'] ? [] : '';
        }

        if ($arguments['multiValue']) {
            $result = array_map([ SolrBinaryUtility::class, 'decode'], $value);
        } else {
            $result = SolrBinaryUtility::decode($value);
        }

        return $result;
    }
}

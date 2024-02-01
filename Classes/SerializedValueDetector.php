<?php

declare(strict_types=1);

namespace Gaya\SolrStructuredRelation;

use ApacheSolrForTypo3\Solr\IndexQueue\SerializedValueDetector as SerializedValueDetectorInterface;

/**
 * @internal
 */
final class SerializedValueDetector implements SerializedValueDetectorInterface
{
    public function isSerializedValue(array $indexingConfiguration, string $solrFieldName): bool
    {
        return $indexingConfiguration[$solrFieldName] === 'SOLR_STRUCTURED_RELATION'
            && ($indexingConfiguration[$solrFieldName . '.']['multiValue'] ?? '') === '1';
    }
}

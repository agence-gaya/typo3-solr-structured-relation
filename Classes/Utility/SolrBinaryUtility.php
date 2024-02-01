<?php

declare(strict_types=1);

namespace Gaya\SolrStructuredRelation\Utility;

final class SolrBinaryUtility
{
    public static function encode(array $value): string
    {
        return base64_encode(json_encode($value, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
    }

    public static function decode(string $value): array
    {
        $value = base64_decode($value, true);
        if ($value === false) {
            throw new \InvalidArgumentException('Value must be a valid base64 string encoded from a SOLR_STRUCTURED_RELATION cObject during Solr indexing.');
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}

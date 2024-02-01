<?php

declare(strict_types=1);

use Gaya\SolrStructuredRelation\SerializedValueDetector;

if (!isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'])) {
    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'] = [];
}
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'][] = SerializedValueDetector::class;

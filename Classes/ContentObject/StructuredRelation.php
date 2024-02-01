<?php

namespace Gaya\SolrStructuredRelation\ContentObject;

use ApacheSolrForTypo3\Solr\System\Language\FrontendOverlayService;
use ApacheSolrForTypo3\Solr\System\TCA\TCAService;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Result;
use Gaya\SolrStructuredRelation\Utility\SolrBinaryUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\AbstractContentObject;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\Exception\ContentRenderingException;

/**
 * A content object (cObj) to resolve relations between database records and store them in a structured way
 *
 * Configuration options:
 *
 * - localField: the record's field to use to resolve relations
 * - relationTableSortingField: field in a mm relation table to sort by, usually "sorting"
 * - multiValue: whether to return related records suitable for a multi value field
 *
 * @internal
 * @see \ApacheSolrForTypo3\Solr\ContentObject\Relation from which this code is heavily inspired.
 */
final class StructuredRelation extends AbstractContentObject
{
    /**
     * Content object configuration
     */
    protected array $configuration = [];

    protected ?FrontendOverlayService $frontendOverlayService = null;

    public function __construct(
        protected readonly TCAService $tcaService
    ) {}

    /**
     * Executes the SOLR_STRUCTURED_RELATION content object.
     *
     * Resolves relations between records. Currently, supported relations are
     * TYPO3-style m:n relations.
     * May resolve single value and multi value relations.
     *
     * @noinspection PhpMissingReturnTypeInspection
     * @throws \Exception
     */
    public function render($conf = [])
    {
        $this->configuration = array_merge($this->configuration, $conf);

        $relatedItems = $this->getRelatedItems($this->cObj);

        if (empty($conf['multiValue'] ?? '')) {
            if (count($relatedItems) > 1) {
                throw new \Exception('Cannot glue multiple items in one single value. Consider using a multivalue field');
            }
            if (count($relatedItems) === 0) {
                return '';
            }
            $relatedItems = $this->applyFieldsConfiguration($relatedItems);
            $result = SolrBinaryUtility::encode($relatedItems[0]);
        } else {
            $relatedItems = $this->applyFieldsConfiguration($relatedItems);
            $result = array_map([
                SolrBinaryUtility::class,
                'encode',
            ], $relatedItems);
            // multi value, need to serialize as content objects must return strings
            // @see \Gaya\SolrStructuredRelation\SerializedValueDetector
            $result = serialize($result);
        }

        return $result;
    }

    /**
     * Gets the related items of the current record's configured field.
     *
     * @param ContentObjectRenderer $parentContentObject parent content object
     *
     * @return array Array of related items, values already resolved from related records
     *
     * @throws ContentRenderingException
     * @throws DBALException
     */
    protected function getRelatedItems(ContentObjectRenderer $parentContentObject): array
    {
        [
            $table,
            $uid
        ] = explode(':', $parentContentObject->currentRecord);
        $uid = (int)$uid;
        $field = $this->configuration['localField'];

        if (!$this->tcaService->getHasConfigurationForField($table, $field)) {
            return [];
        }

        $overlayUid = $this->getFrontendOverlayService()->getUidOfOverlay($table, $field, $uid);
        $fieldTCA = $this->tcaService->getConfigurationForField($table, $field);

        if (isset($fieldTCA['config']['MM']) && trim($fieldTCA['config']['MM']) !== '') {
            $relatedItems = $this->getRelatedItemsFromMMTable($table, $overlayUid, $fieldTCA);
        } else {
            $relatedItems = $this->getRelatedItemsFromForeignTable($table, $overlayUid, $fieldTCA, $parentContentObject);
        }

        return $relatedItems;
    }

    /**
     * Gets the related items from a table using the n:m relation.
     *
     * @param string $localTableName Local table name
     * @param int $localRecordUid Local record uid
     * @param array $localFieldTca The local table's TCA
     *
     * @return array Array of related items, values already resolved from related records
     *
     * @throws ContentRenderingException
     * @throws DBALException
     */
    protected function getRelatedItemsFromMMTable(string $localTableName, int $localRecordUid, array $localFieldTca): array
    {
        $relatedItems = [];
        $foreignTableName = $localFieldTca['config']['foreign_table'];
        $foreignTableTca = $this->tcaService->getTableConfiguration($foreignTableName);
        $mmTableName = $localFieldTca['config']['MM'];

        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->start('', $foreignTableName, $mmTableName, $localRecordUid, $localTableName, $localFieldTca['config']);
        $selectUids = $relationHandler->tableArray[$foreignTableName];
        if (!is_array($selectUids) || count($selectUids) <= 0) {
            return $relatedItems;
        }

        $relatedRecords = $this->getRelatedRecords($foreignTableName, ...$selectUids);
        foreach ($relatedRecords as $record) {
            if ($this->getLanguageUid() > 0) {
                $record = $this->getFrontendOverlayService()->getOverlay($foreignTableName, $record);
            }

            $relatedItems[] = $record;
        }

        return $relatedItems;
    }

    /**
     * Gets the related items from a table using a 1:n relation.
     *
     * @param string $localTableName Local table name
     * @param int $localRecordUid Local record uid
     * @param array $localFieldTca The local table's TCA
     * @param ContentObjectRenderer $parentContentObject parent content object
     *
     * @return array Array of related items, values already resolved from related records
     *
     * @throws ContentRenderingException
     * @throws DBALException
     */
    protected function getRelatedItemsFromForeignTable(
        string $localTableName,
        int $localRecordUid,
        array $localFieldTca,
        ContentObjectRenderer $parentContentObject
    ): array {
        $relatedItems = [];
        $foreignTableName = $localFieldTca['config']['foreign_table'];
        $foreignTableTca = $this->tcaService->getTableConfiguration($foreignTableName);
        $localField = $this->configuration['localField'];

        /** @var RelationHandler $relationHandler */
        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        if (!empty($localFieldTca['config']['MM'] ?? '')) {
            $relationHandler->start(
                '',
                $foreignTableName,
                $localFieldTca['config']['MM'],
                $localRecordUid,
                $localTableName,
                $localFieldTca['config']
            );
        } else {
            $itemList = $parentContentObject->data[$localField] ?? '';
            $relationHandler->start($itemList, $foreignTableName, '', $localRecordUid, $localTableName, $localFieldTca['config']);
        }

        $selectUids = $relationHandler->tableArray[$foreignTableName];
        if (!is_array($selectUids) || count($selectUids) <= 0) {
            return $relatedItems;
        }

        $relatedRecords = $this->getRelatedRecords($foreignTableName, ...$selectUids);
        foreach ($relatedRecords as $relatedRecord) {
            if ($this->getLanguageUid() > 0) {
                $relatedRecord = $this->getFrontendOverlayService()->getOverlay($foreignTableName, $relatedRecord);
            }

            $relatedItems[] = $relatedRecord;
        }

        return $relatedItems;
    }

    /**
     * Return records via relation.
     *
     * @param string $foreignTable The table to fetch records from.
     * @param int ...$uids The uids to fetch from table.
     *
     * @throws DBALException
     */
    protected function getRelatedRecords(string $foreignTable, int ...$uids): array
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($foreignTable);
        $queryBuilder->select('*')
            ->from($foreignTable)
            ->where($queryBuilder->expr()->in('uid', $uids));
        if (isset($this->configuration['additionalWhereClause'])) {
            $queryBuilder->andWhere($this->configuration['additionalWhereClause']);
        }
        $queryResult = $queryBuilder->executeQuery();

        return $this->sortByKeyInIN($queryResult, 'uid', ...$uids);
    }

    /**
     * Sorts the result set by key in array for IN values.
     *   Simulates MySqls ORDER BY FIELD(fieldname, COPY_OF_IN_FOR_WHERE)
     *   Example: SELECT * FROM a_table WHERE field_name IN (2, 3, 4) SORT BY FIELD(field_name, 2, 3, 4)
     *
     * @throws DBALException
     */
    protected function sortByKeyInIN(Result $statement, string $columnName, ...$arrayWithValuesForIN): array
    {
        $records = [];
        while ($record = $statement->fetchAssociative()) {
            $indexNumber = array_search($record[$columnName], $arrayWithValuesForIN);
            $records[$indexNumber] = $record;
        }
        ksort($records);

        return $records;
    }

    /**
     * Returns current language id fetched from the SiteLanguage
     *
     * @throws ContentRenderingException
     */
    protected function getLanguageUid(): int
    {
        return $this->getTypoScriptFrontendController()->getLanguage()->getLanguageId();
    }

    /**
     * Returns and sets FrontendOverlayService instance to this object.
     *
     * @throws ContentRenderingException
     */
    protected function getFrontendOverlayService(): FrontendOverlayService
    {
        if ($this->frontendOverlayService !== null) {
            return $this->frontendOverlayService;
        }

        return $this->frontendOverlayService = GeneralUtility::makeInstance(
            FrontendOverlayService::class,
            $this->tcaService,
            $this->getTypoScriptFrontendController()
        );
    }

    protected function applyFieldsConfiguration(array $relatedItems): array
    {
        // Select only the configured fields to return
        $fields = GeneralUtility::trimExplode(',', $this->configuration['fields'] ?? '', true);
        if ($fields !== []) {
            $fields = array_flip($fields);

            return array_map(static function ($row) use ($fields) {
                return array_intersect_key($row, $fields);
            }, $relatedItems);
        }

        // Or filter out "system" fields
        $systemFields = array_flip([
            'crdate',
            'deleted',
            'hidden',
            'l10n_diffsource',
            'l10n_parent',
            'l10n_source',
            'l10n_state',
            'pid',
            'sys_language_uid',
            't3ver_oid',
            't3ver_stage',
            't3ver_state',
            't3ver_wsid',
            'tstamp',
        ]);

        return array_map(static function ($row) use ($systemFields) {
            return array_diff_key($row, $systemFields);
        }, $relatedItems);
    }
}

<?php declare(strict_types=1);

namespace Access\Job;

use Access\Api\Representation\AccessStatusRepresentation;
use Omeka\Job\AbstractJob;

class AccessStatusUpdate extends AbstractJob
{
    use AccessPropertiesTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $modeMissing = 'skip';

    /**
     * @var string
     */
    protected $modeSync = 'skip';

    /**
     * @var array
     */
    protected $recursiveProcesses = [];

    /**
     * @var int
     */
    protected $totalSucceed;

    /**
     * @var int
     */
    protected $totalFailed;

    /**
     * @var int
     */
    protected $totalSkipped;

    /**
     * @var int
     */
    protected $totalMedias;

    /**
     * @var int
     */
    protected $totalProcessed;

    /**
     * @var int
     */
    protected $totalToProcess;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('access/index_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->entityManager = $services->get('Omeka\EntityManager');
        // These two connections are not the same.
        $this->connection = $services->get('Omeka\Connection');
        // $this->connection = $this->entityManager->getConnection();
        $this->api = $services->get('Omeka\ApiManager');

        $settings = $services->get('Omeka\Settings');

        $modeSyncs = [
            'skip',
            'from_properties_to_accesses',
            'from_accesses_to_properties',
        ];
        $this->modeSync = $this->getArg('sync', 'skip') ?: 'skip';
        if (!in_array($this->modeSync, $modeSyncs)) {
            $this->logger->err(
                'Sync mode {mode} is invalid.', // @translate
                ['mode' => $this->modeSync]
            );
            return;
        }

        $modeMissings = [
            'skip',
            'free',
            'reserved',
            'protected',
            'forbidden',
            'visibility_reserved',
            'visibility_protected',
            'visibility_forbidden',
        ];
        $this->modeMissing = $this->getArg('missing', 'skip') ?: 'skip';
        if (!in_array($this->modeMissing, $modeMissings)) {
            $this->logger->err(
                'Missing mode {mode} is invalid.', // @translate
                ['mode' => $this->modeMissing]
            );
            return;
        }

        $this->recursiveProcesses = $this->getArg('recursive', []) ?: [];
        $recursives = [
            'from_item_sets_to_items_and_media',
            'from_items_to_media',
        ];
        if ($this->recursiveProcesses && count($this->recursiveProcesses) !== count(array_intersect($this->recursiveProcesses, $recursives))) {
            $this->logger->err(
                'These recursive processes are unknown: {names}.', // @translate
                ['names' => implode(', ', array_diff($this->recursiveProcesses, $recursives))]
            );
            return;
        }

        if ($this->modeSync === 'skip'
            && $this->modeMissing === 'skip'
            && $this->recursiveProcesses === []
        ) {
            $this->logger->warn(
                'Synchronization and missing modes are set as "skip" and no recursive processes are set.' // @translate
            );
            return;
        }

        $this->accessViaProperty = (bool) $settings->get('access_property');
        if (!$this->accessViaProperty && $this->modeSync !== 'skip') {
            $this->logger->warn(
                'Synchronization of property values and index is set, but the config for access mode does not use properties.' // @translate
            );
        }

        if ($this->accessViaProperty || $this->modeSync !== 'skip') {
            $result = $this->prepareProperties(true);
            if (!$result) {
                return;
            }
        }

        $this->logger->info(
            'Starting indexation of access statuses of all resources.' // @translate
        );

        // Warning: this is not a full sync: only existing properties and
        // indexes are updated.

        if (in_array('from_item_sets_to_items_and_media', $this->recursiveProcesses)) {
            $ids = $this->api->search('item_sets', [], ['returnScalar' => 'id'])->getContent();
            if ($ids) {
                $args = $this->job->getArgs();
                $args['resource_ids'] = $ids;
                $this->job->setArgs($args);
                $subJob = new AccessStatusRecursive($this->job, $services);
                $subJob->perform();
            }
        }

        // There may be items without item sets.
        if (in_array('from_items_to_media', $this->recursiveProcesses)) {
            $ids = $this->api->search('items', [], ['returnScalar' => 'id'])->getContent();
            if ($ids) {
                $args = $this->job->getArgs();
                $args['resource_ids'] = $ids;
                $this->job->setArgs($args);
                $subJob = new AccessStatusRecursive($this->job, $services);
                $subJob->perform();
            }
        }

        if ($this->modeSync === 'from_properties_to_accesses') {
            $this->updateLevelAndEmbargoViaProperty();
        } elseif ($this->modeSync === 'from_accesses_to_properties') {
            // This job can be skipped if the missing mode is not skip, but it
            // is simpler to understand. It's just a quick sql anyway.
            $this->copyIndexIntoPropertyValues();
        }

        if ($this->modeMissing !== 'skip') {
            $this->addMissingLevelViaVisibility();
            // Update properties when the config use them.
            if ($this->accessViaProperty) {
                $this->copyIndexIntoPropertyValues();
            }
        }

        $this->logger->info(
            'End of indexation.' // @translate
        );
    }

    protected function copyIndexIntoPropertyValues(): self
    {
        $quotedList = [];
        foreach ($this->accessLevels as $key => $value) {
            $quotedList[$key] = $this->connection->quote($value);
        }

        $intType = \Doctrine\DBAL\ParameterType::INTEGER;
        $strType = \Doctrine\DBAL\ParameterType::STRING;
        $embargoStartType = $this->hasNumericDataTypes
            ? 'numeric:timestamp' : 'literal';
        $embargoEndType = $this->hasNumericDataTypes
            ? 'numeric:timestamp' : 'literal';

        // Each statement is executed separately: PDO emulated prepares does not
        // reliably bind named params across multiple statements in one call.

        $this->connection->transactional(function () use ($quotedList, $intType, $strType, $embargoStartType, $embargoEndType) {
            // Remove all level and embargo values.
            $this->connection->executeStatement(
                <<<'SQL'
                DELETE `value`
                FROM `value`
                JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
                JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
                WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                SQL,
                [
                    'property_level' => $this->propertyLevelId,
                    'property_embargo_start' => $this->propertyEmbargoStartId,
                    'property_embargo_end' => $this->propertyEmbargoEndId,
                ],
                [
                    'property_level' => $intType,
                    'property_embargo_start' => $intType,
                    'property_embargo_end' => $intType,
                ]
            );

            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    DELETE `numeric_data_types_timestamp`
                    FROM `numeric_data_types_timestamp`
                    JOIN `resource` ON `resource`.`id` = `numeric_data_types_timestamp`.`resource_id`
                    JOIN `access_status` ON `access_status`.`id` = `numeric_data_types_timestamp`.`resource_id`
                    WHERE `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                    SQL,
                    [
                        'property_embargo_start' => $this->propertyEmbargoStartId,
                        'property_embargo_end' => $this->propertyEmbargoEndId,
                    ],
                    [
                        'property_embargo_start' => $intType,
                        'property_embargo_end' => $intType,
                    ]
                );
            }

            // Set all levels.
            $this->connection->executeStatement(
                <<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT
                    `access_status`.`id`,
                    :property_level,
                    :level_type,
                    CASE `access_status`.`level`
                        WHEN "free" THEN {$quotedList['free']}
                        WHEN "reserved" THEN {$quotedList['reserved']}
                        WHEN "protected" THEN {$quotedList['protected']}
                        WHEN "forbidden" THEN {$quotedList['forbidden']}
                        ELSE {$quotedList['free']}
                    END,
                    1
                FROM `access_status`
                SQL,
                [
                    'property_level' => $this->propertyLevelId,
                    'level_type' => $this->levelDataType,
                ],
                [
                    'property_level' => $intType,
                    'level_type' => $strType,
                ]
            );

            // Set embargo start.
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT
                    `access_status`.`id`,
                    :property_embargo_start,
                    :embargo_start_type,
                    IF(TIME(`access_status`.`embargo_start`) = '00:00:00',
                        CAST(DATE(`access_status`.`embargo_start`) AS CHAR),
                        CAST(`access_status`.`embargo_start` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`embargo_start` IS NOT NULL
                SQL,
                [
                    'property_embargo_start' => $this->propertyEmbargoStartId,
                    'embargo_start_type' => $embargoStartType,
                ],
                [
                    'property_embargo_start' => $intType,
                    'embargo_start_type' => $strType,
                ]
            );

            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `access_status`.`id`, :property_embargo_start, UNIX_TIMESTAMP(`access_status`.`embargo_start`)
                    FROM `access_status`
                    WHERE `access_status`.`embargo_start` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`embargo_start`) IS NOT NULL
                    SQL,
                    ['property_embargo_start' => $this->propertyEmbargoStartId],
                    ['property_embargo_start' => $intType]
                );
            }

            // Set embargo end.
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT
                    `access_status`.`id`,
                    :property_embargo_end,
                    :embargo_end_type,
                    IF(TIME(`access_status`.`embargo_end`) = '00:00:00',
                        CAST(DATE(`access_status`.`embargo_end`) AS CHAR),
                        CAST(`access_status`.`embargo_end` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`embargo_end` IS NOT NULL
                SQL,
                [
                    'property_embargo_end' => $this->propertyEmbargoEndId,
                    'embargo_end_type' => $embargoEndType,
                ],
                [
                    'property_embargo_end' => $intType,
                    'embargo_end_type' => $strType,
                ]
            );

            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `access_status`.`id`, :property_embargo_end, UNIX_TIMESTAMP(`access_status`.`embargo_end`)
                    FROM `access_status`
                    WHERE `access_status`.`embargo_end` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`embargo_end`) IS NOT NULL
                    SQL,
                    ['property_embargo_end' => $this->propertyEmbargoEndId],
                    ['property_embargo_end' => $intType]
                );
            }

            // Strip trailing "-00-00", "-00" from partial dates produced by
            // DATETIME columns (e.g. "2070-00-00" => "2070", "2026-09-00" => "2026-09").
            $this->connection->executeStatement(
                <<<'SQL'
                UPDATE `value`
                SET `value` = LEFT(`value`, LENGTH(`value`) - 6)
                WHERE `property_id` IN (:property_embargo_start, :property_embargo_end)
                    AND `value` LIKE '%-00-00'
                SQL,
                [
                    'property_embargo_start' => $this->propertyEmbargoStartId,
                    'property_embargo_end' => $this->propertyEmbargoEndId,
                ],
                [
                    'property_embargo_start' => $intType,
                    'property_embargo_end' => $intType,
                ]
            );
            $this->connection->executeStatement(
                <<<'SQL'
                UPDATE `value`
                SET `value` = LEFT(`value`, LENGTH(`value`) - 3)
                WHERE `property_id` IN (:property_embargo_start, :property_embargo_end)
                    AND `value` LIKE '%-00'
                SQL,
                [
                    'property_embargo_start' => $this->propertyEmbargoStartId,
                    'property_embargo_end' => $this->propertyEmbargoEndId,
                ],
                [
                    'property_embargo_start' => $intType,
                    'property_embargo_end' => $intType,
                ]
            );
        });

        return $this;
    }

    protected function addMissingLevelViaVisibility(): self
    {
        if (in_array($this->modeMissing, AccessStatusRepresentation::LEVELS)) {
            $sql = <<<SQL
                # Set the specified status for all missing resources.
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `id`, "{$this->modeMissing}", NULL, NULL
                FROM `resource`
                ON DUPLICATE KEY UPDATE
                   `id` = `resource`.`id`
                ;
                SQL;
        } else {
            $mode = substr($this->modeMissing, 11);
            $sql = <<<SQL
                # Set free all missing public resources.
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `id`, "free", NULL, NULL
                FROM `resource`
                WHERE `is_public` = 1
                ON DUPLICATE KEY UPDATE
                    `id` = `resource`.`id`
                ;
                # Set reserved/protected/forbidden all missing private resources.
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `id`, "$mode", NULL, NULL
                FROM `resource`
                WHERE `is_public` = 0
                ON DUPLICATE KEY UPDATE
                    `id` = `resource`.`id`
                ;
                SQL;
        }

        $this->connection->transactional(function (\Doctrine\DBAL\Connection $connection) use ($sql) {
            $this->connection->executeStatement($sql);
        });

        return $this;
    }

    protected function updateLevelAndEmbargoViaProperty(): self
    {
        $quotedList = [];
        foreach ($this->accessLevels as $key => $value) {
            $quotedList[$key] = $this->connection->quote($value);
        }
        $quotedListString = implode(', ', $quotedList);

        // Insert missing access according to property values.
        if (in_array($this->modeMissing, AccessStatusRepresentation::LEVELS)) {
            $subSql = "'$this->modeMissing'";
        } else {
            $mode = substr($this->modeMissing, 11);
            $subSql = "IF(`resource`.`is_public` = 1, 'free', '$mode')";
        }

        // TODO %T espace?
        // Use insert: statuses were created previously, but there may be levels
        // or embargos without levels, in which case the default level is used.

        // The process check for dates like "0000", that is an error, or partial
        // dates, that are completed.

        // Use LENGTH to determine the date format instead of STR_TO_DATE which
        // matches too eagerly on partial dates.
        $sql = <<<SQL
            # Set access statuses according to values.
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT
                `resource`.`id`,
                (CASE `value`.`value`
                    WHEN {$quotedList['free']} THEN "free"
                    WHEN {$quotedList['reserved']} THEN "reserved"
                    WHEN {$quotedList['protected']} THEN "protected"
                    WHEN {$quotedList['forbidden']} THEN "forbidden"
                    ELSE $subSql
                END),
                NULL,
                NULL
            FROM `resource`
            JOIN `value`
                ON `value`.`resource_id` = `resource`.`id`
                AND `value`.`property_id` = $this->propertyLevelId
                AND `value`.`value` IN ($quotedListString)
            ON DUPLICATE KEY UPDATE
                `level` = (CASE `value`.`value`
                    WHEN {$quotedList['free']} THEN "free"
                    WHEN {$quotedList['reserved']} THEN "reserved"
                    WHEN {$quotedList['protected']} THEN "protected"
                    WHEN {$quotedList['forbidden']} THEN "forbidden"
                    ELSE $subSql
                END)
            ;
            # Set access embargo start according to values.
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT
                `resource_id`,
                $subSql,
                CASE
                    WHEN `value`.`value` IS NULL THEN NULL
                    WHEN LENGTH(TRIM(REPLACE(REPLACE(REPLACE(`value`.`value`, '0', ''), '-', ''), ':', ''))) = 0 THEN NULL
                    WHEN LENGTH(`value`.`value`) > 10 THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, ' 00:00:00')
                    WHEN LENGTH(`value`.`value`) = 7 THEN CONCAT(`value`.`value`, '-01 00:00:00')
                    WHEN LENGTH(`value`.`value`) <= 4 THEN CONCAT(`value`.`value`, '-01-01 00:00:00')
                    ELSE NULL
                END,
                NULL
            FROM `resource`
            JOIN `value`
                ON `value`.`resource_id` = `resource`.`id`
                AND `value`.`property_id` = $this->propertyEmbargoStartId
            ON DUPLICATE KEY UPDATE
                `embargo_start` = CASE
                    WHEN `value`.`value` IS NULL THEN NULL
                    WHEN LENGTH(TRIM(REPLACE(REPLACE(REPLACE(`value`.`value`, '0', ''), '-', ''), ':', ''))) = 0 THEN NULL
                    WHEN LENGTH(`value`.`value`) > 10 THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, ' 00:00:00')
                    WHEN LENGTH(`value`.`value`) = 7 THEN CONCAT(`value`.`value`, '-01 00:00:00')
                    WHEN LENGTH(`value`.`value`) <= 4 THEN CONCAT(`value`.`value`, '-01-01 00:00:00')
                    ELSE NULL
                END
            ;
            # Set access embargo end according to values.
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT
                `resource_id`,
                $subSql,
                NULL,
                CASE
                    WHEN `value`.`value` IS NULL THEN NULL
                    WHEN LENGTH(TRIM(REPLACE(REPLACE(REPLACE(`value`.`value`, '0', ''), '-', ''), ':', ''))) = 0 THEN NULL
                    WHEN LENGTH(`value`.`value`) > 10 THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, ' 23:59:59')
                    WHEN LENGTH(`value`.`value`) = 7 THEN CONCAT(`value`.`value`, '-01 23:59:59')
                    WHEN LENGTH(`value`.`value`) <= 4 THEN CONCAT(`value`.`value`, '-12-31 23:59:59')
                    ELSE NULL
                END
            FROM `resource`
            JOIN `value`
                ON `value`.`resource_id` = `resource`.`id`
                AND `value`.`property_id` = $this->propertyEmbargoEndId
            ON DUPLICATE KEY UPDATE
                `embargo_end` = CASE
                    WHEN `value`.`value` IS NULL THEN NULL
                    WHEN LENGTH(TRIM(REPLACE(REPLACE(REPLACE(`value`.`value`, '0', ''), '-', ''), ':', ''))) = 0 THEN NULL
                    WHEN LENGTH(`value`.`value`) > 10 THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, ' 23:59:59')
                    WHEN LENGTH(`value`.`value`) = 7 THEN CONCAT(`value`.`value`, '-01 23:59:59')
                    WHEN LENGTH(`value`.`value`) <= 4 THEN CONCAT(`value`.`value`, '-12-31 23:59:59')
                    ELSE NULL
                END
            ;
            SQL;

        $this->connection->transactional(function (\Doctrine\DBAL\Connection $connection) use ($sql) {
            $this->connection->executeStatement($sql);
        });

        return $this;
    }
}

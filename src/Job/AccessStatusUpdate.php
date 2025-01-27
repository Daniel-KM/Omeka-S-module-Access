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
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var string
     */
    protected $missingMode = 'skip';

    /**
     * @var string
     */
    protected $syncMode = 'skip';

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

        $syncModes = [
            'skip',
            'from_properties_to_accesses',
            'from_accesses_to_properties',
        ];
        $this->syncMode = $this->getArg('sync', 'skip');
        if (!in_array($this->missingMode, $syncModes)) {
            $this->logger->err(
                'Sync mode {mode} is invalid.', // @translate
                ['mode' => $this->syncMode]
            );
            return;
        }

        $missingModes = [
            'skip',
            'free',
            'reserved',
            'protected',
            'forbidden',
            'visibility_reserved',
            'visibility_protected',
            'visibility_forbidden',
        ];
        $this->missingMode = $this->getArg('missing', 'skip');
        if (!in_array($this->missingMode, $missingModes)) {
            $this->logger->err(
                'Missing mode {mode} is invalid.', // @translate
                ['mode' => $this->missingMode]
            );
            return;
        }

        $this->recursiveProcesses = $this->getArg('recursive', []);
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

        if ($this->syncMode === 'skip'
            && $this->missingMode === 'skip'
            && $this->recursiveProcesses === []
        ) {
            $this->logger->warn(
                'Synchronization and missing modes are set as "skip" and no recursive processes are set.' // @translate
            );
            return;
        }

        $this->accessViaProperty = (bool) $settings->get('access_property');
        if (!$this->accessViaProperty && $this->syncMode !== 'skip') {
            $this->logger->warn(
                'Synchronization of property values and index is set, but the config for access mode does not use properties.' // @translate
            );
        }

        if ($this->accessViaProperty || $this->syncMode !== 'skip') {
            $result = $this->prepareProperties(true);
            if (!$result) {
                return;
            }
        }

        $this->logger->info(
            'Starting indexation of access statuses of all resources.' // @translate
        );

        // Warning: this is not a full sync: only existing properties and indexes are updated.

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

        if ($this->syncMode === 'from_properties_to_index') {
            $this->updateLevelAndEmbargoViaProperty();
        } elseif ($this->syncMode === 'from_index_to_properties') {
            // This job can be skipped if the missing mode is not skip, but it
            // is simpler to understand. It's just a quick sql anyway.
            $this->copyIndexIntoPropertyValues();
        }

        if ($this->missingMode !== 'skip') {
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

        $bind = [
            'property_level' => $this->propertyLevelId,
            'property_embargo_start' => $this->propertyEmbargoStartId,
            'property_embargo_end' => $this->propertyEmbargoEndId,
            'level_type' => $this->levelDataType,
            'embargo_start_type' => $this->hasNumericDataTypes ? 'numeric:timestamp' : 'literal',
            'embargo_end_type' => $this->hasNumericDataTypes ? 'numeric:timestamp' : 'literal',
        ];
        $types = [
            'property_level' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_start' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_end' => \Doctrine\DBAL\ParameterType::INTEGER,
            'level_type' => \Doctrine\DBAL\ParameterType::STRING,
            'embargo_start_type' => \Doctrine\DBAL\ParameterType::STRING,
            'embargo_end_type' => \Doctrine\DBAL\ParameterType::STRING,
        ];

        $sql = <<<'SQL'
            # Remove all level and embargo values set in statuses.
            DELETE `value`
            FROM `value`
            JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
            JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
            ;
            SQL;

        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<'SQL'
                # Remove all embargo numeric timestamps set in statuses.
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
                JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
                WHERE `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                ;
                SQL;
        }

        $sql .= "\n" . <<<SQL
            # Set all levels set in statuses.
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
            ;
            SQL;

        $sql .= "\n" . <<<'SQL'
            # Set all embargo start timestamp set in statuses.
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT
                `access_status`.`id`,
                :property_embargo_start,
                :embargo_start_type,
                `access_status`.`embargo_start`,
                1
            FROM `access_status`
            WHERE `access_status`.`embargo_start` IS NOT NULL
            ;
            SQL;
        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                SELECT `access_status`.`id`, :property_embargo_start, UNIX_TIMESTAMP(`access_status`.`embargo_start`)
                FROM `access_status`
                WHERE `access_status`.`embargo_start` IS NOT NULL
                ;
                SQL;
        }

        $sql .= "\n" . <<<'SQL'
            # Set all embargo end timestamp set in statuses.
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT
                `access_status`.`id`,
                :property_embargo_end,
                :embargo_end_type,
                `access_status`.`embargo_end`,
                1
            FROM `access_status`
            WHERE `access_status`.`embargo_end` IS NOT NULL
            ;
            SQL;
        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                SELECT `access_status`.`id`, :property_embargo_end, UNIX_TIMESTAMP(`access_status`.`embargo_end`)
                FROM `access_status`
                WHERE `access_status`.`embargo_end` IS NOT NULL
                ;
                SQL;
        }

        $this->connection->executeStatement($sql, $bind, $types);

        return $this;
    }

    protected function addMissingLevelViaVisibility(): self
    {
        if (in_array($this->missingMode, AccessStatusRepresentation::LEVELS)) {
            $sql = <<<SQL
                # Set the specified status for all missing resources.
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `id`, "{$this->missingMode}", NULL, NULL
                FROM `resource`
                ON DUPLICATE KEY UPDATE
                   `id` = `resource`.`id`
                ;
                SQL;
        } else {
            $mode = substr($this->missingMode, 11);
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

        $this->connection->executeStatement($sql);

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
        if (in_array($this->missingMode, AccessStatusRepresentation::LEVELS)) {
            $subSql = "'$this->missingMode'";
        } else {
            $mode = substr($this->missingMode, 11);
            $subSql = "IF(`resource`.`is_public` = 1, 'free', '$mode')";
        }

        // TODO %T espace?
        // Use insert: statuses were created previously, but there may be levels
        // or embargos without levels, in which case the default level is used.

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
                CASE `value`.`value`
                    WHEN NULL THEN NULL
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
                    ELSE NULL
                END,
                NULL
            FROM `resource`
            JOIN `value`
                ON `value`.`resource_id` = `resource`.`id`
                AND `value`.`property_id` = $this->propertyEmbargoStartId
            ON DUPLICATE KEY UPDATE
                `embargo_start` = CASE `value`.`value`
                    WHEN NULL THEN NULL
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
                    ELSE NULL
                END
            ;
            # Set access embargo end according to values.
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT
                `resource_id`,
                $subSql,
                NULL,
                CASE `value`.`value`
                    WHEN NULL THEN NULL
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
                    ELSE NULL
                END
            FROM `resource`
            JOIN `value`
                ON `value`.`resource_id` = `resource`.`id`
                AND `value`.`property_id` = $this->propertyEmbargoEndId
            ON DUPLICATE KEY UPDATE
                `embargo_end` = CASE `value`.`value`
                    WHEN NULL THEN NULL
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
                    WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
                    ELSE NULL
                END
            ;
            SQL;

        $this->connection->executeStatement($sql);

        return $this;
    }
}

<?php declare(strict_types=1);

namespace Access\Job;

use Omeka\Job\AbstractJob;

/**
 * Copy the access statuses into the property values.
 *
 * Used by the upgrade to version 3.4.40 to restore the property values removed
 * by a bug of the daily embargo job. The reverse sync is done by the rebuild.
 *
 * @see \Access\Job\AccessStatusRebuild
 */
class AccessStatusUpdate extends AbstractJob
{
    use AccessPropertiesTrait;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('access/index_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);
        $this->connection = $services->get('Omeka\Connection');

        $this->accessViaProperty = (bool) $services->get('Omeka\Settings')->get('access_property');
        if (!$this->accessViaProperty) {
            $this->logger->warn(
                'The access statuses are not stored as property values, so there is nothing to copy.' // @translate
            );
            return;
        }

        if (!$this->prepareProperties(true)) {
            return;
        }

        $this->copyIndexIntoPropertyValues();

        $this->logger->info(
            'The access statuses were copied into the property values.' // @translate
        );
    }

    protected function copyIndexIntoPropertyValues(): self
    {
        $quotedList = [];
        foreach ($this->accessLevels as $key => $value) {
            $quotedList[$key] = $this->connection->quote($value);
        }

        // The own columns are used since version 3.4.45. Before, during the
        // replay of the upgrade to version 3.4.40, only the effective ones exist.
        $hasSetColumns = in_array('level_set', $this->connection->fetchFirstColumn('SHOW COLUMNS FROM `access_status`'), true);
        [$level, $embargoStart, $embargoEnd] = $hasSetColumns
            ? ['level_set', 'embargo_start_set', 'embargo_end_set']
            : ['level', 'embargo_start', 'embargo_end'];

        $intType = \Doctrine\DBAL\ParameterType::INTEGER;
        $strType = \Doctrine\DBAL\ParameterType::STRING;
        $embargoStartType = $this->hasNumericDataTypes
            ? 'numeric:timestamp' : 'literal';
        $embargoEndType = $this->hasNumericDataTypes
            ? 'numeric:timestamp' : 'literal';

        // Each statement is executed separately: PDO emulated prepares does not
        // reliably bind named params across multiple statements in one call.

        $this->connection->transactional(function () use ($quotedList, $intType, $strType, $embargoStartType, $embargoEndType, $level, $embargoStart, $embargoEnd) {
            // Remove all level and embargo values.
            $this->connection->executeStatement(
                <<<SQL
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
                    <<<SQL
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
                    CASE `access_status`.`$level`
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
                <<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT
                    `access_status`.`id`,
                    :property_embargo_start,
                    :embargo_start_type,
                    IF(TIME(`access_status`.`$embargoStart`) = '00:00:00',
                        CAST(DATE(`access_status`.`$embargoStart`) AS CHAR),
                        CAST(`access_status`.`$embargoStart` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`$embargoStart` IS NOT NULL
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
                    <<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `access_status`.`id`, :property_embargo_start, UNIX_TIMESTAMP(`access_status`.`$embargoStart`)
                    FROM `access_status`
                    WHERE `access_status`.`$embargoStart` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`$embargoStart`) IS NOT NULL
                    SQL,
                    ['property_embargo_start' => $this->propertyEmbargoStartId],
                    ['property_embargo_start' => $intType]
                );
            }

            // Set embargo end.
            $this->connection->executeStatement(
                <<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT
                    `access_status`.`id`,
                    :property_embargo_end,
                    :embargo_end_type,
                    IF(TIME(`access_status`.`$embargoEnd`) = '00:00:00',
                        CAST(DATE(`access_status`.`$embargoEnd`) AS CHAR),
                        CAST(`access_status`.`$embargoEnd` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`$embargoEnd` IS NOT NULL
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
                    <<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `access_status`.`id`, :property_embargo_end, UNIX_TIMESTAMP(`access_status`.`$embargoEnd`)
                    FROM `access_status`
                    WHERE `access_status`.`$embargoEnd` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`$embargoEnd`) IS NOT NULL
                    SQL,
                    ['property_embargo_end' => $this->propertyEmbargoEndId],
                    ['property_embargo_end' => $intType]
                );
            }

            // Strip trailing "-00-00", "-00" from partial dates produced by
            // DATETIME columns (e.g. "2070-00-00" => "2070", "2026-09-00" => "2026-09").
            $this->connection->executeStatement(
                <<<SQL
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
                <<<SQL
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
}

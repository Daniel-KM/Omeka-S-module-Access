<?php declare(strict_types=1);

namespace Access\Job;

use Access\Api\Representation\AccessStatusRepresentation;
use Common\Stdlib\PsrMessage;

trait AccessPropertiesTrait
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $hasNumericDataTypes;

    /**
     * @var bool
     */
    protected $accessViaProperty;

    /**
     * @var string
     */
    protected $propertyLevel;

    /**
     * @var int
     */
    protected $propertyLevelId;

    /**
     * @var string
     */
    protected $propertyEmbargoStart;

    /**
     * @var int
     */
    protected $propertyEmbargoStartId;

    /**
     * @var string
     */
    protected $propertyEmbargoEnd;

    /**
     * @var int
     */
    protected $propertyEmbargoEndId;

    /**
     * For mode property / level, list the possible levels.
     *
     * @var array
     */
    protected $accessLevels;

    /**
     * @var string
     */
    protected $levelDataType;

    protected function prepareProperties(bool $useLogger = false): bool
    {
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        if (!$useLogger) {
            /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
        }

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('NumericDataTypes');
        $this->hasNumericDataTypes = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        $this->accessViaProperty = (bool) $settings->get('access_property');

        $this->propertyLevel = $settings->get('access_property_level');
        $this->propertyEmbargoStart = $settings->get('access_property_embargo_start');
        $this->propertyEmbargoEnd = $settings->get('access_property_embargo_end');
        $this->accessLevels = $settings->get('access_property_levels', []);
        $this->levelDataType = $settings->get('access_property_level_datatype');

        $hasError = false;

        if ($this->propertyLevel) {
            $this->propertyLevelId = $this->api->search('properties', ['term' => $this->propertyLevel], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyLevelId) {
                $this->propertyLevelId = (int) reset($this->propertyLevelId);
            } else {
                $hasError = true;
                $message = new PsrMessage(
                    'Property {property} for level does not exist.', // @translate
                    ['property' => $this->propertyLevel]
                );
                $useLogger ? $this->logger->err($message->getMessage(), $message->getContext()) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new PsrMessage(
                'Property to set access level is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message->getMessage()) : $messenger->addError($message);
        }

        if ($this->propertyEmbargoStart) {
            $this->propertyEmbargoStartId = $this->api->search('properties', ['term' => $this->propertyEmbargoStart], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyEmbargoStartId) {
                $this->propertyEmbargoStartId = (int) reset($this->propertyEmbargoStartId);
            } else {
                $hasError = true;
                $message = new PsrMessage(
                    'Property {property} for embargo start does not exist.', // @translate
                    ['property' => $this->propertyEmbargoStart]
                );
                $useLogger ? $this->logger->err($message->getMessage(), $message->getContext()) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new PsrMessage(
                'Property to set embargo start is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message->getMessage()) : $messenger->addError($message);
        }

        if ($this->propertyEmbargoEnd) {
            $this->propertyEmbargoEndId = $this->api->search('properties', ['term' => $this->propertyEmbargoEnd], ['returnScalar' => 'id'])->getContent();
            if ($this->propertyEmbargoEndId) {
                $this->propertyEmbargoEndId = (int) reset($this->propertyEmbargoEndId);
            } else {
                $hasError = true;
                $message = new PsrMessage(
                    'Property {property} for embargo end does not exist.', // @translate
                    ['property' => $this->propertyEmbargoEnd]
                );
                $useLogger ? $this->logger->err($message->getMessage(), $message->getContext()) : $messenger->addError($message);
            }
        } else {
            $hasError = true;
            $message = new PsrMessage(
                'Property to set embargo end is not defined.' // @translate
            );
            $useLogger ? $this->logger->err($message->getMessage()) : $messenger->addError($message);
        }

        // This is not an error since default levels may be used, but this is a
        // sensitive job because it modifies values, so stop it.
        if (count(array_intersect_key($this->accessLevels, AccessStatusRepresentation::LEVELS)) !== 4) {
            $hasError = true;
            $message = new PsrMessage(
                'List of property levels is incomplete, missing {strings}.', // @translate
                ['strings' => implode(', ', array_diff_key(AccessStatusRepresentation::LEVELS, $this->accessLevels))]
            );
            $useLogger ? $this->logger->err($message->getMessage(), $message->getContext()) : $messenger->addError($message);
        } else {
            $this->accessLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $this->accessLevels), AccessStatusRepresentation::LEVELS);
        }

        if (!$this->levelDataType) {
            try {
                // Try to search a specific type for level in existing values.
                // TODO Improve query to get first value that is not literal.
                $anyLevel = $this->api->read('values', ['property' => $this->propertyLevelId], [], ['responseContent' => 'resource'])->getContent();
                $this->levelDataType = $anyLevel->getType();
            } catch (\Throwable $e) {
                $this->levelDataType = 'literal';
            }
            $message = new PsrMessage(
                'The data type for property level is not set, so {data_type} will be used by default when it is not set in metadata.', // @translate
                ['data_type' => $this->levelDataType]
            );
            $useLogger ? $this->logger->warn($message->getMessage(), $message->getContext()) : $messenger->addWarning($message);
        }

        // Don't repeat messages.
        $this->accessViaProperty = $this->accessViaProperty
            && $this->propertyLevelId
            && $this->propertyEmbargoStartId
            && $this->propertyEmbargoEndId;

        return !$hasError;
    }

    /**
     * Copy the own access statuses into the property values.
     */
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
                    CASE `access_status`.`level_set`
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
                    IF(TIME(`access_status`.`embargo_start_set`) = '00:00:00',
                        CAST(DATE(`access_status`.`embargo_start_set`) AS CHAR),
                        CAST(`access_status`.`embargo_start_set` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`embargo_start_set` IS NOT NULL
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
                    SELECT `access_status`.`id`, :property_embargo_start, UNIX_TIMESTAMP(`access_status`.`embargo_start_set`)
                    FROM `access_status`
                    WHERE `access_status`.`embargo_start_set` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`embargo_start_set`) IS NOT NULL
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
                    IF(TIME(`access_status`.`embargo_end_set`) = '00:00:00',
                        CAST(DATE(`access_status`.`embargo_end_set`) AS CHAR),
                        CAST(`access_status`.`embargo_end_set` AS CHAR)),
                    1
                FROM `access_status`
                WHERE `access_status`.`embargo_end_set` IS NOT NULL
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
                    SELECT `access_status`.`id`, :property_embargo_end, UNIX_TIMESTAMP(`access_status`.`embargo_end_set`)
                    FROM `access_status`
                    WHERE `access_status`.`embargo_end_set` IS NOT NULL
                        AND UNIX_TIMESTAMP(`access_status`.`embargo_end_set`) IS NOT NULL
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

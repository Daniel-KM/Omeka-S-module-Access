<?php declare(strict_types=1);

namespace Access\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class AccessStatusRecursive extends AbstractJob
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
     * @var \Access\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatusForResource;

    /**
     * @var bool
     */
    protected $isAdminRole;

    /**
     * @var int
     */
    protected $userId;

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

        $plugins = $services->get('ControllerPluginManager');
        $this->accessStatusForResource = $plugins->get('accessStatus');

        if ($this->accessViaProperty) {
            $result = $this->prepareProperties(true);
            if (!$result) {
                return;
            }
        }

        $resourceId = (int) $this->getArg('resource_id');
        $resourceIds = array_filter(array_map('intval', $this->getArg('resource_ids', [])));
        if (!$resourceId && !$resourceIds) {
            $this->logger->warn(
                'No resource to process.' // @translate
            );
            return;
        }

        $ids = $resourceId && $resourceIds
            ? array_merge([$resourceId] + $resourceIds)
            : ($resourceId ? [$resourceId] : $resourceIds);

        // People who can view all have all rights to update statuses.
        $owner = $this->job->getOwner();
        $this->userId = $owner ? $owner->getId() : null;
        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $services->get('Omeka\Acl');
        $this->isAdminRole = $this->userId && $acl->isAdminRole($owner->getRole());

        $accessStatusValues = $this->getArg('values', []);

        // TODO Use dql/orm to update status of items and media of an item set?
        foreach ($ids as $id) {
            $resourceBindTypes = $this->prepareBind($id, $accessStatusValues);
            if ($resourceBindTypes === null) {
                continue;
            }
            [$resource, $bind, $types] = array_values($resourceBindTypes);
            // Resource name is already checked.
            $resourceName = $resource->getResourceName();
            $resourceName === 'items'
                ? $this->processUpdateItem($bind, $types)
                : $this->processUpdateItemSet($bind, $types);
        }

        $this->logger->info(
            'End of indexation of access statuses in properties.' // @translate
        );
    }

    protected function prepareBind(int $resourceId, array $accessStatusValues): ?array
    {
        /** @var \Omeka\Entity\Resource $resource */
        $resource = $this->entityManager->find(\Omeka\Entity\Resource::class, $resourceId);
        if (!$resource) {
            $this->logger->warn(
                'No resource "{resource_id}" to process.', // @translate
                ['resource_id' => $resourceId]
            );
            return null;
        }

        $resourceName = $resource->getResourceName();
        if (!in_array($resourceName, ['items', 'item_sets'])) {
            $this->logger->warn(new Message(
                'Resource #%d is not an item or an item set.', // @translate
                $resourceId
            ));
            return null;
        }

        /** @var \Access\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->accessStatusForResource->__invoke($resource);
        if (!$accessStatus) {
            $this->logger->warn(new Message(
                'No access status for resource #%d.', // @translate
                $resourceId
            ));
            return null;
        }

        $level = $accessStatus->getLevel();
        $embargoStart = $accessStatus->getEmbargoStart();
        $embargoEnd = $accessStatus->getEmbargoEnd();
        $embargoStartStatus = $embargoStart ? $embargoStart->format('Y-m-d H:i:s') : null;
        $embargoEndStatus = $embargoEnd ? $embargoEnd->format('Y-m-d H:i:s') : null;

        $bind = [
            'resource_id' => $resourceId,
            'level' => $level,
            'embargo_start' => $embargoStartStatus,
            'embargo_end' => $embargoEndStatus,
        ];
        $types = [
            'resource_id' => $resourceId,
            'level' => \Doctrine\DBAL\ParameterType::STRING,
            'embargo_start' => $embargoStartStatus ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL,
            'embargo_end' => $embargoEndStatus ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL,
        ];

        if (!$this->accessViaProperty) {
            return [
                'resource' => $resource,
                'bind' => $bind,
                'types' => $types,
            ];
        }

        // Level values.
        $levelVal = $this->accessLevels[$level] ?? $level;
        $levelType = empty($accessStatusValues['o-access:level']['type']) ? $this->levelDataType : $accessStatusValues['o-access:level']['type'];

        $bind += [
            'property_level' => $this->propertyLevelId,
            'property_embargo_start' => $this->propertyEmbargoStartId,
            'property_embargo_end' => $this->propertyEmbargoEndId,
            'level_value' => $levelVal,
            'level_type' => $levelType,
        ];
        $types += [
            'property_level' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_start' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_end' => \Doctrine\DBAL\ParameterType::INTEGER,
            'level_value' => \Doctrine\DBAL\ParameterType::STRING,
            'level_type' => \Doctrine\DBAL\ParameterType::STRING,
        ];

        // Embargo start values.
        $embargoStartVal = empty($accessStatusValues['o-access:embargo_start']['value'])
            ? ($embargoStartStatus && substr($embargoStartStatus, -8) === '00:00:00' ? substr($embargoStartStatus, 0, 10) : $embargoStartStatus)
            : $accessStatusValues['o-access:embargo_start']['value'];
        if ($embargoStartVal) {
            $embargoStartType = empty($accessStatusValues['o-access:embargo_start']['type'])
                ? ($this->hasNumericDataTypes ? 'numeric:timestamp' : 'literal')
                : $accessStatusValues['o-access:embargo_start']['type'];
            $bind += [
                'embargo_start_value' => $embargoStartVal,
                'embargo_start_type' => $embargoStartType,
            ];
            $types += [
                'embargo_start_value' => \Doctrine\DBAL\ParameterType::STRING,
                'embargo_start_type' => \Doctrine\DBAL\ParameterType::STRING,
            ];
            if ($this->hasNumericDataTypes) {
                $bind['embargo_start_timestamp'] = $embargoStart->getTimestamp();
                $types['embargo_start_timestamp'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
        }

        // Embargo end values.
        $embargoEndVal = empty($accessStatusValues['o-access:embargo_end']['value'])
            ? ($embargoEndStatus && substr($embargoEndStatus, -8) === '00:00:00' ? substr($embargoEndStatus, 0, 10) : $embargoEndStatus)
            : $accessStatusValues['o-access:embargo_end']['value'];
        if ($embargoEndVal) {
            $embargoEndType = empty($accessStatusValues['o-access:embargo_end']['type'])
                ? ($this->hasNumericDataTypes ? 'numeric:timestamp' : 'literal')
                : $accessStatusValues['o-access:embargo_end']['type'];
            $bind += [
                'embargo_end_value' => $embargoEndVal,
                'embargo_end_type' => $embargoEndType,
            ];
            $types += [
                'embargo_end_value' => \Doctrine\DBAL\ParameterType::STRING,
                'embargo_end_type' => \Doctrine\DBAL\ParameterType::STRING,
            ];
            if ($this->hasNumericDataTypes) {
                $bind['embargo_end_timestamp'] = $embargoEnd->getTimestamp();
                $types['embargo_end_timestamp'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
        }

        return [
            'resource' => $resource,
            'bind' => $bind,
            'types' => $types,
        ];
    }

    protected function processUpdateItem(array $bind, array $types): void
    {
        /*
        // Join is not possible with doctrine 2, so use the list of media.
        $mediaIds = [];
        foreach ($resource->getMedia() as $media) {
            $mediaIds[] = $media->getId();
        }
        $qb = $entityManager->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->update(AccessStatus::class, 'access_status')
            ->set('access_status.level', ':level')
            ->setParameter('level', $level)
            ->where($expr->in('access_status.id', ':ids'))
            ->setParameter('ids', $mediaIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
        ;
        $qb->getQuery()->execute();
         */

        // To check rights via sql, the media ids are passed to the query even
        // if most of  the time, if the user has rights on the item, he has
        // rights on all its media.
        $mediaIds = $this->api
            ->search('media', ['item_id' => $bind['resource_id']], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
        if (!$mediaIds) {
            return;
        }

        $bind['media_ids'] = $mediaIds;
        $types['media_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;

        // Use insert into instead of update, because the access statuses may
        // not exist yet.
        $sql = <<<'SQL'
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT `media`.`id`, :level, :embargo_start, :embargo_end
            FROM `media`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
            ON DUPLICATE KEY UPDATE
                `level` = :level,
                `embargo_start` = :embargo_start,
                `embargo_end` = :embargo_end
            ;
            SQL;

        if ($this->accessViaProperty) {
            $sql .= "\n" . $this->sqlUpdateItemProperties($bind, $types);
        }

        $this->connection->executeStatement($sql, $bind, $types);
    }

    protected function sqlUpdateItemProperties(array $bind, array $types): string
    {
        $sql = <<<'SQL'
            DELETE `value`
            FROM `value`
            JOIN `media` ON `media`.`id` = `value`.`resource_id`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
                AND `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
            ;
            SQL;

        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<'SQL'
            DELETE `numeric_data_types_timestamp`
            FROM `numeric_data_types_timestamp`
            JOIN `media` ON `media`.`id` = `numeric_data_types_timestamp`.`resource_id`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
                AND `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
            ;
            SQL;
        }

        $sql .= "\n" . <<<'SQL'
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
            ;
            SQL;

        if (!empty($bind['embargo_start_value'])) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                WHERE `media`.`item_id` = :resource_id
                    AND `media`.`id` IN (:media_ids)
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    WHERE `media`.`item_id` = :resource_id
                        AND `media`.`id` IN (:media_ids)
                    ;
                    SQL;
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                WHERE `media`.`item_id` = :resource_id
                    AND `media`.`id` IN (:media_ids)
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    WHERE `media`.`item_id` = :resource_id
                        AND `media`.`id` IN (:media_ids)
                    ;
                    SQL;
            }
        }

        return $sql;
    }

    protected function processUpdateItemSet(array $bind, array $types): void
    {
        if ($this->isAdminRole) {
            // Update resources without check when user can view all resources.
            $sql = <<<'SQL'
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `item_item_set`.`item_id`, :level, :embargo_start, :embargo_end
                FROM `item_item_set`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ON DUPLICATE KEY UPDATE
                    `level` = :level,
                    `embargo_start` = :embargo_start,
                    `embargo_end` = :embargo_end
                ;
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `media`.`id`, :level, :embargo_start, :embargo_end
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ON DUPLICATE KEY UPDATE
                    `level` = :level,
                    `embargo_start` = :embargo_start,
                    `embargo_end` = :embargo_end
                ;
                SQL;

            if ($this->accessViaProperty) {
                $sql .= "\n" . $this->sqlUpdateItemSetPropertiesAllowed($bind, $types);
            }

            $this->connection->executeStatement($sql, $bind, $types);
            return;
        }

        // Update all resources with check of rights.

        // To check rights via sql, the item ids are passed to the query.
        $countItems = $this->api
            ->search('items', ['item_set_id' => $bind['resource_id']], ['initialize' => false, 'finalize' => false])->getTotalResults();
        if (!$countItems) {
            return;
        }

        // The standard api does not allow to search media by item set or
        // media by a list of items.
        /*
        $countMedias = $api
            ->search('media', ['item_set_id' => $bind['resource_id']], ['initialize' => false, 'finalize' => false])->getTotalResults();
        if ($countMedias) {
           return;
        }
        */

        // So use the standard db visibility check. Normally, there is always a
        // user here.
        /** @see \Omeka\Db\Filter\ResourceVisibilityFilter */
        if ($this->userId) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
            $bind['user_id'] = $this->userId;
            $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        } else {
            $orWhereUser = '';
        }

        $sql = <<<SQL
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT `item_item_set`.`item_id`, :level, :embargo_start, :embargo_end
            FROM `item_item_set`
            JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ON DUPLICATE KEY UPDATE
                `level` = :level,
                `embargo_start` = :embargo_start,
                `embargo_end` = :embargo_end
            ;
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT `media`.`id`, :level, :embargo_start, :embargo_end
            FROM `media`
            JOIN `resource` ON `resource`.`id` = `media`.`id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource_item`.`is_public` = 1 $orWhereUser)
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ON DUPLICATE KEY UPDATE
                `level` = :level,
                `embargo_start` = :embargo_start,
                `embargo_end` = :embargo_end
            ;
            SQL;

        if ($this->accessViaProperty) {
            $sql .= "\n" . $this->sqlUpdateItemSetPropertiesNotAllowed($bind, $types);
        }

        $this->connection->executeStatement($sql, $bind, $types);
    }

    protected function sqlUpdateItemSetPropertiesAllowed(array $bind, array $types): string
    {
        $sql = <<<'SQL'
            DELETE `value`
            FROM `value`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `value`.`resource_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                AND `item_item_set`.`item_set_id` = :resource_id
            ;
            DELETE `value`
            FROM `value`
            JOIN `media` ON `media`.`id` = `value`.`resource_id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
            ;
            SQL;

        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<'SQL'
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `media` ON `media`.`id` = `numeric_data_types_timestamp`.`resource_id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                ;
                SQL;
        }

        $sql .= "\n" . <<<'SQL'
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `item_item_set`.`item_id`, :property_level, :level_type, :level_value, 1
            FROM `item_item_set`
            WHERE `item_item_set`.`item_set_id` = :resource_id
            ;
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
            ;
            SQL;

        if (!empty($bind['embargo_start_value'])) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `item_item_set`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ;
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `item_item_set`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    ;
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    ;
                    SQL;
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $sql .= "\n" . <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `item_item_set`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ;
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `item_item_set`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    ;
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    ;
                    SQL;
            }
        }

        return $sql;
    }

    protected function sqlUpdateItemSetPropertiesNotAllowed(array $bind, array $types): string
    {
        if ($this->userId) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
        } else {
            $orWhereUser = '';
        }

        $sql = <<<SQL
            DELETE `value`
            FROM `value`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `value`.`resource_id`
            JOIN `resource` ON `resource_item`.`id` = `item_item_set`.`item_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                AND `item_item_set`.`item_set_id` = :resource_id
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ;
            DELETE `value`
            FROM `value`
            JOIN `media` ON `media`.`id` = `value`.`resource_id`
            JOIN `resource` ON `resource`.`id` = `media`.`id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                AND `item_item_set`.`item_set_id` = :resource_id
                AND (`resource_item`.`is_public` = 1 $orWhereUser)
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ;
            SQL;

        if ($this->hasNumericDataTypes) {
            $sql .= "\n" . <<<SQL
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `numeric_data_types_timestamp`.`resource_id`
                JOIN `resource` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                    AND `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `media` ON `media`.`id` = `numeric_data_types_timestamp`.`resource_id`
                JOIN `resource` ON `resource`.`id` = `media`.`id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                    AND `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource_item`.`is_public` = 1 $orWhereUser)
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                SQL;
        }

        $sql .= "\n" . <<<SQL
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `item_item_set`.`item_id`, :property_level, :level_type, :level_value, 1
            FROM `item_item_set`
            JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ;
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            JOIN `resource` ON `resource`.`id` = `media`.`id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource_item`.`is_public` = 1 $orWhereUser)
                AND (`resource`.`is_public` = 1 $orWhereUser)
            ;
            SQL;

        if (!empty($bind['embargo_start_value'])) {
            $sql .= "\n" . <<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `item_item_set`
                JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                JOIN `resource` ON `resource`.`id` = `media`.`id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource_item`.`is_public` = 1 $orWhereUser)
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `item_item_set`
                    JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    ;
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    JOIN `resource` ON `resource`.`id` = `media`.`id`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource_item`.`is_public` = 1 $orWhereUser)
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    ;
                    SQL;
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $sql .= "\n" . <<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `item_item_set`
                JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                JOIN `resource` ON `resource`.`id` = `media`.`id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource_item`.`is_public` = 1 $orWhereUser)
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                ;
                SQL;
            if ($this->hasNumericDataTypes) {
                $sql .= "\n" . <<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `item_item_set`
                    JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    ;
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    JOIN `resource` ON `resource`.`id` = `media`.`id`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource_item`.`is_public` = 1 $orWhereUser)
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    ;
                    SQL;
            }
        }

        return $sql;
    }
}

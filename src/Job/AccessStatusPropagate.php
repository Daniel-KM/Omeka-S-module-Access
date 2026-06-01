<?php declare(strict_types=1);

namespace Access\Job;

use Omeka\Job\AbstractJob;

class AccessStatusPropagate extends AbstractJob
{
    use AccessPropertiesTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    /**
     * @var \Access\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatusForResource;

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
     * @var bool
     */
    protected $isAdminRole;

    /**
     * @var int
     */
    protected $userId;

    /**
     * @var string One of: skip_if_set, max_restrictive, overwrite.
     */
    protected $propagationMode = 'skip_if_set';

    /**
     * @var string Pre-computed ON DUPLICATE KEY UPDATE clause for access_status.
     */
    protected $onDupClause = '';

    /**
     * Numeric ordering of access levels, used to compare with GREATEST in the
     * SQL MAX path. Higher = more restrictive.
     */
    protected const LEVEL_ORDER = [
        'free' => 1,
        'reserved' => 2,
        'protected' => 3,
        'forbidden' => 4,
    ];

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

        $mode = $this->getArg('propagation_mode', 'skip_if_set');
        if (in_array($mode, ['skip_if_set', 'max_restrictive', 'overwrite'], true)) {
            $this->propagationMode = $mode;
        }
        // Pre-compute the access_status ON DUPLICATE KEY UPDATE clause and the
        // property value-table sync mode. Set once per job to avoid a recompute
        // in every executeStatement call.
        $this->onDupClause = $this->buildOnDupClause();

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
            $this->logger->warn(
                'Resource #{resource_id} is not an item or an item set.', // @translate
                ['resource_id' => $resourceId]
            );
            return null;
        }

        /** @var \Access\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->accessStatusForResource->__invoke($resource);
        if (!$accessStatus) {
            $this->logger->warn(
                'No access status for resource #{resource_id}.', // @translate
                ['resource_id' => $resourceId]
            );
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
            'level_num' => self::LEVEL_ORDER[$level] ?? 1,
            'embargo_start' => $embargoStartStatus,
            'embargo_end' => $embargoEndStatus,
        ];
        $types = [
            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'level' => \Doctrine\DBAL\ParameterType::STRING,
            'level_num' => \Doctrine\DBAL\ParameterType::INTEGER,
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
            ->setParameter('ids', array_values($mediaIds), \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
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

        $bind['media_ids'] = array_values($mediaIds);
        $types['media_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;

        // Use insert into instead of update, because the access statuses may
        // not exist yet.
        $sql = <<<'SQL'
            INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
            SELECT `media`.`id`, :level, :embargo_start, :embargo_end
            FROM `media`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
            ON DUPLICATE KEY UPDATE {{ON_DUP}}
            ;
            SQL;

        // Each statement is executed separately: PDO emulated
        // prepares does not reliably bind named parameters across
        // multiple statements in one call.
        $this->connection->transactional(function () use ($sql, $bind, $types) {
            $this->execStatement($sql, $bind, $types);
            if ($this->accessViaProperty) {
                $this->execUpdateItemProperties($bind, $types);
            }
        });
    }

    protected function execUpdateItemProperties(array $bind, array $types): void
    {
        // skip_if_set guarantees that no existing access_status is touched, so
        // the matching property values must remain intact too (no DELETE, no
        // INSERT, no realign). New rows in access_status are created for
        // children that had none, but in practice the auto-creation listener
        // makes this case marginal, propagating property values there is not
        // worth the cross-mode incoherence.
        if ($this->propagationMode === 'skip_if_set') {
            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
            DELETE `value`
            FROM `value`
            JOIN `media` ON `media`.`id` = `value`.`resource_id`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
                AND `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
            SQL,
            $bind, $types
        );

        if ($this->hasNumericDataTypes) {
            $this->connection->executeStatement(
                <<<'SQL'
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `media` ON `media`.`id` = `numeric_data_types_timestamp`.`resource_id`
                WHERE `media`.`item_id` = :resource_id
                    AND `media`.`id` IN (:media_ids)
                    AND `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                SQL,
                $bind, $types
            );
        }

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            WHERE `media`.`item_id` = :resource_id
                AND `media`.`id` IN (:media_ids)
            SQL,
            $bind, $types
        );

        if (!empty($bind['embargo_start_value'])) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                WHERE `media`.`item_id` = :resource_id
                    AND `media`.`id` IN (:media_ids)
                SQL,
                $bind, $types
            );
            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    WHERE `media`.`item_id` = :resource_id
                        AND `media`.`id` IN (:media_ids)
                    SQL,
                    $bind, $types
                );
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                WHERE `media`.`item_id` = :resource_id
                    AND `media`.`id` IN (:media_ids)
                SQL,
                $bind, $types
            );
            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    WHERE `media`.`item_id` = :resource_id
                        AND `media`.`id` IN (:media_ids)
                    SQL,
                    $bind, $types
                );
            }
        }

        // See comment in execUpdateItemSetPropertiesAllowed.
        $this->realignPropertyLevelsToAccessStatus('item', $bind, $types);
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
                ON DUPLICATE KEY UPDATE {{ON_DUP}}
                ;
                INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
                SELECT `media`.`id`, :level, :embargo_start, :embargo_end
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                ON DUPLICATE KEY UPDATE {{ON_DUP}}
                ;
                SQL;

            $this->connection->transactional(function () use ($sql, $bind, $types) {
                $this->execStatement($sql, $bind, $types);
                if ($this->accessViaProperty) {
                    $this->execUpdateItemSetPropertiesAllowed($bind, $types);
                }
            });
            return;
        }

        // Update all resources with check of rights.

        // To check rights via sql, the item ids are passed to the query.
        $countItems = $this->api
            ->search('items', ['item_set_id' => $bind['resource_id'], 'limit' => 0], ['initialize' => false, 'finalize' => false])->getTotalResults();
        if (!$countItems) {
            return;
        }

        // The standard api does not allow to search media by item set or
        // media by a list of items.
        /*
        $countMedias = $api
            ->search('media', ['item_set_id' => $bind['resource_id'], 'limit' => 0], ['initialize' => false, 'finalize' => false])->getTotalResults();
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
            ON DUPLICATE KEY UPDATE {{ON_DUP}}
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
            ON DUPLICATE KEY UPDATE {{ON_DUP}}
            ;
            SQL;

        $this->connection->transactional(function () use ($sql, $bind, $types) {
            $this->execStatement($sql, $bind, $types);
            if ($this->accessViaProperty) {
                $this->execUpdateItemSetPropertiesNotAllowed($bind, $types);
            }
        });
    }

    protected function execUpdateItemSetPropertiesAllowed(array $bind, array $types): void
    {
        // skip_if_set: see comment in execUpdateItemProperties.
        if ($this->propagationMode === 'skip_if_set') {
            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
            DELETE `value`
            FROM `value`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `value`.`resource_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                AND `item_item_set`.`item_set_id` = :resource_id
            SQL,
            $bind, $types
        );
        $this->connection->executeStatement(
            <<<'SQL'
            DELETE `value`
            FROM `value`
            JOIN `media` ON `media`.`id` = `value`.`resource_id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
            SQL,
            $bind, $types
        );

        if ($this->hasNumericDataTypes) {
            $this->connection->executeStatement(
                <<<'SQL'
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `media` ON `media`.`id` = `numeric_data_types_timestamp`.`resource_id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                SQL,
                $bind, $types
            );
        }

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `item_item_set`.`item_id`, :property_level, :level_type, :level_value, 1
            FROM `item_item_set`
            WHERE `item_item_set`.`item_set_id` = :resource_id
            SQL,
            $bind, $types
        );
        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
            SQL,
            $bind, $types
        );

        if (!empty($bind['embargo_start_value'])) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `item_item_set`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                SQL,
                $bind, $types
            );
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                SQL,
                $bind, $types
            );
            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `item_item_set`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    SQL,
                    $bind, $types
                );
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    SQL,
                    $bind, $types
                );
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `item_item_set`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                SQL,
                $bind, $types
            );
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                SQL,
                $bind, $types
            );
            if ($this->hasNumericDataTypes) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `item_item_set`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    SQL,
                    $bind, $types
                );
                $this->connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                    SQL,
                    $bind, $types
                );
            }
        }

        // Realign property level values with the actual access_status, so
        // children preserved at a stricter level by max_restrictive (or left
        // unchanged by skip_if_set) are not silently demoted when the next
        // resource save mirrors property->access_status. TODO Embargo dates
        // not yet realigned, refined in 3.4.45.
        $this->realignPropertyLevelsToAccessStatus('item_set', $bind, $types);
        $this->realignPropertyLevelsToAccessStatus('item_set_media', $bind, $types);
    }

    protected function execUpdateItemSetPropertiesNotAllowed(array $bind, array $types): void
    {
        // skip_if_set: see comment in execUpdateItemProperties.
        if ($this->propagationMode === 'skip_if_set') {
            return;
        }

        if ($this->userId) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
        } else {
            $orWhereUser = '';
        }

        $exec = fn (string $sql) => $this->connection
            ->executeStatement($sql, $bind, $types);

        $exec(<<<SQL
            DELETE `value`
            FROM `value`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `value`.`resource_id`
            JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
            WHERE `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
                AND `item_item_set`.`item_set_id` = :resource_id
                AND (`resource`.`is_public` = 1 $orWhereUser)
            SQL);
        $exec(<<<SQL
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
            SQL);

        if ($this->hasNumericDataTypes) {
            $exec(<<<SQL
                DELETE `numeric_data_types_timestamp`
                FROM `numeric_data_types_timestamp`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `numeric_data_types_timestamp`.`resource_id`
                JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                WHERE `numeric_data_types_timestamp`.`property_id` IN (:property_embargo_start, :property_embargo_end)
                    AND `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                SQL);
            $exec(<<<SQL
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
                SQL);
        }

        $exec(<<<SQL
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `item_item_set`.`item_id`, :property_level, :level_type, :level_value, 1
            FROM `item_item_set`
            JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource`.`is_public` = 1 $orWhereUser)
            SQL);
        $exec(<<<SQL
            INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
            SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
            FROM `media`
            JOIN `resource` ON `resource`.`id` = `media`.`id`
            JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
            JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
            WHERE `item_item_set`.`item_set_id` = :resource_id
                AND (`resource_item`.`is_public` = 1 $orWhereUser)
                AND (`resource`.`is_public` = 1 $orWhereUser)
            SQL);

        if (!empty($bind['embargo_start_value'])) {
            $exec(<<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `item_item_set`
                JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                SQL);
            $exec(<<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
                FROM `media`
                JOIN `resource` ON `resource`.`id` = `media`.`id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource_item`.`is_public` = 1 $orWhereUser)
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                SQL);
            if ($this->hasNumericDataTypes) {
                $exec(<<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `item_item_set`
                    JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    SQL);
                $exec(<<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_start, :embargo_start_timestamp
                    FROM `media`
                    JOIN `resource` ON `resource`.`id` = `media`.`id`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource_item`.`is_public` = 1 $orWhereUser)
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    SQL);
            }
        }

        if (!empty($bind['embargo_end_value'])) {
            $exec(<<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `item_item_set`
                JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                SQL);
            $exec(<<<SQL
                INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
                SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
                FROM `media`
                JOIN `resource` ON `resource`.`id` = `media`.`id`
                JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                WHERE `item_item_set`.`item_set_id` = :resource_id
                    AND (`resource_item`.`is_public` = 1 $orWhereUser)
                    AND (`resource`.`is_public` = 1 $orWhereUser)
                SQL);
            if ($this->hasNumericDataTypes) {
                $exec(<<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `item_item_set`.`item_id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `item_item_set`
                    JOIN `resource` ON `resource`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    SQL);
                $exec(<<<SQL
                    INSERT INTO `numeric_data_types_timestamp` (`resource_id`, `property_id`, `value`)
                    SELECT `media`.`id`, :property_embargo_end, :embargo_end_timestamp
                    FROM `media`
                    JOIN `resource` ON `resource`.`id` = `media`.`id`
                    JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
                    JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
                    WHERE `item_item_set`.`item_set_id` = :resource_id
                        AND (`resource_item`.`is_public` = 1 $orWhereUser)
                        AND (`resource`.`is_public` = 1 $orWhereUser)
                    SQL);
            }
        }

        // See comment in execUpdateItemSetPropertiesAllowed.
        $this->realignPropertyLevelsToAccessStatus('item_set', $bind, $types);
        $this->realignPropertyLevelsToAccessStatus('item_set_media', $bind, $types);
    }

    /**
     * Wrapper around connection->executeStatement that resolves the
     * {{ON_DUP}} placeholder used by access_status INSERT ... ON DUPLICATE
     * statements. Other SQL is passed through unchanged.
     */
    protected function execStatement(string $sql, array $bind = [], array $types = []): void
    {
        if (strpos($sql, '{{ON_DUP}}') !== false) {
            $sql = str_replace('{{ON_DUP}}', $this->onDupClause, $sql);
        }
        $this->connection->executeStatement($sql, $bind, $types);
    }

    /**
     * In non-overwrite propagation modes, the access_status row of a child may
     * differ from the parent's level (kept stricter by max_restrictive, or left
     * untouched by skip_if_set). The legacy property-mode sync blindly inserts
     * the PARENT's level value into the value table, which then mirrors back to
     * access_status the next time the resource is saved, silently demoting the
     * stricter child. This realignment pass runs right after the legacy INSERTs
     * and rewrites the level value column from the actual access_status of each
     * child.
     *
     * Required bind keys: resource_id (the item set id), property_level,
     * v_free, v_reserved, v_protected, v_forbidden (the property values for
     * each level, taken from access_property_levels).
     *
     * Embargo dates are NOT realigned by this pass, known limitation for now.
     */
    protected function realignPropertyLevelsToAccessStatus(string $scope, array $bind, array $types): void
    {
        if ($this->propagationMode === 'overwrite') {
            return;
        }

        $joinForScope = $scope === 'item_set' ? <<<'SQL'
            JOIN `item_item_set` iis ON iis.item_id = `value`.resource_id
            SQL
            : ($scope === 'item_set_media' ? <<<'SQL'
                JOIN `media` ON `media`.id = `value`.resource_id
                JOIN `item_item_set` iis ON iis.item_id = `media`.item_id
                SQL
                : <<<'SQL'
                    JOIN `media` ON `media`.id = `value`.resource_id
                    SQL);
        $whereForScope = $scope === 'item' ? '`media`.item_id = :resource_id' : 'iis.item_set_id = :resource_id';

        $sql = <<<SQL
            UPDATE `value`
            JOIN `access_status` a ON a.id = `value`.resource_id
            $joinForScope
            SET `value`.`value` = CASE a.`level`
                WHEN 'free'      THEN :v_free
                WHEN 'reserved'  THEN :v_reserved
                WHEN 'protected' THEN :v_protected
                WHEN 'forbidden' THEN :v_forbidden
                ELSE `value`.`value`
            END
            WHERE $whereForScope
              AND `value`.property_id = :property_level
            SQL;

        $extraBind = [
            'v_free'      => (string) ($this->accessLevels['free']      ?? 'free'),
            'v_reserved'  => (string) ($this->accessLevels['reserved']  ?? 'reserved'),
            'v_protected' => (string) ($this->accessLevels['protected'] ?? 'protected'),
            'v_forbidden' => (string) ($this->accessLevels['forbidden'] ?? 'forbidden'),
        ];
        $extraTypes = [
            'v_free'      => \Doctrine\DBAL\ParameterType::STRING,
            'v_reserved'  => \Doctrine\DBAL\ParameterType::STRING,
            'v_protected' => \Doctrine\DBAL\ParameterType::STRING,
            'v_forbidden' => \Doctrine\DBAL\ParameterType::STRING,
        ];

        $this->connection->executeStatement($sql, $bind + $extraBind, $types + $extraTypes);
    }

    /**
     * Build the SQL fragment for the access_status ON DUPLICATE KEY UPDATE
     * clause according to the propagation mode.
     *
     * - overwrite: always copy the parent's value (legacy behavior).
     * - max_restrictive: keep the strictest level, the earliest embargo_start
     *   and the latest embargo_end (never shorten a protection window).
     * - skip_if_set: no-op on existing rows.
     *
     * The MAX over the ENUM-like level column uses FIELD/ELT to compare on the
     * documented order free < reserved < protected < forbidden, plain GREATEST
     * on the string would compare lexicographically and corrupt the data.
     */
    protected function buildOnDupClause(): string
    {
        switch ($this->propagationMode) {
            case 'overwrite':
                return '`level` = :level, `embargo_start` = :embargo_start, `embargo_end` = :embargo_end';
            case 'skip_if_set':
                return '`id` = `id`';
            case 'max_restrictive':
            default:
                return '`level` = ELT('
                    . 'GREATEST(FIELD(`level`, "free","reserved","protected","forbidden"), :level_num),'
                    . '"free","reserved","protected","forbidden"'
                    . '), '
                    . '`embargo_start` = LEAST(IFNULL(`embargo_start`, :embargo_start), IFNULL(:embargo_start, `embargo_start`)), '
                    . '`embargo_end` = GREATEST(IFNULL(`embargo_end`, :embargo_end), IFNULL(:embargo_end, `embargo_end`))';
        }
    }
}

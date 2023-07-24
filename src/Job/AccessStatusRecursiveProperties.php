<?php declare(strict_types=1);

namespace AccessResource\Job;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use Omeka\Entity\Resource;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class AccessStatusRecursiveProperties extends AbstractJob
{
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
    protected $levelProperty;

    /**
     * @var int
     */
    protected $levelPropertyId;

    /**
     * For mode property / level, list the possible levels.
     *
     * @var array
     */
    protected $levelPropertyLevels;

    /**
     * @var string
     */
    protected $embargoStartProperty;

    /**
     * @var int
     */
    protected $embargoStartPropertyId;

    /**
     * @var string
     */
    protected $embargoEndProperty;

    /**
     * @var int
     */
    protected $embargoEndPropertyId;

    /**
     * @var bool
     */
    protected $isAllowed;

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
        $accessStatusForResource = $plugins->get('accessStatus');

        $settings = $services->get('Omeka\Settings');

        $accessViaProperty = (bool) $settings->get('accessresource_property');
        if (!$accessViaProperty) {
            $this->logger->info(new Message(
                'Skipped: the access status does not use properties .' // @translate
            ));
            return;
        }

        $this->levelProperty = $settings->get('accessresource_property_level');
        $this->levelPropertyLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $settings->get('accessresource_property_levels', [])), AccessStatusRepresentation::LEVELS);
        $this->embargoStartProperty = $settings->get('accessresource_property_embargo_start');
        $this->embargoEndProperty = $settings->get('accessresource_property_embargo_end');

        $this->levelPropertyId = $this->api->search('properties', ['term' => $this->levelProperty], ['returnScalar' => 'id'])->getContent();
        if (!$this->levelPropertyId) {
            $this->logger->err(new Message(
                'Property for access level "%1$s" does not exist.', // @translate
                $this->levelProperty
            ));
            return;
        }
        $this->levelPropertyId = reset($this->levelPropertyId);

        $this->embargoStartPropertyId = $this->api->search('properties', ['term' => $this->embargoStartProperty], ['returnScalar' => 'id'])->getContent();
        if (!$this->embargoStartPropertyId) {
            $this->logger->err(new Message(
                'Property for embargo start "%1$s" does not exist.', // @translate
                $this->embargoStartProperty
            ));
            return;
        }
        $this->embargoStartPropertyId = reset($this->embargoStartPropertyId);

        $this->embargoEndPropertyId = $this->api->search('properties', ['term' => $this->embargoEndProperty], ['returnScalar' => 'id'])->getContent();
        if (!$this->embargoEndPropertyId) {
            $this->logger->err(new Message(
                'Property for embargo end "%1$s" does not exist.', // @translate
                $this->embargoEndProperty
            ));
            return;
        }
        $this->embargoEndPropertyId = reset($this->embargoEndPropertyId);

        $resourceId = (int) $this->getArg('resource_id');
        if (!$resourceId) {
            $this->logger->warn(new Message(
                'No resource to process.' // @translate
            ));
            return;
        }

        /** @var \Omeka\Entity\Resource $resource */
        $resource = $this->entityManager->find(\Omeka\Entity\Resource::class, $resourceId);
        if (!$resource) {
            $this->logger->warn(new Message(
                'No resource "%d" to process.', // @translate
                $resourceId
            ));
            return;
        }

        $resourceName = $resource->getResourceName();
        if (!in_array($resourceName, ['items', 'item_sets'])) {
            $this->logger->warn(new Message(
                'No resource "%d" to process.', // @translate
                $resourceId
            ));
            return;
        }

        /** @var \AccessResource\Entity\AccessStatus $accessStatus */
        $accessStatus = $accessStatusForResource($resource);
        if (!$accessStatus) {
            $this->logger->warn(new Message(
                'No access status for resource "%d".', // @translate
                $resourceId
            ));
            return;
        }

        $accessStatusValues = $this->getArg('values', []);

        // People who can view all have all rights to update statuses.
        $this->isAllowed = $services->get('Omeka\Acl')->userIsAllowed(Resource::class, 'view-all');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('NumericDataTypes');
        $hasNumericDataType = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        $level = $accessStatus->getLevel();
        $embargoStart = $accessStatus->getEmbargoStart();
        $embargoEnd = $accessStatus->getEmbargoEnd();
        $embargoStartStatus = $embargoStart ? $embargoStart->format('Y-m-d H:i:s') : null;
        $embargoEndStatus = $embargoEnd ? $embargoEnd->format('Y-m-d H:i:s') : null;

        // Level values.
        $levelPropertyLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $settings->get('accessresource_property_levels', [])), AccessStatusRepresentation::LEVELS);
        $levelVal = $levelPropertyLevels[$level] ?? $level;
        if (empty($accessStatusValues['o-access:level']['type'])) {
            try {
                $anyLevel = $this->api->read('values', ['property' => $this->levelPropertyId], [], ['responseContent' => 'resource'])->getContent();
                $levelType = $anyLevel->getType();
            } catch (\Exception $e) {
                $levelType = 'literal';
            }
        } else {
            $levelType = $accessStatusValues['o-access:level']['type'];
        }

        $bind = [
            'resource_id' => $resourceId,
            'property_level' => $this->levelPropertyId,
            'property_embargo_start' => $this->embargoStartPropertyId,
            'property_embargo_end' => $this->embargoEndPropertyId,
            'level_value' => $levelVal,
            'level_type' => $levelType,
        ];
        $types = [
            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_level' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_start' => \Doctrine\DBAL\ParameterType::INTEGER,
            'property_embargo_end' => \Doctrine\DBAL\ParameterType::INTEGER,
            'level_value' => \Doctrine\DBAL\ParameterType::STRING,
            'level_type' => \Doctrine\DBAL\ParameterType::STRING,
        ];

        // Embargo start values.
        $embargoStartVal = empty($accessStatusValues['o-access:embargo_start']['value'])
            ? ($embargoStartStatus && substr($embargoStartStatus, -8) === '00:00:00' ? substr($embargoStartStatus, 0,10) : $embargoStartStatus)
            : $accessStatusValues['o-access:embargo_start']['value'];
        if ($embargoStartVal) {
            $embargoStartType = empty($accessStatusValues['o-access:embargo_start']['type'])
                ? ($hasNumericDataType ? 'numeric:timestamp' : 'literal')
                : $accessStatusValues['o-access:embargo_start']['type'];
            $bind += [
                'embargo_start_value' => $embargoStartVal,
                'embargo_start_type' => $embargoStartType,
            ];
            $types += [
                'embargo_start_value' => \Doctrine\DBAL\ParameterType::STRING,
                'embargo_start_type' => \Doctrine\DBAL\ParameterType::STRING,
            ];
        }

        // Embargo end values.
        $embargoEndVal = empty($accessStatusValues['o-access:embargo_end']['value'])
            ? ($embargoEndStatus && substr($embargoEndStatus, -8) === '00:00:00' ? substr($embargoEndStatus, 0,10) : $embargoEndStatus)
            : $accessStatusValues['o-access:embargo_end']['value'];
        if ($embargoEndVal) {
            $embargoEndType = empty($accessStatusValues['o-access:embargo_end']['type'])
                ? ($hasNumericDataType ? 'numeric:timestamp' : 'literal')
                : $accessStatusValues['o-access:embargo_end']['type'];
            $bind += [
                'embargo_end_value' => $embargoEndVal,
                'embargo_end_type' => $embargoEndType,
            ];
            $types += [
                'embargo_end_value' => \Doctrine\DBAL\ParameterType::STRING,
                'embargo_end_type' => \Doctrine\DBAL\ParameterType::STRING,
            ];
        }

        $resourceName === 'items'
            ? $this->processUpdateItems($bind, $types)
            :  $this->processUpdateItemSets($bind, $types);

        $this->logger->info(new Message(
            'End of indexation of access statuses in properties.' // @translate
        ));
    }

    protected function processUpdateItems(array $bind, array $types): void
    {
        // To check rights via sql, the media ids are passed to the query even
        // if most of  the time, if the user has rights on the item, he has
        // rights on all its media.
        if ($this->isAllowed) {
            $whereMedias = '';
        } else {
            $mediaIds = $this->api
                ->search('media', ['item_id' => $bind['resource_id']], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
            if (!$mediaIds) {
                return;
            }
            $whereMedias = 'AND `media`.`id` IN (:media_ids)';
            $bind['media_ids'] = $mediaIds;
            $types['media_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }

        $sql = <<<SQL
DELETE `value`
FROM `value`
JOIN `media` ON `media`.`id` = `value`.`resource_id`
WHERE `media`.`item_id` = :resource_id
    $whereMedias
    AND `value`.`property_id` IN (:property_level, :property_embargo_start, :property_embargo_end)
;
INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
SELECT `media`.`id`, :property_level, :level_type, :level_value, 1
FROM `media`
WHERE `media`.`item_id` = :resource_id
    $whereMedias
;
SQL;

        if (!empty($bind['embargo_start_value'])) {
            $sql .= "\n" . <<<SQL
INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
SELECT `media`.`id`, :property_embargo_start, :embargo_start_type, :embargo_start_value, 1
FROM `media`
WHERE `media`.`item_id` = :resource_id
    $whereMedias
;
SQL;
        }

        if (!empty($bind['embargo_end_value'])) {
            $sql .= "\n" . <<<SQL
INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `is_public`)
SELECT `media`.`id`, :property_embargo_end, :embargo_end_type, :embargo_end_value, 1
FROM `media`
WHERE `media`.`item_id` = :resource_id
    $whereMedias
;
SQL;
        }

        // TODO Numeric timestamps.

        $this->entityManager->getConnection()->executeStatement($sql, $bind, $types);
    }

    protected function processUpdateItemSets(array $bind, array $types): void
    {
        $this->isAllowed
            ? $this->processUpdateItemSetsAllowed($bind, $types)
            :  $this->processUpdateItemSetsNotAllowed($bind, $types);
    }

    protected function processUpdateItemSetsAllowed(array $bind, array $types): void
    {
        $sql = <<<SQL
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
            $sql .= "\n" . <<<SQL
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
        }

        if (!empty($bind['embargo_end_value'])) {
            $sql .= "\n" . <<<SQL
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
        }

        // TODO Numeric timestamps.

        $this->entityManager->getConnection()->executeStatement($sql, $bind, $types);
    }

    protected function processUpdateItemSetsNotAllowed(array $bind, array $types): void
    {
        // Use the standard db visibility check.
        // Normally, there is always a user here.
        /** @see \Omeka\Db\Filter\ResourceVisibilityFilter */
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        if ($user) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
            $bind['user_id'] = $user->getId();
            $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
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
        }

        // TODO Numeric timestamps.

        $this->entityManager->getConnection()->executeStatement($sql, $bind, $types);
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Job;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class AccessStatusUpdate extends AbstractJob
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

    /**
     * @var string
     */
    protected $levelProperty;

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
     * @var string
     */
    protected $embargoEndProperty;

    /**
     * @var string
     */
    protected $missingMode = 'skip';

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
        $this->missingMode = $this->getArg('missing');
        if (!in_array($this->missingMode, $missingModes)) {
            $this->logger->err(new Message(
                'Missing mode is not set or invalid.' // @translate
            ));
            return;
        }

        if ($this->missingMode === 'skip') {
            $this->logger->warn(new Message(
                'Missing mode is set as "skip".' // @translate
            ));
            return;
        }

        $this->logger->info(new Message(
            'Starting indexation of access statuses of all resources.' // @translate
        ));

        $accessViaProperty = (bool) $settings->get('accessresource_property');
        if ($accessViaProperty) {
            $this->levelProperty = $settings->get('accessresource_property_level');
            $this->levelPropertyLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $settings->get('accessresource_property_levels', [])), AccessStatusRepresentation::LEVELS);
            $this->embargoStartProperty = $settings->get('accessresource_property_embargo_start');
            $this->embargoEndProperty = $settings->get('accessresource_property_embargo_end');
            $this->updateLevelViaProperty();
            $this->updateEmbargoViaProperty();
        } else {
            $this->updateLevelViaVisibility();
        }

        $this->logger->info(new Message(
            'End of indexation.' // @translate
        ));
    }

    protected function updateLevelViaVisibility(): bool
    {
        if (in_array($this->missingMode, ['free', 'reserved', 'protected', 'forbidden'])) {
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

        return true;
    }

    protected function updateLevelViaProperty(): bool
    {
        if (!$this->levelProperty) {
            $this->logger->err(new Message(
                'Property to set access resource is not defined.' // @translate
            ));
            return false;
        }

        $propertyId = $this->api->search('properties', ['term' => $this->levelProperty])->getContent();
        if (!$propertyId) {
            $this->logger->err(new Message(
                'Property "%1$s" does not exist.', // @translate
                $this->levelProperty
            ));
            return false;
        }
        $propertyId = reset($propertyId);

        $list = array_intersect_key($this->levelPropertyLevels, $this->levelPropertyLevelsDefault);
        if (count($list) !== 4) {
            $this->logger->err(new Message(
                'List of property levels is incomplete, missing "%s".', // @translate
                implode('", "', array_flip(array_diff_key($this->levelPropertyLevelsDefault, $this->levelPropertyLevels)))
            ));
            return false;
        }

        $quotedList = [];
        foreach ($list as $key => $value) {
            $quotedList[$key] = $this->connection->quote($value);
        }
        $quotedListString = implode(', ', $quotedList);

        // Insert missing access according to property values.
        if (in_array($this->missingMode, ['free', 'reserved', 'protected', 'forbidden'])) {
            $subSql = "'$this->missingMode'";
        } else {
            $mode = substr($this->missingMode, 11);
            $subSql = "IF(`resource`.`is_public` = 1, 'free', '$mode')";
        }

        $sql = <<<SQL
# Set access statuses according to values.
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT
    `resource`.`id`,
    (CASE `value`.`value`
        WHEN {$quotedList['free']} THEN 'free'
        WHEN {$quotedList['reserved']} THEN 'reserved'
        WHEN {$quotedList['protected']} THEN 'protected'
        WHEN {$quotedList['forbidden']} THEN 'forbidden'
        ELSE $subSql
    END),
    NULL,
    NULL
FROM `resource`
JOIN `value`
    ON `value`.`resource_id`
    AND `value`.`property_id` = $propertyId
    AND `value`.`value` IN ($quotedListString)
ON DUPLICATE KEY UPDATE
   `id` = `resource`.`id`,
    (CASE `value`.`value`
        WHEN {$quotedList['free']} THEN 'free'
        WHEN {$quotedList['reserved']} THEN 'reserved'
        WHEN {$quotedList['protected']} THEN 'protected'
        WHEN {$quotedList['forbidden']} THEN 'forbidden'
        ELSE $subSql
    END),
    NULL,
    NULL
;

SQL;

        $this->connection->executeStatement($sql);

        return true;
    }

    protected function updateEmbargoViaProperty(): bool
    {
        $propertyStart = null;
        if ($this->embargoStartProperty) {
            $propertyStart = $this->api->search('properties', ['term' => $this->embargoStartProperty])->getContent();
            if (!$propertyStart) {
                $this->logger->err(new Message(
                    'Property "%1$s" for embargo start does not exist.', // @translate
                    $this->embargoStartProperty
                ));
                return false;
            }
            $propertyStart = reset($propertyStart);
        }

        $propertyEnd = null;
        if ($this->embargoEndProperty) {
            $propertyEnd = $this->api->search('properties', ['term' => $this->embargoEndProperty])->getContent();
            if (!$propertyEnd) {
                $this->logger->err(new Message(
                    'Property "%1$s" for embargo end does not exist.', // @translate
                    $this->embargoEndProperty
                ));
                return false;
            }
            $propertyEnd = reset($propertyEnd);
        }
// TODO %T espace?
        $sql = <<<SQL
# Set access embargo start according to values.
UPDATE `access_status` (`start_date`)
SELECT
    CASE `value`.`value`
        WHEN NULL THEN NULL
        WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
        WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
        ELSE NULL
    END
FROM `access_status`
LEFT JOIN `value`
    ON `value`.`resource_id`
    AND `value`.`property_id` = $propertyStart
;
# Set access embargo end according to values.
UPDATE `access_status` (`start_end`)
    CASE `value`.`value`
        WHEN NULL THEN NULL
        WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T') THEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')
        WHEN STR_TO_DATE(`value`.`value`, '%Y-%m-%d') THEN CONCAT(STR_TO_DATE(`value`.`value`, '%Y-%m-%d'), ' 00:00:00')
        ELSE NULL
    END
FROM `access_status`
LEFT JOIN `value`
    ON `value`.`resource_id`
    AND `value`.`property_id` = $propertyEnd
;

SQL;

        $this->connection->executeStatement($sql);

        return true;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Job;

use AccessResource\Entity\AccessStatus;
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
     * For mode property / level, list the default levels.
     *
     * @var array
     */
    protected $levelPropertyLevelsDefault = [
        AccessStatus::FREE => 'free',
        AccessStatus::RESERVED => 'reserved',
        AccessStatus::PROTECTED => 'protected',
        AccessStatus::FORBIDDEN => 'forbidden',
    ];

    /**
     * @var string
     */
    protected $embargoPropertyStart;

    /**
     * @var string
     */
    protected $embargoPropertyEnd;

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

        $accessViaProperty = (bool) $settings->get('accessresource_level_via_property');
        if ($accessViaProperty) {
            $this->accessProperty = $settings->get('accessresource_level_property');
            $this->accessPropertyLevels = $settings->get('accessresource_level_property_levels', $this->accessPropertyLevelsDefault);
            $this->updateAccessViaProperty();
        } else {
            $this->updateAccessViaVisibility();
        }

        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if  ($embargoViaProperty) {
            $this->embargoPropertyStart = $settings->get('accessresource_embargo_property_start');
            $this->embargoPropertyEnd = $settings->get('accessresource_embargo_property_end');
            $this->updateEmbargoViaProperty();
        }
    }

    protected function updateViaVisibility(): bool
    {
        if (in_array($this->missingMode, ['free', 'reserved', 'protected', 'forbidden'])) {
            $sql = <<<SQL
# Set the specified status for all missing resources.
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `id`, {$this->missingMode}, NULL, NULL
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

    protected function updateAccessViaProperty(): bool
    {
        if (!$this->accessProperty) {
            $this->logger->err(new Message(
                'Property to set access resource is not defined.' // @translate
            ));
            return false;
        }

        $propertyId = $this->api->search('properties', ['term' => $this->accessProperty])->getContent();
        if (!$propertyId) {
            $this->logger->err(new Message(
                'Property "%1$s" does not exist.', // @translate
                $this->accessProperty
            ));
            return false;
        }
        $propertyId = reset($propertyId);

        $list = array_intersect_key($this->accessPropertyLevels, $this->accessPropertyLevelsDefault);
        if (count($list) !== 4) {
            $this->logger->err(new Message(
                'List of property levels is incomplete, missing "%s".', // @translate
                implode('", "', array_flip(array_diff_key($this->accessPropertyLevelsDefault, $this->accessPropertyLevels)))
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
        if ($this->embargoPropertyStart) {
            $propertyStart = $this->api->search('properties', ['term' => $this->embargoPropertyStart])->getContent();
            if (!$propertyStart) {
                $this->logger->err(new Message(
                    'Property "%1$s" for embargo start does not exist.', // @translate
                    $this->embargoPropertyStart
                ));
                return false;
            }
            $propertyStart = reset($propertyStart);
        }

        $propertyEnd = null;
        if ($this->embargoPropertyEnd) {
            $propertyEnd = $this->api->search('properties', ['term' => $this->embargoPropertyEnd])->getContent();
            if (!$propertyEnd) {
                $this->logger->err(new Message(
                    'Property "%1$s" for embargo end does not exist.', // @translate
                    $this->embargoPropertyEnd
                ));
                return false;
            }
            $propertyEnd = reset($propertyEnd);
        }

        $sql = <<<SQL
# Set access embargo start according to values.
UPDATE `access_status` (`start_date`)
SELECT
    CASE `value`.`value`
        WHEN NULL THEN NULL
        WHEN DATE(STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')) IS NOT NULL THEN DATE(STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T'))
        WHEN DATE(STR_TO_DATE(`value`.`value`, CONCAT('%Y-%m-%d', ' 00:00:00'))) IS NOT NULL THEN DATE(STR_TO_DATE(`value`.`value`, CONCAT('%Y-%m-%d', ' 00:00:00')))
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
        WHEN DATE(STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T')) IS NOT NULL THEN DATE(STR_TO_DATE(`value`.`value`, '%Y-%m-%d %T'))
        WHEN DATE(STR_TO_DATE(`value`.`value`, CONCAT('%Y-%m-%d', ' 00:00:00'))) IS NOT NULL THEN DATE(STR_TO_DATE(`value`.`value`, CONCAT('%Y-%m-%d', ' 00:00:00')))
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

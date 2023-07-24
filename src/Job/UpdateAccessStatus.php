<?php declare(strict_types=1);

namespace AccessResource\Job;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_PROTECTED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class UpdateAccessStatus extends AbstractJob
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
     * The include defines the access mode constant "AccessResource::ACCESS_VIA_PROPERTY"
     * that should be used, except to avoid install/update issues.
     *
     * @var bool|string
     */
    protected $accessViaProperty = false;

    /**
     * @var string
     */
    protected $accessProperty;

    /**
     * For mode property / status, list the possible status.
     *
     * @var array
     */
    protected $accessPropertyStatuses;

    /**
     * For mode property / status, list the possible status.
     *
     * @var array
     */
    protected $accessPropertyStatusesDefault = [
        ACCESS_STATUS_FREE => 'free',
        ACCESS_STATUS_RESERVED => 'reserved',
        ACCESS_STATUS_PROTECTED => 'protected',
        ACCESS_STATUS_FORBIDDEN => 'forbidden',
    ];

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
            'reserved',
            'protected',
            'forbidden',
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

        $this->accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $this->accessProperty = $settings->get('accessresource_access_property');
        $this->accessPropertyStatuses = $settings->get('accessresource_access_property_statuses', $this->accessPropertyStatusesDefault);

        if ($this->accessViaProperty) {
            $this->updateViaProperty();
        } else {
            $this->updateViaVisibility();
        }
    }

    protected function updateViaVisibility(): bool
    {
        $sql = <<<SQL
# Set free all missing public resources.
INSERT INTO `access_status` (`id`, `status`, `start_date`, `end_date`)
SELECT `id`, "free", NULL, NULL
FROM `resource`
WHERE `is_public` = 1
ON DUPLICATE KEY UPDATE
   `id` = `resource`.`id`
;
# Set reserved/protected/forbidden all missing private resources.
INSERT INTO `access_status` (`id`, `status`, `start_date`, `end_date`)
SELECT `id`, "{$this->missingMode}", NULL, NULL
FROM `resource`
WHERE `is_public` = 0
ON DUPLICATE KEY UPDATE
   `id` = `resource`.`id`
;

SQL;

        $this->connection->executeStatement($sql);

        return true;
    }

    protected function updateViaProperty(): bool
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

        $list = array_intersect_key($this->accessPropertyStatuses, $this->accessPropertyStatusesDefault);
        if (count($list) !== 4) {
            $this->logger->err(new Message(
                'List of property statuses is incomplete, missing "%s".', // @translate
                implode('", "', array_flip(array_diff_key($this->accessPropertyStatusesDefault, $this->accessPropertyStatuses)))
            ));
            return false;
        }

        $quotedList = [];
        foreach ($list as $key => $value) {
            $quotedList[$key] = $this->connection->quote($value);
        }
        $quotedListString = implode(', ', $quotedList);

        // Insert missing access according to property values.

        $sql = <<<SQL
# Set access statuses according to values.
INSERT INTO `access_status` (`id`, `status`, `start_date`, `end_date`)
SELECT
    `resource`.`id`,
    (CASE `value`.`value`
        WHEN {$quotedList['free']} THEN 'free'
        WHEN {$quotedList['reserved']} THEN 'reserved'
        WHEN {$quotedList['protected']} THEN 'protected'
        WHEN {$quotedList['forbidden']} THEN 'forbidden'
        ELSE '{$this->missingMode}'
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
}

<?php declare(strict_types=1);

namespace AccessResource\Job;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

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

        $this->accessViaProperty = (bool) $settings->get('accessresource_property');
        if ($this->accessViaProperty) {
            $result = $this->prepareProperties(true);
            if (!$result) {
                return;
            }
        }

        $this->logger->info(new Message(
            'Starting indexation of access statuses of all resources.' // @translate
        ));

        if ($this->accessViaProperty) {
            $this->updateLevelAndEmbargoViaProperty();
        } else {
            $this->updateLevelViaVisibility();
        }

        $this->logger->info(new Message(
            'End of indexation.' // @translate
        ));
    }

    protected function updateLevelViaVisibility(): self
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
        foreach ($this->statusLevels as $key => $value) {
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
    `value` = (CASE `value`.`value`
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

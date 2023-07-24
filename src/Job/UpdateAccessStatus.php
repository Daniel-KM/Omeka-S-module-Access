<?php declare(strict_types=1);

namespace AccessResource\Job;

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
        $this->api = $services->get('ControllerPluginManager')->get('api');

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
    }
}

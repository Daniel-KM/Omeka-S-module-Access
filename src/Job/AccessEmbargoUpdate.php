<?php declare(strict_types=1);

namespace Access\Job;

use Omeka\Job\AbstractJob;

class AccessEmbargoUpdate extends AbstractJob
{
    use AccessPropertiesTrait;

    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

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
     * May be "free", "under", or "keep".
     *
     * "under" means: reserved => free, protected/forbidden => reserved.
     *
     * @var string
     */
    protected $modeLevel = 'free';

    /**
     * May be "clear" or "keep".
     *
     * @var string
     */
    protected $modeDate = 'keep';

    /**
     * @var array
     */
    protected $recursiveProcesses = [];

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

        // The check is done before job, so just a quick check here.
        $accessEmbargoFree = $settings->get('access_embargo_free');
        if (!in_array($accessEmbargoFree, ['free_clear', 'free_keep', 'under_clear', 'under_keep', 'keep_clear'], true)) {
            $this->logger->notice('Skipping process according to the option defined.'); // @translate
            return;
        }

        $this->accessViaProperty = (bool) $settings->get('access_property');
        $result = $this->prepareProperties(true);
        if (!$result) {
            // Messages already logged.
            return;
        }

        // TODO Update start embargo (for now, only end of embargo are updated).

        [$this->modeLevel, $this->modeDate] = explode('_', $accessEmbargoFree);

        // Count the total of resources with an embargo.
        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start` IS NOT NULL
                OR `embargo_end` IS NOT NULL;
            SQL;
        $totalWithEmbargo = (int) $this->connection->executeQuery($sql)->fetchOne();
        if (!$totalWithEmbargo) {
            $this->logger->notice('There is no resource with an embargo.'); // @translate
            return;
        }

        // TODO Clarify options and process for embargo start (no ones uses it anyway).

        // There are four cases.
        // 1. no embargo;
        // 2. start embargo only:
        //   - after, update the level (set restricted if mode level is to update and never change date);
        // 3. end embargo only (most common case):
        //   - before (no change),
        //   - after, the level or date should be updated;
        // 4. start and end embargo:
        //   - before (no change),
        //   - during (set restricted if mode level is to update? No: user should have set the right level),
        //   - after, the level or date should be updated. Same as 3 when date before < date after.


        // Count the total of resources with an embargo to update.
        $totalWithEmbargoToUpdate = [];

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start` IS NOT NULL
                AND `embargo_end` IS NULL
                AND NOW() >= `embargo_start`;
            SQL;
        $totalWithEmbargoToUpdate['start_after'] = in_array($this->modeLevel, ['free', 'under'], true)
            ? (int) $this->connection->executeQuery($sql)->fetchOne()
            : 0;

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start` IS NULL
                AND `embargo_end` IS NOT NULL
                AND NOW() > `embargo_end`;
            SQL;
        $totalWithEmbargoToUpdate['end_after'] = (int) $this->connection->executeQuery($sql)->fetchOne();

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start` IS NOT NULL
                AND `embargo_end` IS NOT NULL
                AND NOW() >= `embargo_start`
                AND NOW() <= `embargo_end`;
            SQL;
        $totalWithEmbargoToUpdate['both_during'] = in_array($this->modeLevel, ['free', 'under'], true)
            ? 0 // During embargo: no change (user should set the right level).
            : 0;

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start` IS NOT NULL
                AND `embargo_end` IS NOT NULL
                AND NOW() >= `embargo_start`
                AND NOW() > `embargo_end`;
            SQL;
        $totalWithEmbargoToUpdate['both_after'] = (int) $this->connection->executeQuery($sql)->fetchOne();

        $totalToUpdate = array_sum($totalWithEmbargoToUpdate);
        if (!$totalToUpdate) {
            $this->logger->notice('There is no resource with an embargo to update.'); // @translate
            return;
        }

        if ($this->modeLevel === 'free' && $this->modeDate === 'clear') {
            $this->logger->info(
                'Applying level "free" and clear embargo date for {count}/{total} resources with an embargo that ended.', // @translate
                ['count' => $totalToUpdate, 'total' => $totalWithEmbargo]
            );
        } elseif ($this->modeLevel === 'free' && $this->modeDate === 'keep') {
            $this->logger->info(
                'Applying level "free" for {count}/{total} resources with an embargo that ended.', // @translate
                ['count' => $totalToUpdate, 'total' => $totalWithEmbargo]
            );
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'clear') {
            $this->logger->info(
                'Applying level under and clear embargo date for {count}/{total} resources with an embargo that ended.', // @translate
                ['count' => $totalToUpdate, 'total' => $totalWithEmbargo]
            );
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'keep') {
            $this->logger->info(
                'Applying level under for {count}/{total} resources with an embargo that ended.', // @translate
                ['count' => $totalToUpdate, 'total' => $totalWithEmbargo]
            );
        } elseif ($this->modeLevel === 'keep' && $this->modeDate === 'clear') {
            $this->logger->info(
                'Clearing embargo dates for {count}/{total} resources with an embargo that ended.', // @translate
                ['count' => $totalToUpdate, 'total' => $totalWithEmbargo]
            );
        } else {
            return;
        }

        $this->updateAccessEmbargo();

        // When the properties are used, simply use job AccesStatusUpdate().
        if ($this->accessViaProperty) {
            // To use a sub-job avoids to create an entry in the list of jobs.
            /*
            $services = $this->getServiceLocator();
            $strategy = $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class);
            $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
            $dispatcher->dispatch(\Access\Job\AccessStatusUpdate::class, [
                'sync' => 'from_accesses_to_properties',
                'missing' => 'skip',
                'recursive' => [],
            ], $strategy);
            */
            // The args of the current job (none) and the job AccessStatusUpdate() are
            // are different. The same for job AccessStatusRecursive(), that is not
            // called anyway.
            $args = [
                'sync' => 'from_accesses_to_properties',
                'missing' => 'skip',
                'recursive' => [],
            ] + $this->job->getArgs();
            $this->job->setArgs($args);
            $subJob = new AccessStatusUpdate($this->job, $services);
            $subJob->perform();
        }
    }

    protected function updateAccessEmbargo(): void
    {
        if ($this->modeLevel === 'free' && $this->modeDate === 'clear') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level` = 'free',
                    `embargo_start` = NULL,
                    `embargo_end` = NULL
                SQL;
        } elseif ($this->modeLevel === 'free' && $this->modeDate === 'keep') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level` = 'free'
                SQL;
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'clear') {
            // "under" means: reserved -> free, protected -> reserved, forbidden -> reserved
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level` = CASE
                        WHEN `level` = 'reserved' THEN 'free'
                        WHEN `level` = 'protected' THEN 'reserved'
                        WHEN `level` = 'forbidden' THEN 'reserved'
                        ELSE `level`
                    END,
                    `embargo_start` = NULL,
                    `embargo_end` = NULL
                SQL;
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'keep') {
            // "under" means: reserved -> free, protected -> reserved, forbidden -> reserved
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level` = CASE
                        WHEN `level` = 'reserved' THEN 'free'
                        WHEN `level` = 'protected' THEN 'reserved'
                        WHEN `level` = 'forbidden' THEN 'reserved'
                        ELSE `level`
                    END
                SQL;
        } elseif ($this->modeLevel === 'keep' && $this->modeDate === 'clear') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `embargo_start` = NULL,
                    `embargo_end` = NULL
                SQL;
        } else {
            return;
        }

        $sql .= "\n" . <<<'SQL'
            WHERE
                (`embargo_start` IS NOT NULL AND `embargo_end` IS NULL AND NOW() >= `embargo_start`)
                OR (`embargo_start` IS NULL AND `embargo_end` IS NOT NULL AND NOW() > `embargo_end`)
                OR (`embargo_start` IS NOT NULL AND `embargo_end` IS NOT NULL AND NOW() >= `embargo_start` AND NOW() > `embargo_end`);
            SQL;

        $this->connection->transactional(function (\Doctrine\DBAL\Connection $connection) use ($sql) {
            $this->connection->executeStatement($sql);
        });
    }
}

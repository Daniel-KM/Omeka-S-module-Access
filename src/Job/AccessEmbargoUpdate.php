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
    protected $propagateProcesses = [];

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
        $this->modeLevel = $settings->get('access_embargo_ended_level', 'free');
        $this->modeDate = $settings->get('access_embargo_ended_date', 'keep');
        // Skip if both level and date are set to 'keep' (no update needed).
        if ($this->modeLevel === 'keep' && $this->modeDate === 'keep') {
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

        // Count the total of resources with an embargo.
        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start_set` IS NOT NULL
                OR `embargo_end_set` IS NOT NULL;
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
            WHERE `embargo_start_set` IS NOT NULL
                AND `embargo_end_set` IS NULL
                AND NOW() >= `embargo_start_set`;
            SQL;
        $totalWithEmbargoToUpdate['start_after'] = in_array($this->modeLevel, ['free', 'under'], true)
            ? (int) $this->connection->executeQuery($sql)->fetchOne()
            : 0;

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start_set` IS NULL
                AND `embargo_end_set` IS NOT NULL
                AND NOW() > `embargo_end_set`;
            SQL;
        $totalWithEmbargoToUpdate['end_after'] = (int) $this->connection->executeQuery($sql)->fetchOne();

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start_set` IS NOT NULL
                AND `embargo_end_set` IS NOT NULL
                AND NOW() >= `embargo_start_set`
                AND NOW() <= `embargo_end_set`;
            SQL;
        $totalWithEmbargoToUpdate['both_during'] = in_array($this->modeLevel, ['free', 'under'], true)
            ? 0 // During embargo: no change (user should set the right level).
            : 0;

        $sql = <<<'SQL'
            SELECT COUNT(`id`)
            FROM `access_status`
            WHERE `embargo_start_set` IS NOT NULL
                AND `embargo_end_set` IS NOT NULL
                AND NOW() >= `embargo_start_set`
                AND NOW() > `embargo_end_set`;
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

        // Transition the "set" level and clear/keep the "set" embargo of the
        // resources whose embargo ended.
        $this->updateAccessEmbargo();

        // In property-storage mode, the property values are the source of
        // truth, so the transitioned level and the cleared embargo must be
        // written back into them, else the next resync would revert the
        // transition. Update the value table directly for the affected rows.
        if ($this->accessViaProperty) {
            $this->updateAccessEmbargoProperties();
        }

        // Materialize the effective columns from the updated "set" columns and
        // the hierarchy, so facets and file gating reflect the transition.
        /** @var \Access\Stdlib\AccessCascade $cascade */
        $cascade = $services->get(\Access\Stdlib\AccessCascade::class);
        $cascade->recomputeAll();
    }

    /**
     * Write back the transitioned level and cleared embargo into the property
     * values, for the resources whose embargo just ended. Property mode only.
     *
     * The level value is mapped through access_property_levels. When the date
     * mode is "clear", the embargo property values are removed.
     */
    protected function updateAccessEmbargoProperties(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $levels = ['free' => 'free', 'reserved' => 'reserved', 'protected' => 'protected', 'forbidden' => 'forbidden'];
        $labels = array_intersect_key(array_replace($levels, $settings->get('access_property_levels', [])), $levels);

        // Rewrite the level property value from the (already transitioned)
        // level_set of every access_status row that carries the level property.
        if ($this->propertyLevelId) {
            $cases = '';
            $params = ['prop' => $this->propertyLevelId];
            $i = 0;
            foreach ($labels as $level => $label) {
                $cases .= " WHEN :lvl$i THEN :lab$i";
                $params["lvl$i"] = $level;
                $params["lab$i"] = (string) $label;
                ++$i;
            }
            $this->connection->executeStatement(
                <<<SQL
                UPDATE `value`
                JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
                SET `value`.`value` = CASE `access_status`.`level_set`$cases ELSE `value`.`value` END
                WHERE `value`.`property_id` = :prop
                SQL,
                $params
            );
        }

        // When the date mode clears the embargo, remove the embargo property
        // values of the rows whose set embargo was cleared (now null).
        if ($this->modeDate === 'clear') {
            $propertyIds = array_values(array_filter([$this->propertyEmbargoStartId, $this->propertyEmbargoEndId]));
            if ($propertyIds) {
                $this->connection->executeStatement(
                    <<<'SQL'
                    DELETE `value`
                    FROM `value`
                    JOIN `access_status` ON `access_status`.`id` = `value`.`resource_id`
                    WHERE `value`.`property_id` IN (:props)
                        AND `access_status`.`embargo_start_set` IS NULL
                        AND `access_status`.`embargo_end_set` IS NULL
                    SQL,
                    ['props' => $propertyIds],
                    ['props' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                );
            }
        }
    }

    protected function updateAccessEmbargo(): void
    {
        if ($this->modeLevel === 'free' && $this->modeDate === 'clear') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level_set` = 'free',
                    `embargo_start_set` = NULL,
                    `embargo_end_set` = NULL
                SQL;
        } elseif ($this->modeLevel === 'free' && $this->modeDate === 'keep') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level_set` = 'free'
                SQL;
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'clear') {
            // "under" means: reserved -> free, protected -> reserved, forbidden -> reserved
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level_set` = CASE
                        WHEN `level_set` = 'reserved' THEN 'free'
                        WHEN `level_set` = 'protected' THEN 'reserved'
                        WHEN `level_set` = 'forbidden' THEN 'reserved'
                        ELSE `level_set`
                    END,
                    `embargo_start_set` = NULL,
                    `embargo_end_set` = NULL
                SQL;
        } elseif ($this->modeLevel === 'under' && $this->modeDate === 'keep') {
            // "under" means: reserved -> free, protected -> reserved, forbidden -> reserved
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `level_set` = CASE
                        WHEN `level_set` = 'reserved' THEN 'free'
                        WHEN `level_set` = 'protected' THEN 'reserved'
                        WHEN `level_set` = 'forbidden' THEN 'reserved'
                        ELSE `level_set`
                    END
                SQL;
        } elseif ($this->modeLevel === 'keep' && $this->modeDate === 'clear') {
            $sql = <<<'SQL'
                UPDATE `access_status`
                SET `embargo_start_set` = NULL,
                    `embargo_end_set` = NULL
                SQL;
        } else {
            return;
        }

        $sql .= "\n" . <<<'SQL'
            WHERE
                (`embargo_start_set` IS NOT NULL AND `embargo_end_set` IS NULL AND NOW() >= `embargo_start_set`)
                OR (`embargo_start_set` IS NULL AND `embargo_end_set` IS NOT NULL AND NOW() > `embargo_end_set`)
                OR (`embargo_start_set` IS NOT NULL AND `embargo_end_set` IS NOT NULL AND NOW() >= `embargo_start_set` AND NOW() > `embargo_end_set`);
            SQL;

        $this->connection->transactional(function (\Doctrine\DBAL\Connection $connection) use ($sql) {
            $this->connection->executeStatement($sql);
        });
    }
}

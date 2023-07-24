<?php declare(strict_types=1);

namespace AccessResource\Job;

use const AccessResource\PROPERTY_EMBARGO_START;
use const AccessResource\PROPERTY_EMBARGO_END;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * @todo Take care of the current status (property or AccessStatus)?
 */
class UpdateVisibilityForEmbargo extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();

        /** @var \Laminas\Log\Logger $logger */
        $logger = $services->get('Omeka\Logger');

        /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
        $api = $services->get('ControllerPluginManager')->get('api');

        $startPropertyId = (int) $api->searchOne('properties', ['term' => PROPERTY_EMBARGO_START], ['returnScalar' => 'id'])->getContent();
        $endPropertyId = (int) $api->searchOne('properties', ['term' => PROPERTY_EMBARGO_END], ['returnScalar' => 'id'])->getContent();
        if (!$startPropertyId) {
            $logger->warn(new Message(
                'The property "%s" for start embargo is missing', // @translate
                PROPERTY_EMBARGO_START
            ));
            return;
        }
        if (!$endPropertyId) {
            $logger->warn(new Message(
                'The property "%s" for end embargo is missing', // @translate
                PROPERTY_EMBARGO_START
            ));
            return;
        }

        // The entity manager is used because it is not possible to search
        // resources currently.

        // These two connections are not the same.
        // $connection = $services->get('Omeka\EntityManager')->getConnection();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // Quick check.
        $sql = <<<SQL
SELECT COUNT(`id`) FROM `value` WHERE `property_id` = :property_id LIMIT 1;
SQL;
        $hasStart = $connection->executeQuery($sql, ['property_id' => PROPERTY_EMBARGO_START], ['property_id' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchOne();
        $hasEnd = $connection->executeQuery($sql, ['property_id' => PROPERTY_EMBARGO_END], ['property_id' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchOne();
        if (!$hasStart && !$hasEnd) {
            // No need to log.
            return;
        }

        // TODO Check if comparaison is working for date without time.
        $now = date('Y-m-d\TH:i:s');
        $bind = [
            'start_id' => $startPropertyId,
            'end_id' => $endPropertyId,
            'now' => $now,
        ];

        // The cases are numerous, so only update when the metadata are logical.

        // Set private resources with a start date greater than now and no end.
        if ($hasStart) {
            $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = :start_id
SET `resource`.`is_public` = 0
WHERE `resource`.`is_public` = 1
    AND `value`.`value` >= :now
    AND `resource`.`id` NOT IN (
        SELECT `res`.`id`
        FROM `resource` AS res
        INNER JOIN `value` AS val
            ON `val`.`resource_id` = `res`.`id`
            AND `val`.`property_id` = :end_id
        WHERE `res`.`is_public` = 1
            AND `val`.`value` >= :now
    )
;
SQL;
            $total = $connection->executeStatement($sql, $bind);
            if ($total) {
                $logger->notice(new Message(
                    'A total of %d resources have been marked private for start embargo.', // @translate
                    $total
                ));
            }
        }

        // Set public resources with an end date greater than now and no start.
        if ($hasEnd) {
            $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = :end_id
SET `resource`.`is_public` = 1
WHERE `resource`.`is_public` = 0
    AND `value`.`value` < :now
    AND `resource`.`id` NOT IN (
        SELECT `res`.`id`
        FROM `resource` AS res
        INNER JOIN `value` AS val
            ON `val`.`resource_id` = `res`.`id`
            AND `val`.`property_id` = :start_id
        WHERE `res`.`is_public` = 0
            AND `val`.`value` < :now
    )
;
SQL;
            $total = $connection->executeStatement($sql, $bind);
            if ($total) {
                $logger->notice(new Message(
                    'A total of %d resources have been marked public for end of embargo.', // @translate
                    $total
                ));
            }
        }

        if (!$hasStart || !$hasEnd) {
            return;
        }

        // Set private all resources with a start date greater than now and a
        // end date lower than now.
        // TODO Improve the query to make private value between start and end of an embargo.
        $sql = <<<SQL
UPDATE `resource`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` IN (:start_id, :end_id)
SET `resource`.`is_public` = 0
WHERE `resource`.`is_public` = 1
    AND `resource`.`id` IN (
        SELECT `res1`.`id`
        FROM `resource` AS res1
        INNER JOIN `value` AS val1
            ON `val1`.`resource_id` = `res1`.`id`
            AND `val1`.`property_id` = :start_id
        WHERE `res1`.`is_public` = 1
            AND `val1`.`value` >= :now
    )
    AND `resource`.`id` IN (
        SELECT `res2`.`id`
        FROM `resource` AS res2
        INNER JOIN `value` AS val2
            ON `val2`.`resource_id` = `res2`.`id`
            AND `val2`.`property_id` = :end_id
        WHERE `res2`.`is_public` = 1
            AND `val2`.`value` < :now
    )
;
SQL;
        $total = $connection->executeStatement($sql, $bind);
        if ($total) {
            $logger->notice(new Message(
                'A total of %d resources have been marked private for start/end of embargo.', // @translate
                $total
            ));
        }
    }
}

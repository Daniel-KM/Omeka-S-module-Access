<?php declare(strict_types=1);

namespace Access\Job;

use Access\Stdlib\AccessCascade;
use Omeka\Job\AbstractJob;

/**
 * Rebuild the effective access columns (level, embargo) of every resource from
 * the own columns and the hierarchy item set > item > media.
 *
 * Used by the 3.4.45 upgrade to materialize the cascade for the first time, and
 * available afterwards from the config form to repair the effective columns
 * after a bulk import or any direct database edit that bypassed the resource
 * save events.
 *
 * The recompute is set-based SQL (three ordered passes), so it does not load
 * entities and stays fast even on large bases.
 */
class AccessStatusRebuild extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('access/rebuild_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        /** @var \Access\Stdlib\AccessCascade $cascade */
        $cascade = $services->get(AccessCascade::class);
        $settings = $services->get('Omeka\Settings');
        $connection = $services->get('Omeka\Connection');

        // Snapshot the current effective level, to report afterwards how many
        // resources become more or less restrictive. On the 3.4.45 upgrade,
        // this reveals for instance the free files inside a reserved item set
        // that now inherit its level.
        $connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS `access_level_before`');
        $connection->executeStatement(
            'CREATE TEMPORARY TABLE `access_level_before` (INDEX `idx_id` (`id`))'
            . ' AS SELECT `id`, `level` FROM `access_status`'
        );

        // Optional reset: neutralize the admin decision of whole resource
        // types, to switch an install to a "by collection" logic (reset items
        // and media) or a "by document" logic (reset item sets). In property
        // mode, also delete the corresponding property values so the reset is
        // not reverted on the next save.
        $reset = array_values(array_intersect(
            ['item_sets', 'items', 'media'],
            (array) $this->getArg('reset', [])
        ));

        if ($reset) {
            $cascade->resetSetColumns($reset);
            $logger->info(
                'Reset the access status of: {types}.', // @translate
                ['types' => implode(', ', $reset)]
            );
            if ($settings->get('access_property')) {
                $easyMeta = $services->get('Common\EasyMeta');
                $propertyIds = array_values(array_filter(array_map(
                    fn ($key) => $easyMeta->propertyId($settings->get($key)),
                    ['access_property_level', 'access_property_embargo_start', 'access_property_embargo_end']
                )));
                $cascade->clearPropertyValues($reset, $propertyIds);
            }
        } elseif ($settings->get('access_property')) {
            // In property-storage mode, resync the "set" columns from the
            // property values first, so a bulk edit of the properties that
            // bypassed the resource save events is taken into account.
            $easyMeta = $services->get('Common\EasyMeta');
            $levelPropertyId = $easyMeta->propertyId($settings->get('access_property_level'));
            if ($levelPropertyId) {
                // access_property_levels maps the canonical level to its label
                // used as the property value; invert it to value => level.
                $levels = ['free' => 'free', 'reserved' => 'reserved', 'protected' => 'protected', 'forbidden' => 'forbidden'];
                $labels = array_intersect_key(array_replace($levels, $settings->get('access_property_levels', [])), $levels);
                $valueToLevel = [];
                foreach ($labels as $level => $label) {
                    $valueToLevel[(string) $label] = $level;
                }
                $cascade->resyncSetFromProperties(
                    $levelPropertyId,
                    $valueToLevel,
                    $easyMeta->propertyId($settings->get('access_property_embargo_start')),
                    $easyMeta->propertyId($settings->get('access_property_embargo_end'))
                );
                $logger->info(
                    'Resynced the set access columns from the property values.' // @translate
                );
            }
        }

        $cascade->recomputeAll();

        // Report how the effective access levels moved. A file that becomes
        // more restrictive is no longer downloadable by visitors who could
        // reach it before (e.g. a free file inside a reserved item set); the
        // reset task can switch the base to a by-document logic if needed.
        $rankBefore = "FIELD(`b`.`level`, 'free', 'reserved', 'protected', 'forbidden')";
        $rankAfter = "FIELD(`access_status`.`level`, 'free', 'reserved', 'protected', 'forbidden')";
        $counts = $connection->executeQuery(
            <<<SQL
            SELECT
                SUM($rankAfter > $rankBefore) AS more_restrictive,
                SUM($rankAfter < $rankBefore) AS less_restrictive
            FROM `access_status`
            JOIN `access_level_before` `b` ON `b`.`id` = `access_status`.`id`
            SQL
        )->fetchAssociative();
        $connection->executeStatement('DROP TEMPORARY TABLE IF EXISTS `access_level_before`');

        $moreRestrictive = (int) ($counts['more_restrictive'] ?? 0);
        $lessRestrictive = (int) ($counts['less_restrictive'] ?? 0);

        if ($moreRestrictive) {
            $logger->warn(
                '{count} resources now have a more restrictive effective access level (e.g. free files inside a reserved item set that now inherit its level). Their files are no longer downloadable by visitors who could reach them before. Use the "reset item sets" task to switch to a by-document logic if this is not wanted.', // @translate
                ['count' => $moreRestrictive]
            );
        }
        if ($lessRestrictive) {
            $logger->notice(
                '{count} resources now have a less restrictive effective access level.', // @translate
                ['count' => $lessRestrictive]
            );
        }

        $logger->info(
            'End of rebuild of the effective access levels.' // @translate
        );
    }
}

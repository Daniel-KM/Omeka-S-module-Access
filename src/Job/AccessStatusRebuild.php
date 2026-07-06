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

        $logger->info(
            'Rebuilding the effective access levels of all resources.' // @translate
        );

        // In property-storage mode, resync the "set" columns from the property
        // values first, so a bulk edit of the properties that bypassed the
        // resource save events is taken into account.
        if ($settings->get('access_property')) {
            $api = $services->get('Omeka\ApiManager');
            $levelTerm = $settings->get('access_property_level');
            $levelProperty = $levelTerm
                ? $api->searchOne('properties', ['term' => $levelTerm])->getContent()
                : null;
            if ($levelProperty) {
                // access_property_levels maps the canonical level to its label
                // used as the property value; invert it to value => level.
                $levels = ['free' => 'free', 'reserved' => 'reserved', 'protected' => 'protected', 'forbidden' => 'forbidden'];
                $labels = array_intersect_key(array_replace($levels, $settings->get('access_property_levels', [])), $levels);
                $valueToLevel = [];
                foreach ($labels as $level => $label) {
                    $valueToLevel[(string) $label] = $level;
                }
                $embargoStartTerm = $settings->get('access_property_embargo_start');
                $embargoEndTerm = $settings->get('access_property_embargo_end');
                $embargoStartProperty = $embargoStartTerm
                    ? $api->searchOne('properties', ['term' => $embargoStartTerm])->getContent()
                    : null;
                $embargoEndProperty = $embargoEndTerm
                    ? $api->searchOne('properties', ['term' => $embargoEndTerm])->getContent()
                    : null;
                $cascade->resyncSetFromProperties(
                    $levelProperty->id(),
                    $valueToLevel,
                    $embargoStartProperty ? $embargoStartProperty->id() : null,
                    $embargoEndProperty ? $embargoEndProperty->id() : null
                );
                $logger->info(
                    'Resynced the set access columns from the property values.' // @translate
                );
            }
        }

        $cascade->recomputeAll();

        $logger->info(
            'End of rebuild of the effective access levels.' // @translate
        );
    }
}

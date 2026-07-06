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

        $logger->info(
            'Rebuilding the effective access levels of all resources.' // @translate
        );

        $cascade->recomputeAll();

        $logger->info(
            'End of rebuild of the effective access levels.' // @translate
        );
    }
}

<?php declare(strict_types=1);

namespace Access\Job;

use Access\Stdlib\AccessAudit;
use Omeka\Job\AbstractJob;

/**
 * Audit the management mode of the access statuses and their inconsistencies.
 *
 * The audit is read-only. The report is written in the logs and stored in the
 * setting "access_audit" for the tab Audit of the config form.
 */
class AccessStatusAudit extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');
        $translator = $services->get('MvcTranslator');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('access/audit_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        $audit = new AccessAudit(
            $services->get('Omeka\Connection'),
            (bool) $settings->get('access_embargo_cascade')
        );
        $result = $audit->audit();

        $settings->set('access_audit', [
            'date' => (new \DateTime())->format('Y-m-d H:i:s'),
            'job_id' => $this->job->getId(),
        ] + $result);

        $logger->notice(AccessAudit::MODES[$result['mode']]);
        $logger->info(
            'Restricted levels set on item sets: {item_sets}; items: {items}; medias: {media}.', // @translate
            $result['decisions']
        );

        $rows = AccessAudit::rows($result['findings']);
        foreach ($rows as $row) {
            $logger->warn(AccessAudit::FINDINGS[$row['finding']], [
                'type' => $row['type'] ? $translator->translate(AccessAudit::TYPE_LABELS[$row['type']]) : '',
                'count' => $row['count'],
                'ids' => implode(', #', $row['ids']),
            ]);
        }

        if (!$rows) {
            $logger->notice(
                'No inconsistency was found in the access statuses.' // @translate
            );
        }

        $logger->info(
            'End of the access audit.' // @translate
        );
    }
}

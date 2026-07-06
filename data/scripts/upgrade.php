<?php declare(strict_types=1);

namespace Access;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$configLocal = require dirname(__DIR__, 2) . '/config/module.config.php';

/**
 * Dispatch a background job during module upgrade.
 *
 * During upgrade, module classes are not yet available to the background
 * process because the module state in the database is still "needs_upgrade".
 * This function temporarily sets the module version and active flag so the
 * spawned process can bootstrap the module, waits for the job to start, then
 * restores the original state. The Module Manager will set the real version
 * and state once upgrade() returns.
 */
$dispatchJobDuringUpgrade = function (string $jobClass, array $args = [])
    use ($services, $connection, $newVersion, $messenger): \Omeka\Entity\Job {
    $moduleId = 'Access';

    $shortClass = substr(strrchr('\\' . $jobClass, '\\'), 1);
    $jobDir = dirname(__DIR__, 2) . '/src/Job/';
    require_once $jobDir . 'AccessPropertiesTrait.php';
    require_once $jobDir . $shortClass . '.php';

    $moduleRow = $connection->executeQuery(
        'SELECT is_active FROM module WHERE id = :id',
        ['id' => $moduleId]
    )->fetchAssociative();
    $wasActive = (bool) ($moduleRow['is_active'] ?? false);

    $connection->executeStatement(
        'UPDATE module SET version = :version, is_active = 1 WHERE id = :id',
        ['version' => $newVersion, 'id' => $moduleId]
    );

    $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
    $job = $dispatcher->dispatch($jobClass, $args);

    sleep(5);

    $jobId = $job->getId();
    $status = $connection->executeQuery(
        'SELECT status FROM job WHERE id = :id',
        ['id' => $jobId]
    )->fetchOne();
    if ($status === \Omeka\Entity\Job::STATUS_STARTING) {
        $messenger->addWarning(new PsrMessage(
            'The job #{job_id} is still starting after the sleep delay. It may need to be relaunched manually.', // @translate
            ['job_id' => $jobId]
        ));
    }

    if (!$wasActive) {
        $connection->executeStatement(
            'UPDATE module SET is_active = 0 WHERE id = :id',
            ['id' => $moduleId]
        );
    }

    return $job;
};

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.86')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.86'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare((string) $oldVersion, '3.4.19', '<')) {
    // Update vocabulary via sql.
    foreach ([
        'curation:dateStart' => 'curation:start',
        'curation:dateEnd' => 'curation:end',
    ] as $propertyOld => $propertyNew) {
        $propertyOld = $api->searchOne('properties', ['term' => $propertyOld])->getContent();
        $propertyNew = $api->searchOne('properties', ['term' => $propertyNew])->getContent();
        if ($propertyOld && $propertyNew) {
            // Remove the new property, it will be created below.
            $connection->executeStatement('UPDATE `value` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOld->id(),
                'property_id_2' => $propertyNew->id(),
            ]);
            $connection->executeStatement('UPDATE `resource_template_property` SET `property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                'property_id_1' => $propertyOld->id(),
                'property_id_2' => $propertyNew->id(),
            ]);
            try {
                $connection->executeStatement('UPDATE `resource_template_property_data` SET `resource_template_property_id` = :property_id_1 WHERE `property_id` = :property_id_2;', [
                    'property_id_1' => $propertyOld->id(),
                    'property_id_2' => $propertyNew->id(),
                ]);
            } catch (\Throwable $e) {
                // Already done.
            }
            $connection->executeStatement('DELETE FROM `property` WHERE id = :property_id;', [
                'property_id' => $propertyNew->id(),
            ]);
        }
    }

    $sql = <<<'SQL'
        UPDATE `vocabulary`
        SET
            `comment` = 'Generic and common properties that are useful in Omeka for the curation of resources. The use of more common or more precise ontologies is recommended when it is possible.'
        WHERE `prefix` = 'curation'
        ;
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'start',
            `property`.`label` = 'Start',
            `property`.`comment` = 'A start related to the resource, for example the start of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateStart'
        ;
        UPDATE `property`
        JOIN `vocabulary` on `vocabulary`.`id` = `property`.`vocabulary_id`
        SET
            `property`.`local_name` = 'end',
            `property`.`label` = 'End',
            `property`.`comment` = 'A end related to the resource, for example the end of an embargo.'
        WHERE
            `vocabulary`.`prefix` = 'curation'
            AND `property`.`local_name` = 'dateEnd'
        ;
        SQL;
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $sql) {
        $connection->executeStatement($sql);
    }

    $levels = [
        'free' => 'free',
        'reserved' => 'reserved',
        'protected' => 'protected',
        'forbidden' => 'forbidden',
    ];
    $accessLevels = $settings->get('access_property_levels', []);
    $accessLevels = array_intersect_key(array_replace($levels, $accessLevels), $levels);
    $settings->set('access_property_levels', $accessLevels);

    $settings->set('access_modes', $settings->get('access_access_modes', []));
    $settings->delete('access_access_modes');
}

if (version_compare((string) $oldVersion, '3.4.21', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `access_request`
            ADD `name` VARCHAR(190) DEFAULT NULL AFTER `end`,
            ADD `message` LONGTEXT DEFAULT NULL AFTER `name`,
            ADD `fields` LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json_array)' AFTER `message`
        ;
        SQL;
    $connection->executeStatement($sql);

    $messageUserUpdated = $settings->get('access_message_user_request_updated') ?: $configLocal['access']['settings']['access_message_user_request_accepted'];
    $settings->delete('access_message_user_request_updated');
    $settings->set('access_message_user_request_accepted', $messageUserUpdated);
    $settings->set('access_message_user_request_rejected', $configLocal['access']['settings']['access_message_user_request_rejected']);
    $settings->set('access_message_visitor_subject', $configLocal['access']['settings']['access_message_visitor_subject']);
    $settings->set('access_message_visitor_request_created', $configLocal['access']['settings']['access_message_visitor_request_created']);
    $settings->set('access_message_visitor_request_accepted', $configLocal['access']['settings']['access_message_visitor_request_accepted']);
    $settings->set('access_message_visitor_request_rejected', $configLocal['access']['settings']['access_message_visitor_request_rejected']);

    $message = new PsrMessage(
        'It is now possible to add a page block to request an access.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'New messages were added to make a distinction between user/visitor and accepted/rejected. Check main settings to adapt them.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.22', '<')) {
    // Fix for upgrade of 3.4.21.
    $settings->set('access_message_user_request_accepted', $settings->get('access_message_user_request_accepted') ?: $configLocal['access']['settings']['access_message_user_request_accepted']);
    $settings->set('access_message_user_request_rejected', $settings->get('access_message_user_request_rejected') ?: $configLocal['access']['settings']['access_message_user_request_rejected']);
    $settings->set('access_message_visitor_subject', $settings->get('access_message_visitor_subject') ?: $configLocal['access']['settings']['access_message_visitor_subject']);
    $settings->set('access_message_visitor_request_created', $settings->get('access_message_visitor_request_created') ?: $configLocal['access']['settings']['access_message_visitor_request_created']);
    $settings->set('access_message_visitor_request_accepted', $settings->get('access_message_visitor_request_accepted') ?: $configLocal['access']['settings']['access_message_visitor_request_accepted']);
    $settings->set('access_message_visitor_request_rejected', $settings->get('access_message_visitor_request_rejected') ?: $configLocal['access']['settings']['access_message_visitor_request_rejected']);

    $message = new PsrMessage(
        'The module manages now http requests "Content Range" that allow to read files faster.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.25', '<')) {
    $accessModes = $settings->get('access_modes') ?: [];
    $newAccessModes = [
        'external' => 'auth_external',
        'cas' => 'auth_cas',
        'ldap' => 'auth_ldap',
        'sso' => 'auth_sso',
    ];
    foreach ($accessModes as &$accessMode) {
        if (isset($newAccessModes[$accessMode])) {
            $accessMode = $newAccessModes[$accessMode];
        }
    }
    unset($accessMode);
    $settings->set('access_modes', $accessModes);

    $message = new PsrMessage(
        'A new access mode allows to limit access to medias to users with a specific email via regex.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.27', '<')) {
    // Check themes that use "$heading" and templates in block.
    $logger = $services->get('Omeka\Logger');
    $pageRepository = $entityManager->getRepository(\Omeka\Entity\SitePage::class);

    $viewHelpers = $services->get('ViewHelperManager');
    $escape = $viewHelpers->get('escapeHtml');
    $hasBlockPlus = $this->isModuleActive('BlockPlus');

    $pagesUpdated = [];
    $pagesUpdated2 = [];
    foreach ($pageRepository->findAll() as $page) {
        $pageSlug = $page->getSlug();
        $siteSlug = $page->getSite()->getSlug();
        $position = 0;
        foreach ($page->getBlocks() as $block) {
            $block->setPosition(++$position);
            $layout = $block->getLayout();
            if ($layout !== 'accessRequest') {
                continue;
            }
            $data = $block->getData() ?: [];

            $heading = $data['heading'] ?? '';
            if (strlen($heading)) {
                $b = new \Omeka\Entity\SitePageBlock();
                $b->setPage($page);
                $b->setPosition(++$position);
                if ($hasBlockPlus) {
                    $b->setLayout('heading');
                    $b->setData([
                        'text' => $heading,
                        'level' => 2,
                    ]);
                } else {
                    $b->setLayout('html');
                    $b->setData([
                        'html' => '<h2>' . $escape($heading) . '</h2>',
                    ]);
                }
                $entityManager->persist($b);
                $block->setPosition(++$position);
                $pagesUpdated[$siteSlug][$pageSlug] = $pageSlug;
            }
            unset($data['heading']);

            $template = $data['template'] ?? '';
            $layoutData = $block->getLayoutData() ?? [];
            $existingTemplateName = $layoutData['template_name'] ?? null;
            $templateName = pathinfo($template, PATHINFO_FILENAME);
            $templateCheck = 'sso-login-link';
            if ($templateName
                && $templateName !== $templateCheck
                && (!$existingTemplateName || $existingTemplateName === $templateCheck)
            ) {
                $layoutData['template_name'] = $templateName;
                $pagesUpdated2[$siteSlug][$pageSlug] = $pageSlug;
            }
            unset($data['template']);

            $block->setData($data);
            $block->setLayoutData($layoutData);
        }
    }

    $entityManager->flush();

    if ($pagesUpdated) {
        $result = array_map('array_values', $pagesUpdated);
        $message = new PsrMessage(
            'The settings "heading" was removed from block Access request. New blocks "Heading" or "Html" were prepended to all blocks that had a filled heading. You may check pages for styles: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage(), $message->getContext());
    }

    if ($pagesUpdated2) {
        $result = array_map('array_values', $pagesUpdated2);
        $message = new PsrMessage(
            'The setting "template" was moved to the new block layout settings available since Omeka S v4.1. You may check pages for styles: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage(), $message->getContext());

        $message = new PsrMessage(
            'The template files for the block Access request should be moved from "view/common/block-layout" to "view/common/block-template" in your themes. You may check your themes for pages: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addError($message);
        $logger->warn($message->getMessage(), $message->getContext());
    }

    // Update existing ip reserved.
    $this->prepareIpItemSets();
    $settings->delete('access_ip_reserved');

    $message = new PsrMessage(
        'It is now possible to define accesses by sso idps and item sets.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to define excluded item sets for accesses by ip and sso idp.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.31', '<')) {
    $message = new PsrMessage(
        'When no time is set to an date of end of embargo, the check is now done against 23:59:59 and no more 00:00:00.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.32', '<')) {
    // New default property.
    if (!$settings->get('access_property') || !$settings->get('access_property_level')) {
        $settings->set('access_property_level', 'dcterms:accessRights');
    }
}

if (version_compare((string) $oldVersion, '3.4.35', '<')) {
    $sql = <<<'SQL'
        ALTER TABLE `access_status`
        ADD INDEX `IDX_898BF02E41CE64D3` (`embargo_start`),
        ADD INDEX `IDX_898BF02E197EE67A` (`embargo_end`);
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Throwable $e) {
        // Already done.
    }

    $settings->set('access_embargo_free', 'keep_keep');

    $message = new PsrMessage(
        'A new option allows to update the status level and to remove the embargo metadata when it ends. Default option is to keep them.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A job is run once a day to update accesses when embargo ends.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare((string) $oldVersion, '3.4.36', '<')) {
    // Migrate from access_embargo_free to the two new settings.
    // Only if the process is not already done.
    if (!$settings->get('access_embargo_ended_level')) {
        $accessEmbargoFree = $settings->get('access_embargo_free', 'free_keep');
        $parts = explode('_', $accessEmbargoFree);
        $modeLevel = $parts[0] ?? 'free';
        $modeDate = $parts[1] ?? 'keep';
        // Validate and set defaults if invalid.
        if (!in_array($modeLevel, ['free', 'under', 'keep'], true)) {
            $modeLevel = 'free';
        }
        if (!in_array($modeDate, ['clear', 'keep'], true)) {
            $modeDate = 'keep';
        }
        $settings->set('access_embargo_ended_level', $modeLevel);
        $settings->set('access_embargo_ended_date', $modeDate);
        $settings->delete('access_embargo_free');
    }

    $searchFields = $settings->get('advancedsearch_search_fields');
    if ($searchFields !== null) {
        $searchFields[] = 'common/advanced-search/access';
        $settings->set('advancedsearch_search_fields', $searchFields);
    }

    $message = new PsrMessage(
        'The three criteria to access a media are now fully independant: visibility of item and media should be public; access level should be free (or reserved for authorized users); resource should not be under embargo.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.38', '<')) {
    // Detect protected types from existing .htaccess rule and save as setting.
    $htaccessPath = OMEKA_PATH . '/.htaccess';
    $htaccess = @file_get_contents($htaccessPath);
    $detectedTypes = [];
    $knownTypes = ['original', 'large', 'medium', 'square'];
    if ($htaccess !== false) {
        $marker = '# Module Access: protect files.';
        if (strpos($htaccess, $marker) !== false
            && preg_match('/' . preg_quote($marker, '/') . '\s*\n\s*RewriteRule\s+"\^files\/\(([^)]+)\)\//', $htaccess, $matches)
        ) {
            $detectedTypes = explode('|', $matches[1]);
        } else {
            // Legacy rules: grouped format files/(original|large)/ or individual files/original/.
            if (preg_match_all('/^\s*RewriteRule\s+.*files\/\(([^)]+)\).*\/access\/files\//m', $htaccess, $matches)) {
                foreach ($matches[1] as $group) {
                    $detectedTypes = array_merge($detectedTypes, explode('|', $group));
                }
            }
            if (preg_match_all('/^\s*RewriteRule\s+["\^]*files\/(' . implode('|', $knownTypes) . ')\/.*\/access\/files\//m', $htaccess, $matches)) {
                $detectedTypes = array_merge($detectedTypes, $matches[1]);
            }
            $detectedTypes = array_values(array_unique(array_intersect($detectedTypes, $knownTypes)));
        }
    }
    if (empty($detectedTypes)) {
        $detectedTypes = ['original', 'large'];
    }
    $settings->set('access_htaccess_types', $detectedTypes);
    if (strpos($htaccess, $marker) !== false) {
        $message = new PsrMessage(
            'The .htaccess rule is managed by the module and protects file types: {types}.', // @translate
            ['types' => implode(', ', $detectedTypes)]
        );
        $messenger->addSuccess($message);
    } elseif ($htaccess !== false && preg_match('/RewriteRule.*\/access\/files\//', $htaccess)) {
        $message = new PsrMessage(
            'A legacy .htaccess rule was detected for file types: {types}. It is recommended to open the module configuration form and save it to convert the rule to the managed format.', // @translate
            ['types' => implode(', ', $detectedTypes)]
        );
        $messenger->addWarning($message);
    } else {
        $message = new PsrMessage(
            'The .htaccess rule is not yet set. Open the module configuration form to manage it. Default file types: {types}.', // @translate
            ['types' => implode(', ', $detectedTypes)]
        );
        $messenger->addWarning($message);
    }
}

if (version_compare((string) $oldVersion, '3.4.39', '<')) {
    // The old Statistics module (before the split into Statistics + Analytics)
    // managed .htaccess rules. Since version 3.4.12, this part has moved to
    // module Analytics. Block upgrade if old Statistics is active.
    $moduleManager = $services->get('Omeka\ModuleManager');
    $statisticsModule = $moduleManager->getModule('Statistics');
    if ($statisticsModule
        && $statisticsModule->getState() === \Omeka\Module\Manager::STATE_ACTIVE
        && version_compare($statisticsModule->getIni('version') ?? '', '3.4.12', '<')
    ) {
        $message = new PsrMessage(
            'The module {module} should be upgraded to version {version} or later.', // @translate
            ['module' => 'Statistics', 'version' => '3.4.12']
        );
        throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
    }
}

if (version_compare((string) $oldVersion, '3.4.40', '<')) {
    // A bug in previous versions caused the daily embargo job to delete all
    // access property values (curation:access, start, end) without re-creating
    // them. Re-sync now synchronously.
    $accessViaProperty = (bool) $settings->get('access_property');
    if ($accessViaProperty) {
        $dispatchJobDuringUpgrade(\Access\Job\AccessStatusUpdate::class, [
            'sync' => 'from_accesses_to_properties',
            'missing' => 'skip',
            'recursive' => [],
        ]);
        $message = new PsrMessage(
            'A previous bug may have removed access property values. A background job has been launched to restore them from indexes.' // @translate
        );
        $messenger->addSuccess($message);
    }
}

// Check for old module.
if (!empty($config['accessresource'])) {
    $message = new PsrMessage(
        'The key "accessresource" in the file config/local.config.php at the root of Omeka can be removed.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.41', '<')) {
    $siteIds = $api->search('sites', [], ['returnScalar' => 'id'])->getContent();
    foreach ($siteIds as $siteId) {
        $siteSettings->setTargetId($siteId);
        $siteSettings->set('access_placement', ['after/items', 'after/media', 'after/item_sets', 'browse/items', 'browse/item_sets']);
    }
}

if (version_compare($oldVersion, '3.4.42', '<')) {
    $message = new PsrMessage(
        'The module configuration now warns when a reverse proxy is detected but no trusted proxy is configured, when private/loopback IPs are listed in access rules, or when the current request IP matches a rule. Review the Access config page to check these warnings.' // @translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'A new sidebar allows to search quickly in access requests when individual mode is enabled.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The access status of a resource is now displayed in the right sidebar of admin resource.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A new authorization endpoint is available at url /access/authorize for external services to check access to a media. It can be used by any third party media or image server like Cantaloupe.' // @translate
    );
    $messenger->addSuccess($message);

    // The boolean option access_ip_proxy has been replaced by the presence of
    // entries in access_ip_proxy_trusted. A non-empty list enables the proxy
    // mode; an empty list disables it.
    $settings->delete('access_ip_proxy');

    $message = new PsrMessage(
        'Proxy header handling has been hardened against spoofing. The option "Use proxy to get client IP" has been replaced by "Trusted proxies", filled with the internal IPs of the reverse proxy. It enables proxy mode, so X-Forwarded-For and X-Real-IP are only honored when the request comes from a listed IP. Without it, every visitor is seen with REMOTE_ADDR.' // @translate
    );
    $messenger->addWarning($message);

    $this->autoDetectTrustedProxy();
}

if (version_compare($oldVersion, '3.4.44', '<')) {
    // Force skip_if_set on upgrade to prevent silent data loss: the legacy
    // "overwrite" behavior copies parent levels onto every child, so an item or
    // a media that was manually set to a stricter or specific level would be
    // silently rewritten on the first "Apply recursive" save. Admins who relied
    // on bulk propagation can opt back into "overwrite" or "max_restrictive"
    // explicitly in the Tasks tab.
    $settings->set('access_propagation_mode', 'skip_if_set');
    $settings->set('access_propagation_embargo', false);

    $message = new PsrMessage(
        'A new option "propagation mode" was added and forced to "skip if set" for this upgraded install: previous saves with "Apply recursive" would silently overwrite per-item and per-media levels. If your workflow relies on bulk propagation from an item set to its items and medias, switch to "max restrictive" or "overwrite" in the Tasks tab of the module config, but be aware that "overwrite" writes the parent level onto every child unconditionally.' // @translate
    );
    $messenger->addWarning($message);

    $message = new PsrMessage(
        'A new option allows to separate propagation of the embargo dates and the status level. Embargo dates are per-resource and time-bound and checked independantly from the level.' // @translate
    );
    $messenger->addWarning($message);


    $message = new PsrMessage(
        'The status "protected" has been clarified: a "reserved" file is granted to global bypass modes, while a "protected" file requires an approved individual access request and no global bypass applies.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.45', '<')) {
    // Split the access status into an "own" level (the admin decision, before
    // inheritance) and an effective level (the materialized cascade item set >
    // item > media). The effective columns keep their names so every existing
    // reader (file gating, facets in Reference / Solr, custom SQL) keeps
    // working and now reads the cascaded value. The new "_set" columns hold the
    // admin decision and feed the recompute of the effective columns.
    $sql = <<<'SQL'
        ALTER TABLE `access_status`
            ADD COLUMN `level_set` VARCHAR(15) NOT NULL DEFAULT 'free' AFTER `level`,
            ADD COLUMN `embargo_start_set` DATETIME DEFAULT NULL AFTER `embargo_start`,
            ADD COLUMN `embargo_end_set` DATETIME DEFAULT NULL AFTER `embargo_end`
        SQL;
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        // Columns already added (re-run of a partial upgrade).
    }

    // The current level and embargo columns hold the admin decision, because no
    // runtime cascade existed before: copy them into the "own" columns.
    $connection->executeStatement(<<<'SQL'
        UPDATE `access_status`
        SET `level_set` = `level`,
            `embargo_start_set` = `embargo_start`,
            `embargo_end_set` = `embargo_end`
        SQL);

    // Embargo cascade is opt-in and independent of the level. Off by default:
    // an embargo stays per-resource unless the admin turns the cascade on.
    $settings->set('access_embargo_cascade', false);

    // Recompute the effective columns from the own columns and the item set /
    // item / media hierarchy. Dispatched as a background job because the
    // recompute walks the whole hierarchy and may be heavy on large bases.
    $job = $dispatchJobDuringUpgrade(\Access\Job\AccessStatusRebuild::class);

    $message = new PsrMessage(
        'An access level set on an item set or an item now applies automatically to its items and medias, without any propagation step to run.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'A background job #{job_id} was started to apply the access levels to all resources. Watch its progress in the Jobs list.', // @translate
        ['job_id' => $job->getId()]
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'The old "propagation mode" options have been removed: they are no longer needed. A new option "Cascade embargo dates" (off by default) makes an embargo set on an item set or an item apply to its items and medias too, like the access level. The embargo is still checked separately.' // @translate
    );
    $messenger->addWarning($message);
}

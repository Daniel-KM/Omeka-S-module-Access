<?php declare(strict_types=1);

namespace Access;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $oldVersion
 * @var string $newVersion
 */

/**
 * @var \Doctrine\DBAL\Connection $connection
 * @var array $config
 * @var \Omeka\Mvc\Controller\Plugin\Api $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

$config = $services->get('Config');
$configLocal = require dirname(__DIR__, 2) . '/config/module.config.php';

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.65'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
            } catch (\Exception $e) {
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
    $connection->executeStatement($sql);

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

// Check for old module.
if (!empty($config['accessresource'])) {
    $message = new PsrMessage(
        'The key "accessresource" in the file config/local.config.php at the root of Omeka can be removed.' // @translate
    );
    $messenger->addWarning($message);
}

<?php declare(strict_types=1);

namespace Access;

use Omeka\Stdlib\Message;

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
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $config = require dirname(__DIR__, 2) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');

$config = $services->get('Config');

// The module AccessResource may be absent: only its tables and its settings
// are required.

// Convert the settings.

// This option was not upgraded from an old server, so update it manually.
$useProperty = (bool) $settings->get('accessresource_property');

// The settings are converted via the settings services, not via sql, so their
// cache is kept up to date for the next steps of the upgrade.
$rows = $connection->fetchAllAssociative(
    'SELECT `id`, `value` FROM `setting` WHERE `id` LIKE "accessresource#_%" ESCAPE "#"'
);
foreach ($rows as $row) {
    $settings->set('access_' . substr($row['id'], 15), json_decode((string) $row['value'], true));
    $settings->delete($row['id']);
}

$settings->set('access_property', $useProperty);

// The message was empty after the upgrade of AccessResource to 3.4.17.
if (!$settings->get('access_message_access_text')) {
    $configModule = require dirname(__DIR__, 2) . '/config/module.config.php';
    $settings->set('access_message_access_text', $configModule['access']['settings']['access_message_access_text']);
}

// Convert the site settings.

$siteSettings = $services->get('Omeka\Settings\Site');
$rows = $connection->fetchAllAssociative(
    'SELECT `id`, `site_id`, `value` FROM `site_setting` WHERE `id` LIKE "accessresource#_%" ESCAPE "#"'
);
foreach ($rows as $row) {
    $siteSettings->set('access_' . substr($row['id'], 15), json_decode((string) $row['value'], true), (int) $row['site_id']);
    $siteSettings->delete($row['id'], (int) $row['site_id']);
}

// Remove original data and module.

try {
    $connection->executeStatement('DELETE FROM `module` WHERE `id` = "AccessResource"');
} catch (\Throwable $e) {
    $messenger->addError($e->getMessage());
}

$message = new Message(
    'The module "%1$s" was upgraded by module "%2$s" and uninstalled.', // @translate
    'Access Resource', 'Access'
);
$messenger->addWarning($message);

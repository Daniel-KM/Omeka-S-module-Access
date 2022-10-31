<?php declare(strict_types=1);

namespace AccessResource;

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

if (version_compare((string) $oldVersion, '3.3.0.6', '<')) {
    $sqls = <<<'SQL'
ALTER TABLE `access_log`
    CHANGE `user_id` `user_id` INT DEFAULT NULL,
    CHANGE `action` `action` VARCHAR(190) NOT NULL,
    CHANGE `type` `type` VARCHAR(190) NOT NULL,
    CHANGE `date` `date` DATETIME DEFAULT NULL;
ALTER TABLE `access_resource`
    CHANGE `user_id` `user_id` INT DEFAULT NULL,
    CHANGE `token` `token` VARCHAR(190) DEFAULT NULL,
    CHANGE `start_date` `start_date` DATETIME DEFAULT NULL,
    CHANGE `end_date` `end_date` DATETIME DEFAULT NULL,
    CHANGE `modified` `modified` DATETIME DEFAULT NULL;
ALTER TABLE `access_request`
    CHANGE `status` `status` VARCHAR(190) DEFAULT 'new' NOT NULL,
    CHANGE `modified` `modified` DATETIME DEFAULT NULL;
SQL;
    foreach (array_filter(explode(";\n", $sqls)) as $sql) {
        $connection->executeStatement($sql);
    }
}

if (version_compare((string) $oldVersion, '3.3.0.7', '<')) {
    $settings->set('accessresource_ip_sites', []);
    $settings->set('accessresource_ip_reserved', ['sites' => [], 'ranges' => []]);
}

if (version_compare((string) $oldVersion, '3.3.0.11', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';
}

if (version_compare((string) $oldVersion, '3.3.0.12', '<')) {
    $settings->set('accessresource_ip_item_sets', $settings->get('accessresource_ip_sites', []) ?: []);
    $settings->delete('accessresource_ip_sites', []);

    $reservedIps = $settings->get('accessresource_ip_reserved') ?: [];
    foreach (array_keys($reservedIps) as $ip) {
        unset($reservedIps[$ip]['site'], $reservedIps[$ip]['ranges']);
        $reservedIps[$ip]['reserved'] = [];
    }
    $settings->set('accessresource_ip_reserved', $reservedIps);

    $message = new Message(
        'The reserved access by ip by site has been replaced by a reserved acces by ip by item set. You should check and resave your config if needed and eventually create item sets.' // @translate
    );
    $messenger->addWarning($message);
}

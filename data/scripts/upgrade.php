<?php declare(strict_types=1);

namespace AccessResource;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */
// $entityManager = $services->get('Omeka\EntityManager');
$connection = $services->get('Omeka\Connection');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
// $config = require dirname(__DIR__, 2) . '/config/module.config.php';

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

if (version_compare((string) $oldVersion, '3.3.0.10', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';
}

if (version_compare((string) $oldVersion, '3.3.0.11', '<')) {
    require_once __DIR__ . '/upgrade_vocabulary.php';
}

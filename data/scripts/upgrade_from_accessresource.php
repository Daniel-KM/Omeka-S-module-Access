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

$moduleManager = $services->get('Omeka\ModuleManager');
$accessResourceModule = $moduleManager->getModule('AccessResource');

if (!$accessResourceModule) {
    return;
}

// Convert the settings.

$sql = <<<'SQL'
REPLACE INTO `setting` (`id`, `value`)
SELECT
    REPLACE(`setting`.`id`, "accessresource_", "access_"),
    `setting`.`value`
FROM `setting`
WHERE
    `setting`.`id` LIKE "accessresource#_%" ESCAPE "#";
SQL;
$connection->executeStatement($sql);

// Convert the site settings.

$sql = <<<'SQL'
REPLACE INTO `site_setting` (`id`, `site_id`, `value`)
SELECT
    REPLACE(`site_setting`.`id`, "accessresource_", "access_"),
    `site_setting`.`site_id`,
    `site_setting`.`value`
FROM `site_setting`
WHERE
    `site_setting`.`id` LIKE "accessresource#_%" ESCAPE "#";
SQL;
$connection->executeStatement($sql);

// Remove original data and module.

$sql = <<<'SQL'
# Uninstall data of the module Access Resource.

DELETE FROM `setting`
WHERE
    `setting`.`id` LIKE "accessresource#_%" ESCAPE "#";

DELETE FROM `site_setting`
WHERE
    `site_setting`.`id` LIKE "accessresource#_%" ESCAPE "#";

DELETE FROM `module` WHERE `id` = "AccessResource";
SQL;

$sqls = array_filter(array_map('trim', explode(";\n", $sql)));
foreach ($sqls as $sql) {
    try {
        $connection->executeStatement($sql);
    } catch (\Exception $e) {
        $messenger->addError($e->getMessage());
    }
}

$message = new Message(
    'The module "%1$s" was upgraded by module "%2$s" and uninstalled.', // @translate
    'Access Resource', 'Access'
);
$messenger->addWarning($message);

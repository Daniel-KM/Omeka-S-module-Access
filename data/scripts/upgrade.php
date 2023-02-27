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

if (version_compare((string) $oldVersion, '3.4.0.13', '<')) {
    // Prepare the table.
    $sqls = <<<'SQL'
CREATE TABLE `access_reserved` (
    `id` INT NOT NULL,
    `start_date` DATETIME DEFAULT NULL,
    `end_date` DATETIME DEFAULT NULL,
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE `access_reserved` ADD CONSTRAINT FK_EDF218C689329D25 FOREIGN KEY(`id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;
SQL;
    foreach (array_filter(explode(";\n", $sqls)) as $sql) {
        $connection->executeStatement($sql);
    }

    // Fill the table with all private resources that have the restricted value.
    $reservedPropertyId = (int) $api->searchOne('properties', ['term' => 'curation:reserved'])->getContent()->id();
    $sql = <<<SQL
INSERT INTO `access_reserved` (id)
SELECT DISTINCT `resource`.`id`
FROM `resource`
JOIN `value` ON `value`.`resource_id` = `resource`.`id` AND `value`.`property_id` = $reservedPropertyId
WHERE
    `resource`.`is_public` = 0
;
SQL;
    $connection->executeStatement($sql);

    // Invalid dates are skipped.
    $dateStartPropertyId = (int) $api->searchOne('properties', ['term' => 'curation:dateStart'])->getContent()->id();
    $sql = <<<SQL
UPDATE `access_reserved`
INNER JOIN `resource` ON `resource`.`id` = `access_reserved`.`id`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = $dateStartPropertyId
    AND `value`.`type` = "numeric:timestamp"
    AND `value`.`value` != ""
SET `start_date` =
    CASE
        WHEN LENGTH(`value`.`value`) = 19 THEN `value`.`value`
        WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, " 00:00:00")
        ELSE NULL
    END
;
SQL;
    $connection->executeStatement($sql);

    $dateEndPropertyId = (int) $api->searchOne('properties', ['term' => 'curation:dateEnd'])->getContent()->id();
    $sql = <<<SQL
UPDATE `access_reserved`
INNER JOIN `resource` ON `resource`.`id` = `access_reserved`.`id`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = $dateEndPropertyId
    AND `value`.`type` = "numeric:timestamp"
    AND `value`.`value` != ""
SET `end_date` =
    CASE
        WHEN LENGTH(`value`.`value`) = 19 THEN `value`.`value`
        WHEN LENGTH(`value`.`value`) = 10 THEN CONCAT(`value`.`value`, " 00:00:00")
        ELSE NULL
    END
;
SQL;
    $connection->executeStatement($sql);

    $message = new Message(
        'A new option allows to set the access restricted status via a simple radio in resource form instead of a property.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'Warning: to use only the button is the default behavior. To keep the old behavior via the property, you must set the param access_via_property to true in the file config/local.config.php.' // @translate
    );
    $messenger->addWarning($message);

    $message = new Message(
        'Warning: if you use specific properties (not "curation:reserved", "curation:dateStart", "curation:dateEnd"), you need to update the table "access_reserved".' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.0.15', '<')) {
    $message = new Message(
        'A new option allows to set the access restricted status via a a property with three status (free, reserved, forbidden).' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'Warning: if you use the old mode "property", you must update your local.config.php with status "reserved".' // @translate
    );
    $messenger->addWarning($message);
}

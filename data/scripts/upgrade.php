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
    // Prepare the table access_reserved.
    $sqls = <<<'SQL'
CREATE TABLE `access_reserved` (
    `id` INT NOT NULL,
    `start_date` DATETIME DEFAULT NULL,
    `end_date` DATETIME DEFAULT NULL,
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE `access_reserved` ADD CONSTRAINT FK_EDF218C689329D25 FOREIGN KEY(`id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;
SQL;
    try {
        foreach (array_filter(explode(";\n", $sqls)) as $sql) {
            $connection->executeStatement($sql);
        }
        $tableExisted = false;
    } catch (\Exception $e) {
        $tableExisted = true;
    }

    if (!$tableExisted) {
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
        $startPropertyId = (int) $api->searchOne('properties', ['term' => 'curation:dateStart'])->getContent()->id()
            ?: (int) $api->searchOne('properties', ['term' => 'curation:start'])->getContent()->id();
        $sql = <<<SQL
UPDATE `access_reserved`
INNER JOIN `resource` ON `resource`.`id` = `access_reserved`.`id`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = $startPropertyId
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

        $endPropertyId = (int) $api->searchOne('properties', ['term' => 'curation:dateEnd'])->getContent()->id()
            ?: (int) $api->searchOne('properties', ['term' => 'curation:end'])->getContent()->id();
        $sql = <<<SQL
UPDATE `access_reserved`
INNER JOIN `resource` ON `resource`.`id` = `access_reserved`.`id`
INNER JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
    AND `value`.`property_id` = $endPropertyId
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
    }

    $message = new Message(
        'A new option allows to set the access restricted status via a simple radio in resource form instead of a property.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'Warning: to use only the button is the default behavior. To keep the old behavior via the property, you must set the param level_via_property to true in the file config/local.config.php.' // @translate
    );
    $messenger->addWarning($message);

    $message = new Message(
        'Warning: if you use specific properties (not "curation:reserved", "curation:start", "curation:end"), you need to update the table "access_reserved".' // @translate
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

    $message = new Message(
        'Warning: if you want to hide records and not only media files, the current version may have break some configs. Keep old releases (until 3.3.0.10) in that case for now.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare((string) $oldVersion, '3.4.16', '<')) {
    $skipMessage = true;
    require_once __DIR__ . '/upgrade_vocabulary.php';
}

if (version_compare((string) $oldVersion, '3.4.17', '<')) {
    // Update vocabulary via sql.
    $sql = <<<SQL
UPDATE `vocabulary`
SET
    `comment` = 'Curation of resources for Omeka.'
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

    // This is a major upgrade.
    $sqls = <<<'SQL'
# Update table logs.
ALTER TABLE `access_log`
    DROP FOREIGN KEY FK_EF7F3510A76ED395;

DROP INDEX IDX_EF7F3510A76ED395 ON `access_log`;

ALTER TABLE `access_log`
    CHANGE `user_id` `user_id` INT NOT NULL,
    CHANGE `record_id` `access_id` INT NOT NULL,
    CHANGE `type` `access_type` VARCHAR(7) NOT NULL,
    CHANGE `action` `action` VARCHAR(31) NOT NULL AFTER `access_type`;

# Prepare the table access_status.
CREATE TABLE `access_status` (
    `id` INT NOT NULL,
    `level` VARCHAR(15) NOT NULL,
    `embargo_start` DATETIME DEFAULT NULL,
    `embargo_end` DATETIME DEFAULT NULL,
    INDEX IDX_898BF02E9AEACC13 (`level`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `access_status` ADD CONSTRAINT FK_898BF02EBF396750 FOREIGN KEY (`id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;

# Fill the table with data in private/public.
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `id`, "free", NULL, NULL
FROM `resource`
WHERE `is_public` = 1;

INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `id`, "forbidden", NULL, NULL
FROM `resource`
WHERE `is_public` = 0;

# Update the table for reserved resources.
UPDATE `access_status`
INNER JOIN `access_reserved` ON `access_reserved`.`id` = `access_status`.`id`
SET
    `access_status`.`level` = "reserved",
    `access_status`.`embargo_start` = `access_reserved`.`start_date`,
    `access_status`.`embargo_end` = `access_reserved`.`end_date`
;
# Remove table "access_reserved".
DROP TABLE IF EXISTS `access_reserved`;

# Update table requests and resources.
# The resources are now attached to requests, so use email to do a simple upgrade.
ALTER TABLE `access_request`
    DROP FOREIGN KEY FK_F3B2558A89329D25,
    DROP INDEX IDX_F3B2558A89329D25,
    CHANGE `resource_id` `resource_id` INT DEFAULT NULL AFTER `id`,
    CHANGE `user_id` `user_id` INT DEFAULT NULL AFTER `resource_id`,
    ADD `email` VARCHAR(190) DEFAULT NULL AFTER `user_id`,
    ADD `token` VARCHAR(32) DEFAULT NULL AFTER `email`,
    CHANGE `status` `status` VARCHAR(8) DEFAULT 'new' NOT NULL AFTER `token`,
    ADD `recursive` TINYINT(1) DEFAULT '0' NOT NULL AFTER `status`,
    ADD `enabled` TINYINT(1) DEFAULT '0' NOT NULL AFTER `recursive`,
    ADD `temporal` TINYINT(1) DEFAULT '0' NOT NULL AFTER `enabled`,
    ADD `start` DATETIME DEFAULT NULL AFTER `temporal`,
    ADD `end` DATETIME DEFAULT NULL AFTER `start`,
    CHANGE `created` `created` DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL AFTER `end`,
    CHANGE `modified` `modified` DATETIME DEFAULT NULL AFTER `created`,
    ADD INDEX IDX_F3B2558A5F37A13B (`token`)
;
# Convert existing access resources into requests, using email to keep id during process.
INSERT INTO `access_request` (`email`, `resource_id`, `user_id`, `token`, `status`, `enabled`, `temporal`, `start`, `end`, `created`, `modified`)
SELECT `id`, `resource_id`, `user_id`, `token`, "new", `enabled`, `temporal`, `start_date`, `end_date`, `created`, `modified`
FROM `access_resource`
;
ALTER TABLE `access_resource`
    DROP PRIMARY KEY,
    DROP FOREIGN KEY FK_D1843527A76ED395,
    DROP FOREIGN KEY FK_D184352789329D25,
    DROP INDEX IDX_D1843527A76ED395,
    DROP INDEX IDX_D184352789329D25,
    CHANGE `id` `id` INT NULL FIRST,
    ADD `access_request_id` INT AFTER `id`,
    CHANGE `resource_id` `resource_id` INT DEFAULT NULL AFTER `access_request_id`,
    DROP `user_id`,
    DROP `token`,
    DROP `enabled`,
    DROP `temporal`,
    DROP `start_date`,
    DROP `end_date`,
    DROP `created`,
    DROP `modified`
;
INSERT INTO `access_resource` (`access_request_id`, `resource_id`)
SELECT `id`, `resource_id`
FROM `access_request`
WHERE `access_request`.`email` IS NULL
    OR `access_request`.`email` LIKE "%@%"
;
UPDATE `access_resource`
INNER JOIN `access_request` ON `access_request`.`email` = `access_resource`.`id`
SET
    `access_request_id` = `access_request`.`id`
;
UPDATE `access_request`
INNER JOIN `access_resource` ON `access_resource`.`id` = `access_request`.`email`
SET
    `email` = NULL
;
ALTER TABLE `access_request`
    DROP `resource_id`
;
ALTER TABLE `access_resource`
    DROP `id`,
    CHANGE `access_request_id` `access_request_id` INT NOT NULL,
    CHANGE `resource_id` `resource_id` INT NOT NULL AFTER `access_request_id`,
    ADD INDEX IDX_D184352768402024 (`access_request_id`),
    ADD INDEX IDX_D184352789329D25 (`resource_id`),
    ADD PRIMARY KEY(`access_request_id`, `resource_id`),
    ADD CONSTRAINT FK_D184352768402024 FOREIGN KEY (`access_request_id`) REFERENCES `access_request` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT FK_D184352789329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE CASCADE
;
SQL;

    foreach (explode(";\n", $sqls) as $sql) {
        try {
            $connection->executeStatement($sql);
        } catch (\Exception $e) {
            $message = new Message(
                'An error occurred during upgrade of the database: %s', // @translate
                $e->getMessage()
            );
            $messenger->addError($message);
            return;
        }
    }

    $settings->set('accessresource_full', false);

    $settings->set('accessresource_access_modes', [empty($config['accessresource']['access_mode']) ? 'guest' : $config['accessresource']['access_mode']]);
    $settings->delete('accessresource_access_mode');

    // For the upgrade, status is set to false in all cases. The admin should rerun the
    $oldViaProperty = $settings->get('accessresource_level_via_property', false);
    $settings->set('accessresource_property', false);
    $settings->set('accessresource_property_level', 'curation:access');
    $settings->set('accessresource_property_levels', $settings->get('accessresource_access_via_property_statuses', [
        'free' => 'free',
        'reserved' => 'reserved',
        'protected' => 'protected',
        'forbidden' => 'forbidden',
    ]));
    $settings->delete('accessresource_access_via_property_statuses');

    $settings->set('accessresource_property_embargo_start', 'curation:start');
    $settings->set('accessresource_property_embargo_end', 'curation:end');

    $settings->delete('accessresource_access_apply');
    $settings->delete('accessresource_embargo_auto_update');

    $settings->set('accessresource_message_access_text', $config['accessresource_message_access_text']);

    $message = new Message(
        'The structure of the module has been rewritten to make status easier to manage and quicker to check.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'The access status is now independant of the visibility status (public/private).' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'A new checkbox in resource form and a new api argument have been added to allow to recursively set a status.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'The config has been simplified and fully manageable in admin board. The modes "guest", "ip" and "user" are no more exclusive.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'The property mode "presence" of a value has been removed for now. The value "free", "reserved", "protected" or "forbidden" (or the specified ones) should be set.' // @translate
    );
    $messenger->addWarning($message);

    if ($oldViaProperty) {
        $message = new Message(
            'The property mode has been unset during upgrade. Update config, save it, then submit the job in config form.' // @translate
        );
        $messenger->addWarning($message);
    }

    $message = new Message(
        'The embargo can be set via a specific setting in resource advanced tab or via a property as before. The option should be set in config.' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'The properties for embargo has not been updated during upgrade. Update config, save it, then submit the job in config form.' // @translate
    );
    $messenger->addWarning($message);

    $message = new Message(
        'Warning: you may have to check the config and the resources statuses with the new config. If wrong, save config, then run the job.' // @translate
    );
    $messenger->addWarning($message);

    /*
    $message = new Message(
        'A new status has been added, "protected", in order to clarify the distinction between record and content: "reserved" gives access to the record to all visitors, but not to the file; "protected" does not give any acess unless allowed.' // @translate
    );
    $messenger->addSuccess($message);
    */
}

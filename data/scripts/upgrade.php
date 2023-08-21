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

if (version_compare((string) $oldVersion, '3.4.19', '<')) {
    // Update vocabulary via sql.
    $sql = <<<SQL
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

if (!empty($config['accessresource'])) {
    $message = new Message(
        'The key "accessresource" in the file config/local.config.php at the root of Omeka can be removed.' // @translate
    );
    $messenger->addWarning($message);
}

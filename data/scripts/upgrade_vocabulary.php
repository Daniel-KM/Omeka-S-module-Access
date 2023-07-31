<?php declare(strict_types=1);

namespace Access;

use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var bool $skipMessage
 */
$services = $this->getServiceLocator();

if (!method_exists($this, 'getInstallResources')) {
    throw new ModuleCannotInstallException((string) new Message(
        'This module requires module %1$s version %2$s or greater.', // @translate
        'Generic',
        '3.4.43'
    ));
}

$installResources = $this->getInstallResources();

$module = __NAMESPACE__;
$filepath = dirname(__DIR__, 2) . '/data/vocabularies/curation.json';
$data = file_get_contents($filepath);
$data = json_decode($data, true);
$installResources->createOrUpdateVocabulary($data, $module);

if (!empty($skipMessage)) {
    $messenger = $services->get('ControllerPluginManager')->get('messenger');
    $message = new Message(
        'The vocabulary "%s" was updated successfully.', // @translate
        pathinfo($filepath, PATHINFO_FILENAME)
    );
    $messenger->addSuccess($message);
}

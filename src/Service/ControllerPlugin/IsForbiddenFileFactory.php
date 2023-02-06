<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\IsForbiddenFile;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsForbiddenFileFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Controller plugins are set, because in rare case, the controller is
        // not set.
        // TODO Check if to prepare controller plugins is still needed in Omeka S v4, included some background tasks.
        $plugins = $services->get('ControllerPluginManager');
        return new IsForbiddenFile(
            $services->get('Omeka\EntityManager'),
            $plugins->get('accessStatus'),
            $plugins->get('isUnderEmbargo'),
            $plugins->get('userIsAllowed'),
            $plugins->get('params'),
            $plugins->get('api'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\AuthenticationService')->getIdentity()
        );
    }
}

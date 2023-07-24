<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\IsAllowedMediaContent;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsAllowedMediaContentFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Controller plugins are set, because in rare case, the controller is
        // not set.
        // TODO Check if to prepare controller plugins is still needed in Omeka S v4, included some background tasks.
        $plugins = $services->get('ControllerPluginManager');
        return new IsAllowedMediaContent(
            $services->get('Omeka\EntityManager'),
            $plugins->get('userIsAllowed'),
            $plugins->get('accessStatus'),
            $plugins->get('isExternalUser'),
            $services->get('Omeka\AuthenticationService')->getIdentity(),
            $services->get('Omeka\Settings'),
            $plugins->get('params')
        );
    }
}

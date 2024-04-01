<?php declare(strict_types=1);

namespace Access\Service\ControllerPlugin;

use Access\Mvc\Controller\Plugin\IsExternalUser;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsExternalUserFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new IsExternalUser(
            $services->get('Omeka\AuthenticationService'),
            $plugins->has('isCasUser') ? $plugins->get('isCasUser') : null,
            $plugins->has('isLdapUser') ? $plugins->get('isLdapUser') : null,
            $plugins->has('isSsoUser') ? $plugins->get('isSsoUser') : null
        );
    }
}

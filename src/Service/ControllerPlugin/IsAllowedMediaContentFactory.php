<?php declare(strict_types=1);

namespace Access\Service\ControllerPlugin;

use Access\Mvc\Controller\Plugin\IsAllowedMediaContent;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsAllowedMediaContentFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Controller plugins are set, because in rare case, the controller is
        // not set.
        // TODO Check if to prepare controller plugins is still needed in Omeka S v4, included some background tasks.
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $plugins = $services->get('ControllerPluginManager');
        return new IsAllowedMediaContent(
            $plugins->get('accessStatus'),
            $services->get('Omeka\EntityManager'),
            $plugins->has('isCasUser') ? $plugins->get('isCasUser') : null,
            $plugins->get('isExternalUser'),
            $plugins->has('isLdapUser') ? $plugins->get('isLdapUser') : null,
            $plugins->has('isSsoUser') ? $plugins->get('isSsoUser') : null,
            $plugins->get('params'),
            $services->get('Omeka\Settings'),
            $user,
            $plugins->get('userIsAllowed'),
            $user ? $services->get('Omeka\Settings\User') : null
        );
    }
}

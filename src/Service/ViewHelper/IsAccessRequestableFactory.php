<?php declare(strict_types=1);

namespace Access\Service\ViewHelper;

use Access\View\Helper\IsAccessRequestable;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsAccessRequestableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $fullAccess = (bool) $services->get('Omeka\Settings')->get('access_full');
        if ($fullAccess) {
            $translator = $this->getServiceLocator()->get('MvcTranslator');
            throw new \Exception($translator->translate('Full access is not supported currently')); // @translate
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();

        return new IsAccessRequestable(
            $user,
            $user && $services->get('Omeka\Acl')->isAllowed($user, \Omeka\Entity\Resource::class, 'view-all'),
            $services->get('ControllerPluginManager')->get('accessLevel'),
            $services->get('ControllerPluginManager')->get('isAllowedMediaContent'),
            $services->get('Omeka\EntityManager')
        );
    }
}

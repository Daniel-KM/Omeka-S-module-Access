<?php declare(strict_types=1);

namespace Access\Service\ViewHelper;

use Access\View\Helper\AccessStatus;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessStatusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AccessStatus(
            $services->get('ControllerPluginManager')->get('accessStatus')
        );
    }
}

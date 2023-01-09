<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\IsReservedResource;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsReservedResourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new IsReservedResource(
            $services->get('ControllerPluginManager')->get('isReservedResource')
        );
    }
}

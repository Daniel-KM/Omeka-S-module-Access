<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\IsUnderEmbargo;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsUnderEmbargoFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new IsUnderEmbargo(
            $services->get('ControllerPluginManager')->get('isUnderEmbargo')
        );
    }
}

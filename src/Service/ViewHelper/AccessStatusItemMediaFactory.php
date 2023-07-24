<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\AccessStatusItemMedia;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessStatusItemMediaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AccessStatusItemMedia(
            $services->get('ControllerPluginManager')->get('accessStatusItemMedia')
        );
    }
}

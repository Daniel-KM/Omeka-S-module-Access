<?php declare(strict_types=1);

namespace Access\Service\ViewHelper;

use Access\View\Helper\AccessibleMedia;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessibleMediaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AccessibleMedia(
            $services->get('ControllerPluginManager')->get('isAllowedMediaContent')
        );
    }
}

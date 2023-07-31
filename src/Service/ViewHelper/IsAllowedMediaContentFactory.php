<?php declare(strict_types=1);

namespace Access\Service\ViewHelper;

use Access\View\Helper\IsAllowedMediaContent;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsAllowedMediaContentFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new isAllowedMediaContent(
            $services->get('ControllerPluginManager')->get('isAllowedMediaContent')
        );
    }
}

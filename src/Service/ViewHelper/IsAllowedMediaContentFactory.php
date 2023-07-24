<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\IsAllowedMediaContent;
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

<?php declare(strict_types=1);

namespace AccessResource\Service\ViewHelper;

use AccessResource\View\Helper\RequestResourceAccessForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class RequestResourceAccessFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new RequestResourceAccessForm(
            $services->get('ControllerPluginManager')->get('api'),
            $services->get('FormElementManager')

        );
    }
}

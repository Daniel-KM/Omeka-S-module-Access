<?php declare(strict_types=1);
namespace AccessResource\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $class = $requestedName . 'Controller';
        $controller = new $class();
        $controller->setServiceLocator($serviceLocator);
        return $controller;
    }
}

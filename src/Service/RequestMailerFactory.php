<?php
namespace AccessResource\Service;

use Interop\Container\ContainerInterface;
use AccessResource\Mail\RequestMailer;
use Zend\ServiceManager\Factory\FactoryInterface;

class RequestMailerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $requestMailer = new RequestMailer($serviceLocator);
        return $requestMailer;
    }
}

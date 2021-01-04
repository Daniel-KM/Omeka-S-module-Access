<?php
namespace AccessResource\Service;

use AccessResource\Mail\RequestMailer;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class RequestMailerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $requestMailer = new RequestMailer($serviceLocator);
        return $requestMailer;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Service\Controller;

use AccessResource\Controller\Admin\LogController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class LogControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new LogController(
            $services->get('Omeka\EntityManager')
        );
    }
}

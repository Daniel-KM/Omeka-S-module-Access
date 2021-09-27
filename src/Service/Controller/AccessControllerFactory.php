<?php declare(strict_types=1);

namespace AccessResource\Service\Controller;

use AccessResource\Controller\Admin\AccessController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AccessController(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\DataTypeManager')
        );
    }
}

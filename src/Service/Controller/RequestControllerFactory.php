<?php declare(strict_types=1);

namespace Access\Service\Controller;

use Access\Controller\Admin\RequestController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class RequestControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new RequestController(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\DataTypeManager')
        );
    }
}

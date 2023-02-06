<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\IsUnderEmbargo;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsUnderEmbargoFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new IsUnderEmbargo(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\EntityManager'),
            $services->get('EventManager'),
            $services->get('Omeka\ApiAdapterManager')
        );
    }
}
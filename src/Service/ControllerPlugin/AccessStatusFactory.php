<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\AccessStatus;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessStatusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AccessStatus(
            $services->get('Omeka\EntityManager')
        );
    }
}

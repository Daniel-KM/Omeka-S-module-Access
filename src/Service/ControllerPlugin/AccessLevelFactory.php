<?php declare(strict_types=1);

namespace Access\Service\ControllerPlugin;

use Access\Mvc\Controller\Plugin\AccessLevel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessLevelFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AccessLevel(
            $services->get('Omeka\EntityManager')
        );
    }
}

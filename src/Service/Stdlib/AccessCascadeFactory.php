<?php declare(strict_types=1);

namespace Access\Service\Stdlib;

use Access\Stdlib\AccessCascade;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessCascadeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new AccessCascade(
            $services->get('Omeka\Connection'),
            (bool) $services->get('Omeka\Settings')->get('access_embargo_cascade', false)
        );
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Service\Property;

use const AccessResource\PROPERTY_RESERVED;

use Interop\Container\ContainerInterface;

class ReservedAccess
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        /** @var \Omeka\Api\Adapter\MediaAdapter $adapter */
        $adapter = $services->get('Omeka\ApiAdapterManager')->get('media');
        return $adapter->getPropertyByTerm(PROPERTY_RESERVED);
    }
}

<?php
namespace AccessResource\Service\Property;

use Interop\Container\ContainerInterface;

class ReservedAccess
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        /** @var \Omeka\Api\Adapter\MediaAdapter $adapter */
        $adapter = $services->get('Omeka\ApiAdapterManager')->get('media');
        return $adapter->getPropertyByTerm('curation:reservedAccess');
    }
}

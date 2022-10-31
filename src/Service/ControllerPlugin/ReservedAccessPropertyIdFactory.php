<?php declare(strict_types=1);

namespace AccessResource\Service\ControllerPlugin;

use const AccessResource\PROPERTY_RESERVED;

use AccessResource\Mvc\Controller\Plugin\ReservedAccessPropertyId;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ReservedAccessPropertyIdFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        list($prefix, $localName) = explode(':', PROPERTY_RESERVED);
        $sql = <<<SQL
SELECT property.id
FROM property
JOIN vocabulary ON vocabulary.id = property.vocabulary_id
WHERE vocabulary.prefix = :prefix
    AND property.local_name = :localName;
SQL;
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $propertyId = (int) $connection->executeQuery($sql, ['prefix' => $prefix, 'localName' => $localName])->fetchOne();
        return new ReservedAccessPropertyId($propertyId);
    }
}

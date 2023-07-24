<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

// use AccessResource\Entity\AccessReserved;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Entity\Resource;

class AccessStatus extends AbstractPlugin
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Get the access status of a resource (free, reserved or forbidden).
     */
    public function __invoke($resource): string
    {
        if (!$resource) {
            return ACCESS_STATUS_FREE;
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
            $resourceId = (int) $resource->getId();
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
            $resourceId = (int) $resource->id();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
            $resource = $this->entityManager->find(Resource::class, $resourceId);
            if (!$resource || $resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
            $resource = $this->entityManager->find(Resource::class, $resourceId);
            if (!$resource || $resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
        } else {
            return ACCESS_STATUS_FORBIDDEN;
        }

        // @todo Why entity manager getReference() or find() always output a AccessReserved even if does not exists? One-to-one on id? The construct?
        // $accessReserved = $this->entityManager->getReference(AccessReserved::class, $resourceId);
        // $accessReserved = $this->entityManager->find(AccessReserved::class, $resourceId);
        $sql = <<<'SQL'
SELECT id FROM access_reserved WHERE id = :resource_id;
SQL;
        $accessReserved = $this->entityManager->getConnection()
            ->executeQuery($sql, ['resource_id' => $resourceId], ['resource_id' => \Doctrine\DBAL\ParameterType::INTEGER])
            ->fetchOne();

        return $accessReserved
            ? ACCESS_STATUS_RESERVED
            : ACCESS_STATUS_FORBIDDEN;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

use AccessResource\Entity\AccessReserved;
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
     *
     * For a boolean status, less clear (reserved or not):
     * @see \AccessResource\Mvc\Controller\Plugin\IsReservedResource
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
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
            $resource = $this->entityManager->find(Resource::class, $resourceId);
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
        } else {
            return ACCESS_STATUS_FORBIDDEN;
        }

        $accessReserved = $this->entityManager->getReference(AccessReserved::class, $resourceId);
        return $accessReserved
            ? ACCESS_STATUS_RESERVED
            : ACCESS_STATUS_FORBIDDEN;
    }
}

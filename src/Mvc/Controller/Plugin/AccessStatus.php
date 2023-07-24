<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

use AccessResource\Entity\AccessStatus as EntityAccessStatus;
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
        /** @var \AccessResource\Entity\AccessStatus $status */

        if (!$resource) {
            return ACCESS_STATUS_FREE;
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
            $status = $this->entityManager->find(EntityAccessStatus::class, (int) $resource->getId());
            return $status
                ? $status->getStatus()
                : ACCESS_STATUS_FORBIDDEN;
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            if ($resource->isPublic()) {
                return ACCESS_STATUS_FREE;
            }
            $status = $this->entityManager->find(EntityAccessStatus::class, (int) $resource->id());
            return $status
                ? $status->getStatus()
                : ACCESS_STATUS_FORBIDDEN;
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
            $status = $this->entityManager->find(EntityAccessStatus::class, $resourceId);
            if ($status) {
                return $status->getStatus();
            }
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
            $status = $this->entityManager->find(EntityAccessStatus::class, $resourceId);
            if ($status) {
                return $status->getStatus();
            }
        } else {
            return ACCESS_STATUS_FORBIDDEN;
        }

        // No status, so check resource for visibility.
        $resource = $this->entityManager->find(Resource::class, $resourceId);
        return $resource && $resource->isPublic()
            ? ACCESS_STATUS_FREE
            : ACCESS_STATUS_FORBIDDEN;
    }
}

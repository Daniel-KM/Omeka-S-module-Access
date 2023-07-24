<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use AccessResource\Entity\AccessStatus as EntityAccessStatus;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

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
     * Get access status of a resource (free, reserved, protected or forbidden).
     *
     * The access status is independant from the visibility public or private.
     * The default status is free.
     */
    public function __invoke($resource): string
    {
        if (!$resource) {
            return EntityAccessStatus::FREE;
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            $resourceId = (int) $resource->id();
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            $resourceId = (int) $resource->getId();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
        } else {
            return EntityAccessStatus::FREE;
        }
        /** @var \AccessResource\Entity\AccessStatus $status */
        $status = $this->entityManager->find(EntityAccessStatus::class, $resourceId);
        return $status
            ? $status->getStatus()
            : EntityAccessStatus::FREE;
    }
}

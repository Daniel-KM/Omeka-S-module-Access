<?php declare(strict_types=1);

namespace Access\Mvc\Controller\Plugin;

use Access\Entity\AccessStatus;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class AccessLevel extends AbstractPlugin
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
     * Get access level of a resource (free, reserved, protected or forbidden).
     *
     * The access level is independant from the visibility public or private.
     * The default level is free.
     */
    public function __invoke($resource): string
    {
        if (!$resource) {
            return AccessStatus::FREE;
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            $resourceId = (int) $resource->id();
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            $resourceId = (int) $resource->getId();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
        } else {
            return AccessStatus::FREE;
        }
        /** @var \Access\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->entityManager->find(AccessStatus::class, $resourceId);
        return $accessStatus
            ? $accessStatus->getLevel()
            : AccessStatus::FREE;
    }
}

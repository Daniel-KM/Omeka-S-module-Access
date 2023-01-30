<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use AccessResource\Entity\AccessReserved;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class IsReservedResource extends AbstractPlugin
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
     * Check if access to a resource is restricted.
     */
    public function __invoke($resource): ?bool
    {
        if (!$resource) {
            return false;
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            if ($resource->isPublic()) {
                return false;
            }
            $resourceId = (int) $resource->getId();
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            if ($resource->isPublic()) {
                return false;
            }
            $resourceId = (int) $resource->id();
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
        } else {
            return false;
        }

        // Normally, the check of "is public" is useless.

        $accessReserved = $this->entityManager->getReference(AccessReserved::class, $resourceId);
        return !empty($accessReserved);
    }
}

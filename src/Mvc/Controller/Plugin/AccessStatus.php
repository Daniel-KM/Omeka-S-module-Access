<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use AccessResource\Entity\AccessStatus as EntityAccessStatus;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\ServiceManager\ServiceLocatorInterface;

class AccessStatus extends AbstractPlugin
{
    /**
     * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
     */
    protected $services;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(ServiceLocatorInterface $services, EntityManager $entityManager)
    {
        $this->services = $services;
        $this->entityManager = $entityManager;
    }

    /**
     * Get access status entity or representation of a resource.
     *
     * @return \AccessResource\Entity\AccessStatus|\AccessResource\Api\Representation\AccessStatusRepresentation|null
     * A representation is returned if a representation is set in argument, else
     * an entity.
     */
    public function __invoke($resource, bool $asRepresentation = false)
    {
        if (!$resource) {
            return null;
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            $resourceId = (int) $resource->id();
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            $resourceId = (int) $resource->getId();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
        } else {
            return null;
        }
        $accessStatus = $this->entityManager->find(EntityAccessStatus::class, $resourceId);
        return $accessStatus && $asRepresentation
            ? new AccessStatusRepresentation($accessStatus, $this->services)
            : $accessStatus;
    }
}

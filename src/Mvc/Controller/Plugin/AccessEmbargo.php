<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use AccessResource\Entity\AccessStatus;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class AccessEmbargo extends AbstractPlugin
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
     * Get access embargo of a resource.
     *
     * @return array Associative array of start and end to DateTime or null.
     */
    public function __invoke($resource): array
    {
        $result = [
            'start' => null,
            'end' => null,
        ];

        if (!$resource) {
            return $result;
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            $resourceId = (int) $resource->id();
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            $resourceId = (int) $resource->getId();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
        } else {
            return $result;
        }

        /** @var \AccessResource\Entity\AccessStatus $status */
        $status = $this->entityManager->find(AccessStatus::class, $resourceId);
        return $status
            ? [
                'start' => $status->getEmbargoStart(),
                'end' => $status->getEmbargoEnd(),
            ]
            : $result;
    }
}
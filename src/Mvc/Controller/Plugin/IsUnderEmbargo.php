<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\PROPERTY_EMBARGO_END;
use const AccessResource\PROPERTY_EMBARGO_START;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Adapter\Manager as ApiAdapterManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Request;
use Omeka\Api\Response;

class IsUnderEmbargo extends AbstractPlugin
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\EventManager\EventManagerInterface
     */
    protected $eventManager;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $apiAdapterManager;

    public function __construct(
        Connection $connection,
        EntityManager $entityManager,
        EventManagerInterface $eventManager,
        ApiAdapterManager $apiAdapterManager
    ) {
        $this->connection = $connection;
        $this->entityManager = $entityManager;
        $this->eventManager = $eventManager;
        $this->apiAdapterManager = $apiAdapterManager;
    }

    /**
     * Check if a resource has an embargo start or end date set and check it.
     *
     * When the visibility of the resource is not up-to-date, it may be updated.
     * The update is done via a direct sql without entity manager refresh, so
     * don't process more the resource after the check.
     *
     * @return bool|null Null if the embargo dates are not set, true if resource
     * is under embargo, else false.
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource, bool $updateResource = false): ?bool
    {
        if (is_null($resource)) {
            return null;
        }

        /**
         * @var \Omeka\Api\Representation\ValueRepresentation $start
         * @var \Omeka\Api\Representation\ValueRepresentation $end
         */
        $start = $resource->value(PROPERTY_EMBARGO_START);
        $end = $resource->value(PROPERTY_EMBARGO_END);
        if (!$start && !$end) {
            return null;
        }

        // TODO Start and end dates are not checked for validity.

        $now = new DateTime('now');

        // Check end date first, because it is much more common.
        if ($end) {
            if ($end->type() === 'numeric:timestamp') {
                $endDate = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue((string) $end->value());
                if ($now < $endDate) {
                    return $updateResource ? $this->updateVisibilityForEmbargo($resource, true) : true;
                }
            } else {
                try {
                    $endDate = new DateTime((string) $end);
                    if ($now < $endDate) {
                        return $updateResource ? $this->updateVisibilityForEmbargo($resource, true) : true;
                    }
                } catch (\Exception $e) {
                    $end = null;
                }
            }
        }

        if ($start) {
            if ($start->type() === 'numeric:timestamp') {
                $startDate = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue((string) $start->value());
                if ($now >= $startDate) {
                    return $updateResource ? $this->updateVisibilityForEmbargo($resource, true) : true;
                }
            } else {
                try {
                    $startDate = new DateTime((string) $start);
                    if ($now >= $startDate) {
                        return $updateResource ? $this->updateVisibilityForEmbargo($resource, true) : true;
                    }
                } catch (\Exception $e) {
                    $start = null;
                }
            }
        }

        // Don't update when dates are invalid.
        return $updateResource && ($start || $end)
            ? $this->updateVisibilityForEmbargo($resource, false)
            : false;
    }

    protected function updateVisibilityForEmbargo(AbstractResourceEntityRepresentation $resource, bool $isUnderEmbargo): bool
    {
        // Any visitor can update visibility according to the embargo, since it
        // is an automatic process, so use a direct sql to skip rights check.
        $isPublic = $resource->isPublic();
        if ($isUnderEmbargo === $isPublic) {
            $resourceId = (int) $resource->id();
            $resourceName = $resource->resourceName();
            $this->connection->executeStatement(
                'UPDATE `resource` SET `is_public` = :is_public WHERE `id` = :id',
                ['id' => $resourceId, 'is_public' => (int) !$isUnderEmbargo],
                ['id' => \Doctrine\DBAL\ParameterType::INTEGER, 'is_public' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
            $resourceArray = $resource->jsonSerialize();
            $resourceArray['o:is_public'] = (int) !$isUnderEmbargo;
            $request = new Request('update', $resourceName);
            $request->setContent($resourceArray);
            /** @var \Omeka\Entity\Resource $updatedResource */
            $updatedResource = $this->entityManager->find(\Omeka\Entity\Resource::class, $resourceId);
            $updatedResource->setIsPublic((int) !$isUnderEmbargo);
            $response = new Response($updatedResource);
            $event = new Event(
                'api.update.post',
                $this->apiAdapterManager->get($resourceName),
                ['request' => $request, 'response' => $response]
            );
            $this->eventManager->triggerEvent($event);
        }
        return $isUnderEmbargo;
    }
}

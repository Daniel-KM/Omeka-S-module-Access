<?php declare(strict_types=1);

namespace Access\Controller;

use Doctrine\ORM\EntityManager;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Lightweight authorization endpoint for external services (Cantaloupe delegate
 * script, Traefik ForwardAuth, nginx auth_request, etc.).
 *
 * Returns 200 when the current request is allowed to access the media content,
 * 403 otherwise. Body is empty on purpose so reverse proxies can use it as a
 * pure signal.
 *
 * Accepted query parameters (any one, resolved in this order):
 * - media    : internal media id
 * - storage  : storage_id (filename without extension)
 * - filename : filename with extension (storage_id + extension)
 *
 * The request IP used for rule matching follows the existing
 * IsAllowedMediaContent logic, including the setting access_ip_proxy
 * (reads X-Forwarded-For or X-Real-IP when enabled).
 */
class AuthorizeController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\Api\Adapter\MediaAdapter
     */
    protected $mediaAdapter;

    public function __construct(
        EntityManager $entityManager,
        MediaAdapter $mediaAdapter
    ) {
        $this->entityManager = $entityManager;
        $this->mediaAdapter = $mediaAdapter;
    }

    public function authorizeAction()
    {
        $params = $this->params();
        $mediaId = (int) $params->fromQuery('media', 0);
        $storage = (string) $params->fromQuery('storage', '');
        $filename = (string) $params->fromQuery('filename', '');

        $media = null;
        if ($mediaId > 0) {
            $media = $this->mediaFromId($mediaId);
        } elseif ($storage !== '') {
            $media = $this->mediaFromStorageId($storage);
        } elseif ($filename !== '') {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $storageId = mb_strlen($extension)
                ? mb_substr($filename, 0, -mb_strlen($extension) - 1)
                : $filename;
            $media = $this->mediaFromStorageId($storageId);
        }

        if (!$media) {
            return $this->emptyResponse(Response::STATUS_CODE_404);
        }

        $allowed = (bool) $this->isAllowedMediaContent($media);
        return $this->emptyResponse($allowed
            ? Response::STATUS_CODE_200
            : Response::STATUS_CODE_403);
    }

    protected function mediaFromId(int $id): ?MediaRepresentation
    {
        /** @var \Omeka\Entity\Media $entity */
        $entity = $this->entityManager->find(\Omeka\Entity\Media::class, $id);
        return $entity ? $this->mediaAdapter->getRepresentation($entity) : null;
    }

    protected function mediaFromStorageId(string $storageId): ?MediaRepresentation
    {
        if ($storageId === '') {
            return null;
        }
        $entity = $this->entityManager
            ->getRepository(\Omeka\Entity\Media::class)
            ->findOneBy(['storageId' => $storageId]);
        return $entity ? $this->mediaAdapter->getRepresentation($entity) : null;
    }

    protected function emptyResponse(int $statusCode): Response
    {
        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $response->setContent('');
        return $response;
    }
}

<?php

namespace AccessResource\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Mvc\Exception;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\Mvc\Controller\AbstractActionController;

class AccessResourceController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    /**
     *
     * @var ArrayCollection
     */
    protected $data;

    public function __construct()
    {
        $this->data = new ArrayCollection();
    }

    /**
     * Forward to the 'play' action
     *
     * @see self::playAction()
     */
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'files';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function filesAction()
    {
        $type = $this->getStorageType();
        $file = $this->getFileName();
        if (empty($type) || empty($file)) {
            throw new Exception\NotFoundException;
        }

        //if Without admin permissions check access.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            $access = $this->getMediaAccess();
            if (!$access) {
                // TODO: load access denied image with 403 response
                throw new Exception\PermissionDeniedException;
            }
        }

        return $this->sendFile();
    }

    protected function getFileName()
    {
        $key = 'fileName';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $value = $this->params('file');

        $this->data->set($key, $value);
        return $value;
    }

    protected function getStorageType()
    {
        $key = 'storageType';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $value = $this->params('type');

        $this->data->set($key, $value);
        return $value;
    }

    /**
     * Get Media Representation.
     *
     * @return mixed|null|\Omeka\Api\Representation\MediaRepresentation
     */
    protected function getMedia()
    {
        $key = 'media';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $fileName = $this->getFileName();
        $storageId = pathinfo($fileName, PATHINFO_FILENAME);

        /** @var \Omeka\Api\Representation\MediaRepresentation $value */
        $value = $storageId
            ? $this->api()->searchOne('media', ['storage_id' => $storageId])->getContent()
            : null;

        $this->data->set($key, $value);
        return $value;
    }

    protected function getMediaAccess()
    {
        $key = 'mediaAccess';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $media = $this->getMedia();
        if (!$media) {
            return false;
        }

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $token = $this->params()->fromQuery('token');

        $mediaAccess = $media->isPublic();
        $mediaItemAccess = $media->item()->isPublic();
        $isPublic = $mediaAccess && $mediaItemAccess;
        if (!$isPublic) {
            $user = $services->get('Omeka\AuthenticationService')->getIdentity();

            $accessResource = null;
            if (!is_null($token)) {
                $accessResource = $entityManager
                    ->getRepository(\AccessResource\Entity\AccessResource::class)
                    ->findOneBy(["token" => $token]);
            } elseif (!is_null($user)) {
                $accessResource = $entityManager
                    ->getRepository(\AccessResource\Entity\AccessResource::class)
                    ->findOneBy(['resource' => $media->id()]);
            }

            // Deny for visitor without token.
            if (!$user && is_null($token) && is_null($accessResource)) {
                return false;
            }

            // Deny for guest who has not access.
            if ($user && is_null($token) && is_null($accessResource)) {
                return false;
            }

            // Deny for token with not equal id media.
            if ($token && $accessResource) {
                if ($media->id() !== $accessResource->resource()->id()) {
                    return false;
                }
            }

            // Deny if time access is before start or after end.
            if ($token && $accessResource->getTemporal()) {
                if (strtotime($accessResource->getStartDate()->format('Y-m-d H:i')) <= time()
                    || strtotime($accessResource->getEndDate()->format('Y-m-d H:i')) >= time()
                ) {
                    return false;
                }
            }

            $api = $this->api();
            $access = $api->searchOne('access_resources', [
                'resource_id' => $media->id(),
                'user_id' => $user ? $user->getId() : null,
                'enabled' => 1,
            ])->getContent();
            if (!$mediaAccess) {
                $mediaAccess = (bool) $access;
            }

            if (!$mediaItemAccess) {
                $mediaItemAccess = (bool) $api->searchOne('access_resources', [
                    'resource_id' => $media->item()->id(),
                    'user_id' => $user ? $user->getId() : null,
                    'enabled' => 1,
                ])->getContent();
            }
            $value = ($mediaAccess && $mediaItemAccess);
        } else {
            $value = true;
        }

        $this->data->set($key, $value);
        return $value;
    }

    /**
     * Get filepath.
     *
     * @return string|null Path to the file.
     */
    protected function getFilePath()
    {
        $key = 'filePath';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $media = $this->getMedia();
        if (!$media) {
            return null;
        }

        $storageType = $this->getStorageType();
        if ($storageType == 'original' && !$media->hasOriginal()) {
            return null;
        }

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $value = sprintf('%s/%s/%s', $basePath, $storageType, $media->filename());

        $this->data->set($key, $value);
        return $value;
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file.
     */
    public function sendFile()
    {
        $path = $this->getFilePath();
        $media = $this->getMedia();

        $fileName = $media->source();
        $fileSize = $media->size();
        $mediaType = $media->mediaType();

        // Try to open file.
        if (!is_readable($path)) {
            // Set 404 Not Found status code.
            $this->getResponse()->setStatusCode(404);
            return;
        }

        $dispositionMode =
            (
                strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE') ||
                strstr($_SERVER['HTTP_USER_AGENT'], 'Mozilla')
            )
            ? 'inline'
            : 'attachment';

        // Write headers.
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine(sprintf('Content-type: %s', $mediaType));
        $headers->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', $dispositionMode, $fileName));
        $headers->addHeaderLine(sprintf('Content-length: %s', $fileSize));
        // Use this to open files directly.
        $headers->addHeaderLine('Cache-control: private');

        // Send headers separately to handle large files.
        $response->sendHeaders();
        $response->setContent(readfile($path));

        // Return Response to avoid default view rendering
        return $response;
    }
}

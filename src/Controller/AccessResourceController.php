<?php

namespace AccessResource\Controller;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Mvc\Exception;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class AccessResourceController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    /**
     * @var ArrayCollection
     */
    protected $data;

    public function __construct()
    {
        $this->data = new ArrayCollection();
    }

    /**
     * Forward to the 'files' action
     *
     * @see self::filesAction()
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
        $file = $this->getFilename();
        if (empty($type) || empty($file)) {
            throw new Exception\NotFoundException;
        }

        //if Without admin permissions check access.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if (!$acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            $access = $this->getMediaAccess();
            if (!$access) {
                // Don't throw exception, because it's not really an error.
                // throw new Exception\PermissionDeniedException;
                // return $this->permissionDeniedAction();
                // Return an image instead of a 403.
                return $this->sendFakeFile();
            }
        }

        return $this->sendFile();
    }

    protected function getFilename()
    {
        $key = 'filename';
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
     * @return \Omeka\Api\Representation\MediaRepresentation|null
     */
    protected function getMedia()
    {
        $key = 'media';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $filename = $this->getFilename();
        // For compatibility with module ArchiveRepertory, don't take the
        // filename, but remove the extension.
        // $storageId = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $storageId = strlen($extension)
            ? substr($filename, 0, -strlen($extension) - 1)
            : $filename;

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
    protected function getFilepath()
    {
        $key = 'filepath';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $media = $this->getMedia();
        if (!$media) {
            return null;
        }

        $storageType = $this->getStorageType();
        if ($storageType === 'original') {
            if (!$media->hasOriginal()) {
                return null;
            }
            $filename = $media->filename();
        } elseif (!$media->hasThumbnails()) {
            return null;
        } else {
            $filename = $media->storageId() . '.jpg';
        }

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $value = sprintf('%s/%s/%s', $basePath, $storageType, $filename);
        if (!is_readable($value)) {
            return null;
        }

        $this->data->set($key, $value);
        return $value;
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file.
     */
    protected function sendFile()
    {
        $filepath = $this->getFilepath();
        if (!$filepath) {
            throw new Exception\NotFoundException('File does not exist.'); // @translate
        }

        $media = $this->getMedia();

        $filename = $media->source();
        $filesize = $media->size();
        $mediaType = $this->data->get('storageType') === 'original' ? $media->mediaType() : 'image/jpeg';

        $dispositionMode =
            (
                strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE')
                || strstr($_SERVER['HTTP_USER_AGENT'], 'Mozilla')
            )
            ? 'inline'
            : 'attachment';

        // Write headers.
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine(sprintf('Content-type: %s', $mediaType));
        $headers->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', $dispositionMode, $filename));
        $headers->addHeaderLine(sprintf('Content-length: %s', $filesize));
        // Use this to open files directly.
        $headers->addHeaderLine('Cache-control: private');

        // Send headers separately to handle large files.
        $response->sendHeaders();
        // TODO Use a redirect and a temp storage hard link for big files.
        $response->setContent(readfile($filepath));

        // Return Response to avoid default view rendering
        return $response;
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file, but he has no rights.
     */
    protected function sendFakeFile()
    {
        $media = $this->getMedia();
        $mediaType = $media ? $media->mediaType() : 'image/png';
        switch ($mediaType) {
            case strtok($mediaType, '/') === 'image':
                $file = 'img/locked-file.png';
                break;
            case 'application/pdf':
                $file = 'img/locked-file.pdf';
                break;
            case strtok($mediaType, '/') === 'audio':
            case strtok($mediaType, '/') === 'video':
                $file = 'img/locked-file.mp4';
                break;
            case 'application/vnd.oasis.opendocument.text':
                $file = 'img/locked-file.odt';
                break;
            default:
                $file = 'img/locked-file.png';
                break;
        }

        $assetUrl = $this->viewHelpers()->get('assetUrl');
        $filepath = $assetUrl($file, 'AccessResource', true);
        return $this->redirect()->toUrl($filepath);
    }

    /**
     * Action called if media is not available for the user.
     *
     * It avoids to throw an exception, since it's not really an error and trace
     * is useless.
     *
     * @return ViewModel
     */
    public function permissionDeniedAction()
    {
        $event = $this->getEvent();
        $routeMatch = $event->getRouteMatch();
        $routeMatch->setParam('action', 'forbidden');

        $response = $event->getResponse();
        $response->setStatusCode(403);

        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        $this->logger()->warn(
            sprintf('Access to private resource "%s" by user "%s".', // $translate
                $this->data['storageType'] . '/' . $this->data['filename'],
                $user ? $user->getId() : 'unidentified'
            )
        );

        $view = new ViewModel;
        return $view
            ->setTemplate('error/403-access-resource')
            ->setVariable('exception', new Exception\PermissionDeniedException('Access forbidden')); // @translate
    }
}

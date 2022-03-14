<?php declare(strict_types=1);

namespace AccessResource\Controller;

use const AccessResource\ACCESS_MODE;

use AccessResource\Entity\AccessLog;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Mvc\Exception;

/**
 * @todo Simplify according to Statistics\Controller\DownloadController
 */
class AccessResourceController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager;
     */
    protected $entityManager;

    /**
     * @var \Omeka\Api\Adapter\MediaAdapter;
     */
    protected $mediaAdapter;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var ArrayCollection
     */
    protected $data;

    public function __construct(
        EntityManager $entityManager,
        MediaAdapter $mediaAdapter,
        string $basePath
    ) {
        $this->entityManager = $entityManager;
        $this->mediaAdapter = $mediaAdapter;
        $this->basePath = $basePath;
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

        // When the media is private for the user, it is not available, in any
        // case. This check is done automatically directly at database level.
        $media = $this->getMedia();
        if (!$media) {
            throw new Exception\NotFoundException;
        }

        // Here, the media is restricted or public.
        // The item rights are not checked.

        // Log the statistic for the url even if the file is missing or protected.
        if ($type === 'original' && $this->getPluginManager()->has('logCurrentUrl')) {
            $this->logCurrentUrl();
        }

        $mediaIsPublic = $media->isPublic();
        if ($mediaIsPublic) {
            return $this->sendFile();
        }

        $user = $this->identity();

        // No log when the mode is global or ip, or for admins.

        $accessMode = $this->getAccessMode();
        // Global: any authenticated users can see any restricted resource.
        if ($accessMode === 'global') {
            return $user
                ? $this->sendFile()
                : $this->sendFakeFile();
        }

        // Any admin can see any media in any case, without log.
        if ($user && $this->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            return $this->sendFile();
        }

        // IP: any admin or any users with listed ips can see any restricted resource.
        // This mode is compatible with mode "individual", so the check can be
        // done separately.
        if ($this->isSiteIp()) {
            return $this->sendFile();
        }

        if ($accessMode === 'ip') {
            return $this->sendFakeFile();
        }

        // If without admin permissions, check access.
        $access = $this->hasMediaAccess();
        if (!$access) {
            // Log attempt to access original record without rights.
            if ($type === 'original') {
                $log = new AccessLog();
                $this->entityManager->persist($log);
                $log
                    ->setAction('no_access')
                    ->setUser($user)
                    ->setRecordId($media->id())
                    ->setType(AccessLog::TYPE_ACCESS)
                    ->setDate(new \DateTime());
                $this->entityManager->flush();
            }
            return $this->sendFakeFile();
        }

        // Don't log derivative files.
        if ($type === 'original') {
            $log = new AccessLog();
            $this->entityManager->persist($log);
            $log
                ->setAction('accessed')
                ->setUser($user)
                ->setRecordId($media->id())
                ->setType(AccessLog::TYPE_ACCESS)
                ->setDate(new \DateTime());
            $this->entityManager->flush();
        }

        return $this->sendFile();
    }

    protected function getAccessMode()
    {
        $key = 'accessMode';
        $value = $this->data->get($key);
        if ($value) {
            return $value;
        }

        $value = $this->params('access_mode');
        $this->data->set($key, $value);
        return $value;
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
     */
    protected function getMedia(): ?\Omeka\Api\Representation\MediaRepresentation
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
        $storageId = mb_strlen($extension)
            ? mb_substr($filename, 0, -mb_strlen($extension) - 1)
            : $filename;

        // "storage_id" is not available through default api, so use core entity
        // manager. Nevertheless, the call to the api allows to check rights.
        if ($storageId) {
            $media = $this->entityManager
                ->getRepository(\Omeka\Entity\Media::class)
                ->findOneBy(['storageId' => $storageId]);
            if ($media) {
                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                // To get representation from adapter is quicker and enough,
                // because rights are already checked.
                $media = $this->mediaAdapter->getRepresentation($media);
            }
        } else {
            $media = null;
        }

        $this->data->set($key, $media);
        return $media;
    }

    protected function hasMediaAccess(): bool
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

        $token = $this->params()->fromQuery('token');

        $mediaAccess = $media->isPublic();
        $mediaItemAccess = $media->item()->isPublic();
        $isPublic = $mediaAccess && $mediaItemAccess;
        if (!$isPublic) {
            $user = $this->identity();

            $accessResource = null;
            if (!is_null($token)) {
                $accessResource = $this->entityManager
                    ->getRepository(\AccessResource\Entity\AccessResource::class)
                    ->findOneBy(['token' => $token]);
            } elseif (!is_null($user)) {
                $accessResource = $this->entityManager
                    ->getRepository(\AccessResource\Entity\AccessResource::class)
                    ->findOneBy(['user' => $user->getId(), 'resource' => $media->id()]);
            }

            // Deny for visitor without token.
            if (!$user && is_null($token) && is_null($accessResource)) {
                $this->data->set($key, false);
                return false;
            }

            // Deny for guest who has not access.
            if ($user && is_null($token) && is_null($accessResource)) {
                $this->data->set($key, false);
                return false;
            }

            // Deny for token with not equal id media.
            if ($token && $accessResource) {
                if ($media->id() !== $accessResource->resource()->id()) {
                    $this->data->set($key, false);
                    return false;
                }
            }

            // Deny if time access is before start or after end.
            if ($token && $accessResource->getTemporal()) {
                if (strtotime($accessResource->getStartDate()->format('Y-m-d H:i')) <= time()
                    || strtotime($accessResource->getEndDate()->format('Y-m-d H:i')) >= time()
                ) {
                    $this->data->set($key, false);
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
            $value = $mediaAccess && $mediaItemAccess;
        } else {
            $value = true;
        }

        $this->data->set($key, $value);
        return $value;
    }

    /**
     * Get relative filepath.
     */
    protected function getFilepath(): ?string
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

        $value = sprintf('%s/%s/%s', $this->basePath, $storageType, $filename);
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
    protected function sendFile(): \Laminas\Http\PhpEnvironment\Response
    {
        $filepath = $this->getFilepath();
        if (!$filepath) {
            throw new Exception\NotFoundException('File does not exist.'); // @translate
        }

        $media = $this->getMedia();

        $filename = $media->source();
        $storageType = $this->data->get('storageType');
        $mediaType = $storageType === 'original' ? $media->mediaType() : 'image/jpeg';
        $filesize = $this->mediaFilesize($media, $storageType);

        $dispositionMode = 'inline';

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $this->getResponse();
        // Write headers.
        $response->getHeaders()
            ->addHeaderLine(sprintf('Content-Type: %s', $mediaType))
            ->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', $dispositionMode, $filename))
            ->addHeaderLine(sprintf('Content-Length: %s', $filesize))
            ->addHeaderLine('Content-Transfer-Encoding: binary')
            // Use this to open files directly.
            // Cache for 30 days.
            ->addHeaderLine('Cache-Control: private, max-age=2592000, post-check=2592000, pre-check=2592000')
            ->addHeaderLine(sprintf('Expires: %s', gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT'));
        // Send headers separately to handle large files.
        $response->sendHeaders();

        // TODO Use Laminas stream response.

        // Clears all active output buffers to avoid memory overflow.
        $response->setContent('');
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filepath);

        // TODO Fix issue with session. See readme of module XmlViewer.
        ini_set('display_errors', '0');

        // Return response to avoid default view rendering and to manage events.
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

        // Manage custom asset file from the theme.
        $viewHelpers = $this->viewHelpers();
        $assetUrl = $viewHelpers->get('assetUrl');
        $filepath = $assetUrl($file, 'AccessResource', true, false);
        $serverBasePath = $viewHelpers->get('BasePath')();
        if ($serverBasePath && $serverBasePath !== '/') {
            $filepath = mb_substr($filepath, mb_strlen($serverBasePath));
        }
        $filepath = OMEKA_PATH . $filepath;
        $fileSize = file_exists($filepath) ? filesize($filepath) : 0;

        // Everything has been checked.
        $dispositionMode = 'inline';

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $this->getResponse();
        // Write headers.
        $response->getHeaders()
            ->addHeaderLine(sprintf('Content-Type: %s', $mediaType))
            ->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', $dispositionMode, pathinfo($filepath, PATHINFO_BASENAME)))
            ->addHeaderLine(sprintf('Content-Length: %s', $fileSize))
            ->addHeaderLine('Content-Transfer-Encoding: binary');

        // Send headers separately to handle large files.
        $response->sendHeaders();

        // Clears all active output buffers to avoid memory overflow.
        $response->setContent('');
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filepath);

        // TODO Fix issue with session. See readme of module XmlViewer.
        ini_set('display_errors', '0');

        // Return response to avoid default view rendering and to manage events.
        return $response;
    }

    /**
     * Action called if media is not available for the user.
     *
     * It avoids to throw an exception, since it's not really an error and trace
     * is useless.
     */
    public function permissionDeniedAction(): ViewModel
    {
        $event = $this->getEvent();
        $routeMatch = $event->getRouteMatch();
        $routeMatch->setParam('action', 'forbidden');

        $response = $event->getResponse();
        $response->setStatusCode(403);

        $user = $this->identity();

        $this->logger()->warn(
            sprintf('Access to private resource "%s" by user "%s".', // $translate
                $this->data['storageType'] . '/' . $this->data['filename'],
                $user ? $user->getId() : 'unidentified'
            )
        );

        $view = new ViewModel([
            'exception' => new Exception\PermissionDeniedException('Access forbidden'), // @translate
        ]);
        return $view
            ->setTemplate('error/403-access-resource');
    }

    /**
     * Get the ip of the client (ipv4 or ipv6), or empty ip ("::").
     */
    protected function getClientIp(): string
    {
        // Use $_SERVER['REMOTE_ADDR'], the most reliable.
        $remoteAddress = new RemoteAddress();
        $ip = $remoteAddress->getIpAddress();
        if (!$ip) {
            return '::';
        }

        // A proxy or a htaccess rule can return the server ip, so check it too.
        // The server itself is a trusted proxy when used in htacess or config (see RemoteAddress::getIpAddressFromProxy()).
        $remoteAddress
            ->setUseProxy(true)
            ->setTrustedProxies([$_SERVER['SERVER_ADDR']]);
        return $remoteAddress->getIpAddress()
            ?: '::';
    }

    /**
     * Check if the ip of the user belongs to a site.
     */
    protected function isSiteIp(): ?int
    {
        $ip = $this->getClientIp();
        if ($ip === '::') {
            return null;
        }

        $reservedIps = $this->settings()->get('accessresource_ip_reserved', []);
        if (empty($reservedIps)) {
            return null;
        }

        // Check a single ip.
        if (isset($reservedIps[$ip])) {
            return $reservedIps[$ip]['site'];
        }

        // Check an ip range.
        // FIXME Fix check of ip for ipv6 (ip2long).
        $ipLong = ip2long($ip);
        foreach ($reservedIps as $range) {
            if ($ipLong >= $range['low'] && $ipLong <= $range['high']) {
                return $range['site'];
            }
        }

        return null;
    }
}

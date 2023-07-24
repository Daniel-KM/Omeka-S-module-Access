<?php declare(strict_types=1);

namespace AccessResource\Controller;

use const AccessResource\ACCESS_MODE;
use const AccessResource\ACCESS_MODE_GLOBAL;
use const AccessResource\ACCESS_MODE_IP;
use const AccessResource\ACCESS_MODE_INDIVIDUAL;

use AccessResource\Entity\AccessLog;
use Doctrine\ORM\EntityManager;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\MediaRepresentation;
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

    public function __construct(
        EntityManager $entityManager,
        MediaAdapter $mediaAdapter,
        string $basePath
    ) {
        $this->entityManager = $entityManager;
        $this->mediaAdapter = $mediaAdapter;
        $this->basePath = $basePath;
    }

    /**
     * Forward to the action "file".
     *
     * @see self::filesAction()
     */
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'file';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    /**
     * @todo Use plugin isForbiddenFile.
     */
    public function fileAction()
    {
        /**
         * @var string $storageType
         * @var string $filename
         * @var MediaRepresentation $media
         * @var string $accessMode
         * @var \Omeka\Entity\User $user
         * @var bool $canViewAll
         * @var bool $hasMediaAccess
         * @var string $filepath
         */
        $data = $this->getDataFile();
        extract($data);
        if (empty($storageType) || empty($filename)) {
            throw new Exception\NotFoundException;
        }

        // When the media is private for the user, it is not available, in any
        // case. This check is done automatically directly at database level.
        if (!$media) {
            throw new Exception\NotFoundException;
        }

        // Here, the media is restricted or public.
        // The item rights are not checked.

        // Log the statistic for the url even if the file is missing or protected.
        /// Admin requests are automatically skipped.
        if ($this->getPluginManager()->has('logCurrentUrl')) {
            $this->logCurrentUrl();
        }

        // Log only non-admin individual access to original records.
        if ($accessMode === ACCESS_MODE_INDIVIDUAL
            && $storageType === 'original'
            && !$canViewAll
        ) {
            $user = $this->identity();
            $log = new AccessLog();
            $log
                ->setAction(empty($filepath) ? 'no_access' : 'accessed')
                ->setUserId($user ? $user->getId() : 0)
                ->setAccessId($media->id())
                ->setAccessType(AccessLog::TYPE_ACCESS)
                ->setDate(new \DateTime());
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }

        return $filepath
            ? $this->sendFile($filepath, null, $filename, 'inline', false, $media, $storageType)
            : $this->sendFakeFile($media, $filename);
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

        $data = $this->getDataFile();
        $user = $this->identity();

        $this->logger()->warn(
            new \Omeka\Stdlib\Message('Access to private resource "%s" by user "%s".', // $translate
                $data['storageType'] . '/' . $data['filename'],
                $user ? $user->getId() : 'unidentified'
            )
        );

        $view = new ViewModel([
            'exception' => new Exception\PermissionDeniedException('Access forbidden'), // @translate
        ]);
        return $view
            ->setTemplate('error/403-access-resource');
    }

    protected function getDataFile(): array
    {
        $result = [
            'storageType' => null,
            'filename' => null,
            'media' => null,
            'accessMode' => null,
            'user' => null,
            'canViewAll' => false,
            'hasMediaAccess' => false,
            'isUnderEmbargo' => null,
            'filepath' => null,
        ];

        $routeParams = $this->params()->fromRoute();
        $result['storageType'] = $routeParams['type'] ?? null;
        if (!$result['storageType']) {
            return $result;
        }

        $result['filename'] = $routeParams['filename'] ?? null;
        if (!$result['filename']) {
            return $result;
        }

        // For compatibility with module ArchiveRepertory, don't take the
        // filename, but remove the extension.
        // $storageId = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($result['filename'], PATHINFO_EXTENSION);
        $storageId = mb_strlen($extension)
            ? mb_substr($result['filename'], 0, -mb_strlen($extension) - 1)
            : $result['filename'];
        if (!$storageId) {
            return $result;
        }

        // "storage_id" is not available through default api, so use core entity
        // manager. Nevertheless, the call to the api allows to check rights.
        $media = $this->entityManager
            ->getRepository(\Omeka\Entity\Media::class)
            ->findOneBy(['storageId' => $storageId]);
        if (!$media) {
            return $result;
        }

        /** @var \Omeka\Api\Representation\MediaRepresentation $media */
        // To get representation from adapter is quicker and enough, because
        // rights are already checked.
        $result['media'] = $media = $this->mediaAdapter->getRepresentation($media);

        $result['accessMode'] = $routeParams['access_mode'] ?? null;

        $result['user'] = $this->identity();
        $result['canViewAll'] = $result['user']
            // Slower but manage extra roles and modules permissions.
            // && in_array($result['user']->getRole(), ['global_admin', 'site_admin', 'editor', 'reviewer']);
            && $this->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all');

        if ($result['canViewAll']) {
            $result['hasMediaAccess'] = true;
        } else {
            // The embargo may be finished, but not updated (for example a cron
            // issue), so it is checked when needed.
            $settings = $this->settings();
            $bypassEmbargo = (bool) $settings->get('accessresource_embargo_bypass');
            $result['isUnderEmbargo'] = $bypassEmbargo
                ? null
                : $this->isUnderEmbargo($media, (bool) $settings->get('accessresource_embargo_auto_update'));
            // When here, the resource has been automatically updated, so no
            // more check since the resource is public.
            $result['hasMediaAccess'] = $result['isUnderEmbargo'] === false
                || $this->hasMediaAccess($media, $result['accessMode']);
            if (!$result['hasMediaAccess']
                // Don't bypass embargo when it is set and not overridable.
                || $result['isUnderEmbargo']
            ) {
                return $result;
            }
        }

        $result['filepath'] = $this->getFilepath($media, $result['storageType']);

        return $result;
    }

    protected function hasMediaAccess(?MediaRepresentation $media, ?string $accessMode): bool
    {
        if (!$media || !$accessMode) {
            return false;
        }

        $mediaAccess = $media->isPublic();
        $mediaItemAccess = $media->item()->isPublic();
        $isPublic = $mediaAccess && $mediaItemAccess;
        if ($isPublic) {
            return true;
        }

        $user = $this->identity();
        if ($accessMode === ACCESS_MODE_GLOBAL) {
            return !empty($user);
        }

        // Any admin can see any media in any case.
        if ($user && $this->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            return true;
        }

        // Mode "ip" is compatible with mode "individual", so the check can be
        // done separately.

        $reservedItemSetsForClientIp = $this->reservedItemSetsForClientIp();
        if (is_array($reservedItemSetsForClientIp)) {
            if (count($reservedItemSetsForClientIp)) {
                $isMediaInItemSets = $this->isMediaInItemSets($media, $reservedItemSetsForClientIp);
                // For ip and individuals.
                if ($isMediaInItemSets) {
                    return true;
                }
            } elseif ($accessMode === ACCESS_MODE_IP) {
                return true;
            }
            // Individual user rights (request or token) checked below.
        }

        if ($accessMode === ACCESS_MODE_IP) {
            return false;
        }

        // Check individual access with or without token.

        $token = $this->params()->fromQuery('token');

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
            return false;
        }

        // Deny for guest who has not access.
        if ($user && is_null($token) && is_null($accessResource)) {
            return false;
        }

        // Deny for token with not equal id media.
        if ($token && $accessResource && $media->id() !== $accessResource->resource()->id()) {
            return false;
        }

        // Deny if time access is before start or after end.
        if ($token
            && $accessResource->getTemporal()
            && (
                strtotime($accessResource->getStartDate()->format('Y-m-d H:i')) <= time()
                || strtotime($accessResource->getEndDate()->format('Y-m-d H:i')) >= time()
            )
        ) {
            return false;
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

        return $mediaAccess && $mediaItemAccess;
    }

    /**
     * Check and get relative filepath.
     *
     * @throws \Omeka\Mvc\Exception\NotFoundException
     */
    protected function getFilepath(MediaRepresentation $media, string $storageType): ?string
    {
        if ($storageType === 'original') {
            if (!$media->hasOriginal()) {
                throw new Exception\NotFoundException('File does not exist.'); // @translate
            }
            $filename = $media->filename();
        } elseif (!$media->hasThumbnails()) {
            throw new Exception\NotFoundException('File does not exist.'); // @translate
        } else {
            $filename = $media->storageId() . '.jpg';
        }

        $value = sprintf('%s/%s/%s', $this->basePath, $storageType, $filename);
        if (!is_readable($value)) {
            throw new Exception\NotFoundException('File does not exist.'); // @translate
        }

        return $value;
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file.
     *
     * @see \AccessResource\Controller\AccessResourceController::sendFile()
     * @see \DerivativeMedia\Controller\IndexController::sendFile()
     * @see \Statistics\Controller\DownloadController::sendFile()
     */
    protected function sendFile(
        string $filepath,
        ?string $mediaType = null,
        ?string $filename = null,
        // "inline" or "attachment".
        // It is recommended to set attribute "download" to link tag "<a>".
        ?string $dispositionMode = 'inline',
        ?bool $cache = false,
        ?MediaRepresentation $media = null,
        ?string $storageType = null
    ): \Laminas\Http\PhpEnvironment\Response {
        if (!$mediaType) {
            $mediaType = $storageType === 'original' ? $media->mediaType() : 'image/jpeg';
        }
        $filename = $filename ?: basename($filepath);
        $filesize = $media && $storageType !== 'asset'
            ? $this->mediaFilesize($media, $storageType)
            : (file_exists($filepath) ? (int) filesize($filepath) : 0);

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $this->getResponse();

        // Write headers.
        $headers = $response->getHeaders()
            ->addHeaderLine(sprintf('Content-Type: %s', $mediaType))
            ->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', $dispositionMode, $filename))
            ->addHeaderLine(sprintf('Content-Length: %s', $filesize))
            ->addHeaderLine('Content-Transfer-Encoding: binary');
        if ($cache) {
            // Use this to open files directly.
            // Cache for 30 days.
            $headers
                ->addHeaderLine('Cache-Control: private, max-age=2592000, post-check=2592000, pre-check=2592000')
                ->addHeaderLine(sprintf('Expires: %s', gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT'));
        }

        // Send headers separately to handle large files.
        $response->sendHeaders();

        // TODO Fix issue with session. See readme of module XmlViewer.
        ini_set('display_errors', '0');

        // Normally, these formats are not used, so check quality.
        if ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
            $xmlContent = file_get_contents($filepath);
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument('1.1', 'UTF-8');
            $dom->strictErrorChecking = false;
            $dom->validateOnParse = false;
            $dom->recover = true;
            $dom->loadXML($xmlContent);
            $currentXml = simplexml_import_dom($dom);
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            $response->setContent($currentXml->asXML());
            return $response;
        }

        // TODO Use Laminas stream response.
        // Clears all active output buffers to avoid memory overflow.
        $response->setContent('');
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filepath);

        // Return response to avoid default view rendering and to manage events.
        return $response;
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file without rights.
     */
    protected function sendFakeFile(?MediaRepresentation $media, ?string $filename = null)
    {
        $mediaType = $media ? $media->mediaType() : 'image/png';
        $mediaTypeMain = strtok($mediaType, '/');
        switch ($mediaType) {
            case $mediaTypeMain === 'image':
                $mediaType = 'image/png';
                $file = 'img/locked-file.png';
                break;
            case 'application/pdf':
                $file = 'img/locked-file.pdf';
                break;
            case $mediaTypeMain === 'audio':
            case $mediaTypeMain === 'video':
                $mediaType = 'video/mp4';
                $file = 'img/locked-file.mp4';
                break;
            case 'application/vnd.oasis.opendocument.text':
                $file = 'img/locked-file.odt';
                break;
            default:
                $mediaType = 'image/png';
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

        // "asset" means theme asset, not Asset entity.
        return $this->sendFile($filepath, $mediaType, $filename, 'inline', false, $media, 'asset');
    }

    protected function isMediaInItemSets(?MediaRepresentation $media, ?array $itemSetIds): bool
    {
        if (!$media || !$itemSetIds) {
            return false;
        }
        $mediaItemSetIds = array_keys($media->item()->itemSets());
        return (bool) array_intersect($mediaItemSetIds, $itemSetIds);
    }

    /**
     * Check if the ip of the user is reserved and limited to some item sets.
     *
     * @return array|null Null if the user is not listed in reserved ips, else
     *   array of item sets, that may be empty.
     */
    protected function reservedItemSetsForClientIp(): ?array
    {
        // This method is called one time for each file, but each file is
        // called by a difrerent request.

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
            return $reservedIps[$ip]['reserved'];
        }

        // Check an ip range.
        // FIXME Fix check of ip for ipv6 (ip2long).
        $ipLong = ip2long($ip);
        foreach ($reservedIps as $range) {
            if ($ipLong >= $range['low'] && $ipLong <= $range['high']) {
                return $range['reserved'];
            }
        }

        return null;
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
}

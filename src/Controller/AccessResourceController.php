<?php declare(strict_types=1);

namespace AccessResource\Controller;

use AccessResource\Entity\AccessLog;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Mvc\Exception;
use Omeka\Permissions\Acl;

/**
 * @todo Simplify according to Statistics\Controller\DownloadController
 */
class AccessResourceController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\Api\Adapter\MediaAdapter
     */
    protected $mediaAdapter;

    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var string
     */
    protected $basePath;

    public function __construct(
        EntityManager $entityManager,
        MediaAdapter $mediaAdapter,
        Acl $acl,
        string $basePath
    ) {
        $this->entityManager = $entityManager;
        $this->mediaAdapter = $mediaAdapter;
        $this->acl = $acl;
        $this->basePath = $basePath;
    }

    /**
     * Forward to the action "file".
     *
     * If the user has no right to send a file, a fake file is sent.
     * @todo In a previous version, there was a redirect to a specific page 403.
     *
     * @see self::filesAction()
     */
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'file';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function fileAction()
    {
        // Log the statistic for the url even if the file is missing or protected.
        // Admin requests are automatically skipped.
        $hasStatistics = $this->getPluginManager()->has('logCurrentUrl');
        if ($hasStatistics) {
            $this->logCurrentUrl();
        }

        $params = $this->params()->fromRoute();
        $storageType = $params['type'] ?? '';
        $filename = $params['filename'] ?? '';

        // TODO Manage external stores.
        $filepath = sprintf('%s/%s/%s', $this->basePath, $storageType, $filename);

        // A security. Don't check the realpath to avoid issue on some configs.
        if (strpos($filepath, '../') !== false
            || !file_exists($filepath)
            || !is_readable($filepath)
        ) {
            throw new Exception\NotFoundException;
        }

        $media = $this->mediaFromFilename($filename);
        if (!$media) {
            throw new Exception\NotFoundException;
        }

        // Here, the media exists and is readable by the user (public/private).

        // Only mode "media only" is managed for now.
        $isAllowedMediaContent = $this->isAllowedMediaContent($media);

        // Log only non-admin individual access to original records.
        if ($storageType === 'original'
            && !$this->acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')
        ) {
            $user = $this->identity();
            $log = new AccessLog();
            $log
                ->setAction($isAllowedMediaContent ? 'accessed' : 'no_access')
                ->setUserId($user ? $user->getId() : 0)
                ->setAccessId($media->id())
                ->setAccessType(AccessLog::TYPE_ACCESS)
                ->setDate(new \DateTime());
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        }

        return $isAllowedMediaContent
            ? $this->sendFile($filepath, $media, null, basename($filepath), $storageType)
            : $this->sendFakeFile($media, basename($filepath));
    }

    protected function mediaFromFilename(string $filename): ?MediaRepresentation
    {
        // For compatibility with module ArchiveRepertory, don't take the
        // filename, but remove the extension.
        // $storageId = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $storageId = mb_strlen($extension)
            ? mb_substr($filename, 0, -mb_strlen($extension) - 1)
            : $filename;
        if (!$storageId) {
            return null;
        }

        // "storage_id" is not available via default api, so use entity manager.
        // Nevertheless, the call to api allows to check rights. So double read.
        // Anyway, the cache is used, so the second request is direct.

        $media = $this->entityManager
            ->getRepository(\Omeka\Entity\Media::class)
            ->findOneBy(['storageId' => $storageId]);
        if (!$media) {
            return null;
        }

        // Rights are automatically checked, so an exception may be thrown.
        $media = $this->api()->read('media', ['id' => $media->getId()], [], ['initialize' => false, 'finalize' => false])->getContent();
        return $this->mediaAdapter->getRepresentation($media);
    }

    /**
     * This is the 'file' action that is invoked when a user wants to download
     * the given file.
     *
     * @see \AccessResource\Controller\AccessResourceController::sendFile()
     * @see \DerivativeMedia\Controller\IndexController::sendFile()
     * @see \Statistics\Controller\DownloadController::sendFile()
     * and
     * @see \ImageServer\Controller\ImageController::fetchAction()
     */
    protected function sendFile(
        string $filepath,
        ?MediaRepresentation $media = null,
        ?string $mediaType = null,
        ?string $filename = null,
        ?string $storageType = null,
        bool $cache = false
    ): \Laminas\Http\PhpEnvironment\Response {
        if (!$mediaType) {
            $mediaType = $storageType === 'original' ? $media->mediaType() : 'image/jpeg';
        }

        $filename = $filename ? basename($filename) : basename($filepath);
        $filesize = (int) filesize($filepath);

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $this->getResponse();

        // Write headers.
        $headers = $response->getHeaders()
            ->addHeaderLine(sprintf('Content-Type: %s', $mediaType))
            ->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', 'inline', $filename))
            ->addHeaderLine(sprintf('Content-Length: %s', $filesize))
            ->addHeaderLine('Content-Transfer-Encoding: binary');
        if ($cache) {
            // Use this to open files directly.
            // Cache for 30 days.
            $headers
                ->addHeaderLine('Cache-Control: private, max-age=2592000, post-check=2592000, pre-check=2592000')
                ->addHeaderLine(sprintf('Expires: %s', gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT'));
        }

        // Fix deprecated warning in \Laminas\Http\PhpEnvironment\Response::sendHeaders() (l. 113).
        $errorReporting = error_reporting();
        error_reporting($errorReporting & ~E_DEPRECATED);

        // Send headers separately to handle large files.
        $response->sendHeaders();

        error_reporting($errorReporting);

        // Normally, these formats are not used, so check quality.
        // TODO Why xml content is managed separately when sending file?
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

        // TODO Fix issue with session. See readme of module XmlViewer.
        ini_set('display_errors', '0');

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
        return $this->sendFile($filepath, $media, $mediaType, $filename, 'asset');
    }
}

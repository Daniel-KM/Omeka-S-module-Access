<?php declare(strict_types=1);

namespace Access\Controller;

use Access\Entity\AccessLog;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Mvc\Exception;
use Omeka\Permissions\Acl;

class AccessFileController extends AbstractActionController
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
            $message = new PsrMessage(
                'The file {file} (derivative: {derivative}) is invalid or not available.', // @translate
                ['file' => $filename, 'derivative' => $storageType]
            );
            $this->logger()->err($message->getMessage(), $message->getContext());
            throw new Exception\NotFoundException((string) $message);
        }

        $media = $this->mediaFromFilename($filename);
        if (!$media) {
            /** @var \Omeka\Entity\User  $user */
            $user = $this->identity();
            if ($user) {
                $message = new PsrMessage(
                    'The user #{user_id} ({user_email}) has no rights to get the file {file} or the file is invalid.', // @translate
                    ['user_id' => $user->getId(), 'user_email' => $user->getEmail(), 'file' => $filename]
                );
            } else {
                $message = new PsrMessage(
                    'The visitor has no rights to get the file {file} or the file is invalid.', // @translate
                    ['file' => $filename]
                );
            }
            // Only warn: this is a rights issue, nearly never a file issue.
            $this->logger()->warn($message->getMessage(), $message->getContext());
            throw new Exception\NotFoundException((string) $message);
        }

        // Here, the media exists and is readable by the user (public/private).

        // Only mode "media only" is managed for now.
        $isAllowedMediaContent = $this->isAllowedMediaContent($media);

        // Log only non-admin individual access to original records.
        if ($storageType === 'original'
            && !$this->acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')
            // Return the content even when an issue occurs.
            && $this->entityManager->isOpen()
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
     * @see \Access\Controller\AccessFileController::sendFile()
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
            ->addHeaderLine('Content-Type: ' . $mediaType)
            ->addHeaderLine(sprintf('Content-Disposition: %s; filename="%s"', 'inline', $filename))
            ->addHeaderLine('Content-Transfer-Encoding: binary');
        // $header = new Header\ContentLength();
        if ($cache) {
            // Use this to open files directly.
            // Cache for 30 days.
            $headers
                ->addHeaderLine('Cache-Control: private, max-age=2592000, post-check=2592000, pre-check=2592000')
                ->addHeaderLine(sprintf('Expires: %s', gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT'));
        }

        // Normally, these formats are not used, so check quality.
        // TODO Why xml content is managed separately when sending file?
        if ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
            $headers
                ->addHeaderLine('Content-Length: ' . $filesize);
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

        $headers
            ->addHeaderLine('Accept-Ranges: bytes');

        // TODO Check for Apache XSendFile or Nginx: https://stackoverflow.com/questions/4022260/how-to-detect-x-accel-redirect-nginx-x-sendfile-apache-support-in-php
        // TODO Use Laminas stream response?
        // $response = new \Laminas\Http\Response\Stream();

        // Adapted from https://stackoverflow.com/questions/15797762/reading-mp4-files-with-php.
        $hasRange = !empty($_SERVER['HTTP_RANGE']);
        if ($hasRange) {
            // Start/End are pointers that are 0-based.
            $start = 0;
            $end = $filesize - 1;
            $matches = [];
            $result = preg_match('/bytes=\h*(?<start>\d+)-(?<end>\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches);
            if ($result) {
                $start = (int) $matches['start'];
                if (!empty($matches['end'])) {
                    $end = (int) $matches['end'];
                }
            }
            // Check valid range to avoid hack.
            $hasRange = ($start < $filesize && $end < $filesize && $start < $end)
                && ($start > 0 || $end < ($filesize - 1));
        }

        if ($hasRange) {
            // Set partial content.
            $response
                ->setStatusCode($response::STATUS_CODE_206);
            $headers
                ->addHeaderLine('Content-Length: ' . ($end - $start + 1))
                ->addHeaderLine("Content-Range: bytes $start-$end/$filesize");
        } else {
            $headers
               ->addHeaderLine('Content-Length: ' . $filesize);
        }

        // Fix deprecated warning in \Laminas\Http\PhpEnvironment\Response::sendHeaders() (l. 113).
        $errorReporting = error_reporting();
        error_reporting($errorReporting & ~E_DEPRECATED);

        // Send headers separately to handle large files.
        $response->sendHeaders();

        error_reporting($errorReporting);

        // Clears all active output buffers to avoid memory overflow.
        $response->setContent('');
        while (ob_get_level()) {
            ob_end_clean();
        }

        if ($hasRange) {
            $fp = @fopen($filepath, 'rb');
            $buffer = 1024 * 8;
            $pointer = $start;
            fseek($fp, $start, SEEK_SET);
            while (!feof($fp)
                && $pointer <= $end
                && connection_status() === CONNECTION_NORMAL
            ) {
                set_time_limit(0);
                echo fread($fp, min($buffer, $end - $pointer + 1));
                flush();
                $pointer += $buffer;
            }
            fclose($fp);
        } else {
            readfile($filepath);
        }

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
        $mediaType = $media ? (string) $media->mediaType() : 'image/png';
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
        $filepath = $assetUrl($file, 'Access', true, false);
        $serverBasePath = $viewHelpers->get('BasePath')();
        if ($serverBasePath && $serverBasePath !== '/') {
            $filepath = mb_substr($filepath, mb_strlen($serverBasePath));
        }
        $filepath = OMEKA_PATH . $filepath;

        // "asset" means theme asset, not Asset entity.
        return $this->sendFile($filepath, $media, $mediaType, $filename, 'asset');
    }
}

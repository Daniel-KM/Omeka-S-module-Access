<?php declare(strict_types=1);

namespace AccessTest\Controller;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the cache headers of the files.
 *
 * Only the response "not modified" can be dispatched, because the controller
 * cleans the output buffers when it sends a file.
 *
 * @group file
 */
class AccessFileCacheTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    protected ?string $filepath = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($this->filepath) {
            @unlink($this->filepath);
        }
        $this->loginAdmin();
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    protected function createFile(string $level): string
    {
        $item = $this->createItem();
        $media = $this->createMedia($item);
        $storageId = 'access-cache-' . $media->id();
        $this->getConnection()->executeStatement(
            'UPDATE `media` SET `storage_id` = ?, `extension` = "jpg", `media_type` = "image/jpeg", `has_original` = 1 WHERE `id` = ?',
            [$storageId, $media->id()]
        );
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $this->filepath = $basePath . '/original/' . $storageId . '.jpg';
        file_put_contents($this->filepath, 'content');
        $this->setAccessLevelSet($media->id(), $level);
        $this->rebuildAccessCascade();
        return '/access/files/original/' . $storageId . '.jpg';
    }

    public function testFreeFileIsCachedPublicly(): void
    {
        $url = $this->createFile(AccessStatus::FREE);
        $this->logout();
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', time() + 60) . ' GMT';
        $this->dispatch($url);
        $this->assertResponseStatusCode(304);
        $this->assertResponseHeaderRegex('Cache-Control', '/\\bpublic\\b/');
        $this->assertResponseHeaderRegex('Cache-Control', '/max-age=86400/');
        $this->assertHasResponseHeader('Last-Modified');
    }

    public function testReservedFileAllowedToUserIsCachedPrivately(): void
    {
        $url = $this->createFile(AccessStatus::RESERVED);
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', time() + 60) . ' GMT';
        $this->dispatch($url);
        $this->assertResponseStatusCode(304);
        $this->assertResponseHeaderRegex('Cache-Control', '/\\bprivate\\b/');
        $this->assertResponseHeaderRegex('Cache-Control', '/max-age=3600/');
    }
}

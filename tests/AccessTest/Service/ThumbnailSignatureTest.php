<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use Access\Stdlib\FileSignature;
use AccessTest\AccessTestTrait;
use Laminas\EventManager\Event;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the signature of the urls of the thumbnails allowed to the user.
 */
class ThumbnailSignatureTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    protected array $saved = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        foreach (['access_htaccess_types', 'access_htaccess_custom_types', 'access_modes'] as $name) {
            $this->saved[$name] = $this->getSettings()->get($name);
        }
        $this->getSettings()->set('access_htaccess_types', ['original', 'large', 'medium', 'square']);
        $this->getSettings()->set('access_htaccess_custom_types', '');
    }

    public function tearDown(): void
    {
        $this->loginAdmin();
        foreach ($this->saved as $name => $value) {
            $this->getSettings()->set($name, $value);
        }
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    protected function signedSrc($representation, string $src): string
    {
        $module = new \Access\Module();
        $module->setServiceLocator($this->getServiceLocator());
        $event = new Event('view_helper.thumbnail.attribs', null, [
            'attribs' => ['src' => $src],
            'representation' => $representation,
            'type' => 'square',
        ]);
        $module->signThumbnailUrl($event);
        return $event->getParam('attribs')['src'];
    }

    protected function createMediaWithFile(string $level)
    {
        $item = $this->createItem();
        $media = $this->createMedia($item);
        $this->getConnection()->executeStatement(
            'UPDATE `media` SET `storage_id` = ?, `extension` = "jpg", `media_type` = "image/jpeg", `has_original` = 1, `has_thumbnails` = 1 WHERE `id` = ?',
            ['sig-' . $media->id(), $media->id()]
        );
        $this->setAccessLevelSet($media->id(), $level);
        $this->rebuildAccessCascade();
        $this->getEntityManager()->clear();
        return $this->api()->read('media', $media->id())->getContent();
    }

    public function testAllowedThumbnailIsSigned(): void
    {
        $media = $this->createMediaWithFile(AccessStatus::RESERVED);
        $src = 'http://example.org/files/square/sig-' . $media->id() . '.jpg';

        $signed = $this->signedSrc($media, $src);
        $this->assertStringStartsWith($src . '?' . FileSignature::QUERY_ARG . '=', $signed);
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);
        $signer = FileSignature::fromOmekaPath(OMEKA_PATH);
        $this->assertTrue($signer->isValid('square/sig-' . $media->id() . '.jpg', $query[FileSignature::QUERY_ARG]));
    }

    public function testDeniedThumbnailIsNotSigned(): void
    {
        $media = $this->createMediaWithFile(AccessStatus::RESERVED);
        $src = 'http://example.org/files/square/sig-' . $media->id() . '.jpg';
        $this->getSettings()->set('access_modes', []);
        $this->logout();

        $this->assertSame($src, $this->signedSrc($media, $src));
    }

    public function testUnprotectedTypeIsNotSigned(): void
    {
        $media = $this->createMediaWithFile(AccessStatus::FREE);
        $this->getSettings()->set('access_htaccess_types', ['original']);
        $src = 'http://example.org/files/square/sig-' . $media->id() . '.jpg';

        $this->assertSame($src, $this->signedSrc($media, $src));
    }
}

<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests the behaviour when no access mode is enabled.
 *
 * An empty list of modes means that no bypass is available, so a reserved or
 * protected content cannot be granted to anybody and must be denied. The
 * plugin used to return true in that case, making every restricted content
 * public as soon as an admin unchecked all the modes.
 *
 * @group isallowed
 * @group integration
 */
class NoAccessModeTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->setEmbargoBypass(false);
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * @dataProvider levelProvider
     */
    public function testLevelWithoutAnyMode(string $level, bool $expected): void
    {
        $item = $this->createItem(['title' => 'Item ' . $level]);
        $media = $this->createMedia($item);
        $this->setAccessStatus($item->id(), $level);
        $this->setAccessStatus($media->id(), $level);

        $this->setAccessModes([]);
        $this->logout();

        $media = $this->api()->read('media', ['id' => $media->id()])->getContent();

        $this->assertSame(
            $expected,
            $this->isAllowedMediaContentFresh($media),
            sprintf('Level "%s" without any access mode.', $level)
        );
    }

    public function levelProvider(): array
    {
        return [
            'free is always readable' => [AccessStatus::FREE, true],
            'reserved has no bypass left' => [AccessStatus::RESERVED, false],
            'protected has no bypass left' => [AccessStatus::PROTECTED, false],
            'forbidden is never readable' => [AccessStatus::FORBIDDEN, false],
        ];
    }

    /**
     * The setting may be absent, and not only empty: the plugin must not fail
     * on a null value.
     */
    public function testMissingSettingIsHandledLikeAnEmptyList(): void
    {
        $item = $this->createItem(['title' => 'Item without setting']);
        $media = $this->createMedia($item);
        $this->setAccessStatus($item->id(), AccessStatus::RESERVED);
        $this->setAccessStatus($media->id(), AccessStatus::RESERVED);

        $this->getSettings()->delete('access_modes');
        $this->logout();

        $media = $this->api()->read('media', ['id' => $media->id()])->getContent();

        $this->assertFalse($this->isAllowedMediaContentFresh($media));
    }
}

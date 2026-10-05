<?php declare(strict_types=1);

namespace AccessTest\View;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the view helper isAllowedMediaContent.
 *
 * The helper is a thin delegation to the controller plugin, so the point is
 * not to test the access rules again, but the contract of the helper: it
 * accepts any resource entity, not only a media, and it always answers like
 * the plugin.
 *
 * @group isallowed
 * @group integration
 */
class IsAllowedMediaContentHelperTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->setAccessModes(['user']);
        $this->setEmbargoBypass(false);
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    protected function helper()
    {
        return $this->getServiceLocator()
            ->get('ViewHelperManager')
            ->get('isAllowedMediaContent');
    }

    /**
     * An item is a resource entity, not a media. The helper used to type its
     * argument as a media only, so it raised a TypeError on an item, while the
     * plugin it delegates to accepts any resource.
     */
    public function testHelperAcceptsAnItem(): void
    {
        $item = $this->createItem(['title' => 'Item for helper']);
        $this->setAccessStatus($item->id(), AccessStatus::FREE);

        $helper = $this->helper();
        $this->assertTrue($helper($item));
    }

    public function testHelperAcceptsAMedia(): void
    {
        $item = $this->createItem(['title' => 'Item with media']);
        $media = $this->createMedia($item);
        $this->setAccessStatus($item->id(), AccessStatus::FREE);
        $this->setAccessStatus($media->id(), AccessStatus::FREE);

        $this->assertTrue($this->helper()($media));
    }

    public function testHelperAcceptsNull(): void
    {
        $this->assertFalse($this->helper()(null));
    }

    /**
     * @dataProvider levelProvider
     */
    public function testHelperAnswersLikeThePlugin(string $level): void
    {
        $item = $this->createItem(['title' => 'Item ' . $level]);
        $media = $this->createMedia($item);
        $this->setAccessStatus($item->id(), $level);
        $this->setAccessStatus($media->id(), $level);

        $this->logout();

        $media = $this->api()->read('media', ['id' => $media->id()])->getContent();
        $plugin = $this->getServiceLocator()
            ->get('ControllerPluginManager')
            ->build('isAllowedMediaContent');

        $this->assertSame(
            $plugin($media),
            $this->helper()($media),
            sprintf('The helper and the plugin disagree on level "%s".', $level)
        );
    }

    public function levelProvider(): array
    {
        return [
            'free' => [AccessStatus::FREE],
            'reserved' => [AccessStatus::RESERVED],
            'protected' => [AccessStatus::PROTECTED],
            'forbidden' => [AccessStatus::FORBIDDEN],
        ];
    }
}

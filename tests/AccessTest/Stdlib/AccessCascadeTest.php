<?php declare(strict_types=1);

namespace AccessTest\Stdlib;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the automatic cascade materialized by AccessCascade.
 *
 * The effective level (access_status.level) is the strictest, on the order free
 * < reserved < protected < forbidden, of a resource own level (level_set), its
 * parent item and all its item sets. These tests set the "set" columns and
 * assert the recomputed effective columns, covering the collection-driven
 * (Numistral) and per-document (Dante) usages and the multi-collection
 * contradiction.
 *
 * @group cascade
 * @group integration
 */
class AccessCascadeTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    protected function effectiveLevel(int $resourceId): string
    {
        $status = $this->getAccessStatus($resourceId);
        return $status ? $status->getLevel() : AccessStatus::FREE;
    }

    /**
     * Numistral: a level set on an item set cascades to its items and media.
     */
    public function testItemSetLevelCascadesToItemAndMedia(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();

        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($itemSet->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($item->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($media->id()));
    }

    /**
     * A media set stricter than its item keeps its own level.
     */
    public function testMediaOwnStricterWins(): void
    {
        $item = $this->createItem();
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($media->id(), AccessStatus::FORBIDDEN);
        $this->rebuildAccessCascade();

        $this->assertSame(AccessStatus::FORBIDDEN, $this->effectiveLevel($media->id()));
    }

    /**
     * A media cannot be less restricted than its item (one-way cascade).
     */
    public function testMediaCannotBeLooserThanItem(): void
    {
        $item = $this->createItem();
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($media->id(), AccessStatus::FREE);
        $this->rebuildAccessCascade();

        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($media->id()));
    }

    /**
     * An item in several item sets takes the strictest of their levels.
     */
    public function testItemInMultipleItemSetsTakesStrictest(): void
    {
        $itemSetReserved = $this->createItemSet();
        $itemSetForbidden = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSetReserved]);
        // Attach the second item set too.
        $this->api()->update('items', $item->id(), [
            'o:item_set' => [
                ['o:id' => $itemSetReserved->id()],
                ['o:id' => $itemSetForbidden->id()],
            ],
        ], [], ['isPartial' => true]);
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($itemSetReserved->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($itemSetForbidden->id(), AccessStatus::FORBIDDEN);
        $this->rebuildAccessCascade();

        $this->assertSame(AccessStatus::FORBIDDEN, $this->effectiveLevel($item->id()));
        $this->assertSame(AccessStatus::FORBIDDEN, $this->effectiveLevel($media->id()));
    }

    /**
     * Dante: a neutral (free) item set does not interfere with a per-document
     * level set on the item.
     */
    public function testNeutralItemSetDoesNotInterfere(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);

        // Item set left neutral (free), level set per item.
        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();

        $this->assertSame(AccessStatus::FREE, $this->effectiveLevel($itemSet->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($item->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($media->id()));
    }

    /**
     * Resetting items and media switches to a "by collection" logic: their
     * effective level is then driven only by the item sets.
     */
    public function testResetItemsAndMediaSwitchesToByCollection(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);

        // A messy state: levels set at every level.
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($item->id(), AccessStatus::FORBIDDEN);
        $this->setAccessLevelSet($media->id(), AccessStatus::PROTECTED);

        $cascade = $this->getServiceLocator()->get(\Access\Stdlib\AccessCascade::class);
        $cascade->resetSetColumns(['items', 'media']);
        $this->rebuildAccessCascade();

        // Item and media now inherit the item set level only.
        $this->assertSame(AccessStatus::FREE, $this->getAccessStatus($item->id())->getLevelSet());
        $this->assertSame(AccessStatus::FREE, $this->getAccessStatus($media->id())->getLevelSet());
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($item->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->effectiveLevel($media->id()));
    }

    /**
     * Resetting item sets switches to a "by document" logic: their effective
     * level is then driven only by the items and media.
     */
    public function testResetItemSetsSwitchesToByDocument(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($media->id(), AccessStatus::FORBIDDEN);

        $cascade = $this->getServiceLocator()->get(\Access\Stdlib\AccessCascade::class);
        $cascade->resetSetColumns(['item_sets']);
        $this->rebuildAccessCascade();

        // The item set no longer restricts; the media keeps its own level.
        $this->assertSame(AccessStatus::FREE, $this->effectiveLevel($itemSet->id()));
        $this->assertSame(AccessStatus::FREE, $this->effectiveLevel($item->id()));
        $this->assertSame(AccessStatus::FORBIDDEN, $this->effectiveLevel($media->id()));
    }

    /**
     * Changing the item set level and rebuilding updates the descendants.
     */
    public function testChangingItemSetLevelReflectsOnRebuild(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);

        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FORBIDDEN);
        $this->rebuildAccessCascade();
        $this->assertSame(AccessStatus::FORBIDDEN, $this->effectiveLevel($media->id()));

        // Relax the item set: the media follows back down.
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FREE);
        $this->rebuildAccessCascade();
        $this->assertSame(AccessStatus::FREE, $this->effectiveLevel($media->id()));
    }
}

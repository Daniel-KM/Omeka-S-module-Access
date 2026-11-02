<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the resources without access status, that should not exist, but may
 * be created by a direct import or when the module is disabled.
 *
 * @group cascade
 */
class MissingStatusTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->loginAdmin();
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testMediaWithoutStatusInReservedItemSetIsDenied(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` IN (?, ?)', [$item->id(), $media->id()]);
        $this->getEntityManager()->clear(AccessStatus::class);

        $this->logout();
        $this->assertFalse($this->isAllowedMediaContentFresh($media));
    }

    public function testMediaWithoutStatusInFreeItemSetIsAllowed(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` IN (?, ?)', [$item->id(), $media->id()]);
        $this->getEntityManager()->clear(AccessStatus::class);

        $this->logout();
        $this->assertTrue($this->isAllowedMediaContentFresh($media));
    }

    public function testSearchFreeIncludesResourcesWithoutStatus(): void
    {
        $item = $this->createItem();
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` = ?', [$item->id()]);

        $ids = $this->api()->search('items', ['access' => 'free', 'id' => $item->id()], ['returnScalar' => 'id'])->getContent();
        $this->assertContains($item->id(), array_map('intval', $ids));

        $ids = $this->api()->search('items', ['access' => 'reserved', 'id' => $item->id()], ['returnScalar' => 'id'])->getContent();
        $this->assertNotContains($item->id(), array_map('intval', $ids));
    }
}

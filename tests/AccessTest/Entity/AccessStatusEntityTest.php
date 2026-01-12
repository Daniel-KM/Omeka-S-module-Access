<?php declare(strict_types=1);

namespace AccessTest\Entity;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use DateTime;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the AccessStatus entity.
 *
 * Tests the entity's methods for setting/getting access levels and embargo dates,
 * as well as the isUnderEmbargo() logic.
 *
 * @group entity
 */
class AccessStatusEntityTest extends AbstractHttpControllerTestCase
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

    /**
     * Test that AccessStatus constants are correctly defined.
     */
    public function testAccessLevelConstants(): void
    {
        $this->assertSame('free', AccessStatus::FREE);
        $this->assertSame('reserved', AccessStatus::RESERVED);
        $this->assertSame('protected', AccessStatus::PROTECTED);
        $this->assertSame('forbidden', AccessStatus::FORBIDDEN);
    }

    /**
     * Test creating an access status with FREE level.
     */
    public function testCreateAccessStatusFree(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::FREE]);
        $level = $this->getAccessLevel($item->id());

        $this->assertSame(AccessStatus::FREE, $level);
    }

    /**
     * Test creating an access status with RESERVED level.
     */
    public function testCreateAccessStatusReserved(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $level = $this->getAccessLevel($item->id());

        $this->assertSame(AccessStatus::RESERVED, $level);
    }

    /**
     * Test creating an access status with PROTECTED level.
     */
    public function testCreateAccessStatusProtected(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::PROTECTED]);
        $level = $this->getAccessLevel($item->id());

        $this->assertSame(AccessStatus::PROTECTED, $level);
    }

    /**
     * Test creating an access status with FORBIDDEN level.
     */
    public function testCreateAccessStatusForbidden(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::FORBIDDEN]);
        $level = $this->getAccessLevel($item->id());

        $this->assertSame(AccessStatus::FORBIDDEN, $level);
    }

    /**
     * Test that isUnderEmbargo returns null when no embargo dates are set.
     */
    public function testIsUnderEmbargoWithNoEmbargo(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $entityManager = $this->getEntityManager();

        // Get the resource entity for creating AccessStatus.
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);

        $this->assertNull($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo when currently under embargo (between start and end).
     */
    public function testIsUnderEmbargoCurrentlyEmbargoed(): void
    {
        $item = $this->createItem([
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => $this->getFutureDate(10),
        ]);

        $entityManager = $this->getEntityManager();
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoStart($this->getPastDate(10));
        $accessStatus->setEmbargoEnd($this->getFutureDate(10));

        $this->assertTrue($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo when embargo has ended.
     */
    public function testIsUnderEmbargoEnded(): void
    {
        $item = $this->createItem([
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $entityManager = $this->getEntityManager();
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoStart($this->getPastDate(60));
        $accessStatus->setEmbargoEnd($this->getPastDate(30));

        $this->assertFalse($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo when embargo has not started yet.
     */
    public function testIsUnderEmbargoNotStarted(): void
    {
        $item = $this->createItem([
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getFutureDate(30),
            'embargo_end' => $this->getFutureDate(60),
        ]);

        $entityManager = $this->getEntityManager();
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoStart($this->getFutureDate(30));
        $accessStatus->setEmbargoEnd($this->getFutureDate(60));

        $this->assertFalse($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo with only start date (indefinite embargo from date).
     */
    public function testIsUnderEmbargoStartOnly(): void
    {
        $entityManager = $this->getEntityManager();
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        // Past start date, no end = currently under embargo.
        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoStart($this->getPastDate(10));

        $this->assertTrue($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo with only start date in future.
     */
    public function testIsUnderEmbargoStartOnlyFuture(): void
    {
        $entityManager = $this->getEntityManager();
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        // Future start date, no end = not yet under embargo.
        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoStart($this->getFutureDate(10));

        $this->assertFalse($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo with only end date (embargo until date).
     */
    public function testIsUnderEmbargoEndOnly(): void
    {
        $entityManager = $this->getEntityManager();
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        // No start, future end = currently under embargo.
        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoEnd($this->getFutureDate(10));

        $this->assertTrue($accessStatus->isUnderEmbargo());
    }

    /**
     * Test isUnderEmbargo with only end date in past.
     */
    public function testIsUnderEmbargoEndOnlyPast(): void
    {
        $entityManager = $this->getEntityManager();
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $item->id());

        // No start, past end = embargo ended.
        $accessStatus = new AccessStatus();
        $accessStatus->setId($resourceEntity);
        $accessStatus->setLevel(AccessStatus::RESERVED);
        $accessStatus->setEmbargoEnd($this->getPastDate(10));

        $this->assertFalse($accessStatus->isUnderEmbargo());
    }

    /**
     * Test updating access status level.
     */
    public function testUpdateAccessLevel(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::FREE]);

        // Initially FREE.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));

        // Update to RESERVED.
        $this->setAccessStatus($item->id(), AccessStatus::RESERVED);
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));

        // Update to FORBIDDEN.
        $this->setAccessStatus($item->id(), AccessStatus::FORBIDDEN);
        $this->assertSame(AccessStatus::FORBIDDEN, $this->getAccessLevel($item->id()));
    }

    /**
     * Test that access status is deleted when resource is deleted.
     */
    public function testAccessStatusDeletedWithResource(): void
    {
        $item = $this->createItem(['access_level' => AccessStatus::RESERVED]);
        $itemId = $item->id();

        // Verify access status exists.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($itemId));

        // Delete the item (remove from tracked resources first).
        $this->createdResources = array_filter(
            $this->createdResources,
            fn($r) => !($r['type'] === 'items' && $r['id'] === $itemId)
        );
        $this->api()->delete('items', $itemId);

        // Access status should be deleted via cascade.
        $this->assertNull($this->getAccessLevel($itemId));
    }

    /**
     * Test access status for item set (collection).
     */
    public function testAccessStatusForItemSet(): void
    {
        $itemSet = $this->createItemSet([
            'title' => 'Protected Collection',
            'access_level' => AccessStatus::PROTECTED,
        ]);

        $this->assertSame(AccessStatus::PROTECTED, $this->getAccessLevel($itemSet->id()));
    }

    /**
     * Test access status for media.
     */
    public function testAccessStatusForMedia(): void
    {
        $item = $this->createItem();
        $media = $this->createMedia($item, [
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        $this->assertSame(AccessStatus::FORBIDDEN, $this->getAccessLevel($media->id()));
    }

    /**
     * Test that each resource type can have independent access status.
     */
    public function testIndependentAccessStatusPerResource(): void
    {
        $itemSet = $this->createItemSet(['access_level' => AccessStatus::FREE]);
        $item = $this->createItem([
            'item_set' => $itemSet,
            'access_level' => AccessStatus::RESERVED,
        ]);
        $media = $this->createMedia($item, [
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($itemSet->id()));
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));
        $this->assertSame(AccessStatus::FORBIDDEN, $this->getAccessLevel($media->id()));
    }
}

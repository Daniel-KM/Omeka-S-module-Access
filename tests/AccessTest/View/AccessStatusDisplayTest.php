<?php declare(strict_types=1);

namespace AccessTest\View;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the display of the access status in the admin sidebar.
 *
 * @group view
 */
class AccessStatusDisplayTest extends AbstractHttpControllerTestCase
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

    public function testInheritedStatusDisplaysAppliedAndDefinedStatuses(): void
    {
        $itemSet = $this->createItemSet(['title' => 'Reserved collection']);
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();

        $status = $this->getServiceLocator()->get('ControllerPluginManager')->get('accessStatus')($item, true);
        $this->assertSame([$itemSet->id()], array_map(fn ($r) => $r->id(), $status->levelOrigins()));
        $status = $this->getServiceLocator()->get('ControllerPluginManager')->get('accessStatus')($media, true);
        $this->assertSame([$itemSet->id()], array_map(fn ($r) => $r->id(), $status->levelOrigins()));

        $this->dispatch('/admin/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        $this->assertQueryContentRegex('.access-status .value', '/Applied status: .+ \(inherited from item set Reserved collection\)/');
        $this->assertQueryContentContains('.access-status .value a', 'Reserved collection');
        $this->assertQueryContentRegex('.access-status .value', '/Defined status: /');
    }

    public function testOwnStatusDisplaysSingleStatus(): void
    {
        $item = $this->createItem();
        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();

        $this->dispatch('/admin/item/' . $item->id());
        $this->assertResponseStatusCode(200);
        $this->assertQuery('.access-status .value');
        $this->assertNotQueryContentRegex('.access-status .value', '/Applied status/');
    }
}

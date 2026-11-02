<?php declare(strict_types=1);

namespace AccessTest\Service;

use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the cache of the item sets of a resource in the access check.
 */
class ItemSetIdsCacheTest extends AbstractHttpControllerTestCase
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

    protected function itemSetIds($resource): array
    {
        $plugin = $this->getServiceLocator()->get('ControllerPluginManager')->build('isAllowedMediaContent');
        $method = new \ReflectionMethod($plugin, 'resourceItemSetIds');
        $method->setAccessible(true);
        return $method->invoke($plugin, $resource);
    }

    /**
     * The cache belongs to the instance, so a new check reads the new item sets
     * of the resource.
     */
    public function testCacheIsNotSharedBetweenInstances(): void
    {
        $itemSetA = $this->createItemSet();
        $itemSetB = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSetA]);
        $this->assertSame([$itemSetA->id()], $this->itemSetIds($item));

        $item = $this->api()->update('items', $item->id(), ['o:item_set' => [['o:id' => $itemSetB->id()]]], [], ['isPartial' => true])->getContent();
        $this->assertSame([$itemSetB->id()], $this->itemSetIds($item));
    }
}

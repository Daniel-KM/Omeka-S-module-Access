<?php declare(strict_types=1);

namespace AccessTest\Job;

use Access\Entity\AccessStatus;
use Access\Job\AccessStatusRebuild;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the AccessStatusRebuild job with resources without access status.
 *
 * @group job
 * @group cascade
 */
class AccessStatusRebuildTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    protected bool $accessProperty;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->accessProperty = (bool) $this->getSettings()->get('access_property');
    }

    public function tearDown(): void
    {
        $this->getSettings()->set('access_property', $this->accessProperty);
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testCreatesMissingStatusesAsFree(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` = ?', [$item->id()]);

        $this->runJob(AccessStatusRebuild::class, []);

        $this->assertSame(
            ['level' => AccessStatus::RESERVED, 'level_set' => AccessStatus::FREE],
            $this->getConnection()->fetchAssociative('SELECT `level`, `level_set` FROM `access_status` WHERE `id` = ?', [$item->id()])
        );
    }

    /**
     * In property mode, the missing statuses are created before the sync from
     * the property values, so the property level is applied.
     */
    public function testCreatesMissingStatusesBeforeSyncFromProperties(): void
    {
        $settings = $this->getSettings();
        $settings->set('access_property', true);
        $term = $settings->get('access_property_level') ?: 'dcterms:accessRights';
        $settings->set('access_property_level', $term);
        $labels = array_replace(['reserved' => 'reserved'], (array) $settings->get('access_property_levels', []));

        $item = $this->createItem();
        $this->api()->update('items', $item->id(), [
            $term => [[
                'type' => 'literal',
                'property_id' => $this->getPropertyId($term),
                '@value' => $labels['reserved'],
            ]],
        ], [], ['isPartial' => true, 'collectionAction' => 'append']);
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` = ?', [$item->id()]);

        $this->runJob(AccessStatusRebuild::class, []);

        $this->assertSame(
            ['level' => AccessStatus::RESERVED, 'level_set' => AccessStatus::RESERVED],
            $this->getConnection()->fetchAssociative('SELECT `level`, `level_set` FROM `access_status` WHERE `id` = ?', [$item->id()])
        );
    }

    /**
     * With the option "copy_to_properties", the removed property values are
     * restored from the own statuses before the sync, so the levels are kept.
     */
    public function testCopiesStatusesIntoPropertiesBeforeSync(): void
    {
        $settings = $this->getSettings();
        $settings->set('access_property', true);
        $term = $settings->get('access_property_level') ?: 'dcterms:accessRights';
        $settings->set('access_property_level', $term);
        $labels = array_replace(['free' => 'free', 'forbidden' => 'forbidden'], (array) $settings->get('access_property_levels', []));
        $propertyId = $this->getPropertyId($term);

        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FORBIDDEN);
        $this->getConnection()->executeStatement('DELETE FROM `value` WHERE `property_id` = ? AND `resource_id` IN (?, ?)', [$propertyId, $itemSet->id(), $item->id()]);

        $this->runJob(AccessStatusRebuild::class, ['copy_to_properties' => true]);

        $connection = $this->getConnection();
        $values = $connection->fetchAllKeyValue('SELECT `resource_id`, `value` FROM `value` WHERE `property_id` = ? AND `resource_id` IN (?, ?)', [$propertyId, $itemSet->id(), $item->id()]);
        $this->assertSame($labels['forbidden'], $values[$itemSet->id()] ?? null);
        $this->assertSame($labels['free'], $values[$item->id()] ?? null);
        $this->assertSame(AccessStatus::FORBIDDEN, $connection->fetchOne('SELECT `level_set` FROM `access_status` WHERE `id` = ?', [$itemSet->id()]));
        $this->assertSame(AccessStatus::FORBIDDEN, $connection->fetchOne('SELECT `level` FROM `access_status` WHERE `id` = ?', [$item->id()]));
    }
}

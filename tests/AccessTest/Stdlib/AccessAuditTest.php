<?php declare(strict_types=1);

namespace AccessTest\Stdlib;

use Access\Entity\AccessStatus;
use Access\Stdlib\AccessAudit;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the audit of the management mode and of the inconsistencies.
 *
 * The audit runs on the whole base, so the tests check that the created
 * resources are reported, not the global counts.
 *
 * @group cascade
 * @group integration
 */
class AccessAuditTest extends AbstractHttpControllerTestCase
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

    protected function audit(bool $embargoCascade = false): array
    {
        return (new AccessAudit($this->getConnection(), $embargoCascade, 1000000))->audit();
    }

    public function testModeIsMixedWithDecisionsOnItemSetAndItem(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem();
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();

        $result = $this->audit();
        $this->assertSame('mixed', $result['mode']);
        $this->assertGreaterThanOrEqual(1, $result['decisions']['item_sets']);
        $this->assertGreaterThanOrEqual(1, $result['decisions']['items']);
    }

    public function testStaleLevelsUntilRebuild(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        // Bypass the cascade, like a direct database edit.
        $this->getConnection()->executeStatement('UPDATE `access_status` SET `level` = "free" WHERE `id` IN (?, ?)', [$itemSet->id(), $item->id()]);

        $stale = $this->audit()['findings']['stale_levels'];
        $this->assertContains($itemSet->id(), $stale['item_sets']['ids']);
        $this->assertContains($item->id(), $stale['items']['ids']);

        $this->rebuildAccessCascade();
        $stale = $this->audit()['findings']['stale_levels'];
        $this->assertNotContains($itemSet->id(), $stale['item_sets']['ids']);
        $this->assertNotContains($item->id(), $stale['items']['ids']);
    }

    public function testMaskedDecisionUnderStricterItemSet(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FORBIDDEN);
        $this->setAccessLevelSet($item->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($media->id(), AccessStatus::FORBIDDEN);
        $this->rebuildAccessCascade();

        $masked = $this->audit()['findings']['masked_decisions'];
        $this->assertContains($item->id(), $masked['items']['ids']);
        $this->assertNotContains($media->id(), $masked['media']['ids']);
    }

    public function testItemInItemSetsWithDifferentLevels(): void
    {
        $itemSetReserved = $this->createItemSet();
        $itemSetFree = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSetReserved]);
        $this->api()->update('items', $item->id(), ['o:item_set' => [['o:id' => $itemSetReserved->id()], ['o:id' => $itemSetFree->id()]]], [], ['isPartial' => true]);
        $this->setAccessLevelSet($itemSetReserved->id(), AccessStatus::RESERVED);
        $this->setAccessLevelSet($itemSetFree->id(), AccessStatus::FREE);
        $this->rebuildAccessCascade();

        $finding = $this->audit()['findings']['item_sets_with_different_levels'];
        $this->assertContains($item->id(), $finding['ids']);
    }

    public function testMissingStatusUnderRestrictedItemSet(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();
        $this->getConnection()->executeStatement('DELETE FROM `access_status` WHERE `id` = ?', [$item->id()]);

        $findings = $this->audit()['findings'];
        $this->assertContains($item->id(), $findings['missing_status']['items']['ids']);
        $this->assertContains($item->id(), $findings['missing_status_restricted_parent']['items']['ids']);
    }

    public function testPrivateItemSetForbiddingPublicItems(): void
    {
        $itemSet = $this->createItemSet(['is_public' => false]);
        $this->createItem(['item_set' => $itemSet]);
        $emptyItemSet = $this->createItemSet(['is_public' => false]);
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FORBIDDEN);
        $this->setAccessLevelSet($emptyItemSet->id(), AccessStatus::FORBIDDEN);
        $this->rebuildAccessCascade();

        $ids = $this->audit()['findings']['private_item_sets_forbidding_public']['ids'];
        $this->assertContains($itemSet->id(), $ids);
        $this->assertNotContains($emptyItemSet->id(), $ids);
    }

    public function testItemSetEmbargoWithoutCascade(): void
    {
        $itemSet = $this->createItemSet();
        $this->setAccessLevelSet($itemSet->id(), AccessStatus::FREE);
        $this->getConnection()->executeStatement('UPDATE `access_status` SET `embargo_end_set` = "2100-01-01 00:00:00" WHERE `id` = ?', [$itemSet->id()]);

        $this->assertContains($itemSet->id(), $this->audit()['findings']['item_set_embargoes_without_cascade']['ids']);
        $this->assertArrayNotHasKey('item_set_embargoes_without_cascade', $this->audit(true)['findings']);
    }

    public function testJobStoresReport(): void
    {
        $this->getSettings()->delete('access_audit');
        $job = $this->runJob(\Access\Job\AccessStatusAudit::class, []);
        $this->assertSame(\Omeka\Entity\Job::STATUS_COMPLETED, $job->getStatus());
        $audit = $this->getSettings()->get('access_audit');
        $this->assertSame($job->getId(), $audit['job_id']);
        $this->assertArrayHasKey($audit['mode'], AccessAudit::MODES);
    }

    public function testStoredAuditDisplaysRunningAudit(): void
    {
        $audit = ['date' => '2026-11-01 10:00:00', 'job_id' => 12] + $this->audit();
        $partial = $this->getServiceLocator()->get('ViewHelperManager')->get('partial');

        $html = $partial('common/access-audit', ['audit' => $audit, 'isLive' => false, 'runningJobId' => 34]);
        $this->assertStringContainsString('A new audit is in progress', $html);
        $this->assertStringContainsString('job #34', $html);
        $this->assertStringContainsString('job #12', $html);

        $html = $partial('common/access-audit', ['audit' => $audit, 'isLive' => false, 'runningJobId' => null]);
        $this->assertStringNotContainsString('A new audit is in progress', $html);
    }

    public function testConfigFormDisplaysAudit(): void
    {
        $this->dispatch('/admin/module/configure?id=Access');
        $this->assertResponseStatusCode(200);
        $this->assertQuery('#form-access-audit .access-audit');
        $this->assertQuery('.access-audit-decisions');
    }
}

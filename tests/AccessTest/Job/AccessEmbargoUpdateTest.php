<?php declare(strict_types=1);

namespace AccessTest\Job;

use Access\Entity\AccessStatus;
use Access\Job\AccessEmbargoUpdate;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the AccessEmbargoUpdate job.
 *
 * Tests the job that updates access status when embargoes end,
 * based on the 'access_embargo_ended_level' and 'access_embargo_ended_date' settings.
 *
 * Settings for access_embargo_ended_level:
 * - 'free': Set level to FREE
 * - 'under': Set level to the level under (reserved->free, protected->reserved, forbidden->reserved)
 * - 'keep': Keep current level
 *
 * Settings for access_embargo_ended_date:
 * - 'clear': Remove embargo dates
 * - 'keep': Keep embargo dates
 *
 * @group job
 * @group embargo
 */
class AccessEmbargoUpdateTest extends AbstractHttpControllerTestCase
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

    // ========================================================================
    // Mode: free_clear - Set to FREE and clear dates
    // ========================================================================

    /**
     * Test free_clear mode updates ended embargo correctly.
     */
    public function testFreeClearModeUpdatesEndedEmbargo(): void
    {
        // Create resource with RESERVED status and embargo that has ended.
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        // Verify initial state.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);

        // Set mode and run job.
        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Verify level changed to FREE and dates cleared.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()), 'Level should be FREE after embargo ends with free_clear mode');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start'], 'Embargo start should be cleared');
        $this->assertNull($dates['embargo_end'], 'Embargo end should be cleared');
    }

    /**
     * Test free_clear mode with end-only embargo that has ended.
     */
    public function testFreeClearModeWithEndOnlyEmbargo(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => null,
            'embargo_end' => $this->getPastDate(10),
        ]);

        $this->assertSame(AccessStatus::PROTECTED, $this->getAccessLevel($item->id()));

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_end']);
    }

    /**
     * Test free_clear mode does NOT update active embargo.
     */
    public function testFreeClearModeDoesNotUpdateActiveEmbargo(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => $this->getFutureDate(10),
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Should remain unchanged - embargo is still active.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()), 'Active embargo should not be modified');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);
    }

    /**
     * Test free_clear mode does NOT update future embargo.
     */
    public function testFreeClearModeDoesNotUpdateFutureEmbargo(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getFutureDate(10),
            'embargo_end' => $this->getFutureDate(30),
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Should remain unchanged - embargo hasn't started.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);
    }

    // ========================================================================
    // Mode: free_keep - Set to FREE but keep dates
    // ========================================================================

    /**
     * Test free_keep mode updates level but keeps dates.
     */
    public function testFreeKeepModeUpdatesLevelKeepsDates(): void
    {
        $embargoStart = $this->getPastDate(60);
        $embargoEnd = $this->getPastDate(30);

        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
            'embargo_start' => $embargoStart,
            'embargo_end' => $embargoEnd,
        ]);

        $this->setEmbargoEndedMode('free', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Level should change to FREE.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));

        // But dates should be preserved.
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start'], 'Embargo start should be kept');
        $this->assertNotNull($dates['embargo_end'], 'Embargo end should be kept');
    }

    /**
     * Test free_keep mode with end-only embargo.
     */
    public function testFreeKeepModeWithEndOnlyEmbargo(): void
    {
        $embargoEnd = $this->getPastDate(5);

        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => null,
            'embargo_end' => $embargoEnd,
        ]);

        $this->setEmbargoEndedMode('free', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_end'], 'Embargo end date should be kept');
    }

    // ========================================================================
    // Mode: under_clear - Set to level under and clear dates
    // ========================================================================

    /**
     * Test under_clear mode: reserved -> free, and clear dates.
     */
    public function testUnderClearModeReservedToFree(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('under', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // RESERVED -> FREE
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()), 'RESERVED should become FREE');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start'], 'Embargo start should be cleared');
        $this->assertNull($dates['embargo_end'], 'Embargo end should be cleared');
    }

    /**
     * Test under_clear mode: protected -> reserved, and clear dates.
     */
    public function testUnderClearModeProtectedToReserved(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('under', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // PROTECTED -> RESERVED
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()), 'PROTECTED should become RESERVED');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start']);
        $this->assertNull($dates['embargo_end']);
    }

    /**
     * Test under_clear mode: forbidden -> reserved, and clear dates.
     */
    public function testUnderClearModeForbiddenToReserved(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
            'embargo_start' => null,
            'embargo_end' => $this->getPastDate(10),
        ]);

        $this->setEmbargoEndedMode('under', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // FORBIDDEN -> RESERVED
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()), 'FORBIDDEN should become RESERVED');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_end']);
    }

    /**
     * Test under_clear mode: free stays free.
     */
    public function testUnderClearModeFreeStaysFree(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('under', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // FREE stays FREE
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()), 'FREE should stay FREE');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start']);
        $this->assertNull($dates['embargo_end']);
    }

    // ========================================================================
    // Mode: under_keep - Set to level under but keep dates
    // ========================================================================

    /**
     * Test under_keep mode: reserved -> free, but keep dates.
     */
    public function testUnderKeepModeReservedToFree(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('under', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // RESERVED -> FREE
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()), 'RESERVED should become FREE');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start'], 'Embargo start should be kept');
        $this->assertNotNull($dates['embargo_end'], 'Embargo end should be kept');
    }

    /**
     * Test under_keep mode: protected -> reserved, but keep dates.
     */
    public function testUnderKeepModeProtectedToReserved(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('under', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // PROTECTED -> RESERVED
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()), 'PROTECTED should become RESERVED');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);
    }

    /**
     * Test under_keep mode: forbidden -> reserved, but keep dates.
     */
    public function testUnderKeepModeForbiddenToReserved(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
            'embargo_start' => null,
            'embargo_end' => $this->getPastDate(10),
        ]);

        $this->setEmbargoEndedMode('under', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // FORBIDDEN -> RESERVED
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()), 'FORBIDDEN should become RESERVED');
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_end']);
    }

    // ========================================================================
    // Mode: keep_clear - Keep level but clear dates
    // ========================================================================

    /**
     * Test keep_clear mode keeps level but clears dates.
     */
    public function testKeepClearModeKeepsLevelClearsDates(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('keep', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Level should remain PROTECTED.
        $this->assertSame(AccessStatus::PROTECTED, $this->getAccessLevel($item->id()), 'Level should be kept');

        // But dates should be cleared.
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start'], 'Embargo start should be cleared');
        $this->assertNull($dates['embargo_end'], 'Embargo end should be cleared');
    }

    /**
     * Test keep_clear mode with FORBIDDEN level.
     */
    public function testKeepClearModeWithForbiddenLevel(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
            'embargo_start' => null,
            'embargo_end' => $this->getPastDate(10),
        ]);

        $this->setEmbargoEndedMode('keep', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Level should still be FORBIDDEN.
        $this->assertSame(AccessStatus::FORBIDDEN, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_end']);
    }

    // ========================================================================
    // Mode: keep_keep - Do nothing
    // ========================================================================

    /**
     * Test keep_keep mode does nothing.
     */
    public function testKeepKeepModeDoesNothing(): void
    {
        $embargoStart = $this->getPastDate(60);
        $embargoEnd = $this->getPastDate(30);

        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $embargoStart,
            'embargo_end' => $embargoEnd,
        ]);

        $this->setEmbargoEndedMode('keep', 'keep');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Everything should remain unchanged.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);
    }

    // ========================================================================
    // Multiple Resources
    // ========================================================================

    /**
     * Test job updates multiple resources correctly.
     */
    public function testJobUpdatesMultipleResources(): void
    {
        // Create multiple resources with different embargo states.
        $itemEnded = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $itemActive = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => $this->getFutureDate(10),
        ]);

        $itemNoEmbargo = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Ended embargo: should be updated.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($itemEnded->id()));
        $dates = $this->getEmbargoDates($itemEnded->id());
        $this->assertNull($dates['embargo_start']);
        $this->assertNull($dates['embargo_end']);

        // Active embargo: should NOT be updated.
        $this->assertSame(AccessStatus::PROTECTED, $this->getAccessLevel($itemActive->id()));
        $dates = $this->getEmbargoDates($itemActive->id());
        $this->assertNotNull($dates['embargo_start']);
        $this->assertNotNull($dates['embargo_end']);

        // No embargo: should NOT be affected.
        $this->assertSame(AccessStatus::FORBIDDEN, $this->getAccessLevel($itemNoEmbargo->id()));
    }

    /**
     * Test job handles media with ended embargo.
     */
    public function testJobUpdatesMediaWithEndedEmbargo(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $media = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Media should be updated.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($media->id()));
        $dates = $this->getEmbargoDates($media->id());
        $this->assertNull($dates['embargo_start']);
        $this->assertNull($dates['embargo_end']);
    }

    /**
     * Test job handles item sets with ended embargo.
     */
    public function testJobUpdatesItemSetWithEndedEmbargo(): void
    {
        $itemSet = $this->createItemSet([
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
            'embargo_start' => null,
            'embargo_end' => $this->getPastDate(10),
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($itemSet->id()));
        $dates = $this->getEmbargoDates($itemSet->id());
        $this->assertNull($dates['embargo_end']);
    }

    // ========================================================================
    // Start-Only Embargo (Indefinite)
    // ========================================================================

    /**
     * Test job handles start-only embargo that has started (indefinite).
     *
     * When only embargo_start is set and that date has passed,
     * it's an indefinite embargo from that date forward.
     * The free_clear mode should update it.
     */
    public function testJobWithStartOnlyEmbargoThatStarted(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => null,
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // For start-only that has started: level should be updated to FREE.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNull($dates['embargo_start']);
    }

    /**
     * Test job does NOT update start-only embargo that hasn't started.
     */
    public function testJobDoesNotUpdateStartOnlyEmbargoNotStarted(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getFutureDate(10),
            'embargo_end' => null,
        ]);

        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Should remain unchanged - embargo hasn't started yet.
        $this->assertSame(AccessStatus::RESERVED, $this->getAccessLevel($item->id()));
        $dates = $this->getEmbargoDates($item->id());
        $this->assertNotNull($dates['embargo_start']);
    }

    // ========================================================================
    // Access Check After Job
    // ========================================================================

    /**
     * Test that after job runs, previously embargoed content is accessible.
     */
    public function testContentAccessibleAfterEmbargoEndsAndJobRuns(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
        ]);

        $media = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $mediaId = $media->id();

        // Before job: media should be denied (RESERVED with ended embargo
        // but status not yet updated by job).
        $this->logout();
        $this->assertMediaContentDenied($media, 'RESERVED media should be denied before job (status still RESERVED)');

        // Run job to update status.
        $this->loginAdmin();
        $this->setEmbargoEndedMode('free', 'clear');
        $this->runJob(AccessEmbargoUpdate::class, []);

        // Verify the job actually updated the access level.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($mediaId), 'Job should have set level to FREE');

        // Re-fetch the media representation to get fresh data.
        $media = $this->api()->read('media', $mediaId)->getContent();

        // After job: media should be accessible (now FREE).
        $this->logout();
        $this->assertMediaContentAllowed($media, 'Media should be allowed after embargo ends and job sets FREE');
    }
}

<?php declare(strict_types=1);

namespace AccessTest\Entity;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use DateTime;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for embargo functionality.
 *
 * Tests embargo dates on access status and how they affect media content access:
 * - Embargo start date: resource under embargo from this date
 * - Embargo end date: resource under embargo until this date
 * - Both dates: resource under embargo between the dates
 * - Bypass setting: allows ignoring embargo checks
 *
 * @group embargo
 * @group integration
 */
class EmbargoTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        // Set access_modes to require authentication for reserved/protected.
        $this->setAccessModes(['user']);
        $this->setEmbargoBypass(false); // Default: embargoes are enforced.
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    // ========================================================================
    // Basic Embargo Tests
    // ========================================================================

    /**
     * Test that resource under active embargo is denied.
     */
    public function testActiveEmbargoDenied(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'Media under active embargo should be denied'
        );
    }

    /**
     * Test that resource after embargo end is allowed.
     */
    public function testEmbargoEndedAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(60),
                'embargo_end' => $this->getPastDate(30),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media after embargo end should be allowed'
        );
    }

    /**
     * Test that resource before embargo start is allowed.
     */
    public function testEmbargoNotStartedAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getFutureDate(30),
                'embargo_end' => $this->getFutureDate(60),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media before embargo start should be allowed'
        );
    }

    // ========================================================================
    // Start-Only and End-Only Embargo Tests
    // ========================================================================

    /**
     * Test embargo with only start date (past) - indefinite embargo.
     */
    public function testEmbargoStartOnlyPastDenied(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => null,
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'Media with past-only embargo start should be denied (indefinite)'
        );
    }

    /**
     * Test embargo with only start date (future) - not yet embargoed.
     */
    public function testEmbargoStartOnlyFutureAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getFutureDate(10),
                'embargo_end' => null,
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media with future-only embargo start should be allowed'
        );
    }

    /**
     * Test embargo with only end date (future) - embargoed until date.
     */
    public function testEmbargoEndOnlyFutureDenied(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => null,
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'Media with future-only embargo end should be denied'
        );
    }

    /**
     * Test embargo with only end date (past) - embargo ended.
     */
    public function testEmbargoEndOnlyPastAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => null,
                'embargo_end' => $this->getPastDate(10),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media with past-only embargo end should be allowed'
        );
    }

    // ========================================================================
    // Embargo Bypass Tests
    // ========================================================================

    /**
     * Test that embargo bypass setting allows access to embargoed content.
     */
    public function testEmbargoBypassAllowsAccess(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        // Enable bypass.
        $this->setEmbargoBypass(true);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media under embargo should be allowed when bypass is enabled'
        );
    }

    /**
     * Test that admin can access embargoed content regardless of bypass setting.
     */
    public function testAdminCanAccessEmbargoedContent(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->loginAdmin();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Admin should access embargoed content'
        );
    }

    // ========================================================================
    // Guest (Authenticated Non-Admin) User Embargo Tests
    // ========================================================================

    /**
     * Test that guest user cannot access embargoed content.
     */
    public function testGuestCannotAccessEmbargoedContent(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'Guest should not access embargoed content'
        );
    }

    /**
     * Test that guest user can access content after embargo ends.
     */
    public function testGuestCanAccessAfterEmbargoEnds(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(60),
                'embargo_end' => $this->getPastDate(30),
            ],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Guest should access content after embargo ends'
        );
    }

    /**
     * Test that guest user can access content before embargo starts.
     */
    public function testGuestCanAccessBeforeEmbargoStarts(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getFutureDate(30),
                'embargo_end' => $this->getFutureDate(60),
            ],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Guest should access content before embargo starts'
        );
    }

    /**
     * Test that embargo bypass also works for guest users.
     */
    public function testEmbargoBypassAllowsGuestAccess(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->setEmbargoBypass(true);
        $this->loginAsGuest();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Guest should access embargoed content when bypass is enabled'
        );
    }

    // ========================================================================
    // Embargo with Access Levels Combination Tests
    // ========================================================================

    /**
     * Test embargo on RESERVED content.
     */
    public function testEmbargoOnReservedContent(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::RESERVED],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::RESERVED,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'RESERVED media under embargo should be denied'
        );
    }

    /**
     * Test embargo on PROTECTED content.
     */
    public function testEmbargoOnProtectedContent(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::PROTECTED],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::PROTECTED,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'PROTECTED media under embargo should be denied'
        );
    }

    /**
     * Test that FORBIDDEN takes precedence over embargo dates.
     */
    public function testForbiddenTakesPrecedenceOverEmbargo(): void
    {
        // Create FORBIDDEN media with embargo that has ended.
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FORBIDDEN,
                'embargo_start' => $this->getPastDate(60),
                'embargo_end' => $this->getPastDate(30),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'FORBIDDEN media should be denied even after embargo ends'
        );
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    /**
     * Test media with no embargo dates is not affected.
     */
    public function testNoEmbargoDatesNotAffected(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => null,
                'embargo_end' => null,
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media without embargo dates should be allowed'
        );
    }

    /**
     * Test embargo exactly at current time (boundary condition).
     */
    public function testEmbargoAtCurrentTime(): void
    {
        $now = new DateTime();

        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => (clone $now)->modify('-1 second'),
                'embargo_end' => (clone $now)->modify('+1 hour'),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentDenied(
            $hierarchy['media'],
            'Media just inside embargo window should be denied'
        );
    }

    /**
     * Test embargo on item affects all its media.
     */
    public function testEmbargoOnItemAffectsMedia(): void
    {
        // Create item with embargo, media inherits.
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => $this->getFutureDate(10),
        ]);

        $media = $this->createMedia($item, [
            'is_public' => true,
            // No access status set - should inherit from item?
            // Note: This depends on implementation. Media checks its own status.
        ]);

        // The access check is on media's own status, not inherited from item.
        // If media has no embargo, it should be allowed.
        $this->logout();
        $this->assertMediaContentAllowed(
            $media,
            'Media without its own embargo should be allowed'
        );
    }

    /**
     * Test multiple media with different embargo dates.
     */
    public function testMultipleMediaDifferentEmbargoes(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaEmbargoed = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
            'embargo_start' => $this->getPastDate(10),
            'embargo_end' => $this->getFutureDate(10),
        ]);

        $mediaNotEmbargoed = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaEmbargoEnded = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
            'embargo_start' => $this->getPastDate(60),
            'embargo_end' => $this->getPastDate(30),
        ]);

        $this->logout();

        $this->assertMediaContentDenied($mediaEmbargoed, 'Embargoed media should be denied');
        $this->assertMediaContentAllowed($mediaNotEmbargoed, 'Non-embargoed media should be allowed');
        $this->assertMediaContentAllowed($mediaEmbargoEnded, 'Post-embargo media should be allowed');
    }

    /**
     * Test embargo precision (date vs datetime).
     */
    public function testEmbargoPrecision(): void
    {
        // Test that datetime precision is used, not just date.
        $now = new DateTime();

        // Embargo that ended 1 minute ago.
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
                'embargo_start' => $this->getPastDate(1),
                'embargo_end' => (clone $now)->modify('-1 minute'),
            ],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Media with embargo ended 1 minute ago should be allowed'
        );
    }
}

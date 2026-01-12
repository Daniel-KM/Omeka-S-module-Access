<?php declare(strict_types=1);

namespace AccessTest\Entity;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for access status inheritance rules.
 *
 * Tests the inheritance logic between item sets, items, and media:
 * - When visibility is private, resource is not accessible (except owner/admins)
 * - When visibility is public, access status is checked
 * - Media inherits from item if it has no status
 * - Media status is only used if more restrictive than item's status
 *
 * The restrictiveness order is: free < reserved < protected < forbidden
 *
 * @group inheritance
 * @group integration
 */
class AccessStatusInheritanceTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        // Set access_modes to require authentication for reserved/protected.
        // With 'user' mode set, anonymous users are denied reserved/protected content.
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
    // Public/Private Visibility Tests
    // ========================================================================

    /**
     * Test that public media with FREE access is allowed.
     */
    public function testPublicMediaFreeAccessAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
        ]);

        $this->logout(); // Test as anonymous visitor.
        $this->assertMediaContentAllowed($hierarchy['media']);
    }

    /**
     * Test that public media with no access status defaults to allowed.
     */
    public function testPublicMediaNoStatusAllowed(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true],
            'media' => ['is_public' => true],
        ]);

        $this->logout();
        $this->assertMediaContentAllowed($hierarchy['media']);
    }

    /**
     * Test that FORBIDDEN media is denied regardless of item status.
     */
    public function testForbiddenMediaDenied(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FORBIDDEN],
        ]);

        $this->logout();
        $this->assertMediaContentDenied($hierarchy['media']);
    }

    // ========================================================================
    // Restrictiveness Order Tests
    // ========================================================================

    /**
     * Data provider for restrictiveness tests.
     *
     * The Access module checks ONLY the media's own access status.
     * If media has no access status, it's allowed (no inheritance from item).
     * Item access status doesn't cascade to media automatically.
     *
     * @return array Test cases: [itemLevel, mediaLevel, expectedResult, description]
     */
    public function restrictivenessCasesProvider(): array
    {
        // Format: [item_level, media_level, should_be_allowed_for_anonymous, description]
        // Note: Media access is checked independently - no inheritance from item.
        return [
            // Item FREE + various media levels.
            [AccessStatus::FREE, null, true, 'FREE item, no media status (allowed - no inheritance)'],
            [AccessStatus::FREE, AccessStatus::FREE, true, 'FREE item, FREE media'],
            [AccessStatus::FREE, AccessStatus::RESERVED, false, 'FREE item, RESERVED media'],
            [AccessStatus::FREE, AccessStatus::PROTECTED, false, 'FREE item, PROTECTED media'],
            [AccessStatus::FREE, AccessStatus::FORBIDDEN, false, 'FREE item, FORBIDDEN media'],

            // Item RESERVED + various media levels.
            // Note: Media without status is still allowed (no inheritance).
            [AccessStatus::RESERVED, null, true, 'RESERVED item, no media status (allowed - no inheritance)'],
            [AccessStatus::RESERVED, AccessStatus::FREE, true, 'RESERVED item, FREE media (media status applies)'],
            [AccessStatus::RESERVED, AccessStatus::RESERVED, false, 'RESERVED item, RESERVED media'],
            [AccessStatus::RESERVED, AccessStatus::PROTECTED, false, 'RESERVED item, PROTECTED media'],
            [AccessStatus::RESERVED, AccessStatus::FORBIDDEN, false, 'RESERVED item, FORBIDDEN media'],

            // Item PROTECTED + various media levels.
            [AccessStatus::PROTECTED, null, true, 'PROTECTED item, no media status (allowed - no inheritance)'],
            [AccessStatus::PROTECTED, AccessStatus::FREE, true, 'PROTECTED item, FREE media (media status applies)'],
            [AccessStatus::PROTECTED, AccessStatus::RESERVED, false, 'PROTECTED item, RESERVED media'],
            [AccessStatus::PROTECTED, AccessStatus::PROTECTED, false, 'PROTECTED item, PROTECTED media'],
            [AccessStatus::PROTECTED, AccessStatus::FORBIDDEN, false, 'PROTECTED item, FORBIDDEN media'],

            // Item FORBIDDEN + various media levels.
            [AccessStatus::FORBIDDEN, null, true, 'FORBIDDEN item, no media status (allowed - no inheritance)'],
            [AccessStatus::FORBIDDEN, AccessStatus::FREE, true, 'FORBIDDEN item, FREE media (media status applies)'],
            [AccessStatus::FORBIDDEN, AccessStatus::RESERVED, false, 'FORBIDDEN item, RESERVED media'],
            [AccessStatus::FORBIDDEN, AccessStatus::PROTECTED, false, 'FORBIDDEN item, PROTECTED media'],
            [AccessStatus::FORBIDDEN, AccessStatus::FORBIDDEN, false, 'FORBIDDEN item, FORBIDDEN media'],
        ];
    }

    /**
     * Test restrictiveness inheritance for anonymous users.
     *
     * @dataProvider restrictivenessCasesProvider
     */
    public function testRestrictivenessForAnonymous(
        string $itemLevel,
        ?string $mediaLevel,
        bool $shouldBeAllowed,
        string $description
    ): void {
        $itemOptions = ['is_public' => true, 'access_level' => $itemLevel];
        $mediaOptions = ['is_public' => true];

        if ($mediaLevel !== null) {
            $mediaOptions['access_level'] = $mediaLevel;
        }

        $hierarchy = $this->createResourceHierarchy([
            'item' => $itemOptions,
            'media' => $mediaOptions,
        ]);

        $this->logout(); // Anonymous user.

        if ($shouldBeAllowed) {
            $this->assertMediaContentAllowed($hierarchy['media'], $description);
        } else {
            $this->assertMediaContentDenied($hierarchy['media'], $description);
        }
    }

    // ========================================================================
    // Guest (Authenticated Non-Admin) User Tests
    // ========================================================================

    /**
     * Test restrictiveness inheritance for guest (authenticated) users.
     *
     * @dataProvider restrictivenessCasesProvider
     */
    public function testRestrictivenessForGuest(
        string $itemLevel,
        ?string $mediaLevel,
        bool $shouldBeAllowed,
        string $description
    ): void {
        $itemOptions = ['is_public' => true, 'access_level' => $itemLevel];
        $mediaOptions = ['is_public' => true];

        if ($mediaLevel !== null) {
            $mediaOptions['access_level'] = $mediaLevel;
        }

        $hierarchy = $this->createResourceHierarchy([
            'item' => $itemOptions,
            'media' => $mediaOptions,
        ]);

        $this->loginAsGuest(); // Authenticated non-admin user.

        // Guest users should have same access as anonymous for access levels.
        if ($shouldBeAllowed) {
            $this->assertMediaContentAllowed($hierarchy['media'], "Guest: $description");
        } else {
            $this->assertMediaContentDenied($hierarchy['media'], "Guest: $description");
        }
    }

    /**
     * Test that guest user cannot access FORBIDDEN media.
     */
    public function testGuestCannotAccessForbiddenMedia(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FORBIDDEN],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentDenied($hierarchy['media'], 'Guest should not access FORBIDDEN media');
    }

    /**
     * Test that guest user cannot access RESERVED media (without special mode).
     */
    public function testGuestCannotAccessReservedMedia(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::RESERVED],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentDenied($hierarchy['media'], 'Guest should not access RESERVED media without mode');
    }

    /**
     * Test that guest user can access FREE media.
     */
    public function testGuestCanAccessFreeMedia(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
        ]);

        $this->loginAsGuest();
        $this->assertMediaContentAllowed($hierarchy['media'], 'Guest should access FREE media');
    }

    // ========================================================================
    // Admin Override Tests
    // ========================================================================

    /**
     * Test that admin can access FORBIDDEN media.
     */
    public function testAdminCanAccessForbiddenMedia(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FORBIDDEN],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FORBIDDEN],
        ]);

        $this->loginAdmin();
        $this->assertMediaContentAllowed($hierarchy['media'], 'Admin should access FORBIDDEN media');
    }

    /**
     * Test that admin can access PROTECTED media.
     */
    public function testAdminCanAccessProtectedMedia(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::PROTECTED],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::PROTECTED],
        ]);

        $this->loginAdmin();
        $this->assertMediaContentAllowed($hierarchy['media'], 'Admin should access PROTECTED media');
    }

    // ========================================================================
    // Multiple Media with Different Statuses
    // ========================================================================

    /**
     * Test multiple media on same item with different access levels.
     */
    public function testMultipleMediaDifferentLevels(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaFree = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaReserved = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
        ]);

        $mediaForbidden = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        $this->logout(); // Anonymous user.

        $this->assertMediaContentAllowed($mediaFree, 'FREE media should be allowed');
        $this->assertMediaContentDenied($mediaReserved, 'RESERVED media should be denied');
        $this->assertMediaContentDenied($mediaForbidden, 'FORBIDDEN media should be denied');
    }

    /**
     * Test multiple media with mixed visibility.
     */
    public function testMultipleMediaMixedVisibility(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaPublicFree = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaPrivateFree = $this->createMedia($item, [
            'is_public' => false,
            'access_level' => AccessStatus::FREE,
        ]);

        $this->logout(); // Anonymous user.

        $this->assertMediaContentAllowed($mediaPublicFree, 'Public FREE media should be allowed');
        // Note: Private media won't be accessible via API at all for anonymous users.
        // The isAllowedMediaContent check happens after API visibility check.
    }

    // ========================================================================
    // Item Set (Collection) Hierarchy Tests
    // ========================================================================

    /**
     * Test item set with items and media at different levels.
     */
    public function testFullHierarchyDifferentLevels(): void
    {
        // Create a restricted item set.
        $itemSet = $this->createItemSet([
            'is_public' => true,
            'access_level' => AccessStatus::RESERVED,
        ]);

        // Create an item with FREE access in the restricted set.
        $item = $this->createItem([
            'item_set' => $itemSet,
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        // Create media with no specific access level.
        $media = $this->createMedia($item, [
            'is_public' => true,
        ]);

        $this->logout();

        // Media should follow item's FREE status (not item set's RESERVED).
        // Note: Item set status doesn't cascade to items automatically.
        $this->assertMediaContentAllowed($media, 'Media should follow item FREE status');
    }

    /**
     * Test item inheriting no restrictions when item set is restricted.
     *
     * Item sets restrict access differently - they control which items are in the set,
     * but don't automatically cascade access restrictions to items.
     */
    public function testItemNotAffectedByItemSetRestriction(): void
    {
        $itemSet = $this->createItemSet([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        $item = $this->createItem([
            'item_set' => $itemSet,
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $media = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $this->logout();

        // Item's own FREE status should apply.
        $this->assertMediaContentAllowed($media);
    }

    // ========================================================================
    // Edge Cases
    // ========================================================================

    /**
     * Test resource without any access status.
     */
    public function testResourceWithoutAccessStatus(): void
    {
        $item = $this->createItem(['is_public' => true]);
        $media = $this->createMedia($item, ['is_public' => true]);

        // Don't set any access status - should default to allowed.
        $this->logout();
        $this->assertMediaContentAllowed($media);
    }

    /**
     * Test that null media is not allowed.
     */
    public function testNullMediaNotAllowed(): void
    {
        $plugin = $this->getServiceLocator()
            ->get('ControllerPluginManager')
            ->get('isAllowedMediaContent');

        $this->assertFalse($plugin(null), 'Null media should not be allowed');
    }

    /**
     * Test invalid access level in database is treated as FORBIDDEN.
     */
    public function testInvalidAccessLevelTreatedAsForbidden(): void
    {
        $item = $this->createItem(['is_public' => true]);
        $media = $this->createMedia($item, ['is_public' => true]);

        // Directly insert invalid level.
        $this->setAccessStatus($media->id(), 'invalid_level');

        $this->logout();
        $this->assertMediaContentDenied($media, 'Invalid access level should deny access');
    }
}

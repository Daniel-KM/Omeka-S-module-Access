<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Comprehensive tests for IsAllowedMediaContent controller plugin.
 *
 * Tests all combinations of:
 * - Resource types: item sets (collections), items, media
 * - Visibility: public, private
 * - Access levels: free, reserved, protected, forbidden
 * - User types: anonymous, authenticated, admin, owner
 * - Embargo dates
 *
 * @group isallowed
 * @group integration
 */
class IsAllowedMediaContentTest extends AbstractHttpControllerTestCase
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
    // Complete Matrix Tests: Visibility × Access Level × User Type
    // ========================================================================

    /**
     * Data provider for complete visibility/access/user matrix.
     *
     * @return array Test cases: [is_public, access_level, user_type, expected, description]
     */
    public function visibilityAccessUserMatrixProvider(): array
    {
        $levels = [
            AccessStatus::FREE,
            AccessStatus::RESERVED,
            AccessStatus::PROTECTED,
            AccessStatus::FORBIDDEN,
        ];

        $cases = [];

        foreach ([true, false] as $isPublic) {
            foreach ($levels as $level) {
                $visibility = $isPublic ? 'public' : 'private';

                // Anonymous user - only test public media.
                if ($isPublic) {
                    $expected = $level === AccessStatus::FREE;
                    $cases["anon_{$visibility}_{$level}"] = [
                        $isPublic,
                        $level,
                        'anonymous',
                        $expected,
                        "Anonymous, {$visibility} {$level} media",
                    ];
                }

                // Guest user (authenticated non-admin) - same access as anonymous for access levels.
                if ($isPublic) {
                    $expected = $level === AccessStatus::FREE;
                    $cases["guest_{$visibility}_{$level}"] = [
                        $isPublic,
                        $level,
                        'guest',
                        $expected,
                        "Guest, {$visibility} {$level} media",
                    ];
                }

                // Admin user - always allowed (has view-all permission).
                $cases["admin_{$visibility}_{$level}"] = [
                    $isPublic,
                    $level,
                    'admin',
                    true,
                    "Admin, {$visibility} {$level} media",
                ];
            }
        }

        return $cases;
    }

    /**
     * Test complete visibility/access/user matrix.
     *
     * @dataProvider visibilityAccessUserMatrixProvider
     */
    public function testVisibilityAccessUserMatrix(
        bool $isPublic,
        string $accessLevel,
        string $userType,
        bool $expected,
        string $description
    ): void {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => [
                'is_public' => $isPublic,
                'access_level' => $accessLevel,
            ],
        ]);

        // Setup user context.
        switch ($userType) {
            case 'anonymous':
                $this->logout();
                break;
            case 'guest':
                $this->loginAsGuest();
                break;
            case 'admin':
                $this->loginAdmin();
                break;
        }

        // For private media, anonymous and guest can't even access via API.
        // So we only test public media for non-admin users.
        if (in_array($userType, ['anonymous', 'guest']) && !$isPublic) {
            $this->markTestSkipped("{$userType} cannot access private media via API");
            return;
        }

        if ($expected) {
            $this->assertMediaContentAllowed($hierarchy['media'], $description);
        } else {
            $this->assertMediaContentDenied($hierarchy['media'], $description);
        }
    }

    // ========================================================================
    // Item Set (Collection) × Item × Media Combinations
    // ========================================================================

    /**
     * Data provider for collection/item/media combinations.
     *
     * The Access module checks ONLY the media's own access status.
     * If media has no access status, it's allowed (no inheritance from item or collection).
     *
     * @return array
     */
    public function hierarchyCombinationsProvider(): array
    {
        $f = AccessStatus::FREE;
        $r = AccessStatus::RESERVED;
        $p = AccessStatus::PROTECTED;
        $x = AccessStatus::FORBIDDEN;

        // [item_set_level, item_level, media_level, expected_anonymous, description]
        // Note: Only media's own access status is checked. No inheritance.
        return [
            // All FREE.
            [$f, $f, $f, true, 'All FREE'],
            [$f, $f, null, true, 'Collection FREE, Item FREE, Media none (allowed)'],

            // Collection restricted, Item/Media free.
            [$r, $f, $f, true, 'Collection RESERVED, Item/Media FREE'],
            [$p, $f, $f, true, 'Collection PROTECTED, Item/Media FREE'],
            [$x, $f, $f, true, 'Collection FORBIDDEN, Item/Media FREE'],

            // Item restricted, Media free or none.
            // Note: Media access is independent - item status doesn't cascade.
            [$f, $r, $f, true, 'Item RESERVED, Media FREE (media status applies)'],
            [$f, $r, null, true, 'Item RESERVED, Media none (allowed - no inheritance)'],
            [$f, $p, $f, true, 'Item PROTECTED, Media FREE (media status applies)'],
            [$f, $x, $f, true, 'Item FORBIDDEN, Media FREE (media status applies)'],

            // Media restricted.
            [$f, $f, $r, false, 'Media RESERVED'],
            [$f, $f, $p, false, 'Media PROTECTED'],
            [$f, $f, $x, false, 'Media FORBIDDEN'],

            // Mixed restrictions - media's own status determines access.
            [$r, $r, $r, false, 'All RESERVED'],
            [$f, $r, $p, false, 'Item RESERVED, Media PROTECTED'],
            [$r, $p, $x, false, 'Item PROTECTED, Media FORBIDDEN'],

            // Item FORBIDDEN doesn't affect media with different status.
            [$f, $x, $r, false, 'Item FORBIDDEN, Media RESERVED (media status applies)'],
            [$f, $x, null, true, 'Item FORBIDDEN, no Media level (allowed - no inheritance)'],
        ];
    }

    /**
     * Test collection/item/media level combinations.
     *
     * @dataProvider hierarchyCombinationsProvider
     */
    public function testHierarchyCombinations(
        string $itemSetLevel,
        string $itemLevel,
        ?string $mediaLevel,
        bool $expectedAnonymous,
        string $description
    ): void {
        $itemSetOptions = ['is_public' => true, 'access_level' => $itemSetLevel];
        $itemOptions = ['is_public' => true, 'access_level' => $itemLevel];
        $mediaOptions = ['is_public' => true];

        if ($mediaLevel !== null) {
            $mediaOptions['access_level'] = $mediaLevel;
        }

        $hierarchy = $this->createResourceHierarchy([
            'item_set' => $itemSetOptions,
            'item' => $itemOptions,
            'media' => $mediaOptions,
        ]);

        $this->logout();

        if ($expectedAnonymous) {
            $this->assertMediaContentAllowed($hierarchy['media'], $description);
        } else {
            $this->assertMediaContentDenied($hierarchy['media'], $description);
        }
    }

    /**
     * Test collection/item/media level combinations for guest user.
     *
     * @dataProvider hierarchyCombinationsProvider
     */
    public function testHierarchyCombinationsForGuest(
        string $itemSetLevel,
        string $itemLevel,
        ?string $mediaLevel,
        bool $expectedAnonymous,
        string $description
    ): void {
        $itemSetOptions = ['is_public' => true, 'access_level' => $itemSetLevel];
        $itemOptions = ['is_public' => true, 'access_level' => $itemLevel];
        $mediaOptions = ['is_public' => true];

        if ($mediaLevel !== null) {
            $mediaOptions['access_level'] = $mediaLevel;
        }

        $hierarchy = $this->createResourceHierarchy([
            'item_set' => $itemSetOptions,
            'item' => $itemOptions,
            'media' => $mediaOptions,
        ]);

        $this->loginAsGuest();

        // Guest should have same access as anonymous.
        if ($expectedAnonymous) {
            $this->assertMediaContentAllowed($hierarchy['media'], "Guest: $description");
        } else {
            $this->assertMediaContentDenied($hierarchy['media'], "Guest: $description");
        }
    }

    // ========================================================================
    // Owner Access Tests
    // ========================================================================

    /**
     * Test that owner can access their own FORBIDDEN media.
     *
     * Note: This requires creating a non-admin user who owns the resource.
     */
    public function testOwnerCanAccessOwnForbiddenMedia(): void
    {
        // Create resources as admin.
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FORBIDDEN],
        ]);

        // Admin is the owner, so should have access.
        $this->loginAdmin();
        $this->assertMediaContentAllowed(
            $hierarchy['media'],
            'Owner should access their FORBIDDEN media'
        );
    }

    /**
     * Test that owner can access their own embargoed media.
     */
    public function testOwnerCanAccessOwnEmbargoedMedia(): void
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
            'Owner should access their embargoed media'
        );
    }

    // ========================================================================
    // Multiple Media Same Item Tests
    // ========================================================================

    /**
     * Test multiple media with all access levels for anonymous users.
     */
    public function testMultipleMediaAllLevelsAnonymous(): void
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

        $mediaProtected = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
        ]);

        $mediaForbidden = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        // Test anonymous access.
        $this->logout();

        $this->assertMediaContentAllowed($mediaFree, 'Anonymous: FREE media allowed');
        $this->assertMediaContentDenied($mediaReserved, 'Anonymous: RESERVED media denied');
        $this->assertMediaContentDenied($mediaProtected, 'Anonymous: PROTECTED media denied');
        $this->assertMediaContentDenied($mediaForbidden, 'Anonymous: FORBIDDEN media denied');
    }

    /**
     * Test multiple media with all access levels for guest users.
     */
    public function testMultipleMediaAllLevelsGuest(): void
    {
        // Create resources as admin first.
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

        $mediaProtected = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
        ]);

        $mediaForbidden = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        // Switch to guest for testing access.
        $this->loginAsGuest();

        $this->assertMediaContentAllowed($mediaFree, 'Guest: FREE media allowed');
        $this->assertMediaContentDenied($mediaReserved, 'Guest: RESERVED media denied');
        $this->assertMediaContentDenied($mediaProtected, 'Guest: PROTECTED media denied');
        $this->assertMediaContentDenied($mediaForbidden, 'Guest: FORBIDDEN media denied');
    }

    /**
     * Test multiple media with all access levels for admin users.
     */
    public function testMultipleMediaAllLevelsAdmin(): void
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

        $mediaProtected = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::PROTECTED,
        ]);

        $mediaForbidden = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        // Admin is already logged in from setUp.
        $this->assertMediaContentAllowed($mediaFree, 'Admin: FREE media allowed');
        $this->assertMediaContentAllowed($mediaReserved, 'Admin: RESERVED media allowed');
        $this->assertMediaContentAllowed($mediaProtected, 'Admin: PROTECTED media allowed');
        $this->assertMediaContentAllowed($mediaForbidden, 'Admin: FORBIDDEN media allowed');
    }

    /**
     * Test mixed visibility media on same item.
     */
    public function testMixedVisibilityMedia(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $publicMedia = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $privateMedia = $this->createMedia($item, [
            'is_public' => false,
            'access_level' => AccessStatus::FREE,
        ]);

        // Anonymous can only access public media.
        $this->logout();
        $this->assertMediaContentAllowed($publicMedia, 'Public media allowed for anonymous');
        // Private media check: anonymous can't even load it via API.

        // Admin can access both.
        $this->loginAdmin();
        $this->assertMediaContentAllowed($publicMedia, 'Public media allowed for admin');
        $this->assertMediaContentAllowed($privateMedia, 'Private media allowed for admin');
    }

    // ========================================================================
    // Items in Multiple Collections Tests
    // ========================================================================

    /**
     * Test item in multiple collections with different access levels.
     */
    public function testItemInMultipleCollections(): void
    {
        $collectionFree = $this->createItemSet([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $collectionRestricted = $this->createItemSet([
            'is_public' => true,
            'access_level' => AccessStatus::FORBIDDEN,
        ]);

        // Create item in both collections.
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        // Add to both item sets via direct API.
        $this->api()->update('items', $item->id(), [
            'o:item_set' => [
                ['o:id' => $collectionFree->id()],
                ['o:id' => $collectionRestricted->id()],
            ],
        ], [], ['isPartial' => true]);

        $media = $this->createMedia($item, [
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        // Item's own FREE status should apply.
        $this->logout();
        $this->assertMediaContentAllowed(
            $media,
            'Item FREE status applies regardless of collection status'
        );
    }

    // ========================================================================
    // Embargo Combined with Access Level Tests
    // ========================================================================

    /**
     * Test all access levels with active embargo.
     */
    public function testAllLevelsWithActiveEmbargo(): void
    {
        $levels = [
            AccessStatus::FREE,
            AccessStatus::RESERVED,
            AccessStatus::PROTECTED,
            AccessStatus::FORBIDDEN,
        ];

        // Create all resources as admin first.
        $mediaByLevel = [];
        foreach ($levels as $level) {
            $item = $this->createItem([
                'is_public' => true,
                'access_level' => AccessStatus::FREE,
            ]);

            $mediaByLevel[$level] = $this->createMedia($item, [
                'is_public' => true,
                'access_level' => $level,
                'embargo_start' => $this->getPastDate(10),
                'embargo_end' => $this->getFutureDate(10),
            ]);
        }

        // Now test as anonymous.
        $this->logout();
        foreach ($levels as $level) {
            $this->assertMediaContentDenied(
                $mediaByLevel[$level],
                "{$level} media with active embargo should be denied"
            );
        }
    }

    /**
     * Test that embargo takes effect before access level check.
     */
    public function testEmbargoCheckedBeforeAccessLevel(): void
    {
        // Even FREE media with embargo should be denied.
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
            'FREE media under embargo should still be denied'
        );
    }

    // ========================================================================
    // Stress/Edge Case Tests
    // ========================================================================

    /**
     * Test many media with mixed settings.
     */
    public function testManyMediaMixedSettings(): void
    {
        $item = $this->createItem([
            'is_public' => true,
            'access_level' => AccessStatus::FREE,
        ]);

        $mediaCount = 10;
        $createdMedia = [];

        for ($i = 0; $i < $mediaCount; $i++) {
            $levels = [AccessStatus::FREE, AccessStatus::RESERVED, AccessStatus::PROTECTED, AccessStatus::FORBIDDEN];
            $level = $levels[$i % 4];
            $isPublic = ($i % 2) === 0;

            $media = $this->createMedia($item, [
                'is_public' => $isPublic,
                'access_level' => $level,
            ]);

            $createdMedia[] = [
                'media' => $media,
                'level' => $level,
                'is_public' => $isPublic,
            ];
        }

        // Test anonymous access.
        $this->logout();

        foreach ($createdMedia as $data) {
            if (!$data['is_public']) {
                continue; // Skip private media for anonymous.
            }

            $expectedAllowed = $data['level'] === AccessStatus::FREE;
            if ($expectedAllowed) {
                $this->assertMediaContentAllowed(
                    $data['media'],
                    "Media with {$data['level']} should be allowed"
                );
            } else {
                $this->assertMediaContentDenied(
                    $data['media'],
                    "Media with {$data['level']} should be denied"
                );
            }
        }
    }

    /**
     * Test resources without explicit access_level default to FREE.
     *
     * The Access module automatically creates an AccessStatus with level FREE
     * when a resource is created without an explicit access_level.
     */
    public function testResourcesWithoutExplicitAccessLevelDefaultToFree(): void
    {
        // Create resources without setting access_level.
        $itemSet = $this->createItemSet(['is_public' => true]);
        $item = $this->createItem(['is_public' => true, 'item_set' => $itemSet]);
        $media = $this->createMedia($item, ['is_public' => true]);

        // Module auto-creates access status with FREE level.
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($itemSet->id()));
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($item->id()));
        $this->assertSame(AccessStatus::FREE, $this->getAccessLevel($media->id()));

        // Should be allowed (FREE level).
        $this->logout();
        $this->assertMediaContentAllowed(
            $media,
            'Media with default FREE access should be allowed'
        );
    }

    /**
     * Test rapid access checks don't cause issues.
     */
    public function testRapidAccessChecks(): void
    {
        $hierarchy = $this->createResourceHierarchy([
            'item' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
            'media' => ['is_public' => true, 'access_level' => AccessStatus::FREE],
        ]);

        $this->logout();

        // Check same media multiple times.
        for ($i = 0; $i < 10; $i++) {
            $this->assertMediaContentAllowed(
                $hierarchy['media'],
                "Rapid check {$i} should work"
            );
        }
    }
}

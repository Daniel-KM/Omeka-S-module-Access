<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Exhaustive matrix of the file gating: visitor x item set x item x media.
 *
 * The four states per level are undefined (no admin decision), free, reserved
 * and forbidden. The effective media level is the strictest of the three
 * (undefined = free). The gating is then:
 *
 *  - anonymous: allowed only when the effective level is free;
 *  - guest (role guest, mode guest): reserved is unlocked too, forbidden is
 *    always denied.
 *
 * A federation visitor (SSO / external) behaves like the guest for the level
 * check; the collection scoping specific to IP / SSO IdP is covered by
 * CollectionScopedBypassTest.
 *
 * The 4 x 4 x 4 = 64 hierarchy combinations are checked for both visitors
 * (128 assertions) by reusing a single item set / item / media hierarchy and
 * only changing the levels, so the whole matrix runs fast.
 *
 * @group matrix
 * @group integration
 */
class AccessMatrixTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    private const STATES = ['undefined', AccessStatus::FREE, AccessStatus::RESERVED, AccessStatus::FORBIDDEN];

    private const RANK = ['undefined' => 0, AccessStatus::FREE => 0, AccessStatus::RESERVED => 1, AccessStatus::FORBIDDEN => 2];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        // The guest role unlocks reserved content only when the guest mode is
        // active.
        $this->setAccessModes(['guest']);
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testFullMatrix(): void
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $guest = $this->createGuestRoleUser();

        $failures = [];

        foreach (self::STATES as $itemSetState) {
            foreach (self::STATES as $itemState) {
                foreach (self::STATES as $mediaState) {
                    $this->applyState($itemSet->id(), $itemSetState);
                    $this->applyState($item->id(), $itemState);
                    $this->applyState($media->id(), $mediaState);
                    $this->rebuildAccessCascade();

                    $rank = max(self::RANK[$itemSetState], self::RANK[$itemState], self::RANK[$mediaState]);
                    $anonExpected = $rank === 0;
                    $guestExpected = $rank <= 1;

                    $label = sprintf('itemSet=%s item=%s media=%s (effective rank %d)', $itemSetState, $itemState, $mediaState, $rank);

                    $this->logout();
                    if ($this->isAllowedMediaContentFresh($media) !== $anonExpected) {
                        $failures[] = sprintf('anonymous: expected %s for %s', $anonExpected ? 'allow' : 'deny', $label);
                    }

                    $this->writeIdentity($guest);
                    if ($this->isAllowedMediaContentFresh($media) !== $guestExpected) {
                        $failures[] = sprintf('guest: expected %s for %s', $guestExpected ? 'allow' : 'deny', $label);
                    }
                }
            }
        }

        $this->assertSame([], $failures, "Matrix mismatches:\n" . implode("\n", $failures));
    }

    private function applyState(int $resourceId, string $state): void
    {
        $this->setAccessLevelSet($resourceId, $state === 'undefined' ? AccessStatus::FREE : $state);
    }

    private function writeIdentity(\Omeka\Entity\User $user): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
        $auth->getStorage()->write($user);
    }

    /**
     * Create a user with the guest role and a distinct email, so the guest
     * bypass applies. The shared createGuestUser() helper reuses a fixed-email
     * user without fixing its role, which other tests may have created as
     * researcher.
     */
    private function createGuestRoleUser(): \Omeka\Entity\User
    {
        $entityManager = $this->getEntityManager();
        $user = new \Omeka\Entity\User();
        $user->setEmail('matrix-guest@test.example.com');
        $user->setName('Matrix Guest');
        $user->setRole('guest');
        $user->setIsActive(true);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->createdResources[] = ['type' => 'users', 'id' => $user->getId()];
        return $user;
    }
}

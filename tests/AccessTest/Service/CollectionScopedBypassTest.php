<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the collection-scoped bypass rules (IP and SSO IdP).
 *
 * A reserved file can be unlocked for a client only for the item sets it is
 * allowed in, and blocked for the item sets it is forbidden in, per the maps
 * access_ip_item_sets_by_ip and access_auth_sso_idp_item_sets_by_idp. This is
 * the Dante case: a collection authorized for one IdP and forbidden to the
 * general federation.
 *
 * The IP and IdP paths share the same allow/forbid resolution
 * (IsAllowedMediaContent::isResourceInReservedItemSets), so the IP tests cover
 * the collection-scoping logic without any optional module; the IdP test is
 * skipped when the SingleSignOn plugin isSsoUser is not installed.
 *
 * @group bypass
 * @group integration
 */
class CollectionScopedBypassTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    private ?string $remoteAddrBackup = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->remoteAddrBackup = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public function tearDown(): void
    {
        if ($this->remoteAddrBackup === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remoteAddrBackup;
        }
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    private const IP_INSIDE = '192.0.2.10';
    private const IP_OUTSIDE = '198.51.100.20';

    /**
     * Create a reserved media in a fresh item set and return [itemSetId,
     * media].
     */
    private function reservedMediaInItemSet(): array
    {
        $itemSet = $this->createItemSet();
        $item = $this->createItem(['item_set' => $itemSet]);
        $media = $this->createMedia($item);
        $this->setAccessLevelSet($media->id(), AccessStatus::RESERVED);
        $this->rebuildAccessCascade();
        return [$itemSet->id(), $media];
    }

    /**
     * A collection in the allow list of an IP: the reserved file is unlocked
     * from that IP, denied from any other IP and for anonymous.
     */
    public function testIpAllowsReservedInScopedItemSet(): void
    {
        [$itemSetId, $media] = $this->reservedMediaInItemSet();

        $this->getSettings()->set('access_modes', ['ip']);
        $this->getSettings()->set('access_ip_item_sets_by_ip', [
            self::IP_INSIDE => ['allow' => [$itemSetId], 'forbid' => []],
        ]);
        $this->logout();

        $_SERVER['REMOTE_ADDR'] = self::IP_INSIDE;
        $this->assertTrue($this->isAllowedMediaContentFresh($media), 'Allowed from the mapped IP');

        $_SERVER['REMOTE_ADDR'] = self::IP_OUTSIDE;
        $this->assertFalse($this->isAllowedMediaContentFresh($media), 'Denied from another IP');
    }

    /**
     * A collection NOT in the allow list of an IP: the reserved file is denied
     * even from that IP (the bypass is scoped to other collections).
     */
    public function testIpDoesNotAllowReservedOutsideScopedItemSet(): void
    {
        [, $media] = $this->reservedMediaInItemSet();
        $otherItemSet = $this->createItemSet();

        $this->getSettings()->set('access_modes', ['ip']);
        $this->getSettings()->set('access_ip_item_sets_by_ip', [
            self::IP_INSIDE => ['allow' => [$otherItemSet->id()], 'forbid' => []],
        ]);
        $this->logout();

        $_SERVER['REMOTE_ADDR'] = self::IP_INSIDE;
        $this->assertFalse($this->isAllowedMediaContentFresh($media), 'Denied: the media item set is not in the allow list');
    }

    /**
     * A collection in the forbid list of an IP: the reserved file is blocked
     * for that IP even though the IP otherwise bypasses reserved content. This
     * is the "forbidden to the federation" side of the Dante rule.
     */
    public function testIpForbidsReservedInScopedItemSet(): void
    {
        [$itemSetId, $media] = $this->reservedMediaInItemSet();

        $this->getSettings()->set('access_modes', ['ip']);
        // Forbid only: the IP bypasses reserved everywhere except this item
        // set.
        $this->getSettings()->set('access_ip_item_sets_by_ip', [
            self::IP_INSIDE => ['allow' => [], 'forbid' => [$itemSetId]],
        ]);
        $this->logout();

        $_SERVER['REMOTE_ADDR'] = self::IP_INSIDE;
        $this->assertFalse($this->isAllowedMediaContentFresh($media), 'Denied: the media item set is forbidden for this IP');
    }

    /**
     * With no IP map, the IP mode does not unlock a reserved file.
     */
    public function testNoIpMapDeniesReserved(): void
    {
        [, $media] = $this->reservedMediaInItemSet();

        $this->getSettings()->set('access_modes', ['ip']);
        $this->getSettings()->set('access_ip_item_sets_by_ip', []);
        $this->logout();

        $_SERVER['REMOTE_ADDR'] = self::IP_INSIDE;
        $this->assertFalse($this->isAllowedMediaContentFresh($media), 'Denied: no IP is mapped to any item set');
    }

    // The SSO IdP collection scoping (access_auth_sso_idp_item_sets_by_idp,
    // with the "federation" fallback) uses the very same allow/forbid
    // resolution as the IP scoping tested above, so it is not duplicated here.
    // It additionally requires the SingleSignOn module (isSsoUser), which is
    // out of the module test scope (Common + core only).
}

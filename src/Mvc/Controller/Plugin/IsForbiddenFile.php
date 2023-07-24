<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\ACCESS_MODE;
use const AccessResource\ACCESS_MODE_GLOBAL;
use const AccessResource\ACCESS_MODE_IP;
use const AccessResource\ACCESS_MODE_INDIVIDUAL;

use Doctrine\ORM\EntityManager;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\Controller\Plugin\Params;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\User;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Mvc\Controller\Plugin\UserIsAllowed;
use Omeka\Settings\Settings;

class IsForbiddenFile extends AbstractPlugin
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatus;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\IsUnderEmbargo
     */
    protected $isUnderEmbargo;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\UserIsAllowed
     */
    protected $userIsAllowed;

    /**
     * @var \Laminas\Mvc\Controller\Plugin\Params
     */
    protected $params;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Entity\User
     */
    protected $user;

    public function __construct(
        EntityManager $entityManager,
        AccessStatus $accessStatus,
        IsUnderEmbargo $isUnderEmbargo,
        UserIsAllowed $userIsAllowed,
        Params $params,
        Api $api,
        Settings $settings,
        ?User $user
    ) {
        $this->entityManager = $entityManager;
        $this->accessStatus = $accessStatus;
        $this->isUnderEmbargo = $isUnderEmbargo;
        $this->userIsAllowed = $userIsAllowed;
        $this->params = $params;
        $this->api = $api;
        $this->settings = $settings;
        $this->user = $user;
    }

    /**
     * Check if access to the file of a media is forbidden for the current user.
     *
     * This function is used in modules IiifServer and ImageServer.
     */
    public function __invoke(?MediaRepresentation $media): ?bool
    {
        if (!$media) {
            return false;
        }

        // The fact that the item is public or reserved is already checked.
        $mediaAccess = $media->isPublic();
        $mediaItemAccess = $media->item()->isPublic();
        $isPublic = $mediaAccess && $mediaItemAccess;
        if ($isPublic) {
            return false;
        }

        $canViewAll = $this->user
            // Slower but manage extra roles and modules permissions.
            // && in_array($result['user']->getRole(), ['global_admin', 'site_admin', 'editor', 'reviewer']);
            && $this->userIsAllowed->__invoke(\Omeka\Entity\Resource::class, 'view-all');
        if ($canViewAll) {
            return false;
        }

        // The embargo may be finished, but not updated (for example a cron
        // issue), so it is checked when needed.
        $bypassEmbargo = (bool) $this->settings->get('accessresource_embargo_bypass');
        $isUnderEmbargo = $bypassEmbargo
            ? null
            : $this->isUnderEmbargo->__invoke($media, (bool) $this->settings->get('accessresource_embargo_auto_update'));

        // When here, the resource has been automatically updated, so no
        // more check since the resource is public.
        $hasMediaAccess = $isUnderEmbargo === false
           || $this->hasMediaAccess($media);
        if (!$hasMediaAccess
            // Don't bypass embargo when it is set and not overridable.
            || $isUnderEmbargo
        ) {
            return true;
        }

        return false;
    }

    protected function hasMediaAccess(MediaRepresentation $media): bool
    {
        // The media is already checked and can be private or reserved here.
        // Don't check access mode before this step: the user should be checked.
        // Anyway, the access mode is always set.
        if (!ACCESS_MODE) {
            return false;
        }

        $mediaAccess = $media->isPublic();
        $mediaItemAccess = $media->item()->isPublic();
        $isPublic = $mediaAccess && $mediaItemAccess;
        if ($isPublic) {
            return true;
        }

        if (ACCESS_MODE === ACCESS_MODE_GLOBAL) {
            return !empty($this->user);
        }

        // Any admin can see any media in any case.
        if ($this->user && $this->userIsAllowed->__invoke(\Omeka\Entity\Resource::class, 'view-all')) {
            return true;
        }

        // Mode "ip" is compatible with mode "individual", so the check can be
        // done separately.

        $reservedItemSetsForClientIp = $this->reservedItemSetsForClientIp();
        if (is_array($reservedItemSetsForClientIp)) {
            if (count($reservedItemSetsForClientIp)) {
                $isMediaInItemSets = $this->isMediaInItemSets($media, $reservedItemSetsForClientIp);
                // For ip and individuals.
                if ($isMediaInItemSets) {
                    return true;
                }
            } elseif (ACCESS_MODE === ACCESS_MODE_IP) {
                return true;
            }
            // Individual user rights (request or token) checked below.
        }

        if (ACCESS_MODE === ACCESS_MODE_IP) {
            return false;
        }

        // Check individual access with or without token.

        $token = $this->params->fromQuery('token');

        $accessResource = null;
        if (!is_null($token)) {
            $accessResource = $this->entityManager
                ->getRepository(\AccessResource\Entity\AccessResource::class)
                ->findOneBy(['token' => $token]);
        } elseif (!is_null($this->user)) {
            $accessResource = $this->entityManager
                ->getRepository(\AccessResource\Entity\AccessResource::class)
                ->findOneBy(['user' => $this->user->getId(), 'resource' => $media->id()]);
        }

        // Deny for visitor without token.
        if (!$this->user && is_null($token) && is_null($accessResource)) {
            return false;
        }

        // Deny for guest who has not access.
        if ($this->user && is_null($token) && is_null($accessResource)) {
            return false;
        }

        // Deny for token with not equal id media.
        if ($token && $accessResource && $media->id() !== $accessResource->resource()->id()) {
            return false;
        }

        // Deny if time access is before start or after end.
        if ($token
            && $accessResource->getTemporal()
            && (
                strtotime($accessResource->getStartDate()->format('Y-m-d H:i')) <= time()
                || strtotime($accessResource->getEndDate()->format('Y-m-d H:i')) >= time()
            )
        ) {
            return false;
        }

        $access = $this->api->searchOne('access_resources', [
            'resource_id' => $media->id(),
            'user_id' => $this->user ? $this->user->getId() : null,
            'enabled' => 1,
        ])->getContent();
        if (!$mediaAccess) {
            $mediaAccess = (bool) $access;
        }

        if (!$mediaItemAccess) {
            $mediaItemAccess = (bool) $this->api->searchOne('access_resources', [
                'resource_id' => $media->item()->id(),
                'user_id' => $this->user ? $this->user->getId() : null,
                'enabled' => 1,
            ])->getContent();
        }

        return $mediaAccess && $mediaItemAccess;
    }

    protected function isMediaInItemSets(?MediaRepresentation $media, ?array $itemSetIds): bool
    {
        if (!$media || !$itemSetIds) {
            return false;
        }
        $mediaItemSetIds = array_keys($media->item()->itemSets());
        return (bool) array_intersect($mediaItemSetIds, $itemSetIds);
    }

     /**
      * Check if the ip of the user is reserved and limited to some item sets.
      *
      * @return array|null Null if the user is not listed in reserved ips, else
      *   array of item sets, that may be empty.
      */
     protected function reservedItemSetsForClientIp(): ?array
     {
         // This method is called one time for each file, but each file is
         // called by a difrerent request.

         $ip = $this->getClientIp();
         if ($ip === '::') {
             return null;
         }

         $reservedIps = $this->settings->get('accessresource_ip_reserved', []);
         if (empty($reservedIps)) {
             return null;
         }

         // Check a single ip.
         if (isset($reservedIps[$ip])) {
             return $reservedIps[$ip]['reserved'];
         }

         // Check an ip range.
         // FIXME Fix check of ip for ipv6 (ip2long).
         $ipLong = ip2long($ip);
         foreach ($reservedIps as $range) {
             if ($ipLong >= $range['low'] && $ipLong <= $range['high']) {
                 return $range['reserved'];
             }
         }

         return null;
     }

     /**
      * Get the ip of the client (ipv4 or ipv6), or empty ip ("::").
      */
     protected function getClientIp(): string
     {
         // Use $_SERVER['REMOTE_ADDR'], the most reliable.
         $remoteAddress = new RemoteAddress();
         $ip = $remoteAddress->getIpAddress();
         if (!$ip) {
             return '::';
         }

         // A proxy or a htaccess rule can return the server ip, so check it too.
         // The server itself is a trusted proxy when used in htacess or config (see RemoteAddress::getIpAddressFromProxy()).
         $remoteAddress
             ->setUseProxy(true)
             ->setTrustedProxies([$_SERVER['SERVER_ADDR']]);
         return $remoteAddress->getIpAddress() ?: '::';
     }
}

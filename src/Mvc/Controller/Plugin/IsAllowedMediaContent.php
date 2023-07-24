<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use AccessResource\Entity\AccessResource;
use AccessResource\Entity\AccessStatus;
use Doctrine\ORM\EntityManager;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\Controller\Plugin\Params;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\User;
use Omeka\Mvc\Controller\Plugin\UserIsAllowed;
use Omeka\Settings\Settings;

class IsAllowedMediaContent extends AbstractPlugin
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\UserIsAllowed
     */
    protected $userIsAllowed;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatusForResource
     */
    protected $accessStatusForResource;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\IsExternalUser
     */
    protected $isExternalUser;

    /**
     * @var Laminas\Authentication\AuthenticationService;
     */
    protected $authenticationService;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Laminas\Mvc\Controller\Plugin\Params
     */
    protected $params;

    public function __construct(
        EntityManager $entityManager,
        UserIsAllowed $userIsAllowed,
        AccessStatusForResource $accessStatusForResource,
        IsExternalUser $isExternalUser,
        AuthenticationService $authenticationService,
        Settings $settings,
        Params $params
    ) {
        $this->entityManager = $entityManager;
        $this->userIsAllowed = $userIsAllowed;
        $this->accessStatusForResource = $accessStatusForResource;
        $this->isExternalUser = $isExternalUser;
        $this->authenticationService = $authenticationService;
        $this->settings = $settings;
        $this->params = $params;
    }

    /**
     * Check if access to media content is allowed for the current user.
     *
     * The check is done on status and embargo.
     *
     * Accessibility and visibility are decorrelated, so, for example, a visitor
     * cannot see a private media or a public media with restricted content.
     *
     * Here, the media is readable by the user or visitor: it should be loaded
     * via api to check the visibility first.
     *
     * Can access to public resources that are restricted or protected:
     * - IP: anonymous with IP.
     * - External: authenticated externally (cas for now, ldap or sso later).
     * - Guest: guest users.
     * - Token: user or visitor with a token.
     * - Individual: users with requests and anonymous with token.
     *
     * The embargo is checked when the user has access to a
     *
     * @todo Check embargo via a new column in accessStatus?
     */
    public function __invoke(?MediaRepresentation $media): ?bool
    {
        if (!$media) {
            return false;
        }

        if ($this->userIsAllowed->__invoke(\Omeka\Entity\Resource::class, 'view-all')) {
            return true;
        }

        /** @var \AccessResource\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->accessStatusForResource->__invoke($media);
        if (!$accessStatus) {
            return true;
        }

        $status = $accessStatus->status();
        if ($status === AccessStatus::FORBIDDEN) {
            return false;
        }

        // Check embargo first.
        if ($this->isUnderEmbargo($accessStatus)) {
            return false;
        }

        if ($status === AccessStatus::FREE) {
            return true;
        }

        // Here, the mode is restricted or protected, so check media content.

        $modes = $this->settings->get('accessresource_access_modes');
        if (empty($modes)) {
            return true;
        }

        $modeIp = in_array('ip', $modes);
        if ($modeIp && $this->isMediaInReservedItemSets($media)) {
            return true;
        }

        /** @var \Omeka\Entity\User $user */
        $user = $this->authenticationService->getIdentity();

        $modeGuest = in_array('guest', $modes);
        if ($modeGuest && $user && $user->getRole() === 'guest') {
            return true;
        }

        $modeExternal = in_array('external', $modes);
        if ($modeExternal && $user && $this->isExternalUser->__invoke($user)) {
            return true;
        }

        $modeToken = in_array('token', $modes);
        if ($modeToken && $this->checkToken($media)) {
            return true;
        }

        $modeIndividual = in_array('individual', $modes);
        if ($modeIndividual && $this->checkIndividual($media, $user)) {
            return true;
        }

        return false;
    }

    protected function isUnderEmbargo(AccessStatus $accessStatus): bool
    {
        $bypassEmbargo = (bool) $this->settings->get('accessresource_embargo_bypass');
        if ($bypassEmbargo) {
            return false;
        }
        $embargoStartDate = $accessStatus->getEmbargoStart();
        $embargoEndDate = $accessStatus->getEmbargoEnd();
        if (!$embargoStartDate && !$embargoEndDate) {
            return false;
        }
        $now = time();
        if ($embargoStartDate && $embargoEndDate) {
            return $now >= $embargoStartDate->format('U')
                && $now <= $embargoEndDate->format('U');
        } elseif ($embargoStartDate) {
            return $now >= $embargoStartDate->format('U');
        } elseif ($embargoEndDate) {
            return $now <= $embargoEndDate->format('U');
        } else {
            return false;
        }
    }

    protected function isMediaInReservedItemSets(MediaRepresentation $media): bool
    {
        $reservedItemSetsForClientIp = $this->reservedItemSetsForClientIp();
        if (is_array($reservedItemSetsForClientIp) && count($reservedItemSetsForClientIp)) {
            $isMediaInItemSets = $this->isMediaInItemSets($media, $reservedItemSetsForClientIp);
            if ($isMediaInItemSets) {
                return true;
            }
        }
        return false;
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

     /**
      * Check for a valid token for the resource.
      */
     protected function checkToken(MediaRepresentation $media): bool
     {
         $token = $this->params->fromQuery('token');
         if (!$token) {
             return false;
         }
         /** @var \AccessResource\Entity\AccessResource $accessResource */
         $accessResource = $this->entityManager
             ->getRepository(\AccessResource\Entity\AccessResource::class)
             ->findOneBy([
                 'resource' => $media->id(),
                 'enabled' => true,
                 'token' => $token,
             ]);
         if (!$accessResource) {
             return false;
         }
         return $this->checkAccess($accessResource);
     }

     /**
      * Check for an accepted request of the user.
      */
     protected function checkIndividual(MediaRepresentation $media, ?User $user): bool
     {
         if (!$user) {
             return false;
         }
         /** @var \AccessResource\Entity\AccessResource $accessResource */
         $accessResource = $this->entityManager
             ->getRepository(\AccessResource\Entity\AccessResource::class)
             ->findOneBy([
                 'resource' => $media->id(),
                 'enabled' => true,
                 'user' => $user->getId(),
             ]);
         if (!$accessResource) {
             return false;
         }
         return $this->checkAccess($accessResource);
     }

     /**
      * Check if access is time limited.
      */
     protected function checkAccess(AccessResource $accessResource): bool
     {
         if (!$accessResource->enabled()) {
             return false;
         }
         if (!$accessResource->temporal()) {
             return true;
         }
         $accessStartDate = $accessResource->getStartDate();
         $accessEndDate = $accessResource->getEndDate();
         if (!$accessStartDate && !$accessEndDate) {
             return false;
         }
         $now = time();
         if ($accessStartDate && $now <= $accessStartDate->format('U')) {
             return false;
         }
         if ($accessEndDate && $now >= $accessEndDate->format('U')) {
             return false;
         }
         return true;
     }
}
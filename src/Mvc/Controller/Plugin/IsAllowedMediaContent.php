<?php declare(strict_types=1);

namespace Access\Mvc\Controller\Plugin;

use Access\Api\Representation\AccessStatusRepresentation;
use Access\Entity\AccessRequest;
use Access\Entity\AccessStatus;
use Access\Mvc\Controller\Plugin\AccessStatus as AccessStatusPlugin;
use CAS\Mvc\Controller\Plugin\IsCasUser;
// use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
// use Doctrine\ORM\Query\Parameter;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\Controller\Plugin\Params;
use Ldap\Mvc\Controller\Plugin\IsLdapUser;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\User;
use Omeka\Mvc\Controller\Plugin\UserIsAllowed;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;
use SingleSignOn\Mvc\Controller\Plugin\IsSsoUser;

class IsAllowedMediaContent extends AbstractPlugin
{
    /**
     * @var \Access\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatus;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \CAS\Mvc\Controller\Plugin\IsCasUser
     */
    protected $isCasUser;

    /**
     * @var \Access\Mvc\Controller\Plugin\IsExternalUser
     */
    protected $isExternalUser;

    /**
     * @var \Ldap\Mvc\Controller\Plugin\IsLdapUser
     */
    protected $isLdapUser;

    /**
     * @var \SingleSignOn\Mvc\Controller\Plugin\IsSsoUser
     */
    protected $isSsoUser;

    /**
     * @var \Laminas\Mvc\Controller\Plugin\Params
     */
    protected $params;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Entity\User;
     */
    protected $user;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\UserIsAllowed
     */
    protected $userIsAllowed;

    /**
     * @var \Omeka\Settings\UserSettings
     */
    protected $userSettings;

    public function __construct(
        AccessStatusPlugin $accessStatus,
        EntityManager $entityManager,
        ?IsCasUser $isCasUser,
        IsExternalUser $isExternalUser,
        ?IsLdapUser $isLdapUser,
        ?IsSsoUser $isSsoUser,
        Params $params,
        Settings $settings,
        ?User $user,
        UserIsAllowed $userIsAllowed,
        ?UserSettings $userSettings
    ) {
        $this->accessStatus = $accessStatus;
        $this->entityManager = $entityManager;
        $this->isCasUser = $isCasUser;
        $this->isExternalUser = $isExternalUser;
        $this->isLdapUser = $isLdapUser;
        $this->isSsoUser = $isSsoUser;
        $this->params = $params;
        $this->settings = $settings;
        $this->user = $user;
        $this->userIsAllowed = $userIsAllowed;
        $this->userSettings = $userSettings;
    }

    /**
     * Check if access to media content is allowed for the current user.
     *
     * The check is done on level and embargo.
     *
     * Accessibility and visibility are decorrelated, so, for example, a visitor
     * cannot see a private media or a public media with reserved content.
     *
     * Here, the media is readable by the user or visitor: it should be loaded
     * via api to check the visibility first.
     *
     * Can access to public resources that are reserved or protected:
     * - global modes
     *   - IP: anonymous with IP.
     *   - External: authenticated externally (cas for now, ldap or sso later).
     *   - Guest: guest users.
     * - individual modes
     *   - User: authenticated users via a request .
     *   - Email: visitor identified by email via a request.
     *   - Token: user or visitor with a token via a request.
     *
     * The embargo is checked first.
     *
     * @todo Check embargo via a new column in accessStatus?
     */
    public function __invoke(?MediaRepresentation $media): bool
    {
        if (!$media) {
            return false;
        }

        $owner = $media->owner();
        if ($this->user && $owner && (int) $owner->id() === (int) $this->user->getId()) {
            return true;
        }

        if ($this->user && $this->userIsAllowed->__invoke(\Omeka\Entity\Resource::class, 'view-all')) {
            return true;
        }

        /** @var \Access\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->accessStatus->__invoke($media);
        if (!$accessStatus) {
            return true;
        }

        $level = $accessStatus->getLevel();
        if ($level === AccessStatus::FORBIDDEN || !in_array($level, AccessStatusRepresentation::LEVELS)) {
            return false;
        }

        // Check embargo first.
        if ($this->isUnderEmbargo($accessStatus)) {
            return false;
        }

        if ($level === AccessStatus::FREE) {
            return true;
        }

        // Here, the mode is reserved or protected, so check media content.

        $modes = $this->settings->get('access_modes');
        if (empty($modes)) {
            return true;
        }

        $modeIp = in_array('ip', $modes);
        if ($modeIp && $this->isMediaInReservedItemSets($media, 'ip')) {
            return true;
        }

        if ($this->user) {
            $modeGuest = in_array('guest', $modes);
            if ($modeGuest && $this->user->getRole() === 'guest') {
                return true;
            }

            $modeExternal = in_array('auth_external', $modes);
            if ($modeExternal && $this->isExternalUser->__invoke($this->user)) {
                return true;
            }

            $modeCas = in_array('auth_cas', $modes);
            if ($modeCas && $this->isCasUser && $this->isCasUser->__invoke($this->user)) {
                return true;
            }

            $modeLdap = in_array('auth_ldap', $modes);
            if ($modeLdap && $this->isLdapUser && $this->isLdapUser->__invoke($this->user)) {
                return true;
            }

            $modeSso = in_array('auth_sso', $modes);
            if ($modeSso && $this->isSsoUser && $this->isSsoUser->__invoke($this->user)) {
                return true;
            }

            $modeSsoIdp = in_array('auth_sso_idp', $modes);
            if ($modeSsoIdp
                && $this->isSsoUser
                && $this->isSsoUser->__invoke($this->user)
                && $this->isMediaInReservedItemSets($media, 'auth_sso_idp')
            ) {
                return true;
            }

            $modeEmailRegex = in_array('email_regex', $modes);
            if ($modeEmailRegex && $this->checkEmailRegex($this->user)) {
                return true;
            }
        }

        // Use a single process for all single accesses to avoid multiple
        // queries, that are nearly the same.
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if ($individualModes && $this->checkIndividualAccesses($media, $individualModes, $this->user)) {
            return true;
        }

        return false;
    }

    protected function isUnderEmbargo(AccessStatus $accessStatus): bool
    {
        $bypassEmbargo = (bool) $this->settings->get('access_embargo_bypass');
        if ($bypassEmbargo) {
            return false;
        }
        return (bool) $accessStatus->isUnderEmbargo();
    }

    protected function isMediaInReservedItemSets(MediaRepresentation $media, string $mode): bool
    {
        if ($mode === 'ip') {
            $definedItemSets = $this->definedItemSetsForClientIp();
        } elseif ($mode === 'auth_sso_idp') {
            $definedItemSets = $this->definedItemSetsForAuthSsoIdp();
        } else {
            return false;
        }

        // The user is not in the lists.
        if (!is_array($definedItemSets)) {
            return false;
        }

        $allow = $definedItemSets['allow'] ?? null;
        $forbid = $definedItemSets['forbid'] ?? null;

        if (!count($allow) && !count($forbid)) {
            return true;
        } elseif (count($allow) && !count($forbid)) {
            return $this->isMediaInItemSets($media, $allow);
        } elseif (!count($allow) && count($forbid)) {
            return !$this->isMediaInItemSets($media, $forbid);
        } else {
            return $this->isMediaInItemSets($media, $allow)
                && !$this->isMediaInItemSets($media, $forbid);
        }
    }

    protected function isMediaInItemSets(?MediaRepresentation $media, ?array $itemSetIds): bool
    {
        if (!$media || !$itemSetIds) {
            return false;
        }
        $mediaItemSetIds = $this->mediaItemSetIds($media);
        return (bool) array_intersect($mediaItemSetIds, $itemSetIds);
    }

    protected function mediaItemSetIds(MediaRepresentation $media): array
    {
        static $mediaItemSetIds = [];
        $mediaId = $media->id();
        if (!isset($mediaItemSetIds[$mediaId])) {
            $mediaItemSetIds[$mediaId] = array_keys($media->item()->itemSets());
        }
        return $mediaItemSetIds[$mediaId];
    }

    /**
     * Check if the ip of the user is reserved and limited to some item sets.
     *
     * @return array|null Null if the user is not listed in reserved ips, else
     *   array of item sets, that may be empty, that means any.
     */
    protected function definedItemSetsForClientIp(): ?array
    {
        // This method is called one time for each file, but each file is
        // called by a different request.

        /** @see https://github.com/Daniel-KM/Omeka-S-module-Access/issues/1 */
        $ipList = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($ipList && $this->settings->get('access_ip_proxy')) {
            $ips = explode(',', str_replace(' ', '', $ipList));
            $ip = reset($ips);
        } else {
            $ip = $this->getClientIp();
        }

        if ($ip === '::') {
            return null;
        }

        $listIps = $this->settings->get('access_ip_item_sets_by_ip', []);
        if (empty($listIps)) {
            return null;
        }

        // Check a single ip.
        if (isset($listIps[$ip])) {
            return array_intersect_key($listIps[$ip], ['allow' => null, 'forbid' => null]);
        }

        // Check an ip range.
        // FIXME Fix check of ip for ipv6 (ip2long).
        $ipLong = ip2long($ip);
        foreach ($listIps as $range) {
            if ($ipLong >= $range['low'] && $ipLong <= $range['high']) {
                return array_intersect_key($range, ['allow' => null, 'forbid' => null]);
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
     * Check if the idp of the user is reserved and limited to some item sets.
     *
     * @return array|null Null if the user is not listed in reserved idps, else
     *   array of item sets, that may be empty, that means any.
     */
    protected function definedItemSetsForAuthSsoIdp(): ?array
    {
        // This method is called one time for each file, but each file is
        // called by a different request.

        $reservedIdps = $this->settings->get('access_auth_sso_idp_item_sets_by_idp', []);
        if (empty($reservedIdps)) {
            return null;
        }

        $authenticator = $this->userSettings->get('connection_authenticator');
        if ($authenticator !== 'SingleSignOn') {
            return null;
        }

        $idpName = $this->userSettings->get('connection_idp');
        return $reservedIdps[$idpName]
            ?? $reservedIdps['federation']
            ?? null;
    }

    protected function checkEmailRegex(User $user): bool
    {
        $pattern = (string) $this->settings->get('access_email_regex');
        return $pattern && preg_match($pattern, $user->getEmail());
    }

    protected function checkIndividualAccesses(MediaRepresentation $media, array $individualModes, ?User $user = null): bool
    {
        $bind = [];
        $types = [];
        $sqlModes = [];

        foreach ($individualModes as $mode) switch ($mode) {
            case 'user':
                if (!$user) {
                    continue 2;
                }
                $bind['user_id'] = $user->getId();
                $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
                $sqlModes['user'] = 'ar.user_id = :user_id';
                break;
            case 'email':
                $email = $this->params->fromQuery('access');
                if (!$email) {
                    $session = new \Laminas\Session\Container('Access');
                    $email = $session->offsetGet('access');
                }
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue 2;
                }
                $bind['email'] = $email;
                $types['email'] = \Doctrine\DBAL\ParameterType::STRING;
                $sqlModes['email'] = 'ar.email = :email';
                break;
            case 'token':
                $token = $this->params->fromQuery('access');
                if (!$token) {
                    $session = new \Laminas\Session\Container('Access');
                    $token = $session->offsetGet('access');
                }
                // The check of levels avoids mixing search/browse and show,
                // that have the same argument name in request.
                if (!$token || strpos($token, '@') || in_array($token, AccessStatusRepresentation::LEVELS)) {
                    continue 2;
                }
                $bind['token'] = $token;
                $types['token'] = \Doctrine\DBAL\ParameterType::STRING;
                $sqlModes['token'] = 'ar.token = :token';
                break;
            default:
                // Nothing.
                break;
        }
        if ($sqlModes === []) {
            return false;
        }
        $sqlModesString = implode("\n        OR ", $sqlModes);

        $bind['media_id'] = $media->id();
        $bind['item_id'] = $media->item()->id();
        $types['media_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['item_id'] = \Doctrine\DBAL\ParameterType::INTEGER;

        $mediaItemSetIds = $this->mediaItemSetIds($media);
        if ($mediaItemSetIds) {
            $orInItemSets = 'OR (ar.recursive = 1 AND r.resource_id IN (:item_set_ids))';
            $bind['item_set_ids'] = $mediaItemSetIds;
            $types['item_set_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }

        /** @var \Access\Entity\AccessRequest $accessRequest */
        // TODO How to query on resources with entity manager?
        /*
        $accessRequest = $this->entityManager
            ->getRepository(\Access\Entity\AccessRequest::class)
            ->findOneBy([
                'resources' => $media->id(),
                'enabled' => true,
                'user' => $user->getId(),
            ]);
        */
        /*
        $dql = <<<DQL
            SELECT ar
            FROM Access\Entity\AccessRequest ar
            JOIN ar.resources r
            WHERE
                ar.enabled = 1
                AND ($sqlModesString)
                AND r.id = :media_id
             ORDER BY ar.created DESC
            DQL;
        $query = $this->entityManager
            ->createQuery($dql)
            ->setParameters(new ArrayCollection([
                new Parameter('user_id', $user->getId(), \Doctrine\DBAL\ParameterType::INTEGER),
                new Parameter('media_id', $media->id(), \Doctrine\DBAL\ParameterType::INTEGER),
            ]));
        $accessRequest = $query->getSingleResult();
        if (!$accessRequest) {
            return false;
        }
        */

        $sql = <<<SQL
            SELECT ar.id
            FROM access_request AS ar
            JOIN access_resource AS r ON r.access_request_id = ar.id
            WHERE
                ar.enabled = 1
                AND (
                    $sqlModesString
                )
                AND (
                    r.resource_id = :media_id
                    OR (ar.recursive = 1 AND r.resource_id = :item_id)
                    $orInItemSets
                )
            ORDER BY ar.id DESC
            SQL;
        $accessRequestIds = $this->entityManager->getConnection()
            ->executeQuery($sql, $bind, $types)
            ->fetchFirstColumn();
        if (!$accessRequestIds) {
            return false;
        }

        // TODO Include an automatic cron task (once a day each time a data is requested) to check temporal directly.

        // Most of the time, there is only one access request by user.
        foreach ($accessRequestIds as $accessRequestId) {
            $accessRequest = $this->entityManager->find(\Access\Entity\AccessRequest::class, $accessRequestId);
            if ($this->checkAccessTemporal($accessRequest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if access is time limited.
     */
    protected function checkAccessTemporal(AccessRequest $accessRequest): bool
    {
        if (!$accessRequest->getTemporal()) {
            return true;
        }
        $start = $accessRequest->getStart();
        $end = $accessRequest->getEnd();
        if (!$start && !$end) {
            return false;
        }
        $now = time();
        if ($start && $now <= $start->format('U')) {
            return false;
        }
        if ($end && $now >= $end->format('U')) {
            return false;
        }
        return true;
    }
}

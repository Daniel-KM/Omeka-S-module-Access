<?php declare(strict_types=1);

namespace Access\Mvc\Controller\Plugin;

use Access\Api\Representation\AccessStatusRepresentation;
use Access\Entity\AccessRequest;
use Access\Entity\AccessStatus;
use Access\Mvc\Controller\Plugin\AccessStatus as AccessStatusPlugin;
use Access\Service\BypassResolver;
use CAS\Mvc\Controller\Plugin\IsCasUser;
// use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManager;
// use Doctrine\ORM\Query\Parameter;
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

    /**
     * @var \Access\Service\BypassResolver
     */
    protected $bypassResolver;

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
        ?UserSettings $userSettings,
        ?BypassResolver $bypassResolver = null
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
        $this->bypassResolver = $bypassResolver ?? new BypassResolver($settings, $userSettings);
    }

    /**
     * Check if access to media content is allowed for the current user.
     *
     * Three independent criteria are checked:
     * 1. Visibility: public/private (handled by Omeka core before this module)
     * 2. Access level: free/reserved/protected/forbidden
     * 3. Embargo: if under embargo, access is denied
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
     *   - User: authenticated users via a request.
     *   - Email: visitor identified by email via a request.
     *   - Token: user or visitor with a token via a request.
     *
     * The embargo is rare and slower to check, so checked last.
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
        // If media has no access status, inherit from item.
        if (!$accessStatus) {
            $accessStatus = $this->accessStatus->__invoke($media->item());
        }
        // If neither media nor item has access status, allow access (free by default).
        if (!$accessStatus) {
            return true;
        }

        // Check access level first (quick string comparison, most common check).
        $level = $accessStatus->getLevel();
        if ($level === AccessStatus::FORBIDDEN || !in_array($level, AccessStatusRepresentation::LEVELS)) {
            return false;
        }

        // Check embargo (independent criterion, but rarer so checked after level).
        // A resource under embargo is denied regardless of its access level.
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
            // Use a sql query because the item sets may be private.
            $sql = <<<'SQL'
                SELECT `item_set_id`
                FROM `item_item_set`
                WHERE `item_id` = :item_id
                ORDER BY `item_set_id` ASC;
                SQL;
            $itemSetIds = $this->entityManager->getConnection()
                ->executeQuery($sql, ['item_id' => $media->item()->id()], ['item_id' => \Doctrine\DBAL\ParameterType::INTEGER])
                ->fetchFirstColumn();
            $mediaItemSetIds[$mediaId] = $itemSetIds;
        }
        return $mediaItemSetIds[$mediaId];
    }

    /**
     * Delegated to BypassResolver to avoid duplication with AccessChecker.
     *
     * @return array|null
     */
    protected function definedItemSetsForClientIp(): ?array
    {
        return $this->bypassResolver->definedItemSetsForClientIp();
    }

    /**
     * Delegated to BypassResolver to avoid duplication with AccessChecker.
     *
     * @return array|null
     */
    protected function definedItemSetsForAuthSsoIdp(): ?array
    {
        return $this->bypassResolver->definedItemSetsForAuthSsoIdp();
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
        $orInItemSets = '';
        if ($mediaItemSetIds) {
            $orInItemSets = 'OR (ar.recursive = 1 AND r.resource_id IN (:item_set_ids))';
            $bind['item_set_ids'] = array_values($mediaItemSetIds);
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

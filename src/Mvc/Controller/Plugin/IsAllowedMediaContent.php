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
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
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
    public function __invoke(?AbstractResourceEntityRepresentation $media): bool
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
        // If media has no access status, inherit from item (Media only).
        if (!$accessStatus && $media instanceof MediaRepresentation) {
            $accessStatus = $this->accessStatus->__invoke($media->item());
        }
        // If neither resource nor item has access status, allow access (free by
        // default).
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

        $modes = $this->settings->get('access_modes');
        if (empty($modes)) {
            return true;
        }

        // Protected: stricter than reserved. No global bypass modes (IP, SSO
        // IDP, guest, CAS, LDAP, external, email regex) apply. The only way to
        // grant access is an approved individual access request. This
        // distinguishes "protected" from "reserved" semantically: a reader
        // wandering the site with the right context (IP, SSO) can read a
        // "reserved" file silently, but a "protected" file always requires an
        // explicit, admin-validated request.
        if ($level === AccessStatus::PROTECTED) {
            $individualModes = array_intersect(['user', 'email', 'token'], $modes);
            return $individualModes
                && $this->checkIndividualAccesses($media, $individualModes, $this->user);
        }

        // Here, the level is reserved: all bypass modes apply.

        $modeIp = in_array('ip', $modes);
        if ($modeIp && $this->isResourceInReservedItemSets($media, 'ip')) {
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
                && $this->isResourceInReservedItemSets($media, 'auth_sso_idp')
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

    protected function isResourceInReservedItemSets(?AbstractResourceEntityRepresentation $resource, string $mode): bool
    {
        if (!$resource) {
            return false;
        }
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
            return $this->isResourceInItemSets($resource, $allow);
        } elseif (!count($allow) && count($forbid)) {
            return !$this->isResourceInItemSets($resource, $forbid);
        } else {
            return $this->isResourceInItemSets($resource, $allow)
                && !$this->isResourceInItemSets($resource, $forbid);
        }
    }

    protected function isResourceInItemSets(?AbstractResourceEntityRepresentation $resource, ?array $itemSetIds): bool
    {
        if (!$resource || !$itemSetIds) {
            return false;
        }
        return (bool) array_intersect($this->resourceItemSetIds($resource), $itemSetIds);
    }

    /**
     * Item set ids related to the given resource:
     *   - Media: parent item's item sets.
     *   - DigitalObject: item sets of every item attaching the DO through a
     *     property value of type resource.
     *   - Item: item sets the item belongs to.
     */
    protected function resourceItemSetIds(AbstractResourceEntityRepresentation $resource): array
    {
        static $cache = [];
        $resourceName = $resource->resourceName();
        $cacheKey = $resourceName . ':' . $resource->id();
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $connection = $this->entityManager->getConnection();
        if ($resourceName === 'media') {
            $sql = 'SELECT item_set_id FROM item_item_set WHERE item_id = :id ORDER BY item_set_id';
            $itemId = $resource instanceof MediaRepresentation ? $resource->item()->id() : null;
            $itemSetIds = $itemId
                ? $connection->executeQuery($sql, ['id' => $itemId], ['id' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchFirstColumn()
                : [];
        } elseif ($resourceName === 'items') {
            $sql = 'SELECT item_set_id FROM item_item_set WHERE item_id = :id ORDER BY item_set_id';
            $itemSetIds = $connection->executeQuery($sql, ['id' => $resource->id()], ['id' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchFirstColumn();
        } elseif ($resourceName === 'digital_objects') {
            $sql = <<<'SQL'
                SELECT DISTINCT item_item_set.item_set_id
                FROM value
                JOIN item_item_set ON item_item_set.item_id = value.resource_id
                WHERE value.value_resource_id = :id
                AND (value.type = 'resource' OR value.type LIKE 'resource:%')
                SQL;
            $itemSetIds = $connection->executeQuery($sql, ['id' => $resource->id()], ['id' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchFirstColumn();
        } else {
            $itemSetIds = [];
        }

        $cache[$cacheKey] = array_map('intval', $itemSetIds);
        return $cache[$cacheKey];
    }

    /**
     * @deprecated Kept as a thin wrapper for backwards compatibility.
     */
    protected function isMediaInReservedItemSets(MediaRepresentation $media, string $mode): bool
    {
        return $this->isResourceInReservedItemSets($media, $mode);
    }

    /**
     * @deprecated Kept as a thin wrapper for backwards compatibility.
     */
    protected function isMediaInItemSets(?MediaRepresentation $media, ?array $itemSetIds): bool
    {
        return $this->isResourceInItemSets($media, $itemSetIds);
    }

    /**
     * @deprecated Kept as a thin wrapper for backwards compatibility.
     */
    protected function mediaItemSetIds(MediaRepresentation $media): array
    {
        return $this->resourceItemSetIds($media);
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

    protected function checkIndividualAccesses(AbstractResourceEntityRepresentation $media, array $individualModes, ?User $user = null): bool
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
        $bind['item_id'] = $media instanceof MediaRepresentation ? $media->item()->id() : 0;
        $types['media_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['item_id'] = \Doctrine\DBAL\ParameterType::INTEGER;

        $mediaItemSetIds = $media instanceof MediaRepresentation ? $this->mediaItemSetIds($media) : [];
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

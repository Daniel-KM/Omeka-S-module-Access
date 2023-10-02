<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Entity\AccessStatus;
use Access\Mvc\Controller\Plugin\AccessLevel;
use Access\Mvc\Controller\Plugin\IsAllowedMediaContent;
use Doctrine\ORM\EntityManager;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Entity\User;

class IsAccessRequestable extends AbstractHelper
{
    /**
     * @var \Omeka\Entity\User
     */
    protected $user;

    /**
     * @var bool
     */
    protected $userCanViewAll;

    /**
     * @var \Access\Mvc\Controller\Plugin\AccessLevel
     */
    protected $accessLevel;

    /**
     * @var \Access\Mvc\Controller\Plugin\IsAllowedMediaContent
     */
    protected $isAllowedMediaContent;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    public function __construct(
        ?User $user,
        bool $userCanViewAll,
        AccessLevel $accessLevel,
        IsAllowedMediaContent $isAllowedMediaContent,
        EntityManager $entityManager
    ) {
        $this->user = $user;
        $this->userCanViewAll = $userCanViewAll;
        $this->accessLevel = $accessLevel;
        $this->isAllowedMediaContent = $isAllowedMediaContent;
        $this->entityManager = $entityManager;
    }

    /**
     * Check if the resource is requestable by the current user.
     *
     * A resource is requestable if the user cannot view it and its access is
     * neither free, nor forbidden nor under embargo (except if viewable under
     * embargo).
     *
     * @param string $accessType The check will be done on the specified
     *   resource type if any (items, media, item_sets). This param is currently
     *   not managed because full access is not managed: only media content is
     *   checked.
     *
     * @todo Does this helper check for the visibility or not? Anyway, it is already done for the main resource.
     * @todo fixme Manage embargo when not bypass for items and item sets.
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource, ?string $accessType = null): bool
    {
        if (!$resource || $this->userCanViewAll) {
            return false;
        }

        // TODO Currently, only the media access is checked, not full access.

        $resourceName = $resource->resourceName();
        if ($resourceName === 'media') {
            // The level should be checked, else "forbidden" will output true.
            $level = $this->accessLevel->__invoke($resource);
            return in_array($level, [AccessStatus::RESERVED, AccessStatus::PROTECTED])
                && !$this->isAllowedMediaContent->__invoke($resource);
        } elseif (!in_array($resourceName, ['items', 'item_sets'])) {
            return false;
        }

        $bind = [
            'resource_id' => $resource->id(),
        ];
        $types = [
            'resource_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ];
        if ($this->user) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
            $bind['user_id'] = $this->user->getId();
            $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        } else {
            $orWhereUser = '';
        }

        if ($resourceName === 'items') {
            /*
            foreach ($resource->media() as $media) {
                $level = $this->accessLevel->__invoke($media);
                if (in_array($level, [AccessStatus::RESERVED, AccessStatus::PROTECTED])
                    && !$this->isAllowedMediaContent->__invoke($media)
                ) {
                    return true;
                }
            }
            return false;
            */
            // The standard api does not allow to search media by item set or
            // media by a list of items.
            // TODO Add a search arg and filter for status and embargo.
            // So use the standard db visibility check.
            /** @see \Omeka\Db\Filter\ResourceVisibilityFilter */
            $sql = <<<SQL
SELECT `access_status`.`id`
FROM `access_status`
JOIN `media` ON `media`.`id` = `access_status`.`id`
JOIN `resource` ON `resource`.`id` = `media`.`id`
WHERE `media`.`item_id` = :resource_id
    AND (`resource`.`is_public` = 1 $orWhereUser)
    AND `access_status`.`level` IN ("reserved", "protected")
LIMIT 1
;
SQL;
            return (bool) $this->entityManager->getConnection()->executeQuery($sql, $bind, $types)->fetchOne();
        } elseif ($resourceName === 'item_sets') {
            $sql = <<<SQL
SELECT `access_status`.`id`
FROM `access_status`
JOIN `media` ON `media`.`id` = `access_status`.`id`
JOIN `resource` ON `resource`.`id` = `media`.`id`
JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
JOIN `resource` AS `resource_item` ON `resource_item`.`id` = `item_item_set`.`item_id`
WHERE `item_item_set`.`item_set_id` = :resource_id
    AND (`resource_item`.`is_public` = 1 $orWhereUser)
    AND (`resource`.`is_public` = 1 $orWhereUser)
    AND `access_status`.`level` IN ("reserved", "protected")
LIMIT 1
;
SQL;
            return (bool) $this->entityManager->getConnection()->executeQuery($sql, $bind, $types)->fetchOne();
        } else {
            return false;
        }
    }
}

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
     * A resource is requestable when the visitor cannot access it as is and at
     * least one of its parts (the resource itself or, for items and item sets,
     * the descendants) carries a level in (reserved, protected) or is under an
     * active embargo. Forbidden resources are not requestable.
     *
     * @param string $accessType Reserved for future use; the helper currently
     *   inspects every layer of the resource (record + media files).
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource, ?string $accessType = null): bool
    {
        if (!$resource || $this->userCanViewAll) {
            return false;
        }

        $resourceName = $resource->resourceName();
        if ($resourceName === 'media') {
            // The level itself is enough for the media case: forbidden short
            // circuits, and reserved/protected are requestable when the file is
            // not granted. Embargo is folded inside isAllowedMediaContent.
            $level = $this->accessLevel->__invoke($resource);
            return in_array($level, [AccessStatus::RESERVED, AccessStatus::PROTECTED])
                && !$this->isAllowedMediaContent->__invoke($resource);
        }
        if (!in_array($resourceName, ['items', 'item_sets'])) {
            return false;
        }

        $bind = ['resource_id' => $resource->id()];
        $types = ['resource_id' => \Doctrine\DBAL\ParameterType::INTEGER];
        if ($this->user) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
            $bind['user_id'] = $this->user->getId();
            $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        } else {
            $orWhereUser = '';
        }

        // "Restricted" means the access_status carries an embargo currently
        // running, regardless of level, or one of the reserved/protected
        // levels. Forbidden is excluded because forbidden resources cannot be
        // requested.
        $restrictedClause = <<<'SQL'
            `access_status`.`level` IN ("reserved", "protected")
            OR (
                `access_status`.`level` <> "forbidden"
                AND (
                    (`access_status`.`embargo_start` IS NOT NULL AND `access_status`.`embargo_start` > NOW())
                    OR (`access_status`.`embargo_end` IS NOT NULL AND `access_status`.`embargo_end` > NOW())
                )
            )
            SQL;

        if ($resourceName === 'items') {
            // Either the item itself, or any of its media, qualifies.
            $sql = <<<SQL
                SELECT 1
                FROM `access_status`
                JOIN `resource` ON `resource`.`id` = `access_status`.`id`
                LEFT JOIN `media` ON `media`.`id` = `access_status`.`id`
                WHERE (
                    `access_status`.`id` = :resource_id
                    OR `media`.`item_id` = :resource_id
                )
                AND (`resource`.`is_public` = 1 $orWhereUser)
                AND ($restrictedClause)
                LIMIT 1
                SQL;
            return (bool) $this->entityManager->getConnection()
                ->executeQuery($sql, $bind, $types)->fetchOne();
        }

        // item_sets: the set itself, the items it contains, and the media of
        // those items are all candidates.
        $sql = <<<SQL
            SELECT 1
            FROM `access_status`
            JOIN `resource` ON `resource`.`id` = `access_status`.`id`
            LEFT JOIN `media` ON `media`.`id` = `access_status`.`id`
            LEFT JOIN `item_item_set` ON
                `item_item_set`.`item_id` = `access_status`.`id`
                OR `item_item_set`.`item_id` = `media`.`item_id`
            WHERE (
                `access_status`.`id` = :resource_id
                OR `item_item_set`.`item_set_id` = :resource_id
            )
            AND (`resource`.`is_public` = 1 $orWhereUser)
            AND ($restrictedClause)
            LIMIT 1
            SQL;
        return (bool) $this->entityManager->getConnection()
            ->executeQuery($sql, $bind, $types)->fetchOne();
    }
}

<?php declare(strict_types=1);

namespace Access\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Audit the access statuses: management mode and inconsistencies.
 *
 * The decisions are the restricted levels set by the admin (column level_set).
 * The mode is "collection" when they are set on item sets only, "document" when
 * they are set on items and medias only, "mixed" when both, "none" when all the
 * resources are free.
 *
 * The audit is read-only. Each finding has a count and a sample of ids.
 */
class AccessAudit
{
    protected const RANK = "FIELD(%s, 'free', 'reserved', 'protected', 'forbidden')";

    protected const TYPES = [
        'item_sets' => 'Omeka\\\\Entity\\\\ItemSet',
        'items' => 'Omeka\\\\Entity\\\\Item',
        'media' => 'Omeka\\\\Entity\\\\Media',
    ];

    public const MODES = [
        'collection' => 'The access is managed by item set: the restricted levels are set on item sets only.', // @translate
        'document' => 'The access is managed by document: the restricted levels are set on items and medias only.', // @translate
        'mixed' => 'The access is managed both by item set and by document.', // @translate
        'none' => 'No restricted level is set: all resources are free.', // @translate
    ];

    /**
     * Messages of the findings, in the order of display, with placeholders
     * {type}, {count} and {ids}.
     */
    public const FINDINGS = [
        'missing_status_restricted_parent' => 'Resources without access status, so free, although their parent is restricted ({type}): {count} (for example #{ids}). Run the task "Rebuild access index" to apply the level of their parent.', // @translate
        'private_item_sets_forbidding_public' => 'Private item sets with the level "forbidden", that forbid the files of their public items and medias: {count} (for example #{ids}). This level may come from an old conversion of the private resources: if the item set is private only to be hidden, set its level free.', // @translate
        'missing_status' => 'Resources without access status, so free ({type}): {count} (for example #{ids}). Run the task "Rebuild access index" to create them.', // @translate
        'stale_levels' => 'Resources whose effective level does not follow the hierarchy ({type}): {count} (for example #{ids}). Run the task "Rebuild access index".', // @translate
        'masked_decisions' => 'Restricted levels without effect, because a parent is stricter ({type}): {count} (for example #{ids}).', // @translate
        'item_sets_with_different_levels' => 'Items that belong to item sets with different levels, so the strictest one applies: {count} (for example #{ids}).', // @translate
        'item_set_embargoes_without_cascade' => 'Embargoes set on item sets, that do not apply to their items and medias, because the option "Cascade embargo dates" is disabled: {count} (for example #{ids}).', // @translate
    ];

    public const TYPE_LABELS = [
        'item_sets' => 'item sets', // @translate
        'items' => 'items', // @translate
        'media' => 'medias', // @translate
    ];

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var bool
     */
    protected $embargoCascade;

    /**
     * @var int
     */
    protected $sampleSize;

    public function __construct(Connection $connection, bool $embargoCascade, int $sampleSize = 20)
    {
        $this->connection = $connection;
        $this->embargoCascade = $embargoCascade;
        $this->sampleSize = $sampleSize;
    }

    public function audit(): array
    {
        $decisions = $this->decisions();
        $hasCollection = $decisions['item_sets'] > 0;
        $hasDocument = $decisions['items'] + $decisions['media'] > 0;
        $mode = $hasCollection
            ? ($hasDocument ? 'mixed' : 'collection')
            : ($hasDocument ? 'document' : 'none');

        $findings = [
            'missing_status' => $this->missingStatus(),
            'missing_status_restricted_parent' => $this->missingStatusWithRestrictedParent(),
            'private_item_sets_forbidding_public' => $this->privateItemSetsForbiddingPublic(),
            'stale_levels' => $this->staleLevels(),
            'masked_decisions' => $this->maskedDecisions(),
            'item_sets_with_different_levels' => $this->itemsInItemSetsWithDifferentLevels(),
        ];

        if (!$this->embargoCascade) {
            $findings['item_set_embargoes_without_cascade'] = $this->itemSetEmbargoes();
        }

        return [
            'mode' => $mode,
            'decisions' => $decisions,
            'findings' => $findings,
        ];
    }

    /**
     * List the findings with a count as flat rows, in the order of display.
     *
     * @return array[] Rows with keys "finding", "type" (null when the finding
     *   is not by resource type), "count" and "ids".
     */
    public static function rows(array $findings): array
    {
        $rows = [];
        foreach (array_keys(self::FINDINGS) as $key) {
            $finding = $findings[$key] ?? [];
            foreach (isset($finding['count']) ? [null => $finding] : $finding as $type => $data) {
                if (!empty($data['count'])) {
                    $rows[] = ['finding' => $key, 'type' => $type ?: null] + $data;
                }
            }
        }
        return $rows;
    }

    /**
     * Count the restricted levels set by the admin by resource type.
     */
    protected function decisions(): array
    {
        $result = [];
        foreach (self::TYPES as $name => $type) {
            $result[$name] = (int) $this->connection->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM `access_status` `s`
                JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$type"
                WHERE `s`.`level_set` <> 'free'
                SQL);
        }
        return $result;
    }

    /**
     * Resources without access status, so managed with the default level.
     */
    protected function missingStatus(): array
    {
        $result = [];
        foreach (self::TYPES as $name => $type) {
            $result[$name] = $this->countAndSample(<<<SQL
                SELECT `r`.`id`
                FROM `resource` `r`
                LEFT JOIN `access_status` `s` ON `s`.`id` = `r`.`id`
                WHERE `r`.`resource_type` = "$type" AND `s`.`id` IS NULL
                SQL);
        }
        return $result;
    }

    /**
     * Resources without access status, so free, although a parent is
     * restricted.
     */
    protected function missingStatusWithRestrictedParent(): array
    {
        $typeItem = self::TYPES['items'];
        return [
            'items' => $this->countAndSample(<<<SQL
                SELECT DISTINCT `r`.`id`
                FROM `resource` `r`
                JOIN `item_item_set` `iis` ON `iis`.`item_id` = `r`.`id`
                JOIN `access_status` `a` ON `a`.`id` = `iis`.`item_set_id` AND `a`.`level_set` <> 'free'
                LEFT JOIN `access_status` `s` ON `s`.`id` = `r`.`id`
                WHERE `r`.`resource_type` = "$typeItem" AND `s`.`id` IS NULL
                SQL),
            'media' => $this->countAndSample(<<<'SQL'
                SELECT `m`.`id`
                FROM `media` `m`
                JOIN `access_status` `i` ON `i`.`id` = `m`.`item_id` AND `i`.`level` <> 'free'
                LEFT JOIN `access_status` `s` ON `s`.`id` = `m`.`id`
                WHERE `s`.`id` IS NULL
                SQL),
        ];
    }

    /**
     * Private item sets with the level "forbidden" that contain public items.
     */
    protected function privateItemSetsForbiddingPublic(): array
    {
        $type = self::TYPES['item_sets'];
        return $this->countAndSample(<<<SQL
            SELECT `s`.`id`
            FROM `access_status` `s`
            JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$type" AND `r`.`is_public` = 0
            WHERE `s`.`level_set` = 'forbidden'
                AND EXISTS (
                    SELECT 1
                    FROM `item_item_set` `iis`
                    JOIN `resource` `ri` ON `ri`.`id` = `iis`.`item_id` AND `ri`.`is_public` = 1
                    WHERE `iis`.`item_set_id` = `s`.`id`
                )
            SQL);
    }

    /**
     * Effective levels that differ from the cascade: the index should be
     * rebuilt.
     */
    protected function staleLevels(): array
    {
        $level = sprintf(self::RANK, '`s`.`level`');
        $levelSet = sprintf(self::RANK, '`s`.`level_set`');
        $itemSetRank = sprintf(self::RANK, '`a`.`level_set`');
        $itemLevel = sprintf(self::RANK, '`i`.`level`');
        $typeItemSet = self::TYPES['item_sets'];
        $typeItem = self::TYPES['items'];
        return [
            'item_sets' => $this->countAndSample(<<<SQL
                SELECT `s`.`id`
                FROM `access_status` `s`
                JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$typeItemSet"
                WHERE `s`.`level` <> `s`.`level_set`
                SQL),
            'items' => $this->countAndSample(<<<SQL
                SELECT `s`.`id`
                FROM `access_status` `s`
                JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$typeItem"
                LEFT JOIN (
                    SELECT `iis`.`item_id`, MAX($itemSetRank) AS `rank_set`
                    FROM `item_item_set` `iis`
                    JOIN `access_status` `a` ON `a`.`id` = `iis`.`item_set_id`
                    GROUP BY `iis`.`item_id`
                ) `p` ON `p`.`item_id` = `s`.`id`
                WHERE $level <> GREATEST($levelSet, COALESCE(`p`.`rank_set`, 1), 1)
                SQL),
            'media' => $this->countAndSample(<<<SQL
                SELECT `s`.`id`
                FROM `access_status` `s`
                JOIN `media` `m` ON `m`.`id` = `s`.`id`
                LEFT JOIN `access_status` `i` ON `i`.`id` = `m`.`item_id`
                WHERE $level <> GREATEST($levelSet, COALESCE($itemLevel, 1), 1)
                SQL),
        ];
    }

    /**
     * Restricted levels set on items or medias that have no effect, because a
     * parent is stricter.
     */
    protected function maskedDecisions(): array
    {
        $level = sprintf(self::RANK, '`s`.`level`');
        $levelSet = sprintf(self::RANK, '`s`.`level_set`');
        $result = [];
        foreach (['items', 'media'] as $name) {
            $type = self::TYPES[$name];
            $result[$name] = $this->countAndSample(<<<SQL
                SELECT `s`.`id`
                FROM `access_status` `s`
                JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$type"
                WHERE `s`.`level_set` <> 'free' AND $levelSet < $level
                SQL);
        }
        return $result;
    }

    /**
     * Items that belong to item sets with different levels: the strictest one
     * applies.
     */
    protected function itemsInItemSetsWithDifferentLevels(): array
    {
        return $this->countAndSample(<<<'SQL'
            SELECT `iis`.`item_id` AS `id`
            FROM `item_item_set` `iis`
            JOIN `access_status` `a` ON `a`.`id` = `iis`.`item_set_id`
            GROUP BY `iis`.`item_id`
            HAVING COUNT(DISTINCT `a`.`level_set`) > 1
            SQL);
    }

    /**
     * Embargoes set on item sets, that apply to the item sets only, because the
     * embargo cascade is disabled.
     */
    protected function itemSetEmbargoes(): array
    {
        $type = self::TYPES['item_sets'];
        return $this->countAndSample(<<<SQL
            SELECT `s`.`id`
            FROM `access_status` `s`
            JOIN `resource` `r` ON `r`.`id` = `s`.`id` AND `r`.`resource_type` = "$type"
            WHERE `s`.`embargo_start_set` IS NOT NULL OR `s`.`embargo_end_set` IS NOT NULL
            SQL);
    }

    /**
     * @return array{count: int, ids: int[]}
     */
    protected function countAndSample(string $sql): array
    {
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ($sql) `audit`");
        $ids = $count
            ? array_map('intval', $this->connection->fetchFirstColumn("SELECT `audit`.`id` FROM ($sql) `audit` ORDER BY `audit`.`id` LIMIT " . $this->sampleSize))
            : [];
        return [
            'count' => $count,
            'ids' => $ids,
        ];
    }
}

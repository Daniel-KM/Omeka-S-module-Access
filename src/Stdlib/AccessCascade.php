<?php declare(strict_types=1);

namespace Access\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Materialize the effective access columns of access_status from the "set"
 * columns and the hierarchy item set > item > media.
 *
 * The effective level of a resource is the strictest set level along its chain,
 * on the order free < reserved < protected < forbidden:
 *   - item set: effective = set (item sets have no parent);
 *   - item:     effective = max(item.set, max of its item sets set);
 *   - media:    effective = max(media.set, parent item effective).
 *
 * Embargo cascades the same way only when enabled (earliest set start, latest
 * set end along the chain); otherwise effective embargo = set embargo. The
 * level and the embargo are always materialized and checked independently.
 *
 * The recompute is set-based SQL, ordered item sets, then items, then media, so
 * each layer reads the already-updated effective value of its parent. It runs
 * on the whole base (rebuild job) or on a subtree (resource save events).
 */
class AccessCascade
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var bool
     */
    protected $embargoCascade;

    /**
     * SQL fragment ranking a level column on the documented order. FIELD
     * returns 1..4, or 0 for an unknown value, so COALESCE keeps it >= 1.
     */
    protected const RANK = "FIELD(%s, 'free', 'reserved', 'protected', 'forbidden')";

    protected const UNRANK = "ELT(%s, 'free', 'reserved', 'protected', 'forbidden')";

    public function __construct(Connection $connection, bool $embargoCascade)
    {
        $this->connection = $connection;
        $this->embargoCascade = $embargoCascade;
    }

    /**
     * Recompute the effective columns for every access_status row.
     */
    public function recomputeAll(): void
    {
        $this->recomputeItemSets(null);
        $this->recomputeItems(null);
        $this->recomputeMediaSet(null);
    }

    /**
     * Recompute an item set, its items and their media.
     */
    public function recomputeItemSet(int $itemSetId): void
    {
        $this->recomputeItemSets([$itemSetId]);
        // Items that belong to this item set, and their media.
        $itemIds = $this->connection
            ->executeQuery(
                'SELECT item_id FROM item_item_set WHERE item_set_id = :id',
                ['id' => $itemSetId],
                ['id' => \Doctrine\DBAL\ParameterType::INTEGER]
            )
            ->fetchFirstColumn();
        $itemIds = array_map('intval', $itemIds);
        $this->recomputeItems($itemIds ?: [0]);
        $this->recomputeMediaOfItems($itemIds ?: [0]);
    }

    /**
     * Recompute an item and its media.
     */
    public function recomputeItem(int $itemId): void
    {
        $this->recomputeItems([$itemId]);
        $this->recomputeMediaOfItems([$itemId]);
    }

    /**
     * Recompute a single media.
     */
    public function recomputeMedia(int $mediaId): void
    {
        $this->recomputeMediaSet([$mediaId]);
    }

    /**
     * @param int[]|null $ids Null to process every item set.
     */
    protected function recomputeItemSets(?array $ids): void
    {
        // Item sets have no parent, so the effective value equals the set one,
        // for the level and the embargo, whatever the cascade setting.
        $where = $this->whereIds('access_status.id', $ids)
            . ' AND `resource`.`resource_type` = "Omeka\\\\Entity\\\\ItemSet"';
        $this->connection->executeStatement(
            <<<SQL
            UPDATE `access_status`
            JOIN `resource` ON `resource`.`id` = `access_status`.`id`
            SET `access_status`.`level` = `access_status`.`level_set`,
                `access_status`.`embargo_start` = `access_status`.`embargo_start_set`,
                `access_status`.`embargo_end` = `access_status`.`embargo_end_set`
            WHERE $where
            SQL
        );
    }

    /**
     * @param int[]|null $ids Null to process every item.
     */
    protected function recomputeItems(?array $ids): void
    {
        $setRank = sprintf(self::RANK, '`access_status`.`level_set`');
        $setsRank = sprintf(self::RANK, '`a_set`.`level_set`');
        $unrank = sprintf(self::UNRANK, "GREATEST($setRank, COALESCE((
                SELECT MAX($setsRank)
                FROM `item_item_set` `iis`
                JOIN `access_status` `a_set` ON `a_set`.`id` = `iis`.`item_set_id`
                WHERE `iis`.`item_id` = `access_status`.`id`
            ), 1))");
        $where = $this->whereIds('access_status.id', $ids)
            . ' AND `resource`.`resource_type` = "Omeka\\\\Entity\\\\Item"';

        $this->connection->executeStatement(
            <<<SQL
            UPDATE `access_status`
            JOIN `resource` ON `resource`.`id` = `access_status`.`id`
            SET `access_status`.`level` = $unrank
            WHERE $where
            SQL
        );

        $this->recomputeItemsEmbargo($ids);
    }

    /**
     * @param int[]|null $ids
     */
    protected function recomputeItemsEmbargo(?array $ids): void
    {
        $where = $this->whereIds('access_status.id', $ids)
            . ' AND `resource`.`resource_type` = "Omeka\\\\Entity\\\\Item"';

        if (!$this->embargoCascade) {
            $this->connection->executeStatement(
                <<<SQL
                UPDATE `access_status`
                JOIN `resource` ON `resource`.`id` = `access_status`.`id`
                SET `access_status`.`embargo_start` = `access_status`.`embargo_start_set`,
                    `access_status`.`embargo_end` = `access_status`.`embargo_end_set`
                WHERE $where
                SQL
            );
            return;
        }

        // Widest window: earliest set start, latest set end along the chain.
        $this->connection->executeStatement(
            <<<SQL
            UPDATE `access_status`
            JOIN `resource` ON `resource`.`id` = `access_status`.`id`
            SET `access_status`.`embargo_start` = LEAST(
                    COALESCE(`access_status`.`embargo_start_set`, (
                        SELECT MIN(`a_set`.`embargo_start_set`)
                        FROM `item_item_set` `iis`
                        JOIN `access_status` `a_set` ON `a_set`.`id` = `iis`.`item_set_id`
                        WHERE `iis`.`item_id` = `access_status`.`id`
                    )),
                    COALESCE((
                        SELECT MIN(`a_set`.`embargo_start_set`)
                        FROM `item_item_set` `iis`
                        JOIN `access_status` `a_set` ON `a_set`.`id` = `iis`.`item_set_id`
                        WHERE `iis`.`item_id` = `access_status`.`id`
                    ), `access_status`.`embargo_start_set`)
                ),
                `access_status`.`embargo_end` = GREATEST(
                    COALESCE(`access_status`.`embargo_end_set`, (
                        SELECT MAX(`a_set`.`embargo_end_set`)
                        FROM `item_item_set` `iis`
                        JOIN `access_status` `a_set` ON `a_set`.`id` = `iis`.`item_set_id`
                        WHERE `iis`.`item_id` = `access_status`.`id`
                    )),
                    COALESCE((
                        SELECT MAX(`a_set`.`embargo_end_set`)
                        FROM `item_item_set` `iis`
                        JOIN `access_status` `a_set` ON `a_set`.`id` = `iis`.`item_set_id`
                        WHERE `iis`.`item_id` = `access_status`.`id`
                    ), `access_status`.`embargo_end_set`)
                )
            WHERE $where
            SQL
        );
    }

    /**
     * @param int[]|null $ids Null to process every media.
     */
    protected function recomputeMediaSet(?array $ids): void
    {
        $mediaRank = sprintf(self::RANK, '`access_status`.`level_set`');
        $itemRank = sprintf(self::RANK, '`a_item`.`level`');
        $unrank = sprintf(self::UNRANK, "GREATEST($mediaRank, COALESCE($itemRank, 1))");
        $where = $this->whereIds('access_status.id', $ids);

        $this->connection->executeStatement(
            <<<SQL
            UPDATE `access_status`
            JOIN `media` ON `media`.`id` = `access_status`.`id`
            LEFT JOIN `access_status` `a_item` ON `a_item`.`id` = `media`.`item_id`
            SET `access_status`.`level` = $unrank
            WHERE $where
            SQL
        );

        $this->recomputeMediaEmbargo($ids);
    }

    /**
     * Recompute the media of the given items (targeted subtree).
     *
     * @param int[] $itemIds
     */
    protected function recomputeMediaOfItems(array $itemIds): void
    {
        $mediaIds = $this->connection
            ->executeQuery(
                'SELECT id FROM media WHERE item_id IN (:ids)',
                ['ids' => $itemIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn();
        $mediaIds = array_map('intval', $mediaIds);
        $this->recomputeMediaSet($mediaIds ?: [0]);
    }

    /**
     * @param int[]|null $ids
     */
    protected function recomputeMediaEmbargo(?array $ids): void
    {
        $where = $this->whereIds('access_status.id', $ids);

        if (!$this->embargoCascade) {
            $this->connection->executeStatement(
                <<<SQL
                UPDATE `access_status`
                JOIN `media` ON `media`.`id` = `access_status`.`id`
                SET `access_status`.`embargo_start` = `access_status`.`embargo_start_set`,
                    `access_status`.`embargo_end` = `access_status`.`embargo_end_set`
                WHERE $where
                SQL
            );
            return;
        }

        // Widest window between the media set embargo and the parent item
        // effective embargo (already materialized).
        $this->connection->executeStatement(
            <<<SQL
            UPDATE `access_status`
            JOIN `media` ON `media`.`id` = `access_status`.`id`
            LEFT JOIN `access_status` `a_item` ON `a_item`.`id` = `media`.`item_id`
            SET `access_status`.`embargo_start` = LEAST(
                    COALESCE(`access_status`.`embargo_start_set`, `a_item`.`embargo_start`),
                    COALESCE(`a_item`.`embargo_start`, `access_status`.`embargo_start_set`)
                ),
                `access_status`.`embargo_end` = GREATEST(
                    COALESCE(`access_status`.`embargo_end_set`, `a_item`.`embargo_end`),
                    COALESCE(`a_item`.`embargo_end`, `access_status`.`embargo_end_set`)
                )
            WHERE $where
            SQL
        );
    }

    /**
     * Build a WHERE fragment scoping to a list of ids, or always-true for a
     * full pass.
     *
     * @param int[]|null $ids
     */
    protected function whereIds(string $column, ?array $ids): string
    {
        if ($ids === null) {
            return '1 = 1';
        }
        $ids = array_values(array_unique(array_map('intval', $ids))) ?: [0];
        return sprintf('%s IN (%s)', $column, implode(', ', $ids));
    }
}

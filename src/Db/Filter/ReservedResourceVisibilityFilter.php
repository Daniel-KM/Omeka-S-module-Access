<?php declare(strict_types=1);

namespace AccessResource\Db\Filter;

use Omeka\Db\Filter\ResourceVisibilityFilter;

/**
 * Filter resources by default rules and user access.
 *
 * Any user can view reserved resources metadata.
 * Access to files is managed in the controller.
 *
 * Warning: this filter can be overridden by module Group, so not compatible.
 *
 * {@inheritdoc}
 */
class ReservedResourceVisibilityFilter extends ResourceVisibilityFilter
{
    protected function getResourceConstraint($alias)
    {
        // Check rights from the core resource visibility filter.
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins, who already view all private.
        if (empty($constraints)) {
            return $constraints;
        }

        // The embargo is checked separately to avoid complex request.
        // Useless: the status should be set from embargo via a cron job.

        // And access_status is not forbidden.
        // @todo Use a simple join with table "access_resource" (and embargo dates?) of resources.

        $reservedConstraints = sprintf(
            'EXISTS (
    SELECT `access_status`.`id`
    FROM `access_status`
    WHERE `access_status`.`id` = `%s`.`id`
        AND `access_status`.`status` != "forbidden"
    LIMIT 1
)',
            $alias
        );

        return $constraints . sprintf(' OR (%s)', $reservedConstraints);
    }
}

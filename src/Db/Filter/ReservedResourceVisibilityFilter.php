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

        // Resource should have property 'curation:reserved', whatever the value.
        // The embargo is checked separately to avoid complex request.
        // @todo Check embargo in visibility filter (don't take embargo option in account?).

        $reservedConstraints = [];

        // Resource should be private.
        $reservedConstraints[] = sprintf('%s.`is_public` = 0', $alias);

        // And listed in the table access_reserved.
        // @todo Use a simple join with table "access_resource" (and embargo dates?) of resources.
        $reservedConstraints[] = sprintf(
            'EXISTS (
    SELECT `access_reserved`.`id`
    FROM `access_reserved`
    WHERE `access_reserved`.`id` = `%s`.`id`
    LIMIT 1
)',
            $alias
        );

        return $constraints . sprintf(' OR (%s)', implode(' AND ', $reservedConstraints));
    }
}

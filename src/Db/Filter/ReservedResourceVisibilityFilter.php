<?php declare(strict_types=1);

namespace AccessResource\Db\Filter;

use AccessResource\Service\Property\ReservedAccess as PropertyReservedAccess;

/**
 * Filter resources by default rules and user access.
 * Users can view private resources when they have access to them as globally,
 * by ip or individually.
 *
 * Warning: this filter can be overridden by module Group, so not compatible.
 *
 * {@inheritdoc}
 */
class ReservedResourceVisibilityFilter extends \Omeka\Db\Filter\ResourceVisibilityFilter
{
    protected function getResourceConstraint($alias)
    {
        // Check rights from the core resource visibility filter.
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins, who already view all private.
        if (empty($constraints)) {
            return $constraints;
        }

        $reservedConstraints = [];

        // Resource should be private.
        $reservedConstraints[] = sprintf('%s.`is_public` = 0', $alias);

        // Resource should have property 'curation:reserved', whatever the value.
        $property = $this->serviceLocator->get(PropertyReservedAccess::class);

        $reservedConstraints[] = sprintf(
            '%s.`id` IN (
    SELECT `value`.`resource_id`
    FROM `value`
    WHERE `value`.`property_id` = %s
)',
            $alias,
            (int) $property->getId()
        );

        return $constraints . sprintf(' OR (%s)', implode(' AND ', $reservedConstraints));
    }
}

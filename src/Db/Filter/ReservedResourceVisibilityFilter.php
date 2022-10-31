<?php declare(strict_types=1);

namespace AccessResource\Db\Filter;

use Omeka\Db\Filter\ResourceVisibilityFilter;

/**
 * Filter resources by default rules and user access.
 *
 * Any user can view restricted resources metadata.
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
        static $reservedAccessPropertyId;

        // Check rights from the core resource visibility filter.
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins, who already view all private.
        if (empty($constraints)) {
            return $constraints;
        }

        // Resource should have property 'curation:reserved', whatever the value.
        // The embargo is checked separately to avoid complex request.
        // @todo Use a simple join with a table that index the openness (and embargo dates?) of resources.
        if (is_null($reservedAccessPropertyId)) {
            $reservedAccessPropertyId = $this->serviceLocator->get('ControllerPluginManager')->get('reservedAccessPropertyId')->__invoke();
        }

        $reservedConstraints = [];

        // Resource should be private.
        $reservedConstraints[] = sprintf('%s.`is_public` = 0', $alias);

        $reservedConstraints[] = sprintf(
            'EXISTS (
    SELECT `value`.`resource_id`
    FROM `value`
    WHERE `value`.`property_id` = %s
        AND `value`.`resource_id` = %s.`id`
        LIMIT 1
)',
            $reservedAccessPropertyId,
            $alias
        );

        return $constraints . sprintf(' OR (%s)', implode(' AND ', $reservedConstraints));
    }
}

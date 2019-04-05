<?php
namespace AccessResource\Db\Filter;

use Doctrine\DBAL\Types\Type;
use Omeka\Db\Filter\ResourceVisibilityFilter as BaseResourceVisibilityFilter;
use AccessResource\Service\Property\ReservedAccess as PropertyReservedAccess;

/**
 * Filter resources by default rules and user access.
 *
 * Users can view private resources when they have access to them.
 *
 * {@inheritdoc}
 */
class ResourceVisibilityFilter extends BaseResourceVisibilityFilter
{
    protected function getResourceConstraint($alias)
    {
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins or visitors, who already view all
        // or nothing private.
        if (empty($constraints)) {
            return $constraints;
        }


        $reservedConstraints = [];

        // Resource should be private.
        $reservedConstraints[] = sprintf('%s.is_public = 0', $alias);

        // Resource should have property 'curation:reservedAccess'.
        $property = $this->serviceLocator->get(PropertyReservedAccess::class);
        $reservedConstraints[] = sprintf(
            '%s.id IN (SELECT `value`.`resource_id` FROM `value` WHERE `value`.`property_id` = %s)',
            $alias,
            $this->getConnection()->quote($property->getId(), Type::INTEGER)
        );

        $constraints .= sprintf(' OR (%s)', implode(' AND ', $reservedConstraints));

        return $constraints;
    }
}

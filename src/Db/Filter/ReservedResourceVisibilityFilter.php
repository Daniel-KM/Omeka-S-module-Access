<?php
namespace AccessResource\Db\Filter;

use AccessResource\Service\Property\ReservedAccess as PropertyReservedAccess;
use Doctrine\DBAL\Types\Type;
use Omeka\Db\Filter\ResourceVisibilityFilter as BaseResourceVisibilityFilter;

/**
 * Filter resources by default rules and user access.
 *
 * Users can view private resources when they have access to them.
 *
 * {@inheritdoc}
 */
class ReservedResourceVisibilityFilter extends BaseResourceVisibilityFilter
{
    protected function getResourceConstraint($alias)
    {
        // Check rights from the core resource visibility filter.
        $constraints = parent::getResourceConstraint($alias);

        // Don't add a constraint for admins, who already view all private.
        if (empty($constraints)) {
            return $constraints;
        }

        // Don't add a constraint for visitors, who see only public resources.
        $identity = $this->serviceLocator->get('Omeka\AuthenticationService')->getIdentity();
        if (!$identity) {
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

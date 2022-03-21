<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\PROPERTY_EMBARGO_END;
use const AccessResource\PROPERTY_EMBARGO_START;

use DateTime;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class IsUnderEmbargo extends AbstractPlugin
{
    /**
     * Check if a resource has an embargo start or end date set and check it.
     *
     * @return bool|null Null if the embargo dates are not set, true if resource
     * is under embargo, else false.
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource): ?bool
    {
        if (is_null($resource)) {
            return null;
        }

        /**
         * @var \Omeka\Api\Representation\ValueRepresentation $start
         * @var \Omeka\Api\Representation\ValueRepresentation $end
         */
        $start = $resource->value(PROPERTY_EMBARGO_START);
        $end = $resource->value(PROPERTY_EMBARGO_END);
        if (!$start && !$end) {
            return null;
        }

        // TODO Start and end dates are not checked for validity.

        $now = new DateTime('now');

        // Check end date first, because it is much more common.
        if ($end) {
            if ($end->type() === 'numeric:timestamp') {
                $endDate = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue((string) $end->value());
                if ($now < $endDate) {
                    return true;
                }
            } else {
                try {
                    $endDate = new DateTime((string) $end);
                    if ($now < $endDate) {
                        return true;
                    }
                } catch (\Exception $e) {
                    $end = null;
                }
            }
        }

        if ($start) {
            if ($start->type() === 'numeric:timestamp') {
                $startDate = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue((string) $start->value());
                if ($now >= $startDate) {
                    return true;
                }
            } else {
                try {
                    $startDate = new DateTime((string) $start);
                    if ($now >= $startDate) {
                        return true;
                    }
                } catch (\Exception $e) {
                    $start = null;
                }
            }
        }

        return false;
    }
}

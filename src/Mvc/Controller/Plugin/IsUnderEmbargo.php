<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class IsUnderEmbargo extends AbstractPlugin
{
    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatus;

    public function __construct(
        AccessStatus $accessStatus
    ) {
        $this->accessStatus = $accessStatus;
    }

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

        /** @var \AccessResource\Entity\AccessStatus $accessStatus */
        $accessStatus = $this->accessStatus->__invoke($resource);
        return $accessStatus
            ? $accessStatus->isUnderEmbargo()
            : null;
    }
}

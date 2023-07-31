<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Api\Representation\AccessStatusRepresentation;
use Access\Mvc\Controller\Plugin\AccessStatus as AccessStatusPlugin;
use Laminas\View\Helper\AbstractHelper;

class AccessStatus extends AbstractHelper
{
    /**
     * @var \Access\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatusPlugin;

    public function __construct(AccessStatusPlugin $accessStatus)
    {
        $this->accessStatusPlugin = $accessStatus;
    }

    /**
     * Get access status representation of a resource.
     *
     * @uses \Access\Mvc\Controller\Plugin\AccessStatus
     *
     * @return \Access\Entity\AccessStatus|\Access\Api\Representation\AccessStatusRepresentation|null
     */
    public function __invoke($resource): ?AccessStatusRepresentation
    {
        return $this->accessStatusPlugin->__invoke($resource, true);
    }
}

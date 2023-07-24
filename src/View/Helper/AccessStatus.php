<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Api\Representation\AccessStatusRepresentation;
use AccessResource\Mvc\Controller\Plugin\AccessStatus as AccessStatusPlugin;
use Laminas\View\Helper\AbstractHelper;

class AccessStatus extends AbstractHelper
{
    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatusPlugin;

    public function __construct(AccessStatusPlugin $accessStatus)
    {
        $this->accessStatusPlugin = $accessStatus;
    }

    /**
     * Get access status representation of a resource.
     *
     * @uses \AccessResource\Mvc\Controller\Plugin\AccessStatus
     *
     * @return \AccessResource\Entity\AccessStatus|\AccessResource\Api\Representation\AccessStatusRepresentation|null
     */
    public function __invoke($resource): ?AccessStatusRepresentation
    {
        return $this->accessStatusPlugin->__invoke($resource, true);
    }
}

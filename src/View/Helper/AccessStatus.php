<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Mvc\Controller\Plugin\AccessStatus as AccessStatusPlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

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
     * Get the access status of a resource (free, reserved or forbidden).
     *
     * @uses \AccessResource\Mvc\Controller\Plugin\AccessStatus
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource): string
    {
        return $this->accessStatusPlugin->__invoke($resource);
    }
}

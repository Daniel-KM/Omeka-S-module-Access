<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Mvc\Controller\Plugin\IsReservedResource as IsReservedResourcePlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * @deprecated Since 3.4.0.14.
 */
class IsReservedResource extends AbstractHelper
{
    /**
     * @var \AccessResource\Mvc\Controller\Plugin\IsReservedResource
     */
    protected $isReservedResourcePlugin;

    public function __construct(IsReservedResourcePlugin $isReservedResource)
    {
        $this->isReservedResourcePlugin = $isReservedResource;
    }

    /**
     * Check if access to a resource is restricted.
     *
     * For a three state status (free, reserved or forbidden), see
     * @see \AccessResource\Mvc\Controller\Plugin\AccessStatus
     *
     * @uses \AccessResource\Mvc\Controller\Plugin\IsReservedResource
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource): ?bool
    {
        return $this->isReservedResourcePlugin->__invoke($resource);
    }
}

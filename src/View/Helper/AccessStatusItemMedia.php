<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Mvc\Controller\Plugin\AccessStatusItemMedia as AccessStatusItemMediaPlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

class AccessStatusItemMedia extends AbstractHelper
{
    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatusItemMedia
     */
    protected $accessStatusItemMediaPlugin;

    public function __construct(AccessStatusItemMediaPlugin $accessStatusItemMedia)
    {
        $this->accessStatusItemMediaPlugin = $accessStatusItemMedia;
    }

    /**
     * Get access status of an item and its media (free, reserved or forbidden).
     *
     * @uses \AccessResource\Mvc\Controller\Plugin\AccessStatusItemMedia
     */
    public function __invoke(?ItemRepresentation $item): array
    {
        return $item
            ? $this->accessStatusItemMediaPlugin->__invoke($item)
            : [];
    }
}

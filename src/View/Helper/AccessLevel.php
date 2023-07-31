<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Mvc\Controller\Plugin\AccessLevel as AccessLevelPlugin;
use Laminas\View\Helper\AbstractHelper;

class AccessLevel extends AbstractHelper
{
    /**
     * @var \Access\Mvc\Controller\Plugin\AccessLevel
     */
    protected $accessLevelPlugin;

    public function __construct(AccessLevelPlugin $accessLevel)
    {
        $this->accessLevelPlugin = $accessLevel;
    }

    /**
     * Get access level of a resource (free, reserved, protected or forbidden).
     *
     * @uses \Access\Mvc\Controller\Plugin\AccessLevel
     */
    public function __invoke($resource): string
    {
        return $this->accessLevelPlugin->__invoke($resource);
    }
}

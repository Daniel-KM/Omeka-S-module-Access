<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Mvc\Controller\Plugin\IsUnderEmbargo as IsUnderEmbargoPlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class IsUnderEmbargo extends AbstractHelper
{
    /**
     * @var \Access\Mvc\Controller\Plugin\IsUnderEmbargo
     */
    protected $isUnderEmbargoPlugin;

    public function __construct(IsUnderEmbargoPlugin $isUnderEmbargo)
    {
        $this->isUnderEmbargoPlugin = $isUnderEmbargo;
    }

    /**
     * Check if a resource has an embargo start or end date set and check it.
     *
     * @return bool|null Null if the embargo dates are not set or invalid, true
     * if resource is under embargo, else false.
     *
     * @uses \Access\Mvc\Controller\Plugin\IsUnderEmbargo
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource): ?bool
    {
        return $this->isUnderEmbargoPlugin->__invoke($resource);
    }
}

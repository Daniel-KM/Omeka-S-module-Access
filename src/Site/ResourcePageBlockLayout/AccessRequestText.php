<?php declare(strict_types=1);

namespace Access\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

class AccessRequestText implements ResourcePageBlockLayoutInterface
{
    public function getLabel() : string
    {
        return 'Access request text'; // @translate
    }

    public function getCompatibleResourceNames() : array
    {
        return [
            'items',
            'media',
            'item_sets',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource) : string
    {
        /** @see \Access\View\Helper\IsAccessRequestable */
        $isAccessRequestable = $view->isAccessRequestable($resource);

        return $view->partial('common/resource-page-block-layout/access-request-text', [
            'resource' => $resource,
            'isAccessRequestable' => $isAccessRequestable,
        ]);
    }
}

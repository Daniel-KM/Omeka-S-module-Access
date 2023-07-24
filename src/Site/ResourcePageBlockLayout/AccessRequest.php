<?php declare(strict_types=1);

namespace AccessResource\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

class AccessRequest implements ResourcePageBlockLayoutInterface
{
    public function getLabel() : string
    {
        return 'Access request form'; // @translate
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
        return $view->partial('common/resource-page-block-layout/access-request', [
            'resource' => $resource,
        ]);
    }
}

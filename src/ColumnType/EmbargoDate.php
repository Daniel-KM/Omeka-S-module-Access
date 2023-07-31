<?php declare(strict_types=1);

namespace Access\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

class EmbargoDate implements ColumnTypeInterface
{
    public function getLabel() : string
    {
        return 'Embargo date'; // @translate
    }

    public function getResourceTypes() : array
    {
        return [
            'items',
            'media',
            'item_sets',
        ];
    }

    public function getMaxColumns() : ?int
    {
        return 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data) : string
    {
        return '';
    }

    /**
     * @todo Search and sort resources by embargo and status.
     */
    public function getSortBy(array $data) : ?string
    {
        return null;
    }

    public function renderHeader(PhpRenderer $view, array $data) : string
    {
        return $this->getLabel();
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data) : ?string
    {
        /** @var \Access\Api\Representation\AccessStatusRepresentation $accessStatus */
        $accessStatus = $view->accessStatus($resource, true);
        return $accessStatus
            ? $accessStatus->displayEmbargo()
            : '';
    }
}

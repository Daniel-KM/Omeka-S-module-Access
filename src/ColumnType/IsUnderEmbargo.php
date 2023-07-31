<?php declare(strict_types=1);

namespace Access\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

class IsUnderEmbargo implements ColumnTypeInterface
{
    public function getLabel() : string
    {
        return 'Is under embargo'; // @translate
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
        return $view->isUnderEmbargo($resource)
            ? $view->translate('Yes')
            : $view->translate('No');
    }
}

<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Mvc\Controller\Plugin\IsAllowedMediaContent;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Return the media of an item that are visible to the current visitor.
 *
 * In public context $item->media() is already filtered by the Doctrine SQL
 * filter, so this helper is mainly a documentation hook for theme authors. The
 * "content" check additionally drops media whose file the visitor cannot fetch,
 * which is useful for galleries or viewers that would otherwise display broken
 * placeholders.
 */
class AccessibleMedia extends AbstractHelper
{
    private IsAllowedMediaContent $isAllowedMediaContent;

    public function __construct(IsAllowedMediaContent $isAllowedMediaContent)
    {
        $this->isAllowedMediaContent = $isAllowedMediaContent;
    }

    /**
     * @param string $check 'metadata' (default) or 'content'.
     * @return MediaRepresentation[]
     */
    public function __invoke(ItemRepresentation $item, string $check = 'metadata'): array
    {
        $media = $item->media();
        if ($check === 'metadata') {
            return $media;
        }
        return array_values(array_filter(
            $media,
            fn (MediaRepresentation $m) => ($this->isAllowedMediaContent)($m)
        ));
    }
}

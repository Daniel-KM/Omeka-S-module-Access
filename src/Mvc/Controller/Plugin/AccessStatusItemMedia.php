<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

// use AccessResource\Entity\AccessReserved;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Item;

class AccessStatusItemMedia extends AbstractPlugin
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus
     */
    protected $accessStatus;

    public function __construct(EntityManager $entityManager, AccessStatus $accessStatus)
    {
        $this->entityManager = $entityManager;
        $this->accessStatus = $accessStatus;
    }

    /**
     * Get access status of an item and its media (free, reserved or forbidden).
     */
    public function __invoke($item): array
    {
        if (!$item) {
            return [];
        } elseif (is_object($item)) {
            if ($item instanceof ItemRepresentation) {
                // The resource should be already in the doctrine cache.
                $item = $this->entityManager->find(Item::class, $item->id());
            } elseif (!$item instanceof Item) {
                return [];
            }
        } else {
            if (is_numeric($item)) {
                $itemId = (int) $item;
                $item = $this->entityManager->find(Item::class, $itemId);
                if (!$item) {
                    return [];
                }
            } elseif (is_array($item) && !empty($item['o:id'])) {
                $itemId = (int) $item['o:id'];
                $item = $this->entityManager->find(Item::class, $itemId);
                if (!$item) {
                    return [];
                }
            } else {
                return [];
            }
        }

        /** @var \Omeka\Entity\Item $item */
        $result = [
            $item->getId() => $this->accessStatus->__invoke($item),
        ];
        foreach ($item->getMedia() as $media) {
            $result[$media->getId()] = $this->accessStatus->__invoke($media);
        }
        return $result;
    }
}

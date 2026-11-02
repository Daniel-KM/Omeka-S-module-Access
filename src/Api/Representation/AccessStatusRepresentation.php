<?php declare(strict_types=1);

namespace Access\Api\Representation;

use Access\Entity\AccessStatus;
use DateTime;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class AccessStatusRepresentation extends AbstractEntityRepresentation
{
    const LEVELS = [
        AccessStatus::FREE => AccessStatus::FREE,
        AccessStatus::RESERVED => AccessStatus::RESERVED,
        AccessStatus::PROTECTED => AccessStatus::PROTECTED,
        AccessStatus::FORBIDDEN => AccessStatus::FORBIDDEN,
    ];

    /**
     * Construct the value representation object.
     *
     * @todo Adapter for access status?
     */
    public function __construct(AccessStatus $accessStatus, ServiceLocatorInterface $serviceLocator)
    {
        // Set the service locator first.
        $this->setServiceLocator($serviceLocator);
        $this->resource = $accessStatus;
    }

    public function getJsonLdType()
    {
        return 'o-access:Status';
    }

    /**
     * The json-ld does not include id: see item or media.
     *
     * @todo Create adapter for access status? Create a full representation.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::getJsonLd()
     */
    public function getJsonLd()
    {
        $getDateTimeJsonLd = function (?\DateTime $dateTime): ?array {
            return $dateTime
                ? [
                    '@value' => $dateTime->format('c'),
                    '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
                ]
                : null;
        };

        // "level" is the effective level (materialized cascade), for reading.
        // "level_set" is the admin decision on this resource, for editing.
        $jsonLd = [
            'level' => $this->level(),
            'level_set' => $this->levelSet(),
        ];

        // Append the start and the end if there is at least one of them, both
        // effective and set.
        $embargoStart = $getDateTimeJsonLd($this->embargoStart());
        $embargoEnd = $getDateTimeJsonLd($this->embargoEnd());
        $embargoStartSet = $getDateTimeJsonLd($this->embargoStartSet());
        $embargoEndSet = $getDateTimeJsonLd($this->embargoEndSet());
        if ($embargoStart || $embargoEnd || $embargoStartSet || $embargoEndSet) {
            $jsonLd += [
                'embargoStart' => $embargoStart,
                'embargoEnd' => $embargoEnd,
                'embargoStart_set' => $embargoStartSet,
                'embargoEnd_set' => $embargoEndSet,
            ];
        }
        return $jsonLd;
    }

    /**
     * @todo Use an adaptaer for access statuses and remove this method.
     */
    public function apiUrl()
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'api/default',
            [
                'resource' => 'access_statuses',
                'id' => $this->id(),
            ],
            ['force_canonical' => true]
        );
    }

    public function id()
    {
        return $this->resource->getIdResource();
    }

    public function resource(): \Omeka\Api\Representation\AbstractResourceEntityRepresentation
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getId());
    }

    /**
     * Effective access level (materialized cascade). Use this for gating and
     * display.
     */
    public function level(): string
    {
        return $this->resource->getLevel();
    }

    /**
     * Access level set by the admin on this resource, before inheritance. Use
     * this to pre-fill the edit form.
     */
    public function levelSet(): string
    {
        return $this->resource->getLevelSet();
    }

    public function embargoStart(): ?DateTime
    {
        return $this->resource->getEmbargoStart();
    }

    public function embargoEnd(): ?DateTime
    {
        return $this->resource->getEmbargoEnd();
    }

    public function embargoStartSet(): ?DateTime
    {
        return $this->resource->getEmbargoStartSet();
    }

    public function embargoEndSet(): ?DateTime
    {
        return $this->resource->getEmbargoEndSet();
    }

    /**
     * Check if embargo is set and check it.
     *
     * @return bool|null Null if the embargo dates are not set, true if resource
     * is under embargo, else false.
     */
    public function isUnderEmbargo(): ?bool
    {
        return $this->resource->isUnderEmbargo();
    }

    /**
     * Get the resources that impose the effective level, when it is stricter
     * than the level set on this resource.
     *
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function levelOrigins(): array
    {
        $level = $this->level();
        $ranks = array_flip(array_keys(self::LEVELS));
        if (($ranks[$level] ?? 0) <= ($ranks[$this->levelSet()] ?? 0)) {
            return [];
        }

        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $resource = $this->resource->getId();
        $itemSetIds = fn (int $itemId): array => $connection->fetchFirstColumn(
            <<<'SQL'
            SELECT `item_item_set`.`item_set_id`
            FROM `item_item_set`
            JOIN `access_status` ON `access_status`.`id` = `item_item_set`.`item_set_id`
            WHERE `item_item_set`.`item_id` = ? AND `access_status`.`level_set` = ?
            ORDER BY `item_item_set`.`item_set_id`
            SQL,
            [$itemId, $level]
        );

        if ($resource instanceof \Omeka\Entity\Media) {
            $itemId = $resource->getItem()->getId();
            $itemLevelSet = $connection->fetchOne('SELECT `level_set` FROM `access_status` WHERE `id` = ?', [$itemId]);
            $ids = $itemLevelSet === $level ? [$itemId] : $itemSetIds($itemId);
        } elseif ($resource instanceof \Omeka\Entity\Item) {
            $ids = $itemSetIds($resource->getId());
        } else {
            return [];
        }

        $adapter = $services->get('Omeka\ApiAdapterManager')->get('resources');
        $entityManager = $services->get('Omeka\EntityManager');
        $origins = [];
        foreach ($ids as $id) {
            $entity = $entityManager->find(\Omeka\Entity\Resource::class, (int) $id);
            if ($entity) {
                $origins[] = $adapter->getRepresentation($entity);
            }
        }
        return $origins;
    }

    public function displayLevel(): string
    {
        return $this->displayAnyLevel($this->level());
    }

    public function displayLevelSet(): string
    {
        return $this->displayAnyLevel($this->levelSet());
    }

    protected function displayAnyLevel(string $level): string
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $accessLevels = $settings->get('access_property_levels', []);
        if ($accessLevels && isset($accessLevels[$level])) {
            return $accessLevels[$level];
        }
        return $this->getTranslator()->translate(self::LEVELS[$level] ?? AccessStatus::FREE);
    }

    /**
     * Get a string according to embargo.
     *
     * @param string $dateTimeFormat May be one of the "short", "medium" or "long".
     * @return string The string is empty when there is no defined embargo.
     */
    public function displayEmbargo(?string $dateTimeFormat = 'medium'): string
    {
        return $this->displayAnyEmbargo($this->embargoStart(), $this->embargoEnd(), $dateTimeFormat);
    }

    /**
     * Get a string according to the embargo set on this resource.
     */
    public function displayEmbargoSet(?string $dateTimeFormat = 'medium'): string
    {
        return $this->displayAnyEmbargo($this->embargoStartSet(), $this->embargoEndSet(), $dateTimeFormat);
    }

    protected function displayAnyEmbargo(?DateTime $embargoStart, ?DateTime $embargoEnd, ?string $dateTimeFormat): string
    {
        if (!$embargoStart && !$embargoEnd) {
            return '';
        }
        /** @var \Omeka\View\Helper\i18n $i18n */
        $i18n = $this->getServiceLocator()->get('ViewHelperManager')->get('i18n');
        if (!$embargoEnd) {
            $hasStartTime = $embargoStart->format('H:i:s') !== '00:00:00';
            $formatStartTime = $hasStartTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('from %s'), $i18n->dateFormat($embargoStart, $dateTimeFormat, $formatStartTime)); // @translate
        } elseif (!$embargoStart) {
            $hasEndTime = $embargoEnd->format('H:i:s') !== '00:00:00';
            $formatEndTime = $hasEndTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('until %s'), $i18n->dateFormat($embargoEnd, $dateTimeFormat, $formatEndTime)); // @translate
        } else {
            $hasStartTime = $embargoStart->format('H:i:s') !== '00:00:00';
            $hasEndTime = $embargoEnd->format('H:i:s') !== '00:00:00';
            $formatStartTime = $hasStartTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            $formatEndTime = $hasEndTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('from %1$s until %2$s'), $i18n->dateFormat($embargoStart, $dateTimeFormat, $formatStartTime), $i18n->dateFormat($embargoEnd, $dateTimeFormat, $formatEndTime)); // @translate
        }
    }
}

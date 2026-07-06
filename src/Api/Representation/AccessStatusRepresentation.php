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

    public function displayLevel(): string
    {
        $level = $this->level();
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
        $embargoStart = $this->embargoStart();
        $embargoEnd = $this->embargoEnd();
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

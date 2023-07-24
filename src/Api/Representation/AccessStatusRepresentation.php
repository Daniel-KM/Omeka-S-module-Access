<?php declare(strict_types=1);

namespace AccessResource\Api\Representation;

use AccessResource\Entity\AccessStatus;
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
        $jsonLd = [
            'level' => $this->level(),
        ];

        // Append the start and the end if there is at least one of them.
        $embargoStart = $this->embargoStart();
        $embargoEnd = $this->embargoEnd();
        if ($embargoStart) {
            $embargoStart = [
                '@value' => $this->getDateTime($embargoStart),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }
        if ($embargoEnd) {
            $embargoEnd = [
                '@value' => $this->getDateTime($embargoEnd),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }
        if ($embargoStart || $embargoEnd) {
            $jsonLd += [
                'embargoStart ' => $embargoStart,
                'embargoEnd' => $embargoEnd,
            ];
        }
        return $jsonLd;
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

    public function level(): string
    {
        return $this->resource->getLevel();
    }

    public function embargoStart(): ?DateTime
    {
        return $this->resource->getEmbargoStart();
    }

    public function embargoEnd(): ?DateTime
    {
        return $this->resource->getEmbargoEnd();
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
        $accessLevels = $settings->get('accessresource_level_property_levels', []);
        if ($accessLevels && isset($accessLevels[$level])) {
            return $accessLevels[$level];
        }
        return $this->getTranslator()->translate(self::LEVELS[$level] ?? AccessStatus::FREE);
    }

    public function displayEmbargo(): string
    {
        $embargoStart = $this->embargoStart();
        $embargoEnd = $this->embargoEnd();
        if (!$embargoStart && !$embargoEnd) {
            return '';
        }
        $i18n = $this->getServiceLocator()->get('ViewHelperManager')->get('i18n');
        if (!$embargoEnd) {
            return sprintf($this->translator()->translate('Embargo from %1$s'), $i18n->dateFormat($embargoStart, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT)); // @translate
        } elseif (!$embargoStart) {
            return sprintf($this->translator()->translate('Embargo until %1$s'), $i18n->dateFormat($embargoEnd, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT)); // @translate
        } else {
            return sprintf($this->translator()->translate('Embargo from %1$s until %2$s'), $i18n->dateFormat($embargoStart, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT), $i18n->dateFormat($embargoEnd, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT)); // @translate
        }
    }
}

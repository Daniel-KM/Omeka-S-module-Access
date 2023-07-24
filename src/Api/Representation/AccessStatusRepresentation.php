<?php declare(strict_types=1);

namespace AccessResource\Api\Representation;

use AccessResource\Entity\AccessStatus;
use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class AccessStatusRepresentation extends AbstractEntityRepresentation
{
    const LEVELS = [
        AccessStatus::FREE => AccessStatus::FREE,
        AccessStatus::RESERVED => AccessStatus::RESERVED,
        AccessStatus::PROTECTED => AccessStatus::PROTECTED,
        AccessStatus::FORBIDDEN => AccessStatus::FORBIDDEN,
    ];

    public function getJsonLdType()
    {
        return 'o-access:Status';
    }

    public function getJsonLd()
    {
        $embargoStart = $this->embargoStart();
        if ($embargoStart) {
            $embargoStart = [
                '@value' => $this->getDateTime($embargoStart),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $embargoEnd = $this->embargoEnd();
        if ($embargoEnd) {
            $embargoEnd = [
                '@value' => $this->getDateTime($embargoEnd),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        return [
            'o:id' => $this->id(),
            'o:resource' => $this->resource()->getReference(),
            'o-access:level' => $this->level(),
            'o-access:embargoStart ' => $embargoStart,
            'o-access:embargoEnd' => $embargoEnd,
        ];
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

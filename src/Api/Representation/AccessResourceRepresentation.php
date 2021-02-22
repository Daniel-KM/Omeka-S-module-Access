<?php declare(strict_types=1);

namespace AccessResource\Api\Representation;

use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class AccessResourceRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return \AccessResource\Controller\Admin\AccessController::class;
    }

    public function getJsonLdType()
    {
        return 'o-module-access-resource:AccessResource';
    }

    public function getJsonLd()
    {
        $user = $this->user();

        $startDate = $this->startDate();
        if ($startDate) {
            $startDate = [
                '@value' => $this->getDateTime($startDate),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $endDate = $this->endDate();
        if ($endDate) {
            $endDate = [
                '@value' => $this->getDateTime($endDate),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $created = [
            '@value' => $this->getDateTime($this->created()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];

        $modified = $this->modified();
        if ($modified) {
            $modified = [
                '@value' => $this->getDateTime($modified),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        return [
            'o:id' => $this->id(),
            'o:resource' => $this->resource()->getReference(),
            'o:user' => $user ? $user->getReference() : null,
            'o-module-access-resource:token' => $this->token(),
            'o-module-access-resource:enabled' => $this->enabled(),
            'o-module-access-resource:temporal' => $this->temporal(),
            'o-module-access-resource:startDate' => $startDate,
            'o-module-access-resource:endDate' => $endDate,
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    public function resource(): \Omeka\Api\Representation\AbstractResourceEntityRepresentation
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    public function user(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $user = $this->resource->getUser();
        return $user
            ? $this->getAdapter('users')->getRepresentation($user)
            : null;
    }

    public function token(): ?string
    {
        return $this->resource->getToken();
    }

    public function enabled(): bool
    {
        return (bool) $this->resource->getEnabled();
    }

    public function temporal(): bool
    {
        return (bool) $this->resource->getTemporal();
    }

    public function startDate(): ?DateTime
    {
        return $this->resource->getStartDate();
    }

    public function endDate(): ?DateTime
    {
        return $this->resource->getEndDate();
    }

    public function created(): DateTime
    {
        return $this->resource->getCreated();
    }

    public function modified(): ?DateTime
    {
        return $this->resource->getModified();
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/access-resource/id',
            [
                'controller' => 'access',
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    public function displayTitle($default = null): string
    {
        return sprintf($this->getTranslator()->translate('Access #%d'), $this->id());
    }

    public function displayDescription($default = null): string
    {
        return (string) $default;
    }

    /**
     * @deprecated
     */
    public function toArray()
    {
        $user = $this->user();
        return [
            'id' => $this->id(),
            'resource' => $this->resource()->id(),
            'user' => $user ? $user->id() : null,
            'token' => $this->token(),
            'enabled' => $this->enabled(),
            'temporal' => $this->temporal(),
            'startDate' => $this->startDate(),
            'endDate' => $this->endDate(),
            'created' => $this->created(),
            'modified' => $this->modified(),
        ];
    }
}

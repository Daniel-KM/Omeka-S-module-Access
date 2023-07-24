<?php declare(strict_types=1);

namespace AccessResource\Api\Representation;

use AccessResource\Entity\AccessRequest;
use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class AccessRequestRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var array
     */
    protected $statusLabels = [
        AccessRequest::STATUS_NEW => 'new', // @translate
        AccessRequest::STATUS_RENEW => 'renew', // @translate
        AccessRequest::STATUS_ACCEPTED => 'accepted', // @translate
        AccessRequest::STATUS_REJECTED => 'rejected', // @translate
    ];

    public function getControllerName()
    {
        return \AccessResource\Controller\Admin\RequestController::class;
    }

    public function getJsonLdType()
    {
        return 'o-access:Request';
    }

    public function getJsonLd()
    {
        $resources = [];
        foreach ($this->resources() as $resourceRepresentation) {
            $resources[] = $resourceRepresentation->getReference();
        }

        $user = $this->user();

        $start = $this->start();
        if ($start) {
            $start = [
                '@value' => $this->getDateTime($start),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $end = $this->end();
        if ($end) {
            $end = [
                '@value' => $this->getDateTime($end),
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
            'o:resource' => $resources,
            'o:user' => $user ? $user->getReference() : null,
            'o:email' => $this->email(),
            'o-access:token' => $this->token(),
            'o:status' => $this->status(),
            'o-access:enabled' => $this->enabled(),
            'o-access:temporal' => $this->temporal(),
            'o-access:start' => $start,
            'o-access:end' => $end,
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function resources(): array
    {
        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $resources = [];
        $adapter = $this->getAdapter('resources');
        foreach ($this->resource->getResources() as $resource) {
            $resources[$resource->getId()] = $adapter->getRepresentation($resource);
        }
        return $resources;
    }

    public function user(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $user = $this->resource->getUser();
        return $user
            ? $this->getAdapter('users')->getRepresentation($this->resource->getUser())
            : null;
    }

    public function email(): ?string
    {
        return $this->resource->getEmail();
    }

    public function token(): ?string
    {
        return $this->resource->getToken();
    }

    public function status(): string
    {
        return $this->resource->getStatus();
    }

    public function statusLabel(): string
    {
        $status = $this->resource->getStatus();
        return $this->getTranslator()->translate($this->statusLabels[$status] ?? $status);
    }

    public function enabled(): bool
    {
        return (bool) $this->resource->getEnabled();
    }

    public function temporal(): bool
    {
        return (bool) $this->resource->getTemporal();
    }

    public function start(): ?DateTime
    {
        return $this->resource->getStart();
    }

    public function end(): ?DateTime
    {
        return $this->resource->getEnd();
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
            'admin/access-request/id',
            [
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    public function displayTitle($default = null): string
    {
        return sprintf($this->getTranslator()->translate('Access request #%d'), $this->id());
    }

    public function displayDescription($default = null): string
    {
        return (string) $default;
    }
}

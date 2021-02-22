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
        AccessRequest::STATUS_NEW => 'New', // @translate
        AccessRequest::STATUS_RENEW => 'Renew', // @translate
        AccessRequest::STATUS_ACCEPTED => 'Accepted', // @translate
        AccessRequest::STATUS_REJECTED => 'Rejected', // @translate
    ];

    public function getControllerName()
    {
        return \AccessResource\Controller\Admin\RequestController::class;
    }

    public function getJsonLdType()
    {
        return 'o-module-access-resource:AccessRequest';
    }

    public function getJsonLd()
    {
        $user = $this->user();

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
            'o:user' => $user->getReference(),
            'o:status' => $this->status(),
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    public function resource(): \Omeka\Api\Representation\AbstractResourceEntityRepresentation
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    public function user(): \Omeka\Api\Representation\UserRepresentation
    {
        return $this->getAdapter('users')
            ->getRepresentation($this->resource->getUser());
    }

    public function status(): string
    {
        return $this->resource->getStatus();
    }

    public function statusLabel(): string
    {
        $status = $this->resource->getStatus();
        return $this->statusLabels[$status]
            ?? 'Unknown'; // @translate
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
                'controller' => 'request',
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

    /**
     * @deprecated
     */
    public function toArray()
    {
        $user = $this->user();
        return [
            'id' => $this->id(),
            'resource' => $this->resource()->id(),
            'user' => $user->id(),
            'status' => $this->status(),
            'created' => $this->created(),
            'modified' => $this->modified(),
        ];
    }
}

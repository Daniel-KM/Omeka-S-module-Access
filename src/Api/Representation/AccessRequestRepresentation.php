<?php declare(strict_types=1);

namespace Access\Api\Representation;

use Access\Entity\AccessRequest;
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
        return \Access\Controller\Admin\RequestController::class;
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
            'o-access:recursive' => $this->recursive(),
            'o-access:enabled' => $this->enabled(),
            'o-access:temporal' => $this->temporal(),
            'o-access:start' => $start,
            'o-access:end' => $end,
            'o:name' => $this->name(),
            'o:message' => $this->message(),
            'o-access:fields' => $this->fields(),
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

    public function recursive(): bool
    {
        return (bool) $this->resource->getRecursive();
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

    public function name(): ?string
    {
        return $this->resource->getName();
    }

    public function message(): ?string
    {
        return $this->resource->getMessage();
    }

    public function fields(): ?array
    {
        return $this->resource->getFields();
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
        return sprintf($this->getTranslator()->translate('Request #%d'), $this->id());
    }

    public function displayDescription($default = null): string
    {
        return (string) $default;
    }

    public function displayRequester($default = null): string
    {
        $translator = $this->getTranslator();
        if ($user = $this->user()) {
            return sprintf($translator->translate('User: %s'), $user->link($user->name())); // @translate
        } elseif ($email = $this->email()) {
            return sprintf(
                $translator->translate('Visitor: %s'), // @translate
                sprintf('<a href="mailto:%1$s">%1$s</a>', $email)
            );
        } elseif ($token = $this->token()) {
            return sprintf($translator->translate('Token: %s'), $token); // @translate
        } else {
            return $default ?? $translator->translate('Undefined'); // @translate
        }
    }

    public function displayResources($default = null): string
    {
        $list = [];
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        foreach ($this->resources() as $resource) {
            $list[] = $resource->link($resource->displayTitle(), 'show', ['class' => $resource->resourceName()]);
        }
        return '<ul><li>' . implode("</li>\n<li>", $list) . '</li></ul>';
    }

    public function displayStatus($default = null): string
    {
        $status = $this->resource->getStatus();
        return $this->getTranslator()->translate($this->statusLabels[$status] ?? $status);
    }

    public function displayRecursive(): string
    {
        $recursive = $this->recursive();
        return $this->getTranslator()->translate($recursive ? 'yes' : 'no');
    }

    public function displayTemporal(?string $dateTimeFormat = 'medium'): string
    {
        $start = $this->start();
        $end = $this->end();
        if (!$start && !$end) {
            return '';
        }
        /** @var \Omeka\View\Helper\i18n $i18n */
        $i18n = $this->getServiceLocator()->get('ViewHelperManager')->get('i18n');
        if (!$end) {
            $hasStartTime = $start->format('H:i:s') !== '00:00:00';
            $formatStartTime = $hasStartTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('from %s'), $i18n->dateFormat($start, $dateTimeFormat, $formatStartTime)); // @translate
        } elseif (!$start) {
            $hasEndTime = $end->format('H:i:s') !== '00:00:00';
            $formatEndTime = $hasEndTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('until %s'), $i18n->dateFormat($end, $dateTimeFormat, $formatEndTime)); // @translate
        } else {
            $hasStartTime = $start->format('H:i:s') !== '00:00:00';
            $hasEndTime = $end->format('H:i:s') !== '00:00:00';
            $formatStartTime = $hasStartTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            $formatEndTime = $hasEndTime ? $i18n::DATE_FORMAT_SHORT : $i18n::DATE_FORMAT_NONE;
            return sprintf($this->getTranslator()->translate('from %1$s until %2$s'), $i18n->dateFormat($start, $dateTimeFormat, $formatStartTime), $i18n->dateFormat($end, $dateTimeFormat, $formatEndTime)); // @translate
        }
    }
}

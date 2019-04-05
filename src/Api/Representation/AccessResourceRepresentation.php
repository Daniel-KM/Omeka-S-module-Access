<?php
namespace AccessResource\Api\Representation;

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
            'o:id' => $this->getId(),
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

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    public function resource()
    {
        return $this->getAdapter('resources')
        ->getRepresentation($this->resource->getResource());
    }

    /**
     * @return \Omeka\Api\Representation\UserRepresentation|null
     */
    public function user()
    {
        $user = $this->resource->getUser();
        return $user
            ? $this->getAdapter('users')->getRepresentation($user)
            : null;
    }

    /**
     * @return string
     */
    public function token()
    {
        return $this->resource->getToken();
    }

    /**
     * @return bool
     */
    public function enabled()
    {
        return (bool) $this->resource->getEnabled();
    }

    /**
     * @return bool
     */
    public function temporal()
    {
        return (bool) $this->resource->getTemporal();
    }

    /**
     * @return \DateTime
     */
    public function startDate()
    {
        return $this->resource->getStartDate();
    }

    /**
     * @return \DateTime
     */
    public function endDate()
    {
        return $this->resource->getEndDate();
    }

    /**
     * @return \DateTime
     */
    public function created()
    {
        return $this->resource->getCreated();
    }

    /**
     * @return \DateTime
     */
    public function modified()
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

    public function displayTitle($default = null)
    {
        return sprintf($this->getTranslator()->translate('Access #%d'), $this->id());
    }

    public function displayDescription($default = null)
    {
        return $default;
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

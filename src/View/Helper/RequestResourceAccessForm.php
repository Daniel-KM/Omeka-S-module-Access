<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Form\AccessRequestForm;
use AccessResource\Service\ServiceLocatorAwareTrait;
use Laminas\View\Helper\AbstractHelper;

class RequestResourceAccessForm extends AbstractHelper
{
    use ServiceLocatorAwareTrait;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $resources = [];

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $reservedResources;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $inaccessibleReservedResources;

    /**
     * Prepare the user request resource access form.
     *
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]|\Omeka\Api\Representation\AbstractResourceEntityRepresentation|null $resources
     * @return self|string
     */
    public function __invoke($resources = null)
    {
        if (is_null($resources)) {
            return $this;
        }
        if (!is_array($resources)) {
            $resources = [$resources];
        }
        $this->setResources($resources);

        // $currentTheme = $serviceLocator->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        return $this->render();
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $resources
     */
    public function setResources(array $resources): self
    {
        $this->resources = $resources;
        return $this;
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $reservedResources
     */
    public function setReservedResources(array $reservedResources): self
    {
        $this->reservedResources = $reservedResources;
        return $this;
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function getReservedResources(): array
    {
        if (is_array($this->reservedResources)) {
            return $this->reservedResources;
        }

        // Check if there is any private resources with reservedAccess property.
        $reserveds = [];
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        foreach ($this->getResources() as $resource) {
            if ($resource->isPublic()) {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            $value = $resource->value('curation:reservedAccess');
            if (!$value) {
                continue;
            }
            $reserveds[] = $resource;
        }

        $this->setReservedResources($reserveds);
        return $reserveds;
    }

    /**
     * @param \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $inaccessibleReservedResources
     */
    public function setInaccessibleReservedResources(array $inaccessibleReservedResources): self
    {
        $this->inaccessibleReservedResources = $inaccessibleReservedResources;
        return $this;
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    public function getInaccessibleReservedResources(): array
    {
        if (is_array($this->inaccessibleReservedResources)) {
            return $this->inaccessibleReservedResources;
        }

        $reserveds = $this->getReservedResources();
        if (!count($reserveds)) {
            $this->setInaccessibleReservedResources([]);
            return $this->inaccessibleReservedResources;
        }

        /** @var \Omeka\Entity\User $user */
        $user = $this->getView()->identity();
        if (!$user) {
            $this->setInaccessibleReservedResources($reserveds);
            return $this->inaccessibleReservedResources;
        }

        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            $this->setInaccessibleReservedResources([]);
            return $this->inaccessibleReservedResources;
        }

        $reservedResourcesIds = array_map(function ($v) {
            return $v->id();
        }, $reserveds);

        // The view api cannot manage options.
        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');
        $accessResourceIds = $api
            ->search(
                'access_resources',
                [
                    'user_id' => $user->getId(),
                    'resource_id' => $reservedResourcesIds,
                    'enabled' => true,
                ],
                [
                    'initialize' => false,
                    'finalize' => false,
                    // Returns the associated resource ids.
                    'returnScalar' => 'resource',
                ]
            )
            ->getContent();

        $inaccessibleReserveds = array_filter($reserveds, function ($v) use ($accessResourceIds) {
            return !in_array($v->id(), $accessResourceIds);
        });

        $this->setInaccessibleReservedResources($inaccessibleReserveds);
        return $inaccessibleReserveds;
    }

    public function form(): AccessRequestForm
    {
        return $this->getServiceLocator()->get('FormElementManager')->get(AccessRequestForm::class);
    }

    public function canSendRequest(): bool
    {
        // Users with access to view all resources should not be able to send
        // access requests.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if ($acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            return false;
        }

        // If there are no reserved resources on a page, user should not be able
        // to send access requests.
        $reserveds = $this->getReservedResources();
        if (!count($reserveds)) {
            return false;
        }

        // If user already has access to all reserved resources he should not be
        // able to send access requests.
        $inaccessibleReserveds = $this->getInaccessibleReservedResources();
        if (!count($inaccessibleReserveds)) {
            return false;
        }

        return true;
    }

    public function render(): string
    {
        if (!$this->canSendRequest()) {
            return '';
        }

        // Visitors without user account should not be able to send access
        // requests.
        $user = $this->getView()->identity();

        return $this->getView()->partial(
            'common/helper/request-resource-access-form',
            [
                'resources' => $this->getInaccessibleReservedResources(),
                'form' => $this->form(),
                'visitorHasIdentity' => (bool) $user,
            ]
        );
    }
}

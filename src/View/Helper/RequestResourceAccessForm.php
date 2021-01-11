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
    protected $resources;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $reservedResources;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[]
     */
    protected $inaccessibleReservedResources;

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

    public function setResources(array $resources)
    {
        $this->resources = $resources;
        return $this;
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function setReservedResources(array $reservedResources)
    {
        $this->reservedResources = $reservedResources;
        return $this;
    }

    public function getReservedResources()
    {
        if (is_array($this->reservedResources)) {
            return $this->reservedResources;
        }

        // Check if there is any private resources with reservedAccess property.
        $reservedResources = [];
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

            $reservedResources[] = $resource;
        }

        $this->setReservedResources($reservedResources);
        return $reservedResources;
    }

    public function setInaccessibleReservedResources(array $inaccessibleReservedResources)
    {
        $this->inaccessibleReservedResources = $inaccessibleReservedResources;
        return $this;
    }

    public function getInaccessibleReservedResources()
    {
        if (is_array($this->inaccessibleReservedResources)) {
            return $this->inaccessibleReservedResources;
        }

        $services = $this->getServiceLocator();
        $reservedResources = $this->getReservedResources();

        /** @var \Omeka\Entity\User $user */
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            $this->setInaccessibleReservedResources($reservedResources);
            return $this->inaccessibleReservedResources;
        }

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if ($acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            $this->setInaccessibleReservedResources([]);
            return $this->inaccessibleReservedResources;
        }

        $reservedResourcesIds = array_map(function ($v) {
            return $v->id();
        }, $reservedResources);

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');

        // TODO Output column directly (see AbstractEntityAdapoter with getAssociationNames().
        $accessRecords = $api
            ->search(
                'access_resources',
                [
                    'user_id' => $user->getId(),
                    'resource_id' => $reservedResourcesIds,
                    'enabled' => true,
                ],
                ['responseContent' => 'resource']
            )
            ->getContent();
        $accessRecordsIds = array_map(function ($v) {
            return $v->getResource()->getId();
        }, $accessRecords);

        $inaccessibleReservedResources = array_filter($reservedResources, function ($v) use ($accessRecordsIds) {
            return !in_array($v->id(), $accessRecordsIds);
        });

        $this->setInaccessibleReservedResources($inaccessibleReservedResources);
        return $inaccessibleReservedResources;
    }

    public function form()
    {
        $form = $this->getServiceLocator()->get('FormElementManager')->get(AccessRequestForm::class);
        return $form;
    }

    public function canSendRequest()
    {
        // Users with access to view all resources should not be able to send
        // access requests.
        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        if ($acl->userIsAllowed('Omeka\Entity\Resource', 'view-all')) {
            return false;
        }

        // If there are no reserved resources on a page, user should not be able
        // to send access requests.
        $reservedResources = $this->getReservedResources();
        if (!count($reservedResources)) {
            return false;
        }

        // If user already has access to all reserved resources he should not be
        // able to send access requests.
        $inaccessibleReservedResources = $this->getInaccessibleReservedResources();
        if (!count($inaccessibleReservedResources)) {
            return false;
        }

        return true;
    }

    public function render()
    {
        if (!$this->canSendRequest()) {
            return null;
        }

        // Visitors without user account should not be able to send access
        // requests.
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

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

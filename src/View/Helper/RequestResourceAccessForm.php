<?php
namespace AccessResource\View\Helper;

use AccessResource\Form\AccessRequestForm;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\View\Helper\AbstractHelper;

class requestResourceAccessForm extends AbstractHelper
{
    use ServiceLocatorAwareTrait;

    protected $resources;
    protected $reservedResources;
    protected $inaccessibleReservedResources;

    public function __invoke($resources = null)
    {
        if (!$resources) {
            return;
        }
        if (!is_array($resources)) {
            $resources = [$resources];
        }
        $this->setResources($resources);

        // $currentTheme = $serviceLocator->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        return $this->render();
    }

    public function setResources($value)
    {
        $this->resources = $value;
        return $this;
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function setReservedResources($value)
    {
        $this->reservedResources = $value;
        return $this;
    }

    public function getReservedResources()
    {
        if ($this->reservedResources) {
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

    public function setInaccessibleReservedResources($value)
    {
        $this->inaccessibleReservedResources = $value;
        return $this;
    }

    public function getInaccessibleReservedResources()
    {
        if ($this->inaccessibleReservedResources) {
            return $this->inaccessibleReservedResources;
        }

        $services = $this->getServiceLocator();
        $reservedResources = $this->getReservedResources();

        /** @var \Omeka\Entity\User $identity */
        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$identity) {
            return $reservedResources;
        }

        $reservedResourcesIds = array_map(function ($v) {
            return $v->id();
        }, $reservedResources);

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');

        $accessRecords = $api
            ->search(
                'access_resources',
                [
                    'user_id' => $identity->getId(),
                    'resource_id' => $reservedResourcesIds,
                    'enabled' => true,
                ],
                ['responseContent' => 'resource']
            )
            ->getContent();
        $accessRecordsIds = array_map(function ($v) {
            return $v->resource()->id();
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
        $identity = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        return $this->getView()->partial(
            'common/helper/request-access-resource-form',
            [
                'resources' => $this->getInaccessibleReservedResources(),
                'form' => $this->form(),
                'visitorHasIdentity' => (bool) $identity,
            ]
        );
    }
}

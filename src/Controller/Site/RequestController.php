<?php
namespace AccessResource\Controller\Site;

use AccessResource\Entity\AccessRequest;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class RequestController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    public function submitAction()
    {
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            throw new PermissionDeniedException;
        }

        // Add requests for each resource if it does not exist.
        if ($this->getRequest()->isPost()) {
            $api = $this->api();
            $resources = $this->params()->fromPost('resources', []);

            // Get existing requests.
            $requestsByResource = [];
            if (count($resources)) {
                $requests = $api->search('access_requests', [
                    'user_id' => $user->getId(),
                    'resource_id' => $resources,
                ])->getContent();
                foreach ($requests as $request) {
                    $requestsByResource[$request->resource()->id()] = $request;
                }
            }
            $requestedResources = array_keys($requestsByResource);
            $resourcesRequestCreate = array_diff($resources, $requestedResources);

            foreach ($resourcesRequestCreate as $resource) {
                $api->create('access_requests', [
                    'user_id' => $user->getId(),
                    'resource_id' => $resource,
                    'status' => AccessRequest::STATUS_NEW,
                ]);
            }
            foreach ($requestsByResource as $request) {
                $api->update('access_requests', $request->id(), [
                    'user_id' => $user->getId(),
                    'resource_id' => $request->resource()->id(),
                    'status' => AccessRequest::STATUS_RENEW,
                ]);
            }
        }

        $result = new JsonModel();
        $result
            ->setVariable('status', \Zend\Http\Response::STATUS_CODE_200)
            ->setVariable('data', ['success' => true]);

        // $event = new Event('AccessResource\Controller\RequestController', $this);
        // $event->setName('view.handle.after');
        $this->getEventManager()->trigger('accessresource.request.created');

        return $result;
    }
}

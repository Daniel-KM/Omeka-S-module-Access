<?php declare(strict_types=1);

namespace AccessResource\Controller\Site;

use AccessResource\Entity\AccessRequest;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Mvc\Exception\PermissionDeniedException;

class RequestController extends AbstractActionController
{
    public function submitAction()
    {
        $user = $this->identity();
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

        $result = new JsonModel([
            'status' => \Laminas\Http\Response::STATUS_CODE_200,
            'data' => [
                'success' => true,
            ],
        ]);

        // $event = new Event('AccessResource\Controller\RequestController', $this);
        // $event->setName('view.handle.after');
        $this->getEventManager()->trigger('accessresource.request.created');

        return $result;
    }
}

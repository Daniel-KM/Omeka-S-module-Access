<?php
namespace AccessResource\Controller\Site;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Omeka\Mvc\Exception\PermissionDeniedException;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class GuestBoardController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function browseAction()
    {
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        // To simplify routing, check of rights is done here.
        if (!$user) {
            throw new PermissionDeniedException();
        }

        $params = $this->params();
        $page = $params->fromQuery('page', 1);
        // TODO Use the standard params for per page.
        $perPage = 25;
        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
            'user_id' => $user->getId(),
        ];
        $requests = $this->api()->search('access_requests', $query);

        $this->paginator($requests->getTotalResults(), $page, $perPage);

        $view = new ViewModel();
        $view
            ->setTemplate('guest/site/guest/access-resources')
            ->setVariable('accessRequests', $requests->getContent());
        return $view;
    }
}

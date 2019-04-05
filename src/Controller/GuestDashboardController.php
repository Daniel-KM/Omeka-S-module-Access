<?php
namespace AccessResource\Controller;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class GuestDashboardController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    public function indexAction()
    {
        return $this->forward('browse');
    }

    public function browseAction()
    {
        $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();

        $params = $this->params();
        $page = $params->fromQuery('page', 1);
        // TODO Use the standard params for per page.
        $perPage = 25;
        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
            'user_id' => $user->getId()
        ];
        $requests = $this->api()->search('access_requests', $query);

        $this->paginator($requests->getTotalResults(), $page, $perPage);

        $view = new ViewModel();
        $view->setVariable('accessRequests', $requests->getContent());
        return $view;
    }
}

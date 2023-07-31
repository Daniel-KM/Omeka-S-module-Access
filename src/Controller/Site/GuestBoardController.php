<?php declare(strict_types=1);

namespace Access\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Mvc\Exception\PermissionDeniedException;

class GuestBoardController extends AbstractActionController
{
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function browseAction()
    {
        $user = $this->identity();
        // To simplify routing, check of rights is done here.
        if (!$user) {
            throw new PermissionDeniedException();
        }

        $params = $this->params();
        $page = $params->fromQuery('page', 1);
        $perPage = $this->siteSettings()->get('pagination_per_page') ?: $this->settings()->get('pagination_per_page', 25);
        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
            'user_id' => $user->getId(),
        ];
        $requests = $this->api()->search('access_requests', $query);

        $this->paginator($requests->getTotalResults(), $page, $perPage);

        $view = new ViewModel([
            'accessRequests' => $requests->getContent(),
        ]);
        return $view
            ->setTemplate('guest/site/guest/access-requests');
    }
}

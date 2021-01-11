<?php declare(strict_types=1);

namespace AccessResource\Controller\Admin;

use AccessResource\Entity\AccessLog;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class LogController extends AbstractActionController
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
        $params = $this->params();
        $page = $params->fromQuery('page', 1);
        // TODO Use the standard params for per page.
        $perPage = 25;

        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
        ];

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $qb = $entityManager->createQueryBuilder();

        $log_count = $qb
            ->select($qb->expr()->count('logs_count.id'))
            ->from(AccessLog::class, 'logs_count')->getQuery()->getSingleResult();

        $qb = $entityManager->createQueryBuilder();
        $qb
            ->select('logs')
            ->from(AccessLog::class, 'logs')
            ->setFirstResult(((int) $query['page'] - 1) * (int) $query['per_page'])
            ->setMaxResults((int) $query['per_page'])
            ->orderBy('logs.id', 'DESC');

        $logs = $qb->getQuery()->getResult();

        $this->paginator($log_count[1], $page, $perPage);

        return new ViewModel([
            'logs' => $logs,
        ]);
    }
}

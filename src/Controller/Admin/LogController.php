<?php declare(strict_types=1);

namespace Access\Controller\Admin;

use Access\Entity\AccessLog;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class LogController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager;
     */
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function browseAction()
    {
        /*
        $this->browse()->setDefaults('access_logs');
        $response = $this->api()->search('access_logs', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults());

        $returnQuery = $this->params()->fromQuery();
        unset($returnQuery['page']);

        $accessLogs = $response->getContent();
        */

        $params = $this->params();
        $page = $params->fromQuery('page', 1);
        $perPage = $this->settings()->get('pagination_per_page', 25);

        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
        ];

        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('logs')
            ->from(AccessLog::class, 'logs')
            ->setFirstResult(((int) $query['page'] - 1) * (int) $query['per_page'])
            ->setMaxResults((int) $query['per_page'])
            ->orderBy('logs.id', 'DESC');
        $accessLogs = $qb->getQuery()->getResult();

        $qb = $this->entityManager->createQueryBuilder();

        $logCount = $qb
            ->select($qb->expr()->count('logs_count.id'))
            ->from(AccessLog::class, 'logs_count')
            ->getQuery()
            ->getSingleResult();

        $this->paginator($logCount, $page, $perPage);

        return new ViewModel([
            'accessLogs' => $accessLogs,
            'resources' => $accessLogs,
        ]);
    }
}

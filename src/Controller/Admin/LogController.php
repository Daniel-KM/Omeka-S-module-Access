<?php declare(strict_types=1);

namespace Access\Controller\Admin;

use Access\Entity\AccessLog;
use Access\Form\Admin\QuickSearchAccessLogForm;
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
        $this->applyFilters($qb, $query, 'logs');
        $accessLogs = $qb->getQuery()->getResult();

        $qbCount = $this->entityManager->createQueryBuilder();
        $qbCount
            ->select($qbCount->expr()->count('logs_count.id'))
            ->from(AccessLog::class, 'logs_count');
        $this->applyFilters($qbCount, $query, 'logs_count');
        $logCount = $qbCount->getQuery()->getSingleScalarResult();

        $this->paginator($logCount, $page, $perPage);

        $accessModes = $this->settings()->get('access_modes') ?: [];
        $allowIndividualRequests = (bool) array_intersect($accessModes, ['user', 'email', 'token']);

        $formSearch = $this->getForm(QuickSearchAccessLogForm::class);
        $formSearch->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'browse'], true));
        $formSearch->setData($query);

        return new ViewModel([
            'accessLogs' => $accessLogs,
            'resources' => $accessLogs,
            'allowIndividualRequests' => $allowIndividualRequests,
            'formSearch' => $formSearch,
        ]);
    }

    /**
     * Apply quick-search filters to the AccessLog query builder.
     */
    protected function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $query, string $alias): void
    {
        $expr = $qb->expr();
        if (!empty($query['user_id']) && is_numeric($query['user_id'])) {
            $qb
                ->andWhere($expr->eq("$alias.userId", ':user_id'))
                ->setParameter('user_id', (int) $query['user_id']);
        }
        if (!empty($query['access_id']) && is_numeric($query['access_id'])) {
            $qb
                ->andWhere($expr->eq("$alias.accessId", ':access_id'))
                ->setParameter('access_id', (int) $query['access_id']);
        }
        if (!empty($query['action'])) {
            $qb
                ->andWhere($expr->eq("$alias.action", ':action'))
                ->setParameter('action', (string) $query['action']);
        }
        if (!empty($query['access_type'])) {
            $qb
                ->andWhere($expr->eq("$alias.accessType", ':access_type'))
                ->setParameter('access_type', (string) $query['access_type']);
        }
        if (!empty($query['date'])) {
            $qb
                ->andWhere($expr->like("$alias.date", ':date'))
                ->setParameter('date', ((string) $query['date']) . '%');
        }
    }
}

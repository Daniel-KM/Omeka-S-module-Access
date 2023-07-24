<?php declare(strict_types=1);

namespace AccessResource\Controller\Admin;

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
        $this->browse()->setDefaults('access_logs');
        $response = $this->api()->search('access_logs', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults());

        $returnQuery = $this->params()->fromQuery();
        unset($returnQuery['page']);

        $accessLogs = $response->getContent();

        return new ViewModel([
            'accessLogs' => $accessLogs,
            'resources' => $accessLogs,
        ]);
    }
}

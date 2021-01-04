<?php declare(strict_types=1);
namespace AccessResource\Controller\Admin;

use AccessResource\Entity\AccessLog;
use AccessResource\Form\Admin\AccessResourceForm;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;

class AccessController extends AbstractActionController
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
        $perPage = $this->settings()->get('pagination_per_page', 25);
        $query = $params->fromQuery() + [
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $params->fromQuery('sort_by', 'id'),
            'sort_order' => $params->fromQuery('sort_order', 'desc'),
        ];
        $response = $this->api()->search('access_resources', $query);

        $this->paginator($response->getTotalResults(), $page);

        $view = new ViewModel;
        $view->setVariable('accessResources', $response->getContent());
        return $view;
    }

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        $id = $this->params('id');

        /** @var \AccessResource\Api\Representation\AccessResourceRepresentation $accessResource */
        $accessResource = $id
            ? $this->api()->searchOne('access_resources', ['id' => $id])->getContent()
            : null;

        if ($id && !$accessResource) {
            $this->messenger()->addError(sprintf('Access record with id #%s does not exist', $id)); // @translate
            return $this->redirect()->toRoute('admin/access-resource');
        }

        $form = $this->getForm(AccessResourceForm::class);
        if ($accessResource) {
            $form->setData($accessResource->toArray());
        }

        $services = $this->getServiceLocator();

        $requestedResource = null;
        if ($accessResource && $accessResource->id()) {
            $requestedResource = $accessResource->resource();
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();

            // Move resource id value.
            if (isset($post['resource']) && isset($post['resource']['value_resource_id'])) {
                $post['resource_id'] = $post['resource']['value_resource_id'];
                unset($post['resource']);
            }

            // TODO Use getData().
            $form->setData($post);

            if ($form->isValid()) {
                $response = null;

                $post['startDate'] = new \DateTime($post['startDate']);
                $post['endDate'] = new \DateTime($post['endDate']);

                if ($accessResource) {
                    /** @var \Doctrine\ORM\EntityManager $entityManager */
                    $entityManager = $services->get('Omeka\EntityManager');
                    $response = $this->api($form)->update('access_resources', $accessResource->id(), $post, [], ['isPartial' => true]);
                    $accessResource = $response->getContent();
                    $accessUser = $entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $entityManager->persist($log);
                    $log
                        ->setAction('update')
                        ->setUser($accessUser)
                        ->setRecordId($accessResource->id())
                        ->setType('access')
                        ->setDate(new \DateTime());
                    $entityManager->flush();
                } else {
                    $post['token'] = $this->createToken();

                    /** @var \Doctrine\ORM\EntityManager $entityManager */
                    $entityManager = $services->get('Omeka\EntityManager');
                    $response = $this->api($form)->create('access_resources', $post);
                    $accessResource = $response->getContent();
                    $accessUser = $entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $entityManager->persist($log);
                    $log
                        ->setAction('create')
                        ->setUser($accessUser)
                        ->setRecordId($accessResource->id())
                        ->setType('access')
                        ->setDate(new \DateTime());
                    $entityManager->flush();

                    return $this->redirect()->toUrl($this->getRequest()->getHeader('Referer')->getUri());
                }

                if ($response) {
                    $this->messenger()->addSuccess('Access record successfully saved'); // @translate
                    return $this->redirect()->toUrl($response->getContent()->url('edit'));
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $dataType = $services->get('Omeka\DataTypeManager')->get('resource');

        $view = new ViewModel;
        $view
            ->setVariable('dataType', $dataType)
            ->setVariable('accessResource', $accessResource)
            ->setVariable('requestedResource', $requestedResource)
            ->setVariable('form', $form);
        return $view;
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('access_resources', $this->params('id'));
        $resource = $response->getContent();
        $values = ['@id' => $resource->id()];

        $view = new ViewModel;
        $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details')
            ->setVariable('resource', $resource)
            ->setVariable('resourceLabel', 'access record') // @translate
            ->setVariable('partialPath', 'access-resource/admin/access/show-details')
            ->setVariable('linkTitle', $linkTitle)
            ->setVariable('values', json_encode($values));
        return $view;
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $services = $this->getServiceLocator();
            $id = $this->params('id');

            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('access_resources', $id);

                if ($response) {
                    /** @var \Doctrine\ORM\EntityManager $entityManager */
                    $entityManager = $services->get('Omeka\EntityManager');
                    $accessResource = $response->getContent();
                    $accessUser = $entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $entityManager->persist($log);
                    $log
                        ->setAction('delete')
                        ->setUser($accessUser)
                        ->setRecordId($id)
                        ->setType('access')
                        ->setDate(new \DateTime());
                    $entityManager->flush();

                    $this->messenger()->addSuccess('Access record successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }

            if ($this->getRequest()->isXmlHttpRequest()) {
                $userRole = $services->get('Omeka\AuthenticationService')->getIdentity()->getRole();
                $isAdmin = in_array($userRole, [\Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN, \Omeka\Permissions\Acl::ROLE_SITE_ADMIN]);
                if ($isAdmin) {
                    $response = $this->api($form)->delete('access_resources', $id);

                    if ($response) {
                        // Log changes to access record.
                        /** @var \Doctrine\ORM\EntityManager $entityManager */
                        $entityManager = $services->get('Omeka\EntityManager');
                        $accessResource = $response->getContent();
                        $accessUser = $entityManager
                            ->getRepository(\Omeka\Entity\User::class)
                            ->find($accessResource->user()->id());

                        $log = new AccessLog();
                        $entityManager->persist($log);
                        $log
                            ->setAction('delete')
                            ->setUser($accessUser)
                            ->setRecordId($id)
                            ->setType('access')
                            ->setDate(new \DateTime());
                        $entityManager->flush();
                    }
                }
                return true;
            }
        }

        return $this->redirect()->toRoute(
            'admin/access-resource/default',
            [
                'controller' => 'access',
                'action' => 'browse',
            ],
            true
        );
    }

    public function toggleAction()
    {
        $enabled = 1;

        if ($this->getRequest()->isPost()) {
            $userRole = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity()->getRole();
            $isAdmin = in_array($userRole, [\Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN, \Omeka\Permissions\Acl::ROLE_SITE_ADMIN]);
            if ($isAdmin) {
                $api = $this->api();

                $id = $this->params('id');
                $access = $api->searchOne('access_resources', ['id' => $id])->getContent();
                if ($id && !$access) {
                    return false;
                }

                if ($access->enabled() === true) {
                    $enabled = 0;
                }

                $api->update('access_resources', $id, ['enabled' => $enabled]);

                $status = $enabled === 1 ? 'approved' : 'private';

                return new JsonModel([
                    'status' => \Laminas\Http\Response::STATUS_CODE_200,
                    'data' => [
                        'status' => $status,
                    ],
                ]);
            }

            return new JsonModel([
                'status' => \Laminas\Http\Response::STATUS_CODE_403,
                'message' => $this->translate('No rights to update status.'), // @translate
            ]);
        }

        return new JsonModel([
            'status' => \Laminas\Http\Response::STATUS_CODE_405,
            'message' => $this->translate('Method should be post.'), // @translate
        ]);
    }

    /**
     * Create a random token string.
     *
     * @return string
     */
    protected function createToken()
    {
        $services = $this->getServiceLocator();
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');

        $repository = $entityManager->getRepository(\AccessResource\Entity\AccessResource::class);

        $tokenString = PHP_VERSION_ID < 70000
            ? function () {
                return sha1(mt_rand());
            }
        : function () {
            return substr(str_replace(['+', '/', '-', '='], '', base64_encode(random_bytes(16))), 0, 10);
        };

        // Check if the token is unique.
        do {
            $token = $tokenString();
            $result = $repository->findOneBy(['token' => $token]);
            if (!$result) {
                break;
            }
        } while (true);

        return $token;
    }
}

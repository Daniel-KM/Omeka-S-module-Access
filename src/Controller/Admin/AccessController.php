<?php declare(strict_types=1);

namespace AccessResource\Controller\Admin;

use AccessResource\Entity\AccessLog;
use AccessResource\Form\Admin\AccessResourceForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;

class AccessController extends AbstractActionController
{
    /**
     * @var \Doctrine\ORM\EntityManager;
     */
    protected $entityManager;

    /**
     * @var \Omeka\DataType\Manager;
     */
    protected $dataTypeManager;

    public function __construct(EntityManager $entityManager, DataTypeManager $dataTypeManager)
    {
        $this->entityManager = $entityManager;
        $this->dataTypeManager = $dataTypeManager;
    }

    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function browseAction()
    {
        // TODO Upgrade for Omeka S v4.
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

        return new ViewModel([
            'accessResources' => $response->getContent(),
        ]);
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
        $requestedResource = null;
        if ($accessResource && $accessResource->id()) {
            $requestedResource = $accessResource->resource();
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();

            $form->setData($post);

            if ($form->isValid() && !empty($post['resource_id'])) {
                $data = $form->getData();
                // Some data are not managed by the form.
                $data['resource_id'] = (int) $post['resource_id'] ?: null;
                $data['user_id'] = (int) $post['user_id'] ?: null;

                $response = null;

                $data['startDate'] = $data['startDate']
                    ? new \DateTime($data['startDate'])
                    : null;
                $data['endDate'] = $data['endDate']
                    ? new \DateTime($data['endDate'])
                    : null;

                if ($accessResource) {
                    $response = $this->api($form)->update('access_resources', $accessResource->id(), $data, [], ['isPartial' => true]);
                    /** @var \AccessResource\Api\Representation\AccessResourceRepresentation $accessResource */
                    $accessResource = $response->getContent();
                    $accessUser = $this->entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $this->entityManager->persist($log);
                    $log
                        ->setAction('update')
                        ->setUser($accessUser)
                        ->setRecordId($accessResource->id())
                        ->setType(AccessLog::TYPE_ACCESS)
                        ->setDate(new \DateTime());
                    $this->entityManager->flush();
                } else {
                    $data['token'] = $this->createToken();

                    /** @var \AccessResource\Api\Representation\AccessResourceRepresentation $accessResource */
                    $response = $this->api($form)->create('access_resources', $data);
                    $accessResource = $response->getContent();
                    $accessUser = $this->entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $this->entityManager->persist($log);
                    $log
                        ->setAction('create')
                        ->setUser($accessUser)
                        ->setRecordId($accessResource->id())
                        ->setType(AccessLog::TYPE_ACCESS)
                        ->setDate(new \DateTime());
                    $this->entityManager->flush();
                }

                if ($response) {
                    $this->messenger()->addSuccess('Access record successfully saved'); // @translate
                    return $this->redirect()->toRoute('admin/access-resource', ['controller' => 'access']);
                }
            } elseif (empty($post['resource_id'])) {
                $this->messenger()->addError(new Message(
                    'Resource is undefined.' // @translate
                ));
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'dataType' => $this->dataTypeManager->get('resource'),
            'accessResource' => $accessResource,
            'requestedResource' => $requestedResource,
            'form' => $form,
        ]);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('access_resources', $this->params('id'));
        $resource = $response->getContent();
        $values = ['@id' => $resource->id()];

        $view = new ViewModel([
            'resource' => $resource,
            'resourceLabel' => 'access record', // @translate
            'partialPath' => 'access-resource/admin/access/show-details',
            'linkTitle' => $linkTitle,
            'values' => json_encode($values),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $id = $this->params('id');

            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('access_resources', $id);

                if ($response) {
                    $accessResource = $response->getContent();
                    $accessUser = $this->entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessResource->user()->id());

                    // Log changes to access record.
                    $log = new AccessLog();
                    $this->entityManager->persist($log);
                    $log
                        ->setAction('delete')
                        ->setUser($accessUser)
                        ->setRecordId($id)
                        ->setType(AccessLog::TYPE_ACCESS)
                        ->setDate(new \DateTime());
                    $this->entityManager->flush();

                    $this->messenger()->addSuccess('Access record successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }

            if ($this->getRequest()->isXmlHttpRequest()) {
                $user = $this->identity();
                $userRole = $user ? $user->getRole() : null;
                $isAdmin = in_array($userRole, [
                    \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
                    \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
                ]);
                if ($isAdmin) {
                    $response = $this->api($form)->delete('access_resources', $id);

                    if ($response) {
                        // Log changes to access record.
                        $accessResource = $response->getContent();
                        $accessUser = $this->entityManager
                            ->getRepository(\Omeka\Entity\User::class)
                            ->find($accessResource->user()->id());

                        $log = new AccessLog();
                        $this->entityManager->persist($log);
                        $log
                            ->setAction('delete')
                            ->setUser($accessUser)
                            ->setRecordId($id)
                            ->setType(AccessLog::TYPE_ACCESS)
                            ->setDate(new \DateTime());
                        $this->entityManager->flush();
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
            $userRole = $this->identity()->getRole();
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
        $repository = $this->entityManager->getRepository(\AccessResource\Entity\AccessResource::class);

        $tokenString = function () {
            return substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 10);
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

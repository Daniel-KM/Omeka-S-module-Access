<?php declare(strict_types=1);

namespace AccessResource\Controller\Admin;

use AccessResource\Entity\AccessLog;
use AccessResource\Form\Admin\AccessRequestForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Form\ConfirmForm;

class RequestController extends AbstractActionController
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
        $response = $this->api()->search('access_requests', $query);

        $this->paginator($response->getTotalResults(), $page);

        return new ViewModel([
            'accessRequests' => $response->getContent(),
        ]);
    }

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        $id = $this->params('id');

        $accessRequest = $id
            ? $this->api()->searchOne('access_requests', ['id' => $id])->getContent()
            : null;

        if ($id && !$accessRequest) {
            $this->messenger()->addError(sprintf('Access request record with id #%s does not exist', $id)); // @translate
            return $this->redirect()->toRoute('admin/access-resource');
        }

        $form = $this->getForm(AccessRequestForm::class);
        if ($accessRequest) {
            $form->setData($accessRequest->toArray());
        }

        $requestedResource = null;
        if ($accessRequest && $accessRequest->id()) {
            $requestedResource = $accessRequest->resource();
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

                if ($accessRequest) {
                    $response = $this->api($form)->update('access_requests', $accessRequest->id(), $post, [], ['isPartial' => true]);
                    $accessRequest = $response->getContent();
                    $accessUser = $this->entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessRequest->user()->id());

                    // Log changes to request record.
                    $log = new AccessLog();
                    $this->entityManager->persist($log);
                    $log
                        ->setAction('update_to_' . $accessRequest->status())
                        ->setUser($accessUser)
                        ->setRecordId($accessRequest->id())
                        ->setType(AccessLog::TYPE_REQUEST)
                        ->setDate(new \DateTime());
                        $this->entityManager->flush();

                    // Fire send email event.
                    $this->getEventManager()->trigger('accessresource.request.updated');
                } else {
                    $response = $this->api($form)->create('access_requests', $post);
                    $this->getEventManager()->trigger('accessresource.request.created');
                }

                if ($response) {
                    $this->messenger()->addSuccess('Access request record successfully saved'); // @translate
                    return $this->redirect()->toUrl($response->getContent()->url('edit'));
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'dataType' => $this->dataTypeManager->get('resource'),
            'accessRequest' => $accessRequest,
            'requestedResource' => $requestedResource,
            'form' => $form,
        ]);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('access_requests', $this->params('id'));
        $resource = $response->getContent();
        $values = ['@id' => $resource->id()];

        $view = new ViewModel([
            'resource' => $resource,
            'resourceLabel' => 'access request record', // @translate
            'partialPath' => 'access-resource/admin/request/show-details',
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
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('access_requests', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('Access request record successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return $this->redirect()->toRoute(
            'admin/access-resource/default',
            [
                'controller' => 'request',
                'action' => 'browse',
            ],
            true
        );
    }

    public function toggleAction()
    {
        if ($this->getRequest()->isPost()) {
            $userRole = $this->identity()->getRole();
            // TODO Use the permission check.
            $isAdmin = in_array($userRole, [\Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN, \Omeka\Permissions\Acl::ROLE_SITE_ADMIN]);
            if ($isAdmin) {
                $api = $this->api();

                $id = $this->params('id');
                $request = $api->searchOne('access_requests', ['id' => $id])->getContent();
                if ($id && !$request) {
                    return false;
                }

                $status = $request->status() === \AccessResource\Entity\AccessRequest::STATUS_ACCEPTED
                    ? \AccessResource\Entity\AccessRequest::STATUS_REJECTED
                    : \AccessResource\Entity\AccessRequest::STATUS_ACCEPTED;

                $api->update('access_requests', $id, ['status' => $status]);

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
}

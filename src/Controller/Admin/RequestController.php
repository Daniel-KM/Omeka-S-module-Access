<?php declare(strict_types=1);

namespace Access\Controller\Admin;

use Access\Controller\AccessTrait;
use Access\Entity\AccessRequest;
use Access\Form\Admin\AccessRequestForm;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;

class RequestController extends AbstractActionController
{
    use AccessTrait;

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
        $this->browse()->setDefaults('access_requests');
        $response = $this->api()->search('access_requests', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults());

        // Set the return query for batch actions. Note that we remove the page
        // from the query because there's no assurance that the page will return
        // results once changes are made.
        $returnQuery = $this->params()->fromQuery();
        unset($returnQuery['page']);

        /** @var \Omeka\Form\ConfirmForm $formDeleteSelected */
        $formDeleteSelected = $this->getForm(ConfirmForm::class);
        $formDeleteSelected
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete'], ['query' => $returnQuery], true))
            ->setAttribute('id', 'confirm-delete-selected')
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $formDeleteAll
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete-all'], ['query' => $returnQuery], true))
            ->setAttribute('id', 'confirm-delete-all')
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $accessRequests = $response->getContent();
        return new ViewModel([
            'accessRequests' => $accessRequests,
            'resources' => $accessRequests,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
            'returnQuery' => $returnQuery,
        ]);
    }

    public function showAction()
    {
        $response = $this->api()->read('access_requests', $this->params('id'));

        $accessRequest = $response->getContent();
        return new ViewModel([
            'accessRequest' => $accessRequest,
            'resource' => $accessRequest,
        ]);
    }

    public function showDetailsAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('access_requests', $this->params('id'));
        $accessRequest = $response->getContent();
        $values = ['@id' => $accessRequest->id()];

        $view = new ViewModel([
            'linkTitle' => $linkTitle,
            'resource' => $accessRequest,
            'values' => json_encode($values),
        ]);
        return $view
            ->setTerminal(true);
    }

    public function addAction()
    {
        return $this->editAction();
    }

    public function editAction()
    {
        /** @see \Access\Module::addAccessListAndForm() */

        $id = $this->params('id');

        /** @var \Access\Api\Representation\AccessRequestRepresentation $accessRequest */
        $accessRequest = $id
            ? $this->api()->searchOne('access_requests', ['id' => $id])->getContent()
            : null;

        if ($id && !$accessRequest) {
            $this->messenger()->addError(sprintf('Access request record with id #%s does not exist', $id)); // @translate
            return $this->redirect()->toRoute('admin/access-request');
        }

        /** @var \Access\Form\Admin\AccessRequestForm $form */
        $formOptions = [
            'full_access' => (bool) $this->settings()->get('access_full'),
            // 'resource_id' => null,
            // 'resource_type' => null,
            // 'request_status' => AccessRequest::STATUS_ACCEPTED,
        ];
        $form = $this->getForm(AccessRequestForm::class, $formOptions);
        $form->setOptions($formOptions);
        if ($accessRequest) {
            // Adapt the request to the form.
            $data = $accessRequest->jsonSerialize();
            $res = [];
            foreach ($data['o:resource'] as $resource) {
                $res[] = $resource->id();
            }
            $data['o:resource'] = implode(' ', $res);
            $data['o:user'] = $accessRequest->user() ? $accessRequest->user()->id() : null;
            $date = $accessRequest->start();
            if ($date) {
                $data['o-access:start-date'] = $date->format('Y-m-d');
                $data['o-access:start-time'] = $date->format('H-i');
            }
            $date = $accessRequest->end();
            if ($date) {
                $data['o-access:end-date'] = $date->format('Y-m-d');
                $data['o-access:end-time'] = $date->format('H-i');
            }
            $form->setData($data);
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if (@$form->isValid()) {
                $data = $form->getData();
                $data['o:resource'] = is_array($data['o:resource'])
                    ? array_filter($data['o:resource'])
                    : array_filter(explode(' ', preg_replace('~[^\d]~', ' ', $data['o:resource'])));
                $date = $data['o-access:start-date'] ?? null;
                $date = trim((string) $date) ?: null;
                if ($date) {
                    $date .= 'T' . (empty($data['o-access:start-time']) ? '00:00:00' : $data['o-access:start-time'] . ':00');
                }
                $data['o-access:start'] = $date;
                $date = $data['o-access:end-date'] ?? null;
                $date = trim((string) $date) ?: null;
                if ($date) {
                    $date .= 'T' . (empty($data['o-access:end-time']) ? '00:00:00' : $data['o-access:end-time'] . ':00');
                }
                $data['o-access:end'] = $date;
                if (!$data['o:user'] && !$data['o:email'] && !$data['o-access:token']) {
                    $message = new Message(
                        'You should set either a user or an email or check box for token.' // @translate
                    );
                    $this->messenger()->addError($message);
                } else {
                    unset(
                        $data['csrf'],
                        $data['submit'],
                        $data['o-access:start-date'],
                        $data['o-access:start-time'],
                        $data['o-access:end-date'],
                        $data['o-access:end-time']
                    );
                    if ($data['o:user']) {
                        $data['o:email'] = null;
                        $data['o-access:token'] = null;
                    } elseif ($data['o:email']) {
                        $data['o-access:token'] = null;
                    } else {
                        $data['o-access:token'] = $accessRequest && $accessRequest->token()
                            ? $accessRequest->token()
                            : substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 16);
                    }
                    if (!$id) {
                        $response = $this->api($form)->create('access_requests', $data);
                        if ($response) {
                            $message = new Message(
                                'Access request successfully created.', // @translate
                            );
                            $this->messenger()->addSuccess($message);
                            $accessRequest = $response->getContent();
                            if ($accessRequest->user() || $accessRequest->email()) {
                                $post['request_from'] = 'admin';
                                $result = $this->sendRequestEmailCreate($accessRequest, $post);
                                if (!$result) {
                                    $message = new Message(
                                        $this->translate('The request was sent, but an issue occurred when sending the confirmation email.') // @translate
                                    );
                                    $this->messenger()->addWarning($message);
                                }
                            }
                            return $this->redirect()->toRoute('admin/access-request');
                        }
                    } else {
                        $response = $this->api($form)->update('access_requests', $id, $data);
                        if ($response) {
                            $message = new Message(
                                'Access request successfully updated.' // @translate
                            );
                            $this->messenger()->addSuccess($message);
                            $accessRequest = $response->getContent();
                            if ($accessRequest->user() || $accessRequest->email()) {
                                $post['request_from'] = 'admin';
                                $result = $this->sendRequestEmailUpdate($accessRequest, $post);
                                if (!$result) {
                                    $message = new Message(
                                        $this->translate('The request was sent, but an issue occurred when sending the confirmation email.') // @translate
                                    );
                                    $this->messenger()->addWarning($message);
                                }
                            }
                            return $this->redirect()->toRoute('admin/access-request');
                        }
                    }
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'resource' => $accessRequest,
            'accessRequest' => $accessRequest,
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
            'resourceLabel' => 'access request', // @translate
            'partialPath' => 'access/admin/request/show-details',
            'linkTitle' => $linkTitle,
            'accessRequest' => $resource,
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
                    $this->messenger()->addSuccess('Access request successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/access-request');
    }

    public function removeAction()
    {
        // Rights are already checked in acl for this controller.
        if ($this->getRequest()->isPost()) {
            $api = $this->api();
            $id = $this->params('id');
            $accessRequest = $api->searchOne('access_requests', ['id' => $id])->getContent();
            if ($id && !$accessRequest) {
                $this->getResponse()->setStatusCode(404);
                return new JsonModel([
                    'status' => 'fail',
                    'data' => [
                        'access_request' => [
                            'o:id' => sprintf($this->translate('The request #%s is invalid or unavailable.'), $id), // @translate
                        ],
                    ],
                ]);
            }

            $api->delete('access_requests', $id);

            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'access_request' => [
                        'o:id' => $id,
                    ],
                ],
            ]);
        }

        $this->getResponse()->setStatusCode(405);
        return new JsonModel([
            'status' => 'fail',
            'data' => [
                'access_request' => [
                    'o:id' => $this->translate('Method should be post.'), // @translate
                ],
            ],
        ]);
    }

    public function toggleAction()
    {
        // Rights are already checked in acl for this controller.
        if ($this->getRequest()->isPost()) {
            $api = $this->api();
            $id = $this->params('id');
            $accessRequest = $api->searchOne('access_requests', ['id' => $id])->getContent();
            if ($id && !$accessRequest) {
                $this->getResponse()->setStatusCode(404);
                return new JsonModel([
                    'status' => 'fail',
                    'data' => [
                        'access_request' => [
                            'o:id' => sprintf($this->translate('The request #%s is invalid or unavailable.'), $id), // @translate
                        ],
                    ],
                ]);
            }

            $status = $accessRequest->status() === AccessRequest::STATUS_ACCEPTED
                ? AccessRequest::STATUS_REJECTED
                : AccessRequest::STATUS_ACCEPTED;

            $accessRequest = $api->update('access_requests', $id, ['o:status' => $status])->getContent();

            if ($accessRequest->user() || $accessRequest->email()) {
                $post['request_from'] = 'admin';
                $result = $this->sendRequestEmailUpdate($accessRequest, $post);
                if (!$result) {
                    $message = new Message(
                        $this->translate('The request was sent, but an issue occurred when sending the confirmation email.') // @translate
                    );
                    $this->messenger()->addWarning($message);
                }
            }

            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'access_request' => [
                        'o:id' => $id,
                        'o:status' => $status,
                    ],
                ],
            ]);
        }

        $this->getResponse()->setStatusCode(405);
        return new JsonModel([
            'status' => 'fail',
            'data' => [
                'access_request' => [
                    'o:id' => $this->translate('Method should be post.'), // @translate
                ],
            ],
        ]);
    }
}

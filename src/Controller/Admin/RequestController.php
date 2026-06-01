<?php declare(strict_types=1);

namespace Access\Controller\Admin;

use Access\Controller\AccessTrait;
use Access\Entity\AccessRequest;
use Access\Form\Admin\AccessRequestForm;
use Access\Form\SendMessageForm;
use Access\Form\Admin\QuickSearchAccessRequestForm;
use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Form\ConfirmForm;

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
        $query = $this->params()->fromQuery();
        $this->browse()->setDefaults('access_requests');
        $response = $this->api()->search('access_requests', $query);
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
            ->setButtonLabel('Confirm Delete'); // @translate

        /** @var \Omeka\Form\ConfirmForm $formDeleteAll */
        $formDeleteAll = $this->getForm(ConfirmForm::class);
        $formDeleteAll
            ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete-all'], ['query' => $returnQuery], true))
            ->setButtonLabel('Confirm Delete'); // @translate
        $formDeleteAll
            ->get('submit')->setAttribute('disabled', true);

        $accessModes = $this->settings()->get('access_modes') ?: [];
        $allowIndividualRequests = (bool) array_intersect($accessModes, ['user', 'email', 'token']);

        $formSearch = $this->getForm(QuickSearchAccessRequestForm::class);
        $formSearch->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'browse'], true));
        $formSearch->setData($query);

        $settings = $this->settings();
        $formSendMessage = $this->getForm(SendMessageForm::class);
        $formSendMessage->get('subject')->setValue((string) $settings->get('access_reply_subject'));
        $formSendMessage->get('body')->setValue((string) $settings->get('access_reply_body'));
        // When a support reply-to is set, the answering admin is no longer the
        // reply-to, so default to a discreet copy (bcc); else the admin is the
        // reply-to and needs no copy.
        $formSendMessage->get('myself')->setValue($settings->get('access_reply_to_email') ? 'bcc' : '');

        $accessRequests = $response->getContent();
        return new ViewModel([
            'accessRequests' => $accessRequests,
            'resources' => $accessRequests,
            'formDeleteSelected' => $formDeleteSelected,
            'formDeleteAll' => $formDeleteAll,
            'formSearch' => $formSearch,
            'formSendMessage' => $formSendMessage,
            'returnQuery' => $returnQuery,
            'allowIndividualRequests' => $allowIndividualRequests,
        ]);
    }

    public function sendMessageAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new \Omeka\Mvc\Exception\NotFoundException;
        }

        $id = $this->params('id');
        try {
            $accessRequest = $this->api()->read('access_requests', ['id' => $id])->getContent();
        } catch (\Throwable $e) {
            return $this->jSend()->fail(null, $this->translate('Resource not found.')); // @translate
        }

        $user = $accessRequest->user();
        $toEmail = $user ? $user->email() : $accessRequest->email();
        if (!$toEmail) {
            return $this->jSend()->fail(null, $this->translate(
                'No email defined for this request.' // @translate
            ));
        }
        $toName = $user ? $user->name() : ($accessRequest->name() ?: '');

        $params = $this->params();

        $body = trim((string) $params->fromPost('body'));
        if (!strlen($body)) {
            return $this->jSend()->fail(null, $this->translate('Empty message.')); // @translate
        }
        if (mb_strlen($body) > 10000) {
            return $this->jSend()->fail(null, $this->translate('Too long message.')); // @translate
        }

        $settings = $this->settings();

        $subject = trim((string) $params->fromPost('subject'));
        if (!strlen($subject)) {
            $subject = $settings->get('access_reply_subject')
                ?: $this->translate('Reply to your access request'); // @translate
        }

        $post = [
            'o:email' => $toEmail,
            'o:name' => $toName,
            'o:resource' => array_map(fn ($r) => $r->id(), $accessRequest->resources()),
            'access_request' => $accessRequest,
        ];
        $subject = $this->replacePlaceholders($subject, $post);
        $body = $this->replacePlaceholders($body, $post);

        if (mb_strlen($subject) > 190) {
            return $this->jSend()->fail(null, $this->translate('Too long subject.')); // @translate
        }

        $to = [$toEmail => (string) $toName];
        $replyTo = $this->replyToAddress();

        // From stays the unique installation sender; copies to the answering
        // admin are optional, exclusive (cc or bcc), via the form radio.
        $cc = null;
        $bcc = null;
        $myself = $params->fromPost('myself');
        $admin = $this->identity();
        if ($admin && $myself === 'cc') {
            $cc = [$admin->getEmail() => (string) $admin->getName()];
        } elseif ($admin && $myself === 'bcc') {
            $bcc = [$admin->getEmail() => (string) $admin->getName()];
        }

        /** @see \Common\Mvc\Controller\Plugin\SendEmail */
        $result = $this->sendEmail($body, $subject, $to, null, $cc, $bcc, $replyTo);
        if (!$result) {
            return $this->jSend()->error(null, $this->translate(
                'Sorry, the message cannot be sent. Contact the administrator.' // @translate
            ));
        }

        $message = new PsrMessage(
            'Message successfully sent to {email}.', // @translate
            ['email' => $toEmail]
        );
        return $this->jSend()->success([
            'access_request' => ['o:id' => $id],
        ], $message->setTranslator($this->translator()));
    }

    /**
     * Resolve the reply-to address: the configured support address, else the
     * connected admin. The sender (from) is the unique installation address.
     */
    protected function replyToAddress(): ?array
    {
        $email = $this->settings()->get('access_reply_to_email');
        if ($email) {
            return [$email => ''];
        }
        $user = $this->identity();
        if ($user) {
            return [$user->getEmail() => (string) $user->getName()];
        }
        return null;
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
            'values' => json_encode($values, 320),
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
        try {
            $accessRequest = $id ? $this->api()->read('access_requests', ['id' => $id])->getContent() : null;
        } catch (\Throwable $e) {
            $accessRequest = null;
        }

        // Here, no id means add.
        if ($id && !$accessRequest) {
            $this->messenger()->addError(new PsrMessage(
                'Access request record with id #{access_request_id} does not exist', // @translate
                ['access_request_id' => $id]
            ));
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
                $res[] = $resource['o:id'];
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
                    $message = new PsrMessage(
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
                            : substr(strtr(base64_encode(random_bytes(128)), ['+' => '', '/' => '', '=' => '']), 0, 16);
                    }
                    if (!$id) {
                        $response = $this->api($form)->create('access_requests', $data);
                        if ($response) {
                            $message = new PsrMessage(
                                'Access request successfully created.', // @translate
                            );
                            $this->messenger()->addSuccess($message);
                            $accessRequest = $response->getContent();
                            if ($accessRequest->user() || $accessRequest->email()) {
                                $post['request_from'] = 'admin';
                                $result = $this->sendRequestEmailCreate($accessRequest, $post);
                                if (!$result) {
                                    $message = new PsrMessage(
                                        'The request was sent, but an issue occurred when sending the confirmation email.' // @translate
                                    );
                                    $this->messenger()->addWarning($message);
                                }
                            }
                            return $this->redirect()->toRoute('admin/access-request');
                        }
                    } else {
                        $response = $this->api($form)->update('access_requests', $id, $data);
                        if ($response) {
                            $message = new PsrMessage(
                                'Access request successfully updated.' // @translate
                            );
                            $this->messenger()->addSuccess($message);
                            $accessRequest = $response->getContent();
                            if ($accessRequest->user() || $accessRequest->email()) {
                                $post['request_from'] = 'admin';
                                $result = $this->sendRequestEmailUpdate($accessRequest, $post);
                                if (!$result) {
                                    $message = new PsrMessage(
                                        'The request was sent, but an issue occurred when sending the confirmation email.' // @translate
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
            'values' => json_encode($values, 320),
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

        if (!$this->getRequest()->isPost()) {
            $msg = new PsrMessage(
                'Method should be post.' // @translate
            );
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:id' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        $api = $this->api();
        $id = $this->params('id');

        /** @var \Access\Api\Representation\AccessRequestRepresentation $accessRequest */
        try {
            $accessRequest = $id ? $this->api()->read('access_requests', ['id' => $id])->getContent() : null;
        } catch (\Throwable $e) {
            $accessRequest = null;
        }

        if (!$id || !$accessRequest) {
            $msg = new PsrMessage(
                'The request #{access_request_id} is invalid or unavailable.', // @translate
                ['access_request_id' => $id]
            );
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:id' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_404);
        }

        $api->delete('access_requests', $id);

        return $this->jSend(JSend::SUCCESS, [
            'access_request' => [
                'o:id' => $id,
            ],
        ]);
    }

    public function toggleAction()
    {
        // Rights are already checked in acl for this controller.

        if (!$this->getRequest()->isPost()) {
            $msg = new PsrMessage(
                'Method should be post.' // @translate
            );
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:id' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        $api = $this->api();
        $id = $this->params('id');

        try {
            $accessRequest = $id ? $api->read('access_requests', ['id' => $id])->getContent() : null;
        } catch (\Throwable $e) {
            $accessRequest = null;
        }

        if (!$id || !$accessRequest) {
            $msg = new PsrMessage(
                'The request #{access_request_id} is invalid or unavailable.', // @translate
                ['access_request_id' => $id]
            );
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:id' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_404);
        }

        $status = $accessRequest->status() === AccessRequest::STATUS_ACCEPTED
            ? AccessRequest::STATUS_REJECTED
            : AccessRequest::STATUS_ACCEPTED;

        $accessRequest = $api->update('access_requests', $id, ['o:status' => $status])->getContent();

        if ($accessRequest->user() || $accessRequest->email()) {
            $post['request_from'] = 'admin';
            $result = $this->sendRequestEmailUpdate($accessRequest, $post);
            if (!$result) {
                $message = new PsrMessage(
                    'The request was sent, but an issue occurred when sending the confirmation email.' // @translate
                );
                $this->messenger()->addWarning($message);
            }
        }

        return $this->jSend(JSend::SUCCESS, [
            'access_request' => [
                'o:id' => $id,
                'o:status' => $status,
            ],
        ]);
    }
}

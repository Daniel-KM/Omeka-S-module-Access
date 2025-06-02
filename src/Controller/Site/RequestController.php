<?php declare(strict_types=1);

namespace Access\Controller\Site;

use Access\Controller\AccessTrait;
use Access\Entity\AccessRequest;
use Access\Form\Site\AccessRequestForm;
use Common\Mvc\Controller\Plugin\JSend;
use Common\Stdlib\PsrMessage;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class RequestController extends AbstractActionController
{
    use AccessTrait;

    public function browseAction()
    {
        // For user mode, it is recommended to use the module Guest.

        $modes = $this->settings()->get('access_modes');
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if (!count($individualModes)) {
            return $this->redirect()->toRoute('top');
        }

        // This is a site page: admin can view all, so results are useless.
        // TODO Else does not modify checks below.
        $user = $this->identity();
        $canViewAll = $user && $this->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all');
        if ($canViewAll) {
            return $this->redirect()->toRoute('top');
        }

        $this->browse()->setDefaults('access_requests');
        $query = $this->params()->fromQuery();

        // Don't allow end user to see other user access requests.
        $access = ($query['access'] ?? null) ?: null;
        $accesses = $access
            ? (is_array($access) ? array_filter($access) : [$access])
            : [];
        $accesses[] = $query['email'] ?? null;
        $accesses[] = $query['token'] ?? null;
        $accesses = array_filter($accesses);
        $accesses = array_diff($accesses, array_filter($accesses, 'is_numeric'));

        // Check access first to allow a user to access specific requests.
        if ($accesses && $user) {
            $accesses[] = $user->getId();
            $session = new \Laminas\Session\Container('Access');
            $session->offsetSet('access', $access);
        } elseif ($accesses) {
            $session = new \Laminas\Session\Container('Access');
            $session->offsetSet('access', $access);
        } elseif ($user) {
            $accesses[] = $user->getId();
        } else {
            return $this->redirect()->toRoute('top');
        }
        unset($query['user_id'], $query['email'], $query['token']);
        $query['access'] = $accesses;

        $response = $this->api()->search('access_requests', $query);
        $this->paginator($response->getTotalResults());

        /** @var \Access\Api\Representation\AccessRequestRepresentation[] $accessRequests */
        $accessRequests = $response->getContent();
        return new ViewModel([
            'accessRequests' => $accessRequests,
            'modes' => $modes,
            'access' => $access,
            'user' => $user,
        ]);
    }

    public function submitAction()
    {
        $requestUri = $this->params()->fromPost('request_uri');

        if (!$this->getRequest()->isPost()) {
            $msg = new PsrMessage(
                'Method should be post.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:id' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        $modes = $this->settings()->get('access_modes');
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if (!count($individualModes)) {
            $msg = new PsrMessage(
                'Individual access requests are not allowed.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:resource' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        $api = $this->api();
        $post = $this->params()->fromPost();
        $resources = $post['o:resource'] ?? null;
        if (!$resources) {
            $msg = new PsrMessage(
                'No resources were requested.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:resource' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        // Here, the request is done by user or email.
        $user = $this->identity();
        $email = $this->params()->fromPost('o:email');
        if (!$user && !$email) {
            $msg = new PsrMessage(
                'An email is required.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:email' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        } elseif ($user && $email) {
            $post['email'] = null;
        } elseif (!$user && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Early check: normally checked below.
            $msg = new PsrMessage(
                'A valid email is required.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => [
                    'o:email' => $msg->setTranslator($this->translator()),
                ],
            ], null, HttpResponse::STATUS_CODE_405);
        }

        // TODO Find a way to load the list of resources in RequestController.

        /** @var \Access\Form\Site\AccessRequestForm $form */
        $formOptions = [
            'full_access' => (bool) $this->settings()->get('access_full'),
            'resources' => [],
            'user' => $user,
        ];
        /** @var \Access\Form\Site\AccessRequestForm $form */
        $form = $this->getForm(AccessRequestForm::class, $formOptions);
        $form
            ->setOptions($formOptions)
            ->setData($post);
        if (!$form->isValid()) {
            if ($requestUri) {
                $this->messenger()->addFormErrors($form);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::FAIL, [
                'access_request' => $form->getMessages(),
            ], null, HttpResponse::STATUS_CODE_405);
        }

        $data = $form->getData();
        $fields = $post['fields'];

        /* TODO Check for existing requests.
        $accessRequests = $api->search('access_requests', [
            $user ? 'user_id' : 'email' => $user ? $user->getId() : $email,
            'resource' => $resources,
        ])->getContent();
        */

        $response = $api->create('access_requests', [
            $user ? 'o:user' : 'o:email' => $user ?: $email,
            'o:resource' => $resources,
            'o:status' => AccessRequest::STATUS_NEW,
            // To simplify end user experience, always set request recursive.
            'o-access:recursive' => true,
            'o:name' => empty($data['o:name']) ? null : $data['o:name'],
            'o:message' => empty($data['o:message']) ? null : $data['o:message'],
            'o-access:fields' => $fields,
        ]);
        if (!$response) {
            $msg = new PsrMessage(
                'An error occurred when saving message.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addError($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            // TODO This is error (check js).
            return $this->jSend(JSend::FAIL, [
                'access_request' => $form->getMessages(),
            ], null, HttpResponse::STATUS_CODE_405);
        }
        $accessRequest = $response->getContent();

        $post['request_from'] = 'somebody';
        $result = $this->sendRequestEmailCreate($accessRequest, $post);

        if ($result) {
            if ($requestUri) {
                $msg = new PsrMessage(
                    'The request was submitted successfully.' // @translate
                );
                $this->messenger()->addSuccess($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::SUCCESS, [
                'access_request' => [
                    'o:id' => $accessRequest->id(),
                ],
            ]);
        } else {
            $msg = new PsrMessage(
                'The request was sent, but an issue occurred when sending the confirmation email.' // @translate
            );
            if ($requestUri) {
                $this->messenger()->addWarning($msg);
                return $this->redirect()->toUrl($requestUri);
            }
            return $this->jSend(JSend::SUCCESS, [
                'access_request' => [
                    'o:id' => $accessRequest->id(),
                ],
                $msg->setTranslator($this->translator()),
            ]);
        }

        return $result;
    }
}

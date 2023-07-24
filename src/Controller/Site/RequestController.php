<?php declare(strict_types=1);

namespace AccessResource\Controller\Site;

use AccessResource\Controller\AccessTrait;
use AccessResource\Entity\AccessRequest;
use AccessResource\Form\Site\AccessRequestForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

class RequestController extends AbstractActionController
{
    use AccessTrait;

    public function browseAction()
    {
        // For user mode, it is recommended to use the module Guest.

        $modes = $this->settings()->get('accessresource_access_modes');
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

        /** @var \AccessResource\Api\Representation\AccessRequestRepresentation[] $accessRequests */
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
        if (!$this->getRequest()->isPost()) {
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

        $modes = $this->settings()->get('accessresource_access_modes');
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if (!count($individualModes)) {
            $this->getResponse()->setStatusCode(405);
            return new JsonModel([
                'status' => 'fail',
                'data' => [
                    'access_request' => [
                        'o:resource' => $this->translate('Individual access requests are not allowed.'), // @translate
                    ],
                ],
            ]);
        }

        $api = $this->api();
        $post = $this->params()->fromPost();
        $resources = $post['o:resource'] ?? null;
        if (!$resources) {
            $this->getResponse()->setStatusCode(405);
            return new JsonModel([
                'status' => 'fail',
                'data' => [
                    'access_request' => [
                        'o:resource' => $this->translate('No resources were requested.'), // @translate
                    ],
                ],
            ]);
        }

        // Here, the request is done by user or email.
        $user = $this->identity();
        $email = $this->params()->fromPost('o:email');
        if (!$user && !$email) {
            $this->getResponse()->setStatusCode(405);
            return new JsonModel([
                'status' => 'fail',
                'data' => [
                    'access_request' => [
                        'o:email' => $this->translate('An email is required.'), // @translate
                    ],
                ],
            ]);
        } elseif ($user && $email) {
            $post['email'] = null;
        } elseif (!$user && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Early check: normally checked below.
            $this->getResponse()->setStatusCode(405);
            return new JsonModel([
                'status' => 'fail',
                'data' => [
                    'access_request' => [
                        'o:email' => $this->translate('A valid email is required.'), // @translate
                    ],
                ],
            ]);
        }

        // TODO Find a way to load the list of resources in RequestController.

        /** @var \AccessResource\Form\Site\AccessRequestForm $form */
        $formOptions = [
            'full_access' => (bool) $this->settings()->get('accessresource_full'),
            'resources' => [],
            'user' => $user,
        ];
        /** @var \AccessResource\Form\Site\AccessRequestForm $form */
        $form = $this->getForm(AccessRequestForm::class, $formOptions);
        $form->setOptions($formOptions);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->getResponse()->setStatusCode(405);
            return new JsonModel([
                'status' => 'fail',
                'data' => [
                    'access_request' => $form->getMessages(),
                ],
            ]);
        }

        /* TODO Check for existing requests.
        $accessRequests = $api->search('access_requests', [
            $user ? 'user_id' : 'email' => $user ? $user->getId() : $email,
            'resource' => $resources,
        ])->getContent();
        */

        $accessRequest = $api->create('access_requests', [
            $user ? 'o:user' : 'o:email' => $user ?: $email,
            'o:resource' => $resources,
            'o:status' => AccessRequest::STATUS_NEW,
            // To simplify end user experience, always set request recursive.
            'o-access:recursive' => true,
        ])->getContent();

        $post['request_from'] = 'somebody';
        $result = $this->sendRequestEmailCreate($accessRequest, $post);

        if ($result) {
            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'access_request' => [
                        'o:id' => $accessRequest->id(),
                    ],
                ],
            ]);
        } else {
            return new JsonModel([
                'status' => 'success',
                'data' => [
                    'access_request' => [
                        'o:id' => $accessRequest->id(),
                    ],
                ],
                'message' => $this->translate('The request was sent, but an issue occurred when sending the confirmation email.'), // @translate
            ]);
        }

        return $result;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Controller\Site;

use AccessResource\Controller\AccessTrait;
use AccessResource\Entity\AccessRequest;
use AccessResource\Form\Site\AccessRequestForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class RequestController extends AbstractActionController
{
    use AccessTrait;

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

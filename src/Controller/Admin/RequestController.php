<?php
namespace AccessResource\Controller\Admin;

use Omeka\Form\ConfirmForm;
use AccessResource\Entity\AccessLog;
use AccessResource\Form\Admin\AccessRequestForm;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class RequestController extends AbstractActionController
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

        $view = new ViewModel;
        $view->setVariable('accessRequests', $response->getContent());
        return $view;
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

        $services = $this->getServiceLocator();

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
                    /** @var \Doctrine\ORM\EntityManager $entityManager */
                    $entityManager = $services->get('Omeka\EntityManager');
                    $response = $this->api($form)->update('access_requests', $accessRequest->id(), $post, [], ['isPartial' => true]);
                    $accessRequest = $response->getContent();
                    $accessUser = $entityManager
                        ->getRepository(\Omeka\Entity\User::class)
                        ->find($accessRequest->user()->id());

                    // Log changes to request record.
                    $log = new AccessLog();
                    $entityManager->persist($log);
                    $log
                        ->setAction('update_to_' . $accessRequest->status())
                        ->setUser($accessUser)
                        ->setRecordId($accessRequest->id())
                        ->setType('request')
                        ->setDate(new \DateTime());
                    $entityManager->flush();

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

        $dataType = $services->get('Omeka\DataTypeManager')->get('resource');

        $view = new ViewModel;
        $view
            ->setVariable('dataType', $dataType)
            ->setVariable('accessRequest', $accessRequest)
            ->setVariable('requestedResource', $requestedResource)
            ->setVariable('form', $form);
        return $view;
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $response = $this->api()->read('access_requests', $this->params('id'));
        $resource = $response->getContent();
        $values = ['@id' => $resource->id()];

        $view = new ViewModel;
        $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details')
            ->setVariable('resource', $resource)
            ->setVariable('resourceLabel', 'access request record') // @translate
            ->setVariable('partialPath', 'access-resource/admin/request/show-details')
            ->setVariable('linkTitle', $linkTitle)
            ->setVariable('values', json_encode($values));
        return $view;
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
                'action' => 'browse'
            ],
            true
        );
    }
}

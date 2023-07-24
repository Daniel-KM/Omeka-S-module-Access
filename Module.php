<?php declare(strict_types=1);

namespace AccessResource;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

const ACCESS_MODE_GLOBAL = 'global';
const ACCESS_MODE_IP = 'ip';
const ACCESS_MODE_INDIVIDUAL = 'individual';

use AccessResource\Entity\AccessStatus;
use AccessResource\Form\Admin\BatchEditFieldset;
use Doctrine\DBAL\ParameterType;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        require_once __DIR__ . '/data/scripts/upgrade_vocabulary.php';
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('To control access to files, you must add a rule in file .htaccess at the root of Omeka. See %sreadme%s.'), // @translate
            '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource" target="_blank">', '</a>'
        );
        $message->setEscapeHtml(false);
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->addWarning($message);
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest come after AccessResource.
        // See \Guest\Module::onBootstrap(). Manage other roles too: contributor, etc.
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }

        $acl
            ->allow(
                null,
                ['AccessResource\Controller\AccessResource']
            )
            ->allow(
                null,
                ['AccessResource\Controller\Site\Request'],
                ['submit']
            )
            ->allow(
                // TODO Limit access to authenticated users, instead of checking in controller.
                // Guest role is not yet loaded, neither specific roles (contributor…).
                null,
                ['AccessResource\Controller\Site\GuestBoard'],
                ['browse']
            )
            ->allow(
                null,
                [\AccessResource\Api\Adapter\AccessResourceAdapter::class],
                ['search']
            )
            ->allow(
                null,
                [\AccessResource\Api\Adapter\AccessRequestAdapter::class],
                ['search', 'create', 'update']
            )
            ->allow(
                null,
                [\AccessResource\Entity\AccessRequest::class],
                ['create', 'update']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Override the two core filters for media in order to detach two events
        // of Omeka\Module used to filter media belonging to private items.
        // It allows to filter reserved media and to search by storage_id.
        /** @see \Omeka\Module::filterMedia() */
        $listenersByEvent = [];
        $listenersByEvent['api.search.query'] = $sharedEventManager
            ->getListeners([\Omeka\Api\Adapter\MediaAdapter::class], 'api.search.query');
        $listenersByEvent['api.find.query'] = $sharedEventManager
            ->getListeners([\Omeka\Api\Adapter\MediaAdapter::class], 'api.find.query');
        foreach ($listenersByEvent as $listeners) {
            foreach ($listeners as $listener) {
                $sharedEventManager->detach(
                    [$listener[0][0], $listener[0][1]],
                    \Omeka\Api\Adapter\MediaAdapter::class
                );
            }
        }
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.search.query',
            [$this, 'filterMedia']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.find.query',
            [$this, 'filterMedia']
        );

        // Store status reserved.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.pre',
            [$this, 'updateAccessStatus']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.pre',
            [$this, 'updateAccessStatus']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.pre',
            [$this, 'updateAccessStatus']
        );

        // Attach tab to Item and Media resource.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\Media',
            'Omeka\Controller\Admin\ItemSet',
        ];
        foreach ($controllers as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.add.form.advanced',
                [$this, 'addAccessElements']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.advanced',
                [$this, 'addAccessElements']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.sidebar',
                [$this, 'handleViewShowAfter']
            );
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'handleViewShowAfter']
            );
        }

        // Extend the batch edit form via js.
        $sharedEventManager->attach(
            '*',
            'view.batch_edit.before',
            [$this, 'addAdminResourceHeaders']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'formAddElementsResourceBatchUpdateForm']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_input_filters',
            [$this, 'formAddInputFiltersResourceBatchUpdateForm']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.preprocess_batch_update',
            [$this, 'handleResourceBatchUpdatePreprocess']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.preprocess_batch_update',
            [$this, 'handleResourceBatchUpdatePreprocess']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.preprocess_batch_update',
            [$this, 'handleResourceBatchUpdatePreprocess']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.batch_update.post',
            [$this, 'handleResourceBatchUpdatePost']
        );

        // Attach to Doctrine events Access Request update, to trigger Access
        // resource management.
        $sharedEventManager->attach(
            \AccessResource\Entity\AccessRequest::class,
            'entity.persist.post',
            [$this, 'manageAccessByRequest']
        );
        $sharedEventManager->attach(
            \AccessResource\Entity\AccessRequest::class,
            'entity.update.post',
            [$this, 'manageAccessByRequest']
        );

        // Attach to view render, to inject guest user request form.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.after',
            [$this, 'handleViewBrowseAfterItem']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.browse.after',
            [$this, 'handleViewBrowseAfterItemSet']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleViewShowAfterItem']
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Media',
            'view.show.after',
            [$this, 'handleViewShowAfterMedia']
        );

        $sharedEventManager->attach(
            \AccessResource\Controller\Site\RequestController::class,
            'accessresource.request.created',
            [$this, 'handleRequestCreated']
        );

        $sharedEventManager->attach(
            \AccessResource\Controller\Admin\RequestController::class,
            'accessresource.request.updated',
            [$this, 'handleRequestUpdated']
        );

        // Attach tab to Item and Media resource.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            $sharedEventManager->attach(
                $controller,
                'view.show.section_nav',
                [$this, 'addAccessTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'displayListAndForm']
            );
        }

        // Guest user integration.
        $sharedEventManager->attach(
            \Guest\Controller\Site\GuestController::class,
            'guest.widgets',
            [$this, 'handleGuestWidgets']
        );

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $this->warnConfig();
        return '<style>fieldset[name=fieldset_index] .inputs label {display: block;}</style>'
            . parent::getConfigForm($renderer);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $this->warnConfig();

        $result = parent::handleConfigForm($controller);
        if (!$result) {
            return false;
        }

        $result = $this->prepareIpItemSets();
        if (!$result) {
            return false;
        }

        $post = $controller->getRequest()->getPost();
        if (!empty($post['fieldset_index']['process_index'])) {
            $vars = [
                'missing' => $post['fieldset_index']['missing'] ?? null,
            ];
            $this->processUpdateMissingStatus($vars);
        }

        return true;
    }

    protected function warnConfig(): void
    {
        if ($this->isModuleActive('Group')) {
            $services = $this->getServiceLocator();
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module is currently not compatible with module Group, that should be disabled.') // @translate
            );
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $messenger->addError($message);
        }
    }

    /**
     * Logic for media search filter.
     */
    public function filterMedia(Event $event): void
    {
        $this->filterMediaOverride($event);
        $this->filterMediaAdditional($event);
    }

    /**
     * Override the default event for media in order to add the check for the status.
     *
     * @see \Omeka\Module::filterMedia()
     */
    protected function filterMediaOverride(Event $event): void
    {
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        if ($acl->userIsAllowed(\Omeka\Entity\Resource::class, 'view-all')) {
            return;
        }

        /**
         * @var \Omeka\Api\Adapter\MediaAdapter $adapter
         * @var \Doctrine\ORM\QueryBuilder $qb
         */
        $adapter = $event->getTarget();
        $itemAlias = $adapter->createAlias();
        $qb = $event->getParam('queryBuilder');
        $qb->innerJoin('omeka_root.item', $itemAlias);

        $em = $qb->getEntityManager();
        $expr = $qb->expr();

        // Users can view media they do not own that belong to public items.
        $conditions = [
            $expr->eq("$itemAlias.isPublic", true),
        ];

        // Anonymous or user can view media if a token is used in query
        // and if they have access to it.
        $token = $services->get('Request')->getQuery('token');
        $access = $em->getRepository(\AccessResource\Entity\AccessResource::class)
            ->findOneBy(['token' => $token]);
        if ($access) {
            $conditions[] = $expr->eq('omeka_root.id', $access->getResource()->getId());
        }

        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        if ($identity) {
            // Users can view all media they own.
            $conditions[] = $expr->eq($itemAlias . '.owner', $adapter->createNamedParameter($qb, $identity));

            // Users can view records of all resources with access reserved.
            // Only files are protected (via htaccess).
            $qbs = $em->createQueryBuilder();
            $accessStatusAlias = $adapter->createAlias();
            $qbs
                ->select("$accessStatusAlias.id")
                ->from(AccessStatus::class, $accessStatusAlias)
                ->where($expr->eq("$accessStatusAlias.id", 'omeka_root.id'))
                ->andWhere($expr->neq("$accessStatusAlias.status", 'forbidden'));
            $conditions[] = $expr->exists($qbs->getDQL());
        }

        $expression = $expr->orX();
        foreach ($conditions as $condition) {
            $expression->add($condition);
        }

        $qb->andWhere($expression);
    }

    /**
     *Add the ability to search media by storage_id.
     */
    protected function filterMediaAdditional(Event $event): void
    {
        $adapter = $event->getTarget();
        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $event->getParam('queryBuilder');
        $request = $event->getParam('request');
        if (!$request) {
            return;
        }

        $query = $request->getContent();
        if (isset($query['storage_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.storageId',
                $adapter->createNamedParameter($qb, $query['storage_id'])
            ));
        }
    }

    /**
     * Update access status according to resource edit request.
     *
     * The access status is decorrelated from the visibility since version 3.4.17.
     */
    public function updateAccessStatus(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Api\Request $request
         */
        $resource = $event->getParam('entity');
        $request = $event->getParam('request');
        $requestContent = $request->getContent();

        // The resource may be partial and data should be hydrated or not.
        // This rule applies only to "is public".
        $isPublicBeforeUpdate = $resource->isPublic();
        $isPublicInRequest = $requestContent['o:is_public'] ?? null;
        $isPublicInRequest = is_null($isPublicInRequest)
            ? $isPublicBeforeUpdate
            : (bool) $isPublicInRequest;

        // When option is to use property, set the visibility according to it.
        // Else, use the option from the params or from the resource itself.
        if ($this->accessViaProperty) {
            if ($this->accessViaProperty === 'status') {
                // In api.hydrate.pre, the representation is not yet stored
                // and the representation cannot be used (it will use the
                // previous one, even with getRepresentation()) on resource).
                /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resourceRepr */
                // $resourceRepr = $services->get('Omeka\ApiAdapterManager')->get('resources')->getRepresentation($resource);

                // Request "isPartial" does not check "should hydrate" for
                // properties, so properties are always managed.
                /** @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::hydrate() */
                if (empty($requestContent[PROPERTY_STATUS])) {
                    $resourceAccessStatus = $isPublicInRequest ? ACCESS_STATUS_FREE : ACCESS_STATUS_FORBIDDEN;
                } else {
                    // $val = (string) $resourceRepr->value(PROPERTY_STATUS);
                    $val = (string) (reset($requestContent[PROPERTY_STATUS]))['@value'];
                    $resourceAccessStatus = array_search($val, $this->accessViaPropertyStatuses)
                        // If value is not present or invalid, the status is the
                        // visibility one.
                        ?: ($isPublicInRequest ? ACCESS_STATUS_FREE : ACCESS_STATUS_FORBIDDEN);
                }
            } else {
                // The mode "property reserved" requires two values to define
                // the access. The public visibility forces the status.
                if ($isPublicInRequest) {
                    $resourceAccessStatus = ACCESS_STATUS_FREE;
                } else {
                    // $val = (bool) $resourceRepr->value(PROPERTY_RESERVED);
                    $val = !empty($requestContent[PROPERTY_RESERVED]);
                    $resourceAccessStatus = $val
                        ? ACCESS_STATUS_RESERVED
                        : ACCESS_STATUS_FORBIDDEN;
                }
            }
        } else {
            $resourceData = $event->getParam('request')->getContent();
            $resourceAccessStatus = $resourceData['o-module-access-resource:status'] ?? null;
            // TODO Make the access status editable via api (already possible via the key "o-module-access-resource:status" anyway).
            if (!in_array($resourceAccessStatus, [ACCESS_STATUS_FREE, ACCESS_STATUS_RESERVED, ACCESS_STATUS_FORBIDDEN])) {
                $resourceAccessStatus = $resource->isPublic()
                    ? ACCESS_STATUS_FREE
                    : ACCESS_STATUS_FORBIDDEN;
            }
        }

        // Inside api.hydrate.pre, the request should be updated to be used.
        $isPublicAfterUpdate = $resourceAccessStatus === ACCESS_STATUS_FREE;
        $resource->setIsPublic($isPublicAfterUpdate);
        // Set in all cases because a full update may override it.
        $requestContent['o:is_public'] = $isPublicAfterUpdate;
        $request->setContent($requestContent);

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        // Get current access reserved to keep.
        $currentAccessStatus = $resource->getId()
            ? $entityManager->find(AccessStatus::class, $resource->getId())
            : null;
        if (!$currentAccessStatus) {
            $currentAccessStatus = new AccessStatus($resource);
        }

        $currentAccessStatus->setStatus($resourceAccessStatus);
        $entityManager->persist($currentAccessStatus);
    }

    public function handleRequestCreated(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('accessresource_message_send')) {
            return;
        }

        $requestMailer = $services->get('ControllerPluginManager')->get('requestMailer');
        $requestMailer->sendMailToAdmin('created');
        $requestMailer->sendMailToUser('created');
    }

    public function handleRequestUpdated(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('accessresource_message_send')) {
            return;
        }

        $requestMailer = $services->get('ControllerPluginManager')->get('requestMailer');
        // $requestMailer->sendMailToAdmin('updated');
        $requestMailer->sendMailToUser('updated');
    }

    /**
     * Add a tab to section navigation.
     */
    public function addAccessTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['access'] = 'Access'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     */
    public function displayListAndForm(Event $event): void
    {
        $request = $this->getServiceLocator()->get('Request');

        if ($request->isPost()) {
            // Create access.
            // $post = $request->getPost();
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $api->create('access_resources', []);
        }

        echo '<div id="access" class="section">';
        $resource = $event->getTarget()->resource;
        $this->displayAccessesAccessResource($event, $resource);
        // if ($allowed) {
        // echo $this->createAddAccessForm($event);
        // }
        echo '</div>';
    }

    /*
    protected function createAddAccessForm(Event $event)
    {
        $controller = $event->getTarget();
        $form = new \AccessResource\Form\Admin\AddAccessForm();
        return $controller->formCollection(new \AccessResource\Form\Admin\AddAccessForm());
    }
    */

    public function addAccessElements(Event $event): void
    {
        $services = $this->getServiceLocator();
        if ($this->accessViaProperty
            && $services->get('Omeka\Settings')->get('accessresource_hide_in_advanced_tab')
        ) {
            return;
        }

        $view = $event->getTarget();
        $accessStatus = $services->get('ControllerPluginManager')->get('accessStatus');

        $resource = $view->resource;

        if (!$this->accessApplyToResource($resource)) {
            return;
        }

        $resourceAccessStatus = $accessStatus($resource);
        $element = new \AccessResource\Form\Element\OptionalRadio('o-module-access-resource:status');
        $element
            ->setLabel('Access status') // @translate
            ->setOption('info', 'This status will override the main visibility (public/private).') // @translate
            ->setValueOptions([
                ACCESS_STATUS_FREE => 'Free access', // @translate'
                ACCESS_STATUS_RESERVED => 'Restricted access', // @translate
                ACCESS_STATUS_FORBIDDEN => 'Forbidden access', // @translate
            ])
            ->setAttributes([
                'id' => 'o-module-access-resource-status',
                'value' => $resourceAccessStatus,
                'disabled' => (bool) $this->accessViaProperty,
            ]);
        if ($this->accessViaProperty) {
            $this->accessViaProperty === 'status'
                ? $element->setLabel(sprintf('Access status is managed via property "%s"', PROPERTY_STATUS)) // @translate
                : $element->setLabel(sprintf('Access status is managed via presence of property "%s"', PROPERTY_RESERVED)); // @translate
        }

        echo $view->formRow($element);
    }

    public function handleViewShowAfter(Event $event): void
    {
        if ($this->accessViaProperty === 'status') {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        $resource = $vars->offsetGet('resource');
        if (!$this->accessApplyToResource($resource)) {
            return;
        }

        $plugins = $view->getHelperPluginManager();
        $translate = $plugins->get('translate');
        $accessStatus = $plugins->get('accessStatus');

        $resourceAccessStatus = $accessStatus($resource);

        $accessStatuses = [
            ACCESS_STATUS_FREE => 'Free access', // @translate'
            ACCESS_STATUS_RESERVED => 'Reserved access', // @translate
            ACCESS_STATUS_FORBIDDEN => 'Forbidden access', // @translate
        ];

        echo sprintf('<div class="meta-group">'
            . '<h4>%s</h4>'
            . '<div class="value">%s</div>'
            . '</div>',
            $translate('Access status'), // @translate
            $translate($accessStatuses[$resourceAccessStatus])
        );
    }

    protected function accessApplyToResource($resource): bool
    {
        // TODO Check why resource can be entity, representation or array.
        if (!$resource) {
            return false;
        } elseif ($resource instanceof \Omeka\Entity\Resource) {
            $resourceName = $resource->getResourceName();
        } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
            $resourceName = $resource->resourceName();
        } elseif (is_numeric($resource)) {
            $resourceId = (int) $resource;
            $resource = $this->getServiceLocator()->get('Omeka\EntityManager')->find(\Omeka\Entity\Resource::class, $resourceId);
            if (!$resource) {
                return false;
            }
            $resourceName = $resource->getResourceName();
        } elseif (is_array($resource) && !empty($resource['o:id'])) {
            $resourceId = (int) $resource['o:id'];
            $resource = $this->getServiceLocator()->get('Omeka\EntityManager')->find(\Omeka\Entity\Resource::class, $resourceId);
            if (!$resource) {
                return false;
            }
            $resourceName = $resource->getResourceName();
        } else {
            return false;
        }

        return in_array($resourceName, $this->accessApply);
    }

    /**
     * Helper to display the access for a resource.
     *
     * @param Event $event
     * @param AbstractResourceEntityRepresentation $resource
     */
    protected function displayAccessesAccessResource(
        Event $event,
        AbstractResourceEntityRepresentation $resource
    ): void {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        $accesses = $api->search('access_resources', ['resource_id' => $resource->id()])->getContent();
        $requests = $api->search('access_requests', ['resource_id' => $resource->id()])->getContent();

        $partial = 'common/admin/access-resource-list';
        echo $event->getTarget()->partial(
            $partial,
            [
                'resource' => $resource,
                'accesses' => $accesses,
                'requests' => $requests,
            ]
        );
    }

    public function manageAccessByRequest(Event $event): void
    {
        $entity = $event->getTarget();

        // Process only if status is 'accepted'.
        if ($entity->getStatus() != \AccessResource\Entity\AccessRequest::STATUS_ACCEPTED) {
            return;
        }

        // Find access record.
        /** @var \Omeka\Api\Manager $api */
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $accessRecords = $api->search('access_resources', [
            'resource_id' => $entity->getResource()->getId(),
            'user_id' => $entity->getUser()->getId(),
        ], ['responseContent' => 'resource'])->getContent();
        $accessRecord = count($accessRecords) ? array_pop($accessRecords) : null;

        if ($accessRecord) {
            $api->update('access_resources', $accessRecord->getId(), [
                'enabled' => true,
            ]);
        } else {
            $api->create('access_resources', [
                'resource_id' => $entity->getResource()->getId(),
                'user_id' => $entity->getUser()->getId(),
                'enabled' => true,
                // FIXME Use a random token.
                'token' => md5($entity->getResource()->getId() . '/' . $entity->getUser()->getId()),
                'temporal' => false,
            ]);
        }
    }

    public function handleViewBrowseAfterItem(Event $event): void
    {
        // Note: there is no item-set show, but a special case for items browse.
        $view = $event->getTarget();
        echo $view->accessResourceRequestForm($view->items);
    }

    public function handleViewBrowseAfterItemSet(Event $event): void
    {
        $view = $event->getTarget();
        echo $view->accessResourceRequestForm($view->itemSets);
    }

    public function handleViewShowAfterItem(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->item];
        $resources += $view->item->media();
        echo $view->accessResourceRequestForm($resources);
    }

    public function handleViewShowAfterMedia(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->media->item(), $view->media];
        echo $view->accessResourceRequestForm($resources);
    }

    public function handleGuestWidgets(Event $event): void
    {
        $widgets = $event->getParam('widgets');
        $viewHelpers = $this->getServiceLocator()->get('ViewHelperManager');
        $translate = $viewHelpers->get('translate');
        $partial = $viewHelpers->get('partial');

        $widget = [];
        $widget['label'] = $translate('Resource access'); // @translate
        $widget['content'] = $partial('guest/site/guest/widget/access-resource');
        $widgets['access'] = $widget;

        $event->setParam('widgets', $widgets);
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-resource-admin.css', 'AccessResource'));
        $view->headScript()
            ->appendFile($assetUrl('js/access-resource-bulk-admin.js', 'AccessResource'), 'text/javascript', ['defer' => 'defer']);
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $resourceType = $form->getOption('resource_type');

        $resourceTypeToNames = [
            'item' => 'items',
            'item-set' => 'item_sets',
            'itemSet' => 'item_sets',
            'media' => 'media',
        ];
        $resourceName = $resourceTypeToNames[$resourceType] ?? $resourceType;
        $options = [];
        $applyTo = $this->accessApply;
        switch ($resourceName) {
            case 'media':
                if (($pos = array_search('items', $this->accessApply)) !== false) {
                    unset($applyTo[$pos]);
                }
                // no break.
            case 'items':
                if (($pos = array_search('item_sets', $this->accessApply)) !== false) {
                    unset($applyTo[$pos]);
                }
                break;
        }
        if (count($applyTo) > 1) {
            $options = [
                'access_apply' => $applyTo,
            ];
        }

        $fieldset = $formElementManager->get(BatchEditFieldset::class, $options);
        $form->add($fieldset);
    }

    /**
     * Clean params for batch update.
     */
    public function handleResourceBatchUpdatePreprocess(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $post = $request->getContent();
        $data = $event->getParam('data');

        if (empty($data['access_resource'])
            || !array_filter($data['access_resource'])
            || empty($post['access_resource']['status'])
            || !in_array($post['access_resource']['status'], [ACCESS_STATUS_FREE, ACCESS_STATUS_RESERVED, ACCESS_STATUS_FORBIDDEN])
        ) {
            unset($data['access_resource']);
            $event->setParam('data', $data);
            return;
        }

        $data['access_resource'] = [
            'status' => $post['access_resource']['status'],
            'apply' => $post['access_resource']['apply'] ?? [],
        ];

        $event->setParam('data', $data);
    }

    /**
     * Process action on batch update (all or partial) via direct sql.
     *
     * Data may need to be reindexed if a module like Search is used, even if
     * the results are probably the same with a simple trimming.
     *
     * @param Event $event
     */
    public function handleResourceBatchUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();
        if (empty($data['access_resource'])
            || !array_filter($data['access_resource'])
            || empty($data['access_resource']['status'])
            || !in_array($data['access_resource']['status'], [ACCESS_STATUS_FREE, ACCESS_STATUS_RESERVED, ACCESS_STATUS_FORBIDDEN])
        ) {
            return;
        }

        $ids = (array) $request->getIds();
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return;
        }

        $resourceName = $request->getResource();

        $this->updateAccessResource($resourceName, $ids, $data['access_resource']['status'], $data['access_resource']['apply'] ?? []);
    }

    /**
     * @todo Check access rights before update.
     */
    protected function updateAccessResource(string $resourceName, array $resourceIds, string $status, array $apply): void
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        if (!$apply) {
            $apply = $this->accessApply;
        }

        $isPublic = $status === ACCESS_STATUS_FREE ? 1 : 0;

        $resourceNamesToTable = [
            'items' => 'item',
            'media' => 'media',
            'item_sets' => 'item_set',
        ];

        foreach ($apply as $applyResourceName) {
            if ($resourceName === $applyResourceName) {
                $sql = <<<'SQL'
# Make resource public or private.
UPDATE resource
SET is_public = :is_public
WHERE id IN (:ids)
;

SQL;
                $table = $resourceNamesToTable[$apply];
                if ($status === ACCESS_STATUS_RESERVED) {
                    $sql .= <<<SQL
# Make access reserved.
INSERT INTO access_reserved (id)
SELECT $table.id
WHERE $table.id IN (:ids)
ON DUPLICATE KEY UPDATE
    id = $table.id
;

SQL;
                } else {
                    $sql .= <<<SQL
# Remove access reserved.
DELETE
FROM access_reserved
JOIN $table ON $table.id = access_reserved.id
WHERE $table.id IN (:ids)
;

SQL;
                }
            } elseif ($resourceName === 'item_sets' && $applyResourceName === 'items') {
                $sql = <<<'SQL'
# Make resource public or private.
UPDATE resource
JOIN item ON item.id = resource.id
JOIN item_item_set ON item_item_set.item_id = item.id
SET is_public = :is_public
WHERE item_item_set.item_set_id IN (:ids)
;

SQL;
                if ($status === ACCESS_STATUS_RESERVED) {
                    $sql .= <<<'SQL'
# Make access reserved.
INSERT INTO access_reserved (id)
SELECT item_item_set.item_id
FROM item_item_set
WHERE item_item_set.item_set_id IN (:ids)
ON DUPLICATE KEY UPDATE
    id = item_item_set.item_id
;

SQL;
                } else {
                    $sql .= <<<'SQL'
# Remove access reserved.
DELETE
FROM access_reserved
JOIN item_item_set ON item_item_set.item_id = access_reserved.id
WHERE item_item_set.item_set_id IN (:ids)
;

SQL;
                }
            } elseif ($resourceName === 'item_sets' && $applyResourceName === 'media') {
                $sql = <<<'SQL'
# Make resource public or private.
UPDATE resource
JOIN media ON media.id = resource.id
JOIN item_item_set ON item_item_set.item_id = media.item_id
SET is_public = :is_public
WHERE item_item_set.item_set_id IN (:ids)
;

SQL;
                if ($status === ACCESS_STATUS_RESERVED) {
                    $sql .= <<<'SQL'
# Make access reserved.
INSERT INTO access_reserved (id)
SELECT media.id
FROM media
JOIN item_item_set ON item_item_set.item_id = media.item_id
WHERE item_item_set.item_set_id IN (:ids)
ON DUPLICATE KEY UPDATE
    id = media.id
;

SQL;
                } else {
                    $sql .= <<<'SQL'
# Remove access reserved.
DELETE
FROM access_reserved
JOIN media ON media.id = access_reserved.id
JOIN item_item_set ON item_item_set.item_id = media.item_id
WHERE item_item_set.item_set_id IN (:ids)
;

SQL;
                }
            } elseif ($resourceName === 'items' && $applyResourceName === 'media') {
                $sql = <<<'SQL'
# Make resource public or private.
UPDATE resource
JOIN media ON media.id = resource.id
SET is_public = :is_public
WHERE media.item_id IN (:ids)
;

SQL;
                if ($status === ACCESS_STATUS_RESERVED) {
                    $sql .= <<<'SQL'
# Make access reserved.
INSERT INTO access_reserved (id)
SELECT media.id
FROM media
WHERE media.item_id IN (:ids)
ON DUPLICATE KEY UPDATE
    id = media.id
;

SQL;
                } else {
                    $sql .= <<<'SQL'
# Remove access reserved.
DELETE
FROM access_reserved
JOIN media ON media.id = access_reserved.id
WHERE media.item_id IN (:ids)
;

SQL;
                }
            } else {
                continue;
            }
            $bind = [
                'is_public' => $isPublic,
                'ids' => $resourceIds,
            ];
            $types = [
                'is_public' => ParameterType::INTEGER,
                'ids' => $connection::PARAM_INT_ARRAY,
            ];
            $connection->executeStatement($sql, $bind, $types);
        }
    }

    protected function processUpdateMissingStatus(array $vars): void
    {
        $services = $this->getServiceLocator();

        $plugins = $services->get('ControllerPluginManager');
        $url = $plugins->get('url');
        $messenger = $plugins->get('messenger');

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\AccessResource\Job\UpdateAccessStatus::class, $vars);
        $message = new Message(
            'A job was launched in background to update access statuses according to parameters: (%1$sjob #%2$d%3$s, %4$slogs%3$s).', // @translate
            sprintf('<a href="%s">',
                htmlspecialchars($url->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', $this->isModuleActive('Log')
                ? $url->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])
                : $url->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    /**
     * Check ips and item sets and prepare the quick hidden setting.
     */
    protected function prepareIpItemSets(): bool
    {
        $services = $this->getServiceLocator();

        /**
         * @var \Omeka\\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $plugins = $services->get('ControllerPluginManager');
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $messenger = $plugins->get('messenger');

        $ipItemSets = $settings->get('accessresource_ip_item_sets') ?: [];

        $reservedIps = [];
        $hasError = false;
        foreach ($ipItemSets as $ip => $itemSetIds) {
            $itemSets = [];
            if (!$ip && !$itemSetIds) {
                continue;
            }
            if (!$ip || !filter_var(strtok($ip, '/'), FILTER_VALIDATE_IP)) {
                $message = new Message(
                    'The ip "%s" is empty or invalid.', // @translate
                    $ip
                );
                $messenger->addError($message);
                $hasError = true;
                continue;
            } elseif ($itemSetIds) {
                $itemSetIdsArray = array_unique(array_filter(explode(' ', preg_replace('/\D/', ' ', $itemSetIds))));
                if (!$itemSetIdsArray) {
                    $message = new Message(
                        'The item sets list "%1$s" for ip "%2$s" is invalid: they should be numeric ids.', // @translate
                        $itemSetIds, $ip
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                $itemSets = $api->search('item_sets', ['id' => $itemSetIdsArray], ['returnScalar' => 'id'])->getContent();
                if (count($itemSets) !== count($itemSetIdsArray)) {
                    $message = new Message(
                        'The item sets list "%1$s" for ip "%2$s" contains unknown item sets (%3$s).', // @translate
                        $itemSetIds, $ip, implode(', ', array_diff($itemSetIdsArray, $itemSets))
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
            }
            $reservedIps[$ip] = $this->cidrToRange($ip);
            $reservedIps[$ip]['reserved'] = $itemSets;
        }

        if ($hasError) {
            return false;
        }

        // Move the ip 0.0.0.0/0 as last ip, it will be possible to find a more
        // precise rule if any.
        foreach (['0.0.0.0', '0.0.0.0/0', '::'] as $ip) {
            if (isset($reservedIps[$ip])) {
                $v = $reservedIps[$ip];
                unset($reservedIps[$ip]);
                $reservedIps[$ip] = $v;
            }
        }

        $settings->set('accessresource_ip_reserved', $reservedIps);

        return true;
    }

    /**
     * Extract first and last ip as number from a an ip/cidr.
     *
     * @param string $cidr Checked ip with or without cidr.
     * @link https://stackoverflow.com/questions/4931721/getting-list-ips-from-cidr-notation-in-php/4931756#4931756
     * @return array Associative array with lowest and highest ip as number.
     */
    protected function cidrToRange($cidr): array
    {
        if (strpos($cidr, '/') === false) {
            return [
                'low' => ip2long($cidr),
                'high' => ip2long($cidr),
            ];
        }

        $cidr = explode('/', $cidr);
        $range = [];
        $range['low'] = (ip2long($cidr[0])) & ((-1 << (32 - (int) $cidr[1])));
        $range['high'] = (ip2long(long2ip($range['low']))) + 2 ** (32 - (int) $cidr[1]) - 1;
        return $range;
    }
}

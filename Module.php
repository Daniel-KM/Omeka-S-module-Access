<?php declare(strict_types=1);

namespace AccessResource;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use AccessResource\Entity\AccessStatus;
use AccessResource\Form\Admin\BatchEditFieldset;
use DateTime;
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

    /**
     * The classes are not ready on load of the class, so use a method.
     */
    protected function accessStatuses(): array
    {
        return [
            AccessStatus::FREE => AccessStatus::FREE,
            AccessStatus::RESERVED => AccessStatus::RESERVED,
            AccessStatus::PROTECTED => AccessStatus::PROTECTED,
            AccessStatus::FORBIDDEN => AccessStatus::FORBIDDEN,
        ];
    }

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

        // This is a quick job, so make it synchronous.
        $services->get(\Omeka\Job\Dispatcher::class)->dispatch(
            \AccessResource\Job\AccessStatusUpdate::class,
            ['missing' => AccessStatus::FREE],
            $services->get('Omeka\Job\DispatchStrategy\Synchronous')
        );
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

        // Store status.
        // Use hydrade.post since the resource id is required.
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            [$this, 'updateAccessStatus']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'updateAccessStatus']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.post',
            [$this, 'updateAccessStatus']
        );

        // Attach tab to resources.
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
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.preprocess_batch_update',
            [$this, 'handleResourceBatchUpdatePreprocess']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.preprocess_batch_update',
            [$this, 'handleResourceBatchUpdatePreprocess']
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

        // Send email to admin/user when a request is created or updated.
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
            // Only files are protected (via htaccess, redirected to controller).
            $qbs = $em->createQueryBuilder();
            $accessStatusAlias = $adapter->createAlias();
            $qbs
                ->select("$accessStatusAlias.id")
                ->from(AccessStatus::class, $accessStatusAlias)
                ->where($expr->eq("$accessStatusAlias.id", 'omeka_root.id'))
                ->andWhere($expr->neq("$accessStatusAlias.status", 'protected'))
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
        // In api.hydrate.pre, the representation is not yet stored and the
        // representation cannot be used (it will use the previous one, even
        // with getRepresentation()) on resource). So use api.hydrate.post
        // without early check (useless anyway, and with decorrelation, the
        // current resource is no more modified).

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         */
        $resource = $event->getParam('entity');
        $resourceId = $resource->getId();
        if (!$resourceId) {
            return;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $entityManager = $services->get('Omeka\EntityManager');

        $request = $event->getParam('request');
        $resourceData = $request->getContent();

        // Get current access if any.
        $accessStatus = $entityManager->find(AccessStatus::class, $resourceId);
        if (!$accessStatus) {
            $accessStatus = new AccessStatus();
            $accessStatus->setId($resource);
        }

        // TODO Make the access status editable via api (already possible via the key "o-access:status" anyway).
        // Request "isPartial" does not check "should hydrate" for properties,
        // so properties are always managed, but not access keys.

        $accessStatuses = $this->accessStatuses();

        $accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $accessProperty = $accessViaProperty ? $settings->get('accessresource_access_property') : null;
        if ($accessProperty) {
            $accessPropertyStatuses = array_intersect_key(array_replace($accessStatuses, $settings->get('accessresource_access_property_statuses', $accessStatuses)), $accessStatuses);
            /** @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::hydrate() */
            $accessIsSet = array_key_exists($accessProperty, $resourceData);
            $status = empty($resourceData[$accessProperty])
                ? null
                : (array_values($resourceData[$accessProperty])[0]['@value'] ?? null);
            if ($status) {
                $status = array_search($status, $accessPropertyStatuses) ?: null;
            }
        } else {
            $accessIsSet = array_key_exists('o-access:status', $resourceData);
            $status = $resourceData['o-access:status'] ?? null;
        }

        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($embargoViaProperty) {
            $embargoStartProperty = $embargoViaProperty ? $settings->get('accessresource_embargo_property_start') : null;
            if ($embargoStartProperty) {
                $embargoStartIsSet = array_key_exists($embargoStartProperty, $resourceData);
                $embargoStart = empty($resourceData[$embargoStartProperty])
                    ? null
                    : (array_values($resourceData[$embargoStartProperty])[0]['@value'] ?? null);
            }
            $embargoEndProperty = $embargoViaProperty ? $settings->get('accessresource_embargo_property_end') : null;
            if ($embargoEndProperty) {
                $embargoEndIsSet = array_key_exists($embargoEndProperty, $resourceData);
                $embargoEnd = empty($resourceData[$embargoEndProperty])
                    ? null
                    : (array_values($resourceData[$embargoEndProperty])[0]['@value'] ?? null);
            }
        } else {
            // The process via resource form returns two keys (date and time),
            // but the background process use the right key.
            $embargoStartIsSet = array_key_exists('o-access:embargoStart', $resourceData);
            $embargoStart = $resourceData['o-access:embargoStart'] ?? null;
            // No standard keys means a form process.
            // Merge date and time used in advanced tab of resource form.
            if (!$embargoStartIsSet) {
                $embargoStartIsSet = array_key_exists('embargo_start_date', $resourceData);
                $embargoStart = $resourceData['embargo_start_date'] ?? null;
                $embargoStart = trim((string) $embargoStart) ?: null;
                if ($embargoStart) {
                    $embargoStart .= 'T' . (empty($resourceData['embargo_start_time']) ? '00:00:00' : $resourceData['embargo_start_time']  . ':00');
                }
            }
            $embargoEndIsSet = array_key_exists('o-access:embargoEnd', $resourceData);
            $embargoEnd = $resourceData['o-access:embargoEnd'] ?? null;
            if (!$embargoEndIsSet) {
                $embargoEndIsSet = array_key_exists('embargo_end_date', $resourceData);
                $embargoEnd = $resourceData['embargo_end_date'] ?? null;
                $embargoEnd = trim((string) $embargoEnd) ?: null;
                if ($embargoEnd) {
                    $embargoEnd .= 'T' . (empty($resourceData['embargo_end_time']) ? '00:00:00' : $resourceData['embargo_end_time']  . ':00');
                }
            }
        }

        // Keep the database consistent instead of filling a bad value.
        // Update access status and embargo only when the keys are set.

        if (!empty($accessIsSet)) {
            // Default status is free
            // TODO Create access status "unknown" or "undefined"? Probably better, but complexify later.
            if (empty($status) || !in_array($status, $accessStatuses)) {
                $status = AccessStatus::FREE;
            }
            $accessStatus->setStatus($status);
        }

        if (!empty($embargoStartIsSet)) {
            try {
                $embargoStart = empty($embargoStart) ? null : new DateTime($embargoStart);
            } catch (\Exception $e) {
                $embargoStart = null;
            }
            $accessStatus->setEmbargoStart($embargoStart);
        }

        if (!empty($embargoEndIsSet)) {
            try {
                $embargoEnd = empty($embargoEnd) ? null : new DateTime($embargoEnd);
            } catch (\Exception $e) {
                $embargoEnd = null;
            }
            $accessStatus->setEmbargoEnd($embargoEnd);
        }

        $entityManager->persist($accessStatus);
        // Flush a single entity is required in batch update, but a deprecation
        // warning occurs, so use the unit of work.
        // In hydrate post, most of the checks are done, except files;
        if ($entityManager->isOpen()) {
            // $entityManager->flush($accessStatus);
            $entityManager->getUnitOfWork()->commit($accessStatus);
        }
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
     * Add a tab to section navigation (show has no "advanced tab").
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
        /**
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \AccessResource\Entity\AccessStatus $accessStatus
         * @var \AccessResource\Mvc\Controller\Plugin\AccessStatusForResource $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        if ($settings->get('accessresource_hide_in_advanced_tab')) {
            return;
        }

        $view = $event->getTarget();

        $accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');

        $view = $event->getTarget();
        $plugins = $services->get('ControllerPluginManager');
        $accessStatusForResource = $plugins->get('accessStatusForResource');

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-resource-admin.css', 'AccessResource'));

        // Get current access if any.
        $resource = $view->vars()->offsetGet('resource');
        $accessStatus = $accessStatusForResource($resource);
        if ($accessStatus) {
            $status = $accessStatus->getStatus();
            $embargoStart = $accessStatus->getEmbargoStart();
            $embargoEnd = $accessStatus->getEmbargoEnd();
        } else {
            $status = null;
            $embargoStart = null;
            $embargoEnd = null;
        }

        $statusElement = new \AccessResource\Form\Element\OptionalRadio('o-access:status');
        $statusElement
            ->setLabel('Access status') // @translate
            ->setValueOptions([
                AccessStatus::FREE => 'Free', // @translate'
                AccessStatus::RESERVED => 'Restricted', // @translate
                AccessStatus::PROTECTED => 'Protected', // @translate
                AccessStatus::FORBIDDEN => 'Forbidden', // @translate
            ])
            ->setAttributes([
                'id' => 'o-access-status',
                'value' => $status,
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);
        if ($accessViaProperty) {
            $accessProperty = $settings->get('accessresource_access_property');
            $statusElement
                ->setLabel(sprintf('Access status is managed via property %s.', $accessProperty)); // @translate
        }

        // Html element "datetime" is deprecated and "datetime-local" requires
        // time, even with a pattern, so use elements date and time to avoid js.

        $embargoStartElementDate = new \Laminas\Form\Element\Date('embargo_start_date');
        $embargoStartElementDate
            ->setLabel('Embargo start') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-start-date',
                'value' => $embargoStart ? $embargoStart->format('Y-m-d') : '',
                'disabled' => $embargoViaProperty ? 'disabled' : false,
            ]);
        $embargoStartElementTime = new \Laminas\Form\Element\Time('embargo_start_time');
        $embargoStartElementTime
            ->setLabel(' ')
            ->setAttributes([
                'id' => 'o-access-embargo-start-time',
                'value' => $embargoStart ? $embargoStart->format('H:i') : '',
                'disabled' => $embargoViaProperty ? 'disabled' : false,
            ]);

        $embargoEndElementDate = new \Laminas\Form\Element\Date('embargo_end_date');
        $embargoEndElementDate
            ->setLabel('Embargo end') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-end-date',
                'value' => $embargoEnd ? $embargoEnd->format('Y-m-d') : '',
                'disabled' => $embargoViaProperty ? 'disabled' : false,
            ]);
        $embargoEndElementTime = new \Laminas\Form\Element\Time('embargo_end_time');
        $embargoEndElementTime
            ->setLabel('Embargo end') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-end-time',
                'value' => $embargoEnd ? $embargoEnd->format('H:i') : '',
                'disabled' => $embargoViaProperty ? 'disabled' : false,
            ]);
        if ($embargoViaProperty) {
            $embargoStartProperty = $settings->get('accessresource_embargo_property_start');
            $embargoEndProperty = $settings->get('accessresource_embargo_property_end');
            $embargoStartElementDate
                ->setLabel(sprintf('Access embargo is managed via properties %1$s and %2$s.', $embargoStartProperty, $embargoEndProperty)); // @translate
        }

        echo $view->formRow($statusElement);
        echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoStartElementTime) . '</div>', $view->formRow($embargoStartElementDate));
        echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoEndElementTime) . '</div>', $view->formRow($embargoEndElementDate));
    }

    public function handleViewShowAfter(Event $event): void
    {
        /**
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\View\Helper\I18n $i18n
         * @var \AccessResource\Entity\AccessStatus $accessStatus
         * @var \AccessResource\Mvc\Controller\Plugin\AccessStatusForResource $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        $accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($accessViaProperty && $embargoViaProperty) {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        $i18n = $view->plugin('i18n');
        $translate = $plugins->get('translate');
        $accessStatusForResource = $plugins->get('accessStatusForResource');

        $resource = $vars->offsetGet('resource');
        $accessStatus = $accessStatusForResource($resource);

        if (!$accessViaProperty) {
            $accessStatuses = [
                AccessStatus::FREE => 'Free', // @translate'
                AccessStatus::RESERVED => 'Reserved', // @translate
                AccessStatus::PROTECTED => 'Protected', // @translate
                AccessStatus::FORBIDDEN => 'Forbidden', // @translate
            ];
            $status = $accessStatus ? $accessStatus->getStatus() : AccessStatus::FREE;
            $htmlStatus = sprintf('<div class="value">%s</div>', $translate($accessStatuses[$status]));
        }

        if (!$embargoViaProperty) {
            $embargoStart = $accessStatus ? $accessStatus->getEmbargoStart() : null;
            $embargoEnd = $accessStatus ? $accessStatus->getEmbargoEnd() : null;
            if ($embargoStart && $embargoEnd) {
                $htmlEmbargo= sprintf('<div class="value">%s</div>', sprintf($translate('Embargo from %1$s until %2$s'), $i18n->dateFormat($embargoStart, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT), $i18n->dateFormat($embargoEnd, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT))); // @translate
            } elseif ($embargoStart) {
                $htmlEmbargo= sprintf('<div class="value">%s</div>', sprintf($translate('Embargo from %1$s'), $i18n->dateFormat($embargoStart, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT))); // @translate
            } elseif ($embargoEnd) {
                $htmlEmbargo= sprintf('<div class="value">%s</div>', sprintf($translate('Embargo until %1$s'), $i18n->dateFormat($embargoEnd, $i18n::DATE_FORMAT_LONG, $i18n::DATE_FORMAT_SHORT))); // @translate
            }
        }

        $html = <<<'HTML'
<div class="meta-group">
    <h4>%1$s</h4>
    %2$s
    %3$s
</div>
HTML;
        echo sprintf(
            $html,
            $translate('Access status'), // @translate
            $htmlStatus ?? '',
            $htmlEmbargo ?? ''
        );
    }

    /**
     * Helper to display the accesses and requests for a resource.
     *
     * @param Event $event
     * @param AbstractResourceEntityRepresentation $resource
     */
    protected function displayAccessesAccessResource(Event $event, AbstractResourceEntityRepresentation $resource): void
    {
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
        if ($entity->getStatus() !== \AccessResource\Entity\AccessRequest::STATUS_ACCEPTED) {
            return;
        }

        // Find last access record.
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
            ->appendFile($assetUrl('js/access-resource-admin.js', 'AccessResource'), 'text/javascript', ['defer' => 'defer']);
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Form\ResourceBatchUpdateForm $form
         * @var \AccessResource\Form\Admin\BatchEditFieldset $fieldset
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($accessViaProperty && $embargoViaProperty) {
            return;
        }

        $form = $event->getTarget();
        $formElementManager = $services->get('FormElementManager');

        $fieldset = $formElementManager->get(BatchEditFieldset::class);
        $fieldset
            ->setOption('access_via_property', $accessViaProperty)
            ->setOption('embargo_via_property', $embargoViaProperty);

        $form->add($fieldset);
    }

    /**
     * Form filters shouldn't be needed.
     */
    public function formAddInputFiltersResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Laminas\InputFilter\InputFilterInterface $inputFilter */
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter
            ->get('accessresource')
            ->add([
                'name' => 'o-access:status',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_start_update',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_start_date',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_start_time',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_end_update',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_end_date',
                'required' => false,
            ])
            ->add([
                'name' => 'embargo_end_time',
                'required' => false,
            ])
        ;
    }

    /**
     * Clean params for batch update to avoid to do it for each resource.
     */
    public function handleResourceBatchUpdatePreprocess(Event $event): void
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \AccessResource\Form\Admin\BatchEditFieldset $fieldset
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $request = $event->getParam('request');

        $post = $request->getValue('accessresource');
        $data = $event->getParam('data');

        $accessViaProperty = (bool) $settings->get('accessresource_access_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($accessViaProperty && $embargoViaProperty) {
            unset($data['accessresource']);
            $event->setParam('data', $data);
            return;
        }

        // Access status.
        if (!$accessViaProperty) {
            if (empty($post['o-access:status']) || !in_array($post['o-access:status'], $this->accessStatuses())) {
                unset($data['o-access:status']);
            } else {
                $data['o-access:status'] = $post['o-access:status'];
            }
        }

        // Access embargo.
        if ($embargoViaProperty) {
            unset($data['o-access:embargoStart']);
            unset($data['o-access:embargoEnd']);
        } else {
            // Embargo start.
            if (empty($post['embargo_start_update'])) {
                unset($data['o-access:embargoStart']);
            } elseif ($post['embargo_start_update'] === 'remove') {
                $data['o-access:embargoStart'] = null;
            } elseif ($post['embargo_start_update'] === 'set') {
                $embargoStart = $post['embargo_start_date'] ?? null;
                $embargoStart = trim((string) $embargoStart) ?: null;
                if ($embargoStart) {
                    $embargoStart .= 'T' . (empty($post['embargo_start_time']) ? '00:00:00' : $post['embargo_start_time']  . ':00');
                }
                $data['o-access:embargoStart'] = $embargoStart;
            }
            // Embargo end.
            if (empty($post['embargo_end_update'])) {
                unset($data['o-access:embargoEnd']);
            } elseif ($post['embargo_end_update'] === 'remove') {
                $data['o-access:embargoEnd'] = null;
            } elseif ($post['embargo_end_update'] === 'set') {
                $embargoEnd = $post['embargo_end_date'] ?? null;
                $embargoEnd = trim((string) $embargoEnd) ?: null;
                if ($embargoEnd) {
                    $embargoEnd .= 'T' . (empty($post['embargo_end_time']) ? '00:00:00' : $post['embargo_end_time']  . ':00');
                }
                $data['o-access:embargoEnd'] = $embargoEnd;
            }
        }
        unset($data['accessresource']);

        $event->setParam('data', $data);
    }

    protected function processUpdateMissingStatus(array $vars): void
    {
        $services = $this->getServiceLocator();

        $plugins = $services->get('ControllerPluginManager');
        $url = $plugins->get('url');
        $messenger = $plugins->get('messenger');

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\AccessResource\Job\AccessStatusUpdate::class, $vars);
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

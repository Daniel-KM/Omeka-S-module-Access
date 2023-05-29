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

const ACCESS_STATUS_FREE = 'free';
const ACCESS_STATUS_RESERVED = 'reserved';
const ACCESS_STATUS_FORBIDDEN = 'forbidden';

use const AccessResource\ACCESS_MODE;
use const AccessResource\ACCESS_VIA_PROPERTY;

use const AccessResource\PROPERTY_STATUS;
use const AccessResource\PROPERTY_RESERVED;

use AccessResource\Entity\AccessReserved;
use AccessResource\Form\Admin\BatchEditFieldset;
use Doctrine\DBAL\ParameterType;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\SettingsInterface;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    /**
     * The include defines the access mode constant "AccessResource::ACCESS_MODE"
     * that should be used, except to avoid install/update issues.
     *
     * @var string
     */
    protected $accessMode = ACCESS_MODE_GLOBAL;

    /**
     * @var array
     */
    protected $accessApply = [
        // 'items',
        'media',
        // 'item_sets',
    ];

    /**
     * The include defines the access mode constant "AccessResource::ACCESS_VIA_PROPERTY"
     * that should be used, except to avoid install/update issues.
     *
     * @var bool|string
     */
    protected $accessViaProperty = false;

    /**
     * For mode property / status, list the possible status.
     *
     * @var array
     */
    protected $accessViaPropertyStatuses = [
        ACCESS_STATUS_FREE => 'free',
        ACCESS_STATUS_RESERVED => 'reserved',
        ACCESS_STATUS_FORBIDDEN => 'forbidden',
    ];

    public function getConfig()
    {
        $config = include OMEKA_PATH . '/config/local.config.php';
        $this->accessMode = $config['accessresource']['access_mode'] ?? ACCESS_MODE_GLOBAL;
        $this->accessApply = $config['accessresource']['access_apply'] ?? $this->accessApply;
        $this->accessViaProperty = $config['accessresource']['access_via_property'] ?? false;
        $this->accessViaPropertyStatuses = $config['accessresource']['access_via_property_statuses'] ?? $this->accessViaPropertyStatuses;
        require_once __DIR__ . '/config/access_mode.'
            . $this->accessMode
            . ($this->accessViaProperty ? '.property.' . $this->accessViaProperty : '')
            . '.php';
        return include __DIR__ . '/config/module.config.php';
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
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        if ($this->accessMode === ACCESS_MODE_INDIVIDUAL) {
            $this->addAclRoleAndRulesIndividually();
        } else {
            $this->addAclRoleAndRulesGlobally();
        }
    }

    /**
     * Add ACL role and rules for this module.
     */
    protected function addAclRoleAndRulesGlobally(): void
    {
        $this->getServiceLocator()->get('Omeka\Acl')
            ->allow(
                null,
                ['AccessResource\Controller\AccessResource']
            )
        ;
    }

    /**
     * Add ACL role and rules for this module.
     */
    protected function addAclRoleAndRulesIndividually(): void
    {
        /** @var \Omeka\Permissions\Acl $acl */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered, so Guest come after AccessResource.
        // See \Guest\Module::onBootstrap(). Manage other roles too: contributor, etc.
        // if (!$acl->hasRole('guest')) {
        //     $acl->addRole('guest');
        // }

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
        // of Omeka\Module use to filter media belonging to private items.
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
            [$this, 'updateAccessReserved']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.pre',
            [$this, 'updateAccessReserved']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemSetAdapter',
            'api.hydrate.pre',
            [$this, 'updateAccessReserved']
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
        // TODO Manage update when access is via property. And property can be prioritary or not.
        if (!$this->accessViaProperty) {
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
        }

        // No more event when access is global.
        if ($this->accessMode !== ACCESS_MODE_INDIVIDUAL) {
            return;
        }

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

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');

        $data['accessresource_access_mode'] = ACCESS_MODE;
        $data['accessresource_access_apply'] = $this->accessApply;
        $data['accessresource_access_via_property'] = (string) ACCESS_VIA_PROPERTY;
        $data['accessresource_access_via_property_statuses'] = $this->accessViaPropertyStatuses;

        /** @var \AccessResource\Form\ConfigForm $form */
        $form = $services->get('FormElementManager')->get(\AccessResource\Form\ConfigForm::class);
        $form->init();
        if ($this->accessMode === ACCESS_MODE_GLOBAL) {
            $form->remove('accessresource_ip_item_sets');
        }
        $form->setData($data);
        $form->prepare();
        return $renderer->formCollection($form);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $this->warnConfig();

        $services = $this->getServiceLocator();
        $params = $controller->getRequest()->getPost();

        $params['accessresource_access_mode'] = ACCESS_MODE;
        $params['accessresource_access_apply'] = $this->accessApply;
        $params['accessresource_access_via_property'] = (string) ACCESS_VIA_PROPERTY;
        $params['accessresource_access_via_property_statuses'] = $this->accessViaPropertyStatuses;

        $form = $services->get('FormElementManager')->get(\AccessResource\Form\ConfigForm::class);
        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        // Check ips and item sets and prepare the quick hidden setting.
        $api = $services->get('Omeka\ApiManager');
        $hasError = false;
        $config = $this->getConfig();

        $params = $form->getData();
        $params['accessresource_access_via_property'] = ACCESS_VIA_PROPERTY;

        $settings = $services->get('Omeka\Settings');

        $ipItemSets = $this->accessMode === ACCESS_MODE_GLOBAL
            ? []
            : $params['accessresource_ip_item_sets'] ?? [];
        $params['accessresource_ip_item_sets'] = $ipItemSets;

        $reservedIps = [];
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
                $controller->messenger()->addError($message);
                $hasError = true;
                continue;
            } elseif ($itemSetIds) {
                $itemSetIdsArray = array_unique(array_filter(explode(' ', preg_replace( '/\D/', ' ', $itemSetIds))));
                if (!$itemSetIdsArray) {
                    $message = new Message(
                        'The item sets list "%1$s" for ip "%2$s" is invalid: they should be numeric ids.', // @translate
                        $itemSetIds, $ip
                    );
                    $controller->messenger()->addError($message);
                    $hasError = true;
                    continue;
                }
                $itemSets = $api->search('item_sets', ['id' => $itemSetIdsArray], ['returnScalar' => 'id'])->getContent();
                if (count($itemSets) !== count($itemSetIdsArray)) {
                    $message = new Message(
                        'The item sets list "%1$s" for ip "%2$s" contains unknown item sets (%3$s).', // @translate
                        $itemSetIds, $ip, implode(', ', array_diff($itemSetIdsArray, $itemSets))
                    );
                    $controller->messenger()->addError($message);
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

        $params['accessresource_ip_reserved'] = $reservedIps;

        $defaultSettings = $config['accessresource']['config'];
        $params = array_intersect_key($params, $defaultSettings);
        foreach ($params as $name => $value) {
            $settings->set($name, $value);
        }
        return true;
    }

    protected function warnConfig(): void
    {
        $config = include OMEKA_PATH . '/config/local.config.php';
        if (empty($config['accessresource']['access_mode'])) {
            $services = $this->getServiceLocator();
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module is not configured: the key "[accessresource][access_mode]" should be set in the main config file of Omeka "config/local.config.php".') // @translate
            );
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $messenger->addWarning($message);
        }

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

    protected function prepareDataToPopulate(SettingsInterface $settings, string $settingsType): ?array
    {
        $data = parent::prepareDataToPopulate($settings, $settingsType);
        // The mode is available only in main config file.
        $data['accessresource_access_mode'] = $this->accessMode;
        return $data;
    }

    /**
     * Logic for media filter.
     */
    public function filterMedia(Event $event): void
    {
        $this->filterMediaOverride($event);
        $this->filterMediaAdditional($event);
    }

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
            $accessReservedAlias = $adapter->createAlias();
            $qbs
                ->select("$accessReservedAlias.id")
                ->from(\AccessResource\Entity\AccessReserved::class, $accessReservedAlias)
                ->where($expr->eq("$accessReservedAlias.id", 'omeka_root.id'));
            $conditions[] = $expr->exists($qbs->getDQL());
        }

        $expression = $expr->orX();
        foreach ($conditions as $condition) {
            $expression->add($condition);
        }

        $qb->andWhere($expression);
    }

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
        // Ability to filter by storage_id.
        if (isset($query['storage_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.storageId',
                $adapter->createNamedParameter($qb, $query['storage_id'])
            ));
        }
    }

    public function updateAccessReserved(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Api\Request $request
         */
        $resource = $event->getParam('entity');

        $resourceName = $resource->getResourceName();
        if (!in_array($resourceName, $this->accessApply)) {
            return;
        }

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

        // Get current access reserved to keep or remove it.
        $currentAccessReserved = $resource->getId()
            ? $entityManager->find(AccessReserved::class, $resource->getId())
            : null;

        if ($resourceAccessStatus === ACCESS_STATUS_RESERVED) {
            if (!$currentAccessReserved) {
                $currentAccessReserved = new AccessReserved($resource);
            }
            $entityManager->persist($currentAccessReserved);
        } elseif ($currentAccessReserved) {
            $entityManager->remove($currentAccessReserved);
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

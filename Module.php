<?php declare(strict_types=1);
namespace AccessResource;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\SettingsInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Guest';

    /**
     * @var string
     */
    protected $accessMode = 'global';

    public function getConfig()
    {
        $config = include OMEKA_PATH . '/config/local.config.php';
        $this->accessMode = @$config['accessresource']['access_mode'] ?: 'global';
        return $this->accessMode === 'individual'
            ? include __DIR__ . '/config/module.config.individual.php'
            : include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        if ($this->accessMode === 'individual') {
            $this->addAclRoleAndRulesIndividually();
        } else {
            $this->addAclRoleAndRulesGlobally();
        }
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        parent::install($serviceLocator);
        $this->installVocabulary();
    }

    /**
     * Add ACL role and rules for this module.
     */
    protected function addAclRoleAndRulesGlobally(): void
    {
        $this->getServiceLocator()->get('Omeka\Acl')
            ->allow(
                null,
                ['AccessResource\Controller\AccessResource'],
                ['index', 'files']
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
                ['AccessResource\Controller\AccessResource'],
                ['index', 'files']
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
        // Bypass the core filter for media (detach two events of Omeka\Module).
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

        // No more event when access is global: no form, requests, checks…
        if ($this->accessMode !== 'individual') {
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
            [$this, 'handlerRequestCreated']
        );

        $sharedEventManager->attach(
            \AccessResource\Controller\Admin\RequestController::class,
            'accessresource.request.updated',
            [$this, 'handlerRequestUpdated']
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

    protected function prepareDataToPopulate(SettingsInterface $settings, string $settingsType): ?array
    {
        $data = parent::prepareDataToPopulate($settings, $settingsType);
        $data['accessresource_access_mode'] = $this->accessMode;
        return $data;
    }

    /**
     * Logic for media filter.
     *
     * @param Event $event
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

        /** @var \Omeka\Api\Adapter\MediaAdapter $adapter */
        $adapter = $event->getTarget();

        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $event->getParam('queryBuilder');
        $em = $qb->getEntityManager();
        $expr = $qb->expr();

        $itemAlias = $adapter->createAlias();
        $qb->innerJoin('omeka_root.item', $itemAlias);

        // Users can view media they do not own that belong to public items.
        $conditions = [
            $expr->eq("$itemAlias.isPublic", true),
        ];

        // Anon user can view media if he use a token in query
        $token = $services->get('Request')->getQuery('token');
        $access = $em->getRepository(\AccessResource\Entity\AccessResource::class)
            ->findOneBy(['token' => $token]);

        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        if ($identity) {
            // Users can view all media they own.
            $conditions[] = $expr->eq($itemAlias . '.owner', $adapter->createNamedParameter($qb, $identity));

            // Users can view some media if they have access by token.
            if ($access) {
                $conditions[] = $expr->eq('omeka_root.id', $access->getResource()->getId());
            }

            // Users can view all media in private items with special property.
            $property = $services->get(\AccessResource\Service\Property\ReservedAccess::class);
            $qbs = $em->createQueryBuilder();
            $valueAlias = $adapter->createAlias();
            $qbs
                ->select("IDENTITY($valueAlias.resource)")
                ->from('Omeka\Entity\Value', $valueAlias)
                ->where($expr->eq(
                    "IDENTITY($valueAlias.property)",
                    $adapter->createNamedParameter($qb, $property->getId())
                ));

            $conditions[] = $expr->in('omeka_root.id', $qbs->getDQL());
        } elseif ($access) {
            // Users can view some media if they have access by token.
            $conditions[] = $expr->eq('omeka_root.id', $access->getResource()->getId());
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

    public function handlerRequestCreated(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('accessresource_message_send')) {
            return;
        }

        $requestMailer = $services->get(\AccessResource\Service\RequestMailerFactory::class);
        $requestMailer->sendMailToAdmin('created');
        $requestMailer->sendMailToUser('created');
    }

    public function handlerRequestUpdated(Event $event): void
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Settings')->get('accessresource_message_send')) {
            return;
        }

        $requestMailer = $services->get(\AccessResource\Service\RequestMailerFactory::class);
        // $requestMailer->sendMailToAdmin('updated');
        $requestMailer->sendMailToUser('updated');
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function addAccessTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['access'] = 'Access'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     *
     * @param Event $event
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
        echo $view->requestResourceAccessForm($view->items);
    }

    public function handleViewBrowseAfterItemSet(Event $event): void
    {
        $view = $event->getTarget();
        echo $view->requestResourceAccessForm($view->itemSets);
    }

    public function handleViewShowAfterItem(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->item];
        $resources += $view->item->media();
        echo $view->requestResourceAccessForm($resources);
    }

    public function handleViewShowAfterMedia(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->media->item(), $view->media];
        echo $view->requestResourceAccessForm($resources);
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

    protected function installVocabulary(): void
    {
        $vocabulary = [
            'o:namespace_uri' => 'https://curation.omeka.org',
            'o:prefix' => 'curation',
            'o:label' => 'Curation', // @translate
            'o:comment' => 'Curation of resource access', // @translate
            'o:class' => [],
            'o:property' => [
                [
                    'o:local_name' => 'reservedAccess',
                    'o:label' => 'Reserved Access', // @translate
                    'o:comment' => 'Gives an ability for private resource to be listed (previewed).', // @translate
                ],
            ],
        ];

        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $existingVocabList = $api
            ->search(
                'vocabularies',
                [
                    'namespace_uri' => $vocabulary['o:namespace_uri'],
                    'limit' => 1,
                ]
            )
            ->getContent();
        $existingVocab = is_array($existingVocabList) && count($existingVocabList) ? $existingVocabList[0] : null;

        if (!$existingVocab) {
            $api->create('vocabularies', $vocabulary);
        }
    }
}

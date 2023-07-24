<?php declare(strict_types=1);

namespace AccessResource;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use AccessResource\Api\Representation\AccessStatusRepresentation;
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
use Omeka\Entity\Resource;
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
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // Don't add the job to update initial status: use config form.
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('It is recommenced to run the job in config form to initialize all access statuses.') // @translate
        );
        $messenger->addWarning($message);

        $this->warnConfig();
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

        $roles = $acl->getRoles();
        $rolesExceptGuest = array_diff($roles, ['guest']);

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
                ['search', 'read']
            )
            ->allow(
                null,
                [\AccessResource\Api\Adapter\AccessRequestAdapter::class],
                ['search', 'create', 'update', 'read']
            )
            ->allow(
                null,
                [\AccessResource\Entity\AccessRequest::class],
                ['create', 'update', 'read']
            )
            // Rights on access status are useless for now because they are
            // managed only via sql/orm.
            ->allow(
                null,
                [\AccessResource\Entity\AccessStatus::class],
                ['search', 'read']
            )
            ->allow(
                $rolesExceptGuest,
                [\AccessResource\Entity\AccessStatus::class],
                ['search', 'read', 'create', 'update']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Add the status to the representation.
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemRepresentation::class,
            'rep.resource.json',
            [$this, 'filterEntityJsonLd']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\MediaRepresentation::class,
            'rep.resource.json',
            [$this, 'filterEntityJsonLd']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Representation\ItemSetRepresentation::class,
            'rep.resource.json',
            [$this, 'filterEntityJsonLd']
        );

        // No events are simple to use:
        // - api.hydrate.post: no id for create; errors are not thrown yet.
        // - entity.persist.post: no id for create and not called during batch
        //   and data are not available.
        // - api.create/update.post: skipped without finalize; no call for media
        //   and require a second flush.
        // - api.batch_create.post: redirect to api.create.post and no
        //   optimisations are possible, since each resource may be different.
        // - api.batch_update.post: require another event, double checks and
        //   hard to fix issues with doctrine "new entity is found", because
        //   another module may have flushed during process.
        // So use api.create/update.post because the checks are done. So the
        // action "finalize" should not be skipped.
        // Furthermore, the batch update process is used and allow to skip the
        // individual process to avoid complexity with doctrine issues and
        // flush/not flush.
        // In all cases, new medias are managed, the option "recursive" too and
        // the direct or background batch.
        // The api post events were used in a previous version, but removed to
        // simplify process.

        $adaptersAndControllers = [
            \Omeka\Api\Adapter\ItemAdapter::class => 'Omeka\Controller\Admin\Item',
            \Omeka\Api\Adapter\MediaAdapter::class => 'Omeka\Controller\Admin\Media',
            \Omeka\Api\Adapter\ItemSetAdapter::class => 'Omeka\Controller\Admin\ItemSet',
            // \Annotate\Api\Adapter\AnnotationAdapter::class => \Annotate\Controller\Admin\AnnotationController::class,
        ];
        foreach ($adaptersAndControllers as $adapter => $controller) {
            // Store status.
            $sharedEventManager->attach(
                $adapter,
                'api.create.post',
                [$this, 'handleCreateUpdateResource']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.post',
                [$this, 'handleCreateUpdateResource']
            );
            $sharedEventManager->attach(
                $adapter,
                // "api.preprocess_batch_update" is used only for individuals.
                'api.batch_update.pre',
                [$this, 'handleBatchUpdatePre']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleBatchUpdatePost']
            );

            // Attach tab to resources.
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

        // Management of individual accesses.

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
        // TODO Replace by resource blocks.
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

        $renderer->headScript()
            ->appendFile($renderer->assetUrl('js/access-resource-admin.js', 'AccessResource'), 'text/javascript', ['defer' => 'defer']);
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

        // Message are already prepared  when issues.
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

    /**
     * Add the status to the resource JSON-LD.
     *
     * Use getJsonLd() instead of jsonSerialize() because access status is not
     * an api for now.
     */
    public function filterEntityJsonLd(Event $event): void
    {
        $resource = $event->getTarget();

        /** @var \AccessResource\Api\Representation\AccessStatusRepresentation $accessStatus */
        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $accessStatusForResource = $plugins->get('accessStatus');
        $accessStatus = $accessStatusForResource($resource, true);
        if (!$accessStatus) {
            return;
        }

        $jsonLd = $event->getParam('jsonLd');
        $jsonLd['o-access:status'] = $accessStatus->getJsonLd();
        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Clean params for batch update to avoid to do it for each resource.
     * Anyway, the individual proces is skipped for process without property.
     */
    public function handleBatchUpdatePre(Event $event): void
    {
        /**
         * A batch update process is launched one to three times in the core, at
         * least with option "collectionAction" = "replace" (Omeka < 4.1).
         * Batch updates are always partial.
         *
         * @see \Omeka\Job\BatchUpdate::perform()
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');
        // Warning: collectionAction is not set in background job.
        $collectionAction = $request->getOption('collectionAction');
        if (in_array($collectionAction, ['remove', 'append'])) {
            return;
        }

        $request = $event->getParam('request');

        $data = $request->getContent('data');
        if (empty($data['accessresource'])) {
            unset($data['accessresource']);
            $request->setContent($data);
            return;
        }

        if (!empty($data['accessresource']['is_batch_process'])) {
            return;
        }

        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $levelViaProperty = (bool) $settings->get('accessresource_level_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($levelViaProperty && $embargoViaProperty) {
            unset($data['accessresource']);
            $request->setContent($data);
            return;
        }

        $rawData = $data['accessresource'];
        $newData = [
            'is_batch_process' => true,
        ];

        // Access level.
        if ($levelViaProperty) {
            // TODO Manage batch update for properties.
        } else {
            if (!empty($rawData['o-access:level']) && in_array($rawData['o-access:level'], AccessStatusRepresentation::LEVELS)) {
                $newData['o-access:level'] = $rawData['o-access:level'];
            }
        }

        // Access embargo.
        if ($embargoViaProperty) {
            // TODO Manage batch update for properties.
        } else {
            // Embargo start.
            if (empty($rawData['embargo_start_update'])) {
                // Nothing to do.
            } elseif ($rawData['embargo_start_update'] === 'remove') {
                $newData['o-access:embargoStart'] = null;
            } elseif ($rawData['embargo_start_update'] === 'set') {
                $embargoStart = $rawData['embargo_start_date'] ?? null;
                $embargoStart = trim((string) $embargoStart) ?: null;
                if ($embargoStart) {
                    $embargoStart .= 'T' . (empty($rawData['embargo_start_time']) ? '00:00:00' : $rawData['embargo_start_time']  . ':00');
                }
                $newData['o-access:embargoStart'] = $embargoStart;
            }
            // Embargo end.
            if (empty($rawData['embargo_end_update'])) {
                // Nothing to do.
            } elseif ($rawData['embargo_end_update'] === 'remove') {
                $newData['o-access:embargoEnd'] = null;
            } elseif ($rawData['embargo_end_update'] === 'set') {
                $embargoEnd = $rawData['embargo_end_date'] ?? null;
                $embargoEnd = trim((string) $embargoEnd) ?: null;
                if ($embargoEnd) {
                    $embargoEnd .= 'T' . (empty($rawData['embargo_end_time']) ? '00:00:00' : $rawData['embargo_end_time']  . ':00');
                }
                $newData['o-access:embargoEnd'] = $embargoEnd;
            }
        }

        $needProcess = array_key_exists('o-access:level', $newData)
            || array_key_exists('o-access:embargoStart', $newData)
            || array_key_exists('o-access:embargoEnd', $newData);

        if ($needProcess) {
            if (!empty($rawData['access_recursive'])) {
                $newData['access_recursive'] = true;
            }
            $this->getServiceLocator()->get('Omeka\Logger')->info(new Message(
                "Cleaned params used for access resources:\n%s", // @translate
                json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)
            ));
            $data['accessresource'] = $newData;
        } else {
            unset($data['accessresource']);
        }

        $request->setContent($data);
    }

    public function handleBatchUpdatePost(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Settings\Settings $settings
         */
        // The data are already checked during preprocess.
        $request = $event->getParam('request');
        $data = $request->getValue('accessresource');

        if (!$data) {
            return;
        }

        $level = array_key_exists('o-access:level', $data) ? $data['o-access:level'] : false;
        $embargoStart = array_key_exists('o-access:embargoStart', $data) ? $data['o-access:embargoStart'] : false;
        $embargoEnd = array_key_exists('o-access:embargoEnd', $data) ? $data['o-access:embargoEnd'] : false;

        // Check if a process is needed (normally already done).
        if ($level === false && $embargoStart === false && $embargoEnd === false) {
            return;
        }

        $response = $event->getParam('response');

        $resources = $response->getContent();
        if (!count($resources)) {
            return;
        }

        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource->getId();
        }

        // Use the ids of the response: rights and errors are checked.
        // Doctrine does not allow to do an "insert on duplicate", so two ways:
        // - Use a direct sql on the ids of the response, since rights and
        //   errors are checked.
        // - Get existing access statuses and update them, then create new ones.

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $accessStatuses = $entityManager->getRepository(AccessStatus::class)->findBy(['id' => $resources]);
        if ($accessStatuses) {
            $existingIds = [];
            foreach ($accessStatuses as $accessStatus) {
                $existingIds[] = $accessStatus->getIdResource();
            }
            $qb = $entityManager->createQueryBuilder();
            $expr = $qb->expr();
            $qb
                ->update(AccessStatus::class, 'access_status')
                ->where($expr->in('access_status.id', $existingIds));
            if ($level !== false) {
                $qb
                    ->set('access_status.level', ':level')
                    ->setParameter('level', $level, \Doctrine\DBAL\ParameterType::STRING);
            }
            if ($embargoStart !== false) {
                $qb
                    ->set('access_status.embargoStart', ':embargo_start')
                    ->setParameter('embargo_start', $embargoStart, $embargoStart ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL);
            }
            if ($embargoEnd !== false) {
                $qb
                    ->set('access_status.embargoEnd', ':embargo_end')
                    ->setParameter('embargo_end', $embargoEnd, $embargoEnd ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL);
            }
            $qb->getQuery()->execute();
            // Refresh entity manager cache after update via query.
            foreach ($accessStatuses as $accessStatus) {
                $entityManager->refresh($accessStatus);
            }
        }

        $remainingIds = isset($existingIds) ? array_diff($ids, $existingIds) : $ids;
        if ($remainingIds) {
            foreach ($remainingIds as $id) {
                $resource = $entityManager->find(Resource::class, $id);
                $accessStatus = new AccessStatus();
                $accessStatus
                    ->setId($resource)
                    ->setLevel($level ?: AccessStatus::FREE)
                    ->setEmbargoStart($embargoStart ?: null)
                    ->setEmbargoEnd($embargoEnd ?: null);
                $entityManager->persist($accessStatus);
            }
            // In post, the flush is possible and required anyway.
            $entityManager->flush();
        }

        if (!empty($data['access_recursive'])) {
            // TODO Currently, the recursive process requires a single resource.
            foreach ($resources as $resource) {
                $accessStatus = $entityManager->find(AccessStatus::class, $resource);
                $this->recursiveUpdate($resource, $accessStatus);
            }
        }
    }

    /**
     * Create access status according to resource add request.
     *
     * The access status is decorrelated from the visibility since version 3.4.17.
     */
    public function handleCreateUpdateResource(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         */
        $request = $event->getParam('request');
        $resourceData = $request->getContent();

        if (!empty($resourceData['is_batch_process'])) {
            return;
        }

        // This is an api-post event, so id is ready and checks are done.
        $resource = $event->getParam('response')->getContent();

        $this->manageAccessStatusForResource($resource, $resourceData);

        // Create the access statuses for new medias: there is no event during
        // media creation via item.
        if ($resource->getResourceName() === 'items'
            && empty($resourceData['access_recursive'])
        ) {
            $services = $this->getServiceLocator();
            $entityManager = $services->get('Omeka\EntityManager');
            $isUpdate = $event->getName() === 'api.update.post';
            foreach ($resource->getMedia() as $media) {
                // Don't modify media with an existing status during update.
                if ($isUpdate && $entityManager->getReference(AccessStatus::class, $media->getId())) {
                    continue;
                }
                $this->manageAccessStatusForResource($media, $resourceData);
            }
        }
    }

    protected function manageAccessStatusForResource(Resource $resource, array $resourceData): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Entity\Resource $resource
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $entityManager = $services->get('Omeka\EntityManager');

        $resourceId = $resource->getId();
        $resourceName = $resource->getResourceName();

        // Get current access if any.
        $accessStatus = $resourceId ? $entityManager->find(AccessStatus::class, $resourceId) : null;
        if (!$accessStatus) {
            $accessStatus = new AccessStatus();
            $accessStatus->setId($resource);
        }

        // TODO Make the access status editable via api (already possible via the key "o-access:level" anyway).
        // Request "isPartial" does not check "should hydrate" for properties,
        // so properties are always managed, but not access keys.

        // TODO Access recursive is not allowed for property process for now.
        // For now, recursivity with properties require to run a separate process.
        $accessRecursive = false;

        $levelViaProperty = (bool) $settings->get('accessresource_level_via_property');
        $levelProperty = $levelViaProperty ? $settings->get('accessresource_level_property') : null;
        if ($levelProperty) {
            $levelPropertyLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $settings->get('accessresource_level_property_levels', [])), AccessStatusRepresentation::LEVELS);
            /** @see \Omeka\Api\Adapter\AbstractResourceEntityAdapter::hydrate() */
            $levelIsSet = array_key_exists($levelProperty, $resourceData);
            $level = empty($resourceData[$levelProperty])
                ? null
                : (array_values($resourceData[$levelProperty])[0]['@value'] ?? null);
            if ($level) {
                $level = array_search($level, $levelPropertyLevels) ?: null;
            }
        } else {
            $levelIsSet = array_key_exists('o-access:level', $resourceData);
            $level = $resourceData['o-access:level'] ?? null;
            $accessRecursive = !empty($resourceData['access_recursive']);
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
        // Update access level and embargo only when the keys are set.

        if (!empty($levelIsSet)) {
            // Default level is free.
            // TODO Create access level "unknown" or "undefined"? Probably better, but complexify later.
            if (!in_array($level, AccessStatusRepresentation::LEVELS)) {
                $level = AccessStatus::FREE;
            }
            $accessStatus->setLevel($level);
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
        // In hydrate post, most of the checks are done, except files anyway.
        // $entityManager->flush($accessStatus);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->getUnitOfWork()->commit($accessStatus);

        if ($accessRecursive && in_array($resourceName, ['item_sets', 'items'])) {
            $this->recursiveUpdate($resource, $accessStatus);
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
        //     echo $this->createAddAccessForm($event);
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
         * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        if ($settings->get('accessresource_hide_in_advanced_tab')) {
            return;
        }

        $view = $event->getTarget();

        $levelViaProperty = (bool) $settings->get('accessresource_level_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');

        $view = $event->getTarget();
        $plugins = $services->get('ControllerPluginManager');
        $accessStatusForResource = $plugins->get('accessStatus');

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-resource-admin.css', 'AccessResource'));

        // Get current access if any.
        $resource = $view->vars()->offsetGet('resource');
        $accessStatus = $accessStatusForResource($resource);
        if ($accessStatus) {
            $level = $accessStatus->getLevel();
            $embargoStart = $accessStatus->getEmbargoStart();
            $embargoEnd = $accessStatus->getEmbargoEnd();
        } else {
            $level = null;
            $embargoStart = null;
            $embargoEnd = null;
        }

        $valueOptions = [
            AccessStatus::FREE => 'Free', // @translate'
            AccessStatus::RESERVED => 'Restricted', // @translate
            AccessStatus::PROTECTED => 'Protected', // @translate
            AccessStatus::FORBIDDEN => 'Forbidden', // @translate
        ];
        // There is no difference between reserved and protected when only the
        // file is protected.
        $fullAccess = (bool) $settings->get('accessresource_full');
        if (!$fullAccess) {
            unset($valueOptions[AccessStatus::PROTECTED]);
            if ($level === AccessStatus::PROTECTED) {
                $level = AccessStatus::RESERVED;
            }
        }

        $levelElement = new \AccessResource\Form\Element\OptionalRadio('o-access:level');
        $levelElement
            ->setLabel('Access level') // @translate
            ->setValueOptions($valueOptions)
            ->setAttributes([
                'id' => 'o-access-level',
                'value' => $level,
                'disabled' => $levelViaProperty ? 'disabled' : false,
            ]);
        if ($levelViaProperty) {
            $levelProperty = $settings->get('accessresource_level_property');
            $levelElement
                ->setLabel(sprintf('Access level is managed via property %s.', $levelProperty)); // @translate
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

        if (!$levelViaProperty || !$embargoViaProperty) {
            $resourceName = $resource ? $resource->resourceName() : $this->resourceNameFromRoute();
            if (($resourceName === 'item_sets' && $resource->itemCount())
                // Media may are not yet stored during creation.
                || $resourceName === 'items'
            ) {
                $recursiveElement = new \Laminas\Form\Element\Checkbox('access_recursive');
                $recursiveElement
                    ->setLabel($resourceName === 'item_sets'
                        ? 'Apply access level and embargo to items and medias' // @translate
                        : 'Apply access level and embargo to medias' // @translate
                    )
                    ->setAttributes([
                        'id' => 'o-access-recursive',
                        // The status is recursive only when creating items to
                        // avoid to override individual statuses of related
                        // resources.
                        'value' => $resourceName === 'items'
                            && $event->getName() === 'view.add.form.advanced',
                    ]);
            }
        }

        echo $view->formRow($levelElement);
        echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoStartElementTime) . '</div>', $view->formRow($embargoStartElementDate));
        echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoEndElementTime) . '</div>', $view->formRow($embargoEndElementDate));
        if (isset($recursiveElement)) {
            echo $view->formRow($recursiveElement);
        }
    }

    public function handleViewShowAfter(Event $event): void
    {
        /**
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\View\Helper\I18n $i18n
         * @var \AccessResource\Entity\AccessStatus $accessStatus
         * @var \AccessResource\Mvc\Controller\Plugin\AccessStatus $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        $levelViaProperty = (bool) $settings->get('accessresource_level_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($levelViaProperty && $embargoViaProperty) {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        $i18n = $view->plugin('i18n');
        $translate = $plugins->get('translate');
        $accessStatusForResource = $plugins->get('accessStatus');

        $resource = $vars->offsetGet('resource');

        /** @var \AccessResource\Api\Representation\AccessStatusRepresentation $accessStatus */
        $accessStatus = $accessStatusForResource($resource, true);

        if (!$levelViaProperty) {
            $level = $accessStatus ? $accessStatus->displayLevel() : $translate(AccessStatus::FREE);
            $htmlLevel = sprintf('<div class="value">%s</div>', $level);
        }

        if (!$embargoViaProperty) {
            $embargo = $accessStatus ? $accessStatus->displayEmbargo() : '';
            $htmlEmbargo= $embargo ? sprintf('<div class="value">%s</div>', $embargo) : ''; // @translate
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
            $htmlLevel ?? '',
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

        $levelViaProperty = (bool) $settings->get('accessresource_level_via_property');
        $embargoViaProperty = (bool) $settings->get('accessresource_embargo_via_property');
        if ($levelViaProperty && $embargoViaProperty) {
            return;
        }

        $form = $event->getTarget();
        $formElementManager = $services->get('FormElementManager');

        $fieldset = $formElementManager->get(BatchEditFieldset::class, [
            'full_access' => (bool) $settings->get('accessresource_full'),
            'resource_type' => $event->getTarget()->getOption('resource_type'),
            'level_via_property' => $levelViaProperty,
            'embargo_via_property' => $embargoViaProperty,
        ]);

        $form->add($fieldset);
    }

    /**
     * Form filters shouldn't be needed.
     *
     * @todo To be removed.
     */
    public function formAddInputFiltersResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Laminas\InputFilter\InputFilterInterface $inputFilter */
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter
            ->get('accessresource')
            ->add([
                'name' => 'o-access:level',
                'required' => false,
            ])
            ->add([
                'name' => 'access_recursive',
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

    protected function warnConfig(): void
    {
        $htaccess = file_get_contents(OMEKA_PATH . '/.htaccess');
        if (strpos($htaccess, '/access/')) {
            return;
        }

        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');
        $message = new \Omeka\Stdlib\Message(
            $translator->translate('To control access to files, you must add a rule in file .htaccess at the root of Omeka. See %1$sreadme%2$s.'), // @translate
            '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource" target="_blank">', '</a>'
        );
        $message->setEscapeHtml(false);
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->addError($message);
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
     * Recursivity should not be used with properties.
     *
     * @todo Manage recursivity when properties are used at least for media when an item is updated.
     * @todo Manage recursive update for multiple resources via ids.
     */
    protected function recursiveUpdate(Resource $resource, AccessStatus $accessStatus): void
    {
        $resourceId = $resource->getId();
        if (!$resourceId) {
            return;
        }

        // A job is required, because there may be many items in an item set and
        // many media in an item.
        // But for now, just use a quick sql, because this is a post hydration.
        // TODO Use dql/orm to update status of items and media of an item set?

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Permissions\Acl $acl
         */
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        // TODO Add a argument to avoid to check rights during creation.
        // When creating an item set, there is no item, so no recursivity, so no
        // rights to check.

        // People who can view all have all rights to update statuses.
        $isAllowed = $services->get('Omeka\Acl')->userIsAllowed(Resource::class, 'view-all');

        $level = $accessStatus->getLevel();
        $embargoStart = $accessStatus->getEmbargoStart();
        $embargoEnd = $accessStatus->getEmbargoEnd();

        $bind = [
            'level' => $level,
            'embargo_start' => $embargoStart ? $embargoStart->format('Y-m-d H:i:s') : null,
            'embargo_end' => $embargoEnd ? $embargoEnd->format('Y-m-d H:i:s') : null,
        ];
        $types = [
            'level' => \Doctrine\DBAL\ParameterType::STRING,
            'embargo_start' => $embargoStart ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL,
            'embargo_end' => $embargoEnd ? \Doctrine\DBAL\ParameterType::STRING : \Doctrine\DBAL\ParameterType::NULL,
        ];

        $resourceName = $resource->getResourceName();

        // Items: update medias.

        if ($resourceName === 'items') {
            /*
            // Join is not possible with doctrine 2, so use the list of media.
            $mediaIds = [];
            foreach ($resource->getMedia() as $media) {
                $mediaIds[] = $media->getId();
            }
            $qb = $entityManager->createQueryBuilder();
            $expr = $qb->expr();
            $qb
                ->update(AccessStatus::class, 'access_status')
                ->set('access_status.level', ':level')
                ->setParameter('level', $level)
                ->where($expr->in('access_status.id', ':ids'))
                ->setParameter('ids', $mediaIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
            ;
            $qb->getQuery()->execute();
            */

            $bind['item_id'] = $resourceId;
            $types['item_id'] = \Doctrine\DBAL\ParameterType::INTEGER;

            // To check rights via sql, the media ids are passed to the query
            // even if most of  the time, if the user has rights on the item, he
            // has rights on all its media.
            if ($isAllowed) {
                $whereMedias = '';
            } else {
                $mediaIds = $services->get('Omeka\ApiManager')
                    ->search('media', ['item_id' => $resourceId], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                if (!$mediaIds) {
                    return;
                }
                $whereMedias = 'AND `media`.`id` IN (:media_ids)';
                $bind['media_ids'] = $mediaIds;
                $types['media_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
            }

            // Use insert into instead of update, because the access statuses
            // may not exist yet.
            $sql = <<<SQL
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `media`.`id`, :level, :embargo_start, :embargo_end
FROM `media`
WHERE `media`.`item_id` = :item_id
    $whereMedias
ON DUPLICATE KEY UPDATE
    `level` = :level,
    `embargo_start` = :embargo_start,
    `embargo_end` = :embargo_end
;
SQL;
            $entityManager->getConnection()->executeStatement($sql, $bind, $types);
            return;
        }

        // Item sets: update items and medias.

        $bind['item_set_id'] = $resourceId;
        $types['item_set_id'] = \Doctrine\DBAL\ParameterType::INTEGER;

        if ($isAllowed) {
            $sql = <<<SQL
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `item_item_set`.`item_id`, :level, :embargo_start, :embargo_end
FROM `item_item_set`
WHERE `item_item_set`.`item_set_id` = :item_set_id
ON DUPLICATE KEY UPDATE
    `level` = :level,
    `embargo_start` = :embargo_start,
    `embargo_end` = :embargo_end
;
SQL;
            $entityManager->getConnection()->executeStatement($sql, $bind, $types);

            $sql = <<<SQL
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `media`.`id`, :level, :embargo_start, :embargo_end
FROM `media`
JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
WHERE `item_item_set`.`item_set_id` = :item_set_id
ON DUPLICATE KEY UPDATE
    `level` = :level,
    `embargo_start` = :embargo_start,
    `embargo_end` = :embargo_end
;
SQL;
            $entityManager->getConnection()->executeStatement($sql, $bind, $types);
            return;
        }

        // To check rights via sql, the item ids are passed to the query.
        $itemIds = $services->get('Omeka\ApiManager')
            ->search('items', ['item_set_id' => $resourceId], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
        if (!$itemIds) {
            return;
        }

        $bind['item_ids'] = $itemIds;
        $types['item_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;

        $sql = <<<SQL
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `item_item_set`.`item_id`, :level, :embargo_start, :embargo_end
FROM `item_item_set`
WHERE `item_item_set`.`item_set_id` = :item_set_id
    AND `item_item_set`.`item_id` IN (:item_ids)
ON DUPLICATE KEY UPDATE
    `level` = :level,
    `embargo_start` = :embargo_start,
    `embargo_end` = :embargo_end
;
SQL;
        $entityManager->getConnection()->executeStatement($sql, $bind, $types);

        // The standard api does not allow to search media by item set or
        // media by a list of items.
        /*
        $mediaIds = $services->get('Omeka\ApiManager')
           ->search('media', ['item_set_id' => $resourceId], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
        if (!$mediaIds) {
           return;
        }
        $whereMedias = 'AND media.item_id IN (:item_ids)';
        $bind['media_ids'] = $itemIds;
        $types['media_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        */

        // So use the standard db visibility check.
        /** @see \Omeka\Db\Filter\ResourceVisibilityFilter */
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if ($user) {
            $orWhereUser = 'OR `resource`.`owner_id` = :user_id';
            $bind['user_id'] = $user->getId();
            $types['user_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        } else {
            $orWhereUser = '';
        }

        $sql = <<<SQL
INSERT INTO `access_status` (`id`, `level`, `embargo_start`, `embargo_end`)
SELECT `media`.`id`, :level, :embargo_start, :embargo_end
FROM `media`
JOIN `resource` ON `resource`.`id` = `media`.`id`
JOIN `item_item_set` ON `item_item_set`.`item_id` = `media`.`item_id`
WHERE `item_item_set`.`item_set_id` = :item_set_id
    AND `media`.`item_id` IN (:item_ids)
    AND (`resource`.`is_public` = 1 $orWhereUser)
ON DUPLICATE KEY UPDATE
    `level` = :level,
    `embargo_start` = :embargo_start,
    `embargo_end` = :embargo_end
;
SQL;
        $entityManager->getConnection()->executeStatement($sql, $bind, $types);
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

    protected function resourceNameFromRoute(): ?string
    {
        /** @var \Omeka\Mvc\Status $status */
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');

        // Limit resource templates to the current resource type.
        // The resource type can be known only via the route.
        $controllerToResourceNames = [
            'Omeka\Controller\Admin\Item' => 'items',
            'Omeka\Controller\Admin\Media' => 'media',
            'Omeka\Controller\Admin\ItemSet' => 'item_sets',
            'Omeka\Controller\Site\Item' => 'items',
            'Omeka\Controller\Site\Media' => 'media',
            'Omeka\Controller\Site\ItemSet' => 'item_sets',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
            'items' => 'items',
            'itemset' => 'item_sets',
            'item_sets' => 'item_sets',
            // Module Annotate.
            'Annotate\Controller\Admin\Annotation' => 'annotations',
            'Annotate\Controller\Site\Annotation' => 'annotations',
            'annotation' => 'annotations',
            'annotations' => 'annotations',
        ];
        $params = $status->getRouteMatch()->getParams();
        $controller = $params['__CONTROLLER__'] ?? $params['controller'] ?? null;
        return $controllerToResourceNames[$controller] ?? null;
    }
}

<?php declare(strict_types=1);

namespace Access;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Access\Api\Representation\AccessStatusRepresentation;
use Access\Entity\AccessRequest;
use Access\Entity\AccessStatus;
use Access\Form\Admin\BatchEditFieldset;
use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use DateTime;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity\Resource;
use Omeka\Module\AbstractModule;

/**
 * Access
 *
 * @copyright Daniel Berthereau, 2019-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.65'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        /** @var bool $skipMessage */
        $skipMessage = true;
        require_once __DIR__ . '/data/scripts/upgrade_vocabulary.php';

        // Check if module AccessResource is installed.

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        try {
            $connection->executeQuery('SELECT id FROM access_log LIMIT 1')->fetchOne();
        } catch (\Exception $e) {
            // Continue standard install.
            return;
        }

        $plugins = $services->get('ControllerPluginManager');
        $messenger = $plugins->get('messenger');

        // Check upgrade from old module AccessResource if any.
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('AccessResource');
        $version = $module ? $module->getIni('version') : null;
        $status = $module ? $module->getState() : \Omeka\Module\Manager::STATE_NOT_FOUND;

        if (!$module || !$version || !in_array($status, [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
            \Omeka\Module\Manager::STATE_NEEDS_UPGRADE,
        ])) {
            // Continue standard install.
            return;
        }

        if (version_compare($version, '3.4.17.1', '<')) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                $translate('To be automatically upgraded and replaced by this module, the module "Access Resource" should be upgraded first to version 3.4.17.1. Else uninstall it first, but you will lose access statuses and requests.') // @translate
            );
        }

        if (version_compare($version, '3.4.17.1', '>')) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                $translate('To be automatically upgraded and replaced by this module, the module "Access Resource" should be downgraded first to version 3.4.17.1. Else uninstall it first, but you will lose access statuses and requests.') // @translate
            );
        }

        try {
            $filepath = $this->modulePath() . '/data/scripts/upgrade_from_accessresource.php';
            require_once $filepath;
        } catch (\Exception $e) {
            $message = new PsrMessage(
                'An error occurred during migration of module "{module}". Check the config and uninstall it manually.', // @translate
                ['module' => 'AccessResource']
            );
            $messenger->addError($message);
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        // Upgrade the database with new features.

        /**
         * @var string $oldVersion
         * @var string $newVersion
         */
        $oldVersion = $version;
        $newVersion = '999';
        $filepath = __DIR__ . '/data/scripts/upgrade.php';
        require_once $filepath;
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // Don't add the job to update initial status: use config form.
        $message = new PsrMessage(
            'It is recommenced to run the job in config form to initialize all access statuses.' // @translate
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

        // Since Omeka 1.4, modules are ordered, so Guest comes after Access.
        // See \Guest\Module::onBootstrap(). Manage other roles too: contributor, etc.
        // No need to add role guest_private, since he can view all.
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }
        if (!$acl->hasRole('guest_private')) {
            $acl->addRole('guest_private');
        }

        $roles = $acl->getRoles();
        $rolesExceptGuest = array_diff($roles, ['guest']);

        // Only admins can manage requests.
        $rolesAdmins = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        $acl
            ->allow(
                null,
                [\Access\Controller\AccessFileController::class]
            )
            ->allow(
                null,
                [\Access\Controller\Site\RequestController::class],
                ['browse', 'submit']
            )
            ->allow(
                $rolesAdmins,
                [\Access\Controller\Site\RequestController::class]
            )
            ->allow(
                $roles,
                [\Access\Controller\Site\GuestBoardController::class]
            )
            ->allow(
                null,
                [\Access\Api\Adapter\AccessRequestAdapter::class],
                ['search', 'create', 'update', 'read']
            )
            ->allow(
                null,
                [\Access\Entity\AccessRequest::class],
                ['create', 'update', 'read']
            )
            // Rights on access status are useless for now because they are
            // managed only via sql/orm.
            ->allow(
                null,
                [\Access\Entity\AccessStatus::class],
                ['search', 'read']
            )
            ->allow(
                $rolesExceptGuest,
                [\Access\Entity\AccessStatus::class],
                ['search', 'read', 'create', 'update']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $accessViaProperty = (bool) $settings->get('access_property');
        if (!$accessViaProperty) {
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
        }

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
                [$this, 'addResourceFormElements']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.advanced',
                [$this, 'addResourceFormElements']
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
                [$this, 'addAccessListAndForm']
            );

            // Manage search.
            $sharedEventManager->attach(
                $adapter,
                'api.search.query',
                [$this, 'handleApiSearchQuery']
            );
        }

        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
            'Omeka\Controller\Admin\Query',
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ];
        foreach ($controllers as $controller) {
            // Add the search field to the advanced search pages.
            $sharedEventManager->attach(
                $controller,
                'view.advanced_search',
                [$this, 'handleViewAdvancedSearch']
            );
            // Display the search filters for the search result.
            $sharedEventManager->attach(
                $controller,
                'view.search.filters',
                [$this, 'filterSearchFilters']
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
        $sharedEventManager->attach(
            'Omeka\Controller\Site\ItemSet',
            'view.show.after',
            [$this, 'handleViewShowAfterItemSet']
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
            ->appendFile($renderer->assetUrl('js/access-admin.js', 'Access'), 'text/javascript', ['defer' => 'defer']);
        return '<style>fieldset[name=fieldset_index] .inputs label {display: block;}</style>'
            . $this->getConfigFormAuto($renderer);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $this->warnConfig();

        $result = $this->handleConfigFormAuto($controller);
        if (!$result) {
            return false;
        }

        // Message are already prepared  when issues.
        $result = $this->prepareIpItemSets();
        if (!$result) {
            return false;
        }

        $result = $this->prepareAuthSsoIdpItemSets();
        if (!$result) {
            return false;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Complete the access levels in all cases, so they can be used anywhere
        // in particular in form, even if the default values are commonly used.
        $accessLevels = $settings->get('access_property_levels', AccessStatusRepresentation::LEVELS);
        $accessLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $accessLevels), AccessStatusRepresentation::LEVELS);
        $settings->set('access_property_levels', $accessLevels);

        $accessViaProperty = (bool) $settings->get('access_property');
        if (!$accessViaProperty) {
            return true;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $translator = $services->get('MvcTranslator');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $levelProperty = (bool) $settings->get('access_property_level');
        $embargoStartProperty = (bool) $settings->get('access_property_embargo_start');
        $embargoEndProperty = (bool) $settings->get('access_property_embargo_end');
        if (!$levelProperty || !$embargoStartProperty || !$embargoEndProperty) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('When properties are used, three properties should be defined for "level", "embargo start" and "embargo end".'), // @translate
            );
            $messenger->addError($message);
            return false;
        }

        $post = $controller->getRequest()->getPost();
        if (!empty($post['fieldset_index']['process_index'])) {
            $vars = [
                'recursive' => $post['fieldset_index']['recursive'] ?? [],
                'sync' => $post['fieldset_index']['sync'] ?? 'skip',
                'missing' => $post['fieldset_index']['missing'] ?? 'skip',
            ];
            if ($vars === ['recursive' => [], 'sync' => 'skip', 'missing' => 'skip']) {
                $message = new \Omeka\Stdlib\Message(
                    $translator->translate('Job is not launched: no option was set.'), // @translate
                );
                $messenger->addWarning($message);
            } else {
                $this->processUpdateStatus($vars);
            }
        }

        return true;
    }

    /**
     * Add the status to the resource JSON-LD.
     *
     * When the status is set via properties, it is not appended here.
     *
     * Use getJsonLd() instead of jsonSerialize() because access status is not
     * an api for now.
     */
    public function filterEntityJsonLd(Event $event): void
    {
        $resource = $event->getTarget();

        /** @var \Access\Api\Representation\AccessStatusRepresentation $accessStatus */
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
        if (empty($data['access'])) {
            unset($data['access']);
            $request->setContent($data);
            return;
        }

        if (!empty($data['access']['is_batch_process'])) {
            return;
        }

        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $rawData = $data['access'];
        $newData = [];

        // Access level.
        if (!empty($rawData['o-access:level']) && in_array($rawData['o-access:level'], AccessStatusRepresentation::LEVELS)) {
            $newData['o-access:level'] = $rawData['o-access:level'];
        }

        // Embargo start.
        if (empty($rawData['embargo_start_update'])) {
            // Nothing to do.
        } elseif ($rawData['embargo_start_update'] === 'remove') {
            $newData['o-access:embargo_start'] = null;
        } elseif ($rawData['embargo_start_update'] === 'set') {
            $embargoStart = $rawData['embargo_start_date'] ?? null;
            $embargoStart = trim((string) $embargoStart) ?: null;
            if ($embargoStart) {
                $embargoStart .= 'T' . (empty($rawData['embargo_start_time']) ? '00:00:00' : $rawData['embargo_start_time'] . ':00');
            }
            $newData['o-access:embargo_start'] = $embargoStart;
        }

        // Embargo end.
        if (empty($rawData['embargo_end_update'])) {
            // Nothing to do.
        } elseif ($rawData['embargo_end_update'] === 'remove') {
            $newData['o-access:embargo_end'] = null;
        } elseif ($rawData['embargo_end_update'] === 'set') {
            $embargoEnd = $rawData['embargo_end_date'] ?? null;
            $embargoEnd = trim((string) $embargoEnd) ?: null;
            if ($embargoEnd) {
                $embargoEnd .= 'T' . (empty($rawData['embargo_end_time']) ? '00:00:00' : $rawData['embargo_end_time'] . ':00');
            }
            $newData['o-access:embargo_end'] = $embargoEnd;
        }

        if (!empty($rawData['access_recursive'])) {
            $newData['access_recursive'] = true;
        }

        $needProcess = array_key_exists('o-access:level', $newData)
            || array_key_exists('o-access:embargo_start', $newData)
            || array_key_exists('o-access:embargo_end', $newData)
            || array_key_exists('access_recursive', $newData);

        if ($needProcess) {
            $this->getServiceLocator()->get('Omeka\Logger')->info(
                "Cleaned params used for Access:\n{json}", // @translate
                ['json' => $newData]
            );
            $newData = [
                'is_batch_process' => true,
            ] + $newData;
            $data['access'] = $newData;
        } else {
            unset($data['access']);
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
        $data = $request->getValue('access');

        if (!$data) {
            return;
        }

        $level = array_key_exists('o-access:level', $data) ? $data['o-access:level'] : false;
        $embargoStart = array_key_exists('o-access:embargo_start', $data) ? $data['o-access:embargo_start'] : false;
        $embargoEnd = array_key_exists('o-access:embargo_end', $data) ? $data['o-access:embargo_end'] : false;
        $accessRecursive = !empty($data['access_recursive']);

        // Check if a process is needed (normally already done).
        $processCurrentResource = $level !== false || $embargoStart !== false || $embargoEnd !== false;
        if (!$processCurrentResource && !$accessRecursive) {
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
            if ($processCurrentResource) {
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

        if ($accessRecursive) {
            $settings = $services->get('Omeka\Settings');
            $accessViaProperty = (bool) $settings->get('access_property');
            if ($accessViaProperty) {
                $accessStatusValues = [
                    'o-access:level' => ['value' => $level, 'type' => null],
                    'o-access:embargo_start' => ['value' => $embargoStart && substr($embargoStart, -8) === '00:00:00' ? substr($embargoStart, 0, 10) : $embargoStart, 'type' => null],
                    'o-access:embargo_end' => ['value' => $embargoEnd && substr($embargoEnd, -8) === '00:00:00' ? substr($embargoEnd, 0, 10) : $embargoEnd, 'type' => null],
                ];
            } else {
                $accessStatusValues = [];
            }
            // A job is required, because there may be many items in an item set and
            // many media in an item.

            // Nevertheless, the job is just a quick sql for now, and this is a
            // post event, so use a synchronous job.
            // TODO Here, this is already a job, so don't prepare a new job, but run it directly. How to get the running job id?

            $args = [
                'resource_ids' => $ids,
                'values' => $accessStatusValues,
            ];
            $services->get(\Omeka\Job\Dispatcher::class)
                 ->dispatch(\Access\Job\AccessStatusRecursive::class, $args, $services->get('Omeka\Job\DispatchStrategy\Synchronous'));
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
         */
        $request = $event->getParam('request');
        $resourceData = $request->getContent();

        if (!empty($resourceData['access']['is_batch_process'])) {
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
            // A level is required.
            $accessStatus
                ->setId($resource)
                ->setLevel(AccessStatus::FREE);
        }

        // TODO Make the access status editable via api (already possible via the key "o-access:level" anyway).
        // Request "isPartial" does not check "should hydrate" for properties,
        // so properties are always managed, but not access keys.

        $accessRecursive = !empty($resourceData['access_recursive']);

        $accessViaProperty = (bool) $settings->get('access_property');
        if ($accessViaProperty) {
            // Always update access resource, whatever property is new or not,
            // so don't use submitted resourceData, but the json because here,
            // the resource is already stored.
            /**
             * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter
             * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $representation
             */
            $adapter = $services->get('Omeka\ApiAdapterManager')->get($resource->getResourceName());
            $representation = $adapter->getRepresentation($resource);
            $levelProperty = $accessViaProperty ? $settings->get('access_property_level') : null;
            if ($levelProperty) {
                $levelIsSet = true;
                $accessLevels = array_intersect_key(array_replace(AccessStatusRepresentation::LEVELS, $settings->get('access_property_levels', [])), AccessStatusRepresentation::LEVELS);
                $levelValue = $representation->value($levelProperty);
                $levelVal = $levelValue ? array_search((string) $levelValue->value(), $accessLevels) : null;
                $level = $levelVal ?: AccessStatus::FREE;
                $levelType = $levelValue ? $levelValue->type() : null;
            }
            $embargoStartProperty = $accessViaProperty ? $settings->get('access_property_embargo_start') : null;
            if ($embargoStartProperty) {
                $embargoStartIsSet = true;
                $embargoStartValue = $representation->value($embargoStartProperty);
                $embargoStartVal = $embargoStartValue ? (string) $embargoStartValue->value() : null;
                $embargoStart = $embargoStartVal ?: null;
                $embargoStartType = $embargoStartValue ? $embargoStartValue->type() : null;
            }
            $embargoEndProperty = $accessViaProperty ? $settings->get('access_property_embargo_end') : null;
            if ($embargoEndProperty) {
                $embargoEndIsSet = true;
                $embargoEndValue = $representation->value($embargoEndProperty);
                $embargoEndVal = $embargoEndValue ? (string) $embargoEndValue->value() : null;
                $embargoEnd = $embargoEndVal ?: null;
                $embargoEndType = $embargoEndValue ? $embargoEndValue->type() : null;
            }
            $accessStatusValues = [
                'o-access:level' => ['value' => $level, 'type' => $levelType],
                'o-access:embargo_start' => ['value' => $embargoStartVal, 'type' => $embargoStartType],
                'o-access:embargo_end' => ['value' => $embargoEndVal, 'type' => $embargoEndType],
            ];
        } else {
            $levelIsSet = array_key_exists('o-access:level', $resourceData);
            $level = $resourceData['o-access:level'] ?? null;
            // The process via resource form returns two keys (date and time),
            // but the background process use the right key.
            $embargoStartIsSet = array_key_exists('o-access:embargo_start', $resourceData);
            $embargoStart = $resourceData['o-access:embargo_start'] ?? null;
            // No standard keys means a form process.
            // Merge date and time used in advanced tab of resource form.
            if (!$embargoStartIsSet) {
                $embargoStartIsSet = array_key_exists('embargo_start_date', $resourceData);
                $embargoStart = $resourceData['embargo_start_date'] ?? null;
                $embargoStart = trim((string) $embargoStart) ?: null;
                if ($embargoStart) {
                    $embargoStart .= 'T' . (empty($resourceData['embargo_start_time']) ? '00:00:00' : $resourceData['embargo_start_time'] . ':00');
                }
            }
            $embargoEndIsSet = array_key_exists('o-access:embargo_end', $resourceData);
            $embargoEnd = $resourceData['o-access:embargo_end'] ?? null;
            if (!$embargoEndIsSet) {
                $embargoEndIsSet = array_key_exists('embargo_end_date', $resourceData);
                $embargoEnd = $resourceData['embargo_end_date'] ?? null;
                $embargoEnd = trim((string) $embargoEnd) ?: null;
                if ($embargoEnd) {
                    $embargoEnd .= 'T' . (empty($resourceData['embargo_end_time']) ? '00:00:00' : $resourceData['embargo_end_time'] . ':00');
                }
            }
            $accessStatusValues = [];
        }

        // Keep the database consistent instead of filling a bad value.
        // Update access level and embargo only when the keys are set.

        if (!empty($levelIsSet)) {
            // Default level is free.
            // TODO Create access level "unknown" or "undefined"? Probably better, but complexify later.
            if (!in_array($level, AccessStatusRepresentation::LEVELS)) {
                $level = AccessStatus::FREE;
            }
            // FIXME An issue occurred when storing text content in a property of pdf media in module ExtractOcr not in front-end job: the media does not exist yet, so cannot be updated.
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
            // A job is required, because there may be many items in an item set
            // and many media in an item.
            // Nevertheless, the job is just a quick sql for now, and this is a
            // post event, so use a synchronous job.
            $args = [
                'resource_id' => $resourceId,
                'values' => $accessStatusValues,
            ];
            $services->get(\Omeka\Job\Dispatcher::class)
                ->dispatch(\Access\Job\AccessStatusRecursive::class, $args, $services->get('Omeka\Job\DispatchStrategy\Synchronous'));
        }
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
    public function addAccessListAndForm(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         * @var \Access\Mvc\Controller\Plugin\AccessStatus $accessStatusForResource
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         */
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $api = $plugins->get('api');
        $settings = $services->get('Omeka\Settings');
        $messenger = $plugins->get('messenger');
        $accessStatusForResource = $plugins->get('accessStatus');

        $resource = $view->resource;
        $accessStatus = $accessStatusForResource($resource, true);

        $formOptions = [
            'full_access' => (bool) $settings->get('access_full'),
            'resource_id' => $resource->id(),
            'resource_type' => $resource->resourceName(),
            'request_status' => AccessRequest::STATUS_ACCEPTED,
        ];
        /** @var \Access\Form\Admin\AccessRequestForm $form */
        $form = $services->get('FormElementManager')
            ->get(\Access\Form\Admin\AccessRequestForm::class, $formOptions)
            ->setOptions($formOptions);

        /**
         * @see \Access\Controller\Admin\RequestController::editAction()
         * @var \Laminas\Mvc\MvcEvent $mvcEvent
         * @var \Laminas\Http\PhpEnvironment\Request $httpRequest
         */
        $request = $services->get('Request');
        if ($request->isPost()) {
            $params = $plugins->get('params');
            $post = $params->fromPost();
            $form->setData($post);
            // TODO Fix issue with date time.
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
                        'You should set either a user or an email or check box for token.', // @translate
                    );
                    $messenger->addError($message);
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
                        $data['o-access:token'] = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 16);
                    }
                    $response = $api($form)->create('access_requests', $data);
                    if ($response) {
                        $message = new PsrMessage(
                            'Access request successfully created.', // @translate
                        );
                        $messenger->addSuccess($message);
                    }
                    // Reinit the form for a new request.
                    $form = $services->get('FormElementManager')
                        ->get(\Access\Form\Admin\AccessRequestForm::class, $formOptions)
                        ->setOptions($formOptions);
                }
            } else {
                $messenger->addFormErrors($form);
            }
        }

        $accessRequests = $api->search('access_requests', ['resource_id' => $resource->id()])->getContent();
        $requestHtml = $view->partial('common/access-request-list', [
            'resource' => $resource,
            'accessStatus' => $accessStatus,
            'accessRequests' => $accessRequests,
        ]);

        // Admin request form.
        $requestForm = $view->partial('common/access-request-form', [
            'resource' => $resource,
            'accessStatus' => $accessStatus,
            'requestForm' => $form,
        ]);

        $html = <<<'HTML'
<div id="access" class="section">
    %1$s
    %2$s
</div>
HTML;
        echo sprintf($html, $requestHtml, $requestForm);
    }

    public function addResourceFormElements(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Access\Entity\AccessStatus $accessStatus
         * @var \Access\Mvc\Controller\Plugin\AccessStatus $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $accessViaProperty = (bool) $settings->get('access_property');
        $showInAdvancedTab = !$accessViaProperty || $settings->get('access_property_show_in_advanced_tab');

        $view = $event->getTarget();
        $plugins = $services->get('ControllerPluginManager');
        $accessStatusForResource = $plugins->get('accessStatus');

        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-admin.css', 'Access'));

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
            AccessStatus::RESERVED => 'Reserved', // @translate
            AccessStatus::PROTECTED => 'Protected', // @translate
            AccessStatus::FORBIDDEN => 'Forbidden', // @translate
        ];
        // There is no difference between reserved and protected when only the
        // file is protected.
        $fullAccess = (bool) $settings->get('access_full');
        if (!$fullAccess) {
            unset($valueOptions[AccessStatus::PROTECTED]);
            if ($level === AccessStatus::PROTECTED) {
                $level = AccessStatus::RESERVED;
            }
        }

        $levelElement = new \Common\Form\Element\OptionalRadio('o-access:level');
        $levelElement
            ->setLabel('Access level') // @translate
            ->setValueOptions($valueOptions)
            ->setAttributes([
                'id' => 'o-access-level',
                'value' => $level,
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);

        // Html element "datetime" is deprecated and "datetime-local" requires
        // time, even with a pattern, so use elements date and time to avoid js.
        // TODO Create a full form element with view for standard date + time.

        $embargoStartElementDate = new \Laminas\Form\Element\Date('embargo_start_date');
        $embargoStartElementDate
            ->setLabel('Embargo start') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-start-date',
                'value' => $embargoStart ? $embargoStart->format('Y-m-d') : '',
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);
        $embargoStartElementTime = new \Laminas\Form\Element\Time('embargo_start_time');
        $embargoStartElementTime
            ->setLabel(' ')
            ->setAttributes([
                'id' => 'o-access-embargo-start-time',
                'value' => $embargoStart ? $embargoStart->format('H:i') : '',
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);

        $embargoEndElementDate = new \Laminas\Form\Element\Date('embargo_end_date');
        $embargoEndElementDate
            ->setLabel('Embargo end') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-end-date',
                'value' => $embargoEnd ? $embargoEnd->format('Y-m-d') : '',
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);
        $embargoEndElementTime = new \Laminas\Form\Element\Time('embargo_end_time');
        $embargoEndElementTime
            ->setLabel('Embargo end') // @translate
            ->setAttributes([
                'id' => 'o-access-embargo-end-time',
                'value' => $embargoEnd ? $embargoEnd->format('H:i') : '',
                'disabled' => $accessViaProperty ? 'disabled' : false,
            ]);

        if ($accessViaProperty) {
            $levelProperty = $settings->get('access_property_level');
            $levelElement
                ->setLabel(sprintf('Access level (managed via property %s)', $levelProperty)); // @translate
            $embargoStartProperty = $settings->get('access_property_embargo_start');
            $embargoStartElementDate
                ->setLabel(sprintf('Embargo start (managed via property %s)', $embargoStartProperty)); // @translate
            $embargoEndProperty = $settings->get('access_property_embargo_end');
            $embargoEndElementDate
                ->setLabel(sprintf('Embargo end (managed via property %s)', $embargoEndProperty)); // @translate
        }

        $resourceName = $resource ? $resource->resourceName() : $this->resourceNameFromRoute();
        if (($resourceName === 'item_sets' && $resource && $resource->itemCount())
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

        if ($showInAdvancedTab) {
            echo $view->formRow($levelElement);
            echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoStartElementTime) . '</div>', $view->formRow($embargoStartElementDate));
            echo preg_replace('~<div class="inputs">(\s*.*\s*)</div>~mU', '<div class="inputs">$1' . $view->formTime($embargoEndElementTime) . '</div>', $view->formRow($embargoEndElementDate));
        }
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
         * @var \Access\Entity\AccessStatus $accessStatus
         * @var \Access\Mvc\Controller\Plugin\AccessStatus $accessStatusForResource
         */
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        $accessViaProperty = (bool) $settings->get('access_property');
        if ($accessViaProperty) {
            return;
        }

        $view = $event->getTarget();
        $vars = $view->vars();

        $resource = $vars->offsetGet('resource');
        $accessStatusForResource = $plugins->get('accessStatus');
        $accessStatus = $accessStatusForResource($resource, true);

        echo $view->partial('common/access-status', [
            'resource' => $resource,
            'accessStatus' => $accessStatus,
        ]);
    }

    public function handleViewBrowseAfterItem(Event $event): void
    {
        // Note: there is no item-set show, but a special case for items browse.
        $view = $event->getTarget();
        // $this->storeSingleAccess($event);
        echo $view->accessRequest($view->items);
    }

    public function handleViewBrowseAfterItemSet(Event $event): void
    {
        $view = $event->getTarget();
        // $this->storeSingleAccess($event);
        echo $view->accessRequest($view->itemSets);
    }

    public function handleViewShowAfterItem(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->item];
        $resources += $view->item->media();
        // $this->storeSingleAccess($event);
        echo $view->accessRequest($resources);
    }

    public function handleViewShowAfterMedia(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->media->item(), $view->media];
        // $this->storeSingleAccess($event);
        echo $view->accessRequest($resources);
    }

    public function handleViewShowAfterItemSet(Event $event): void
    {
        $view = $event->getTarget();
        $resources = [$view->itemSet];
        // $this->storeSingleAccess($event);
        echo $view->accessRequest($resources);
    }

    /**
     * Helper to build search queries.
     *
     * @param Event $event
     */
    public function handleApiSearchQuery(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\QueryBuilder $qb
         * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter
         * @var \Omeka\Api\Request $request
         * @var array $query
         */
        $request = $event->getParam('request');
        $query = $request->getContent();

        if (!empty($query['access'])) {
            $adapter = $event->getTarget();
            $qb = $event->getParam('queryBuilder');
            $expr = $qb->expr();

            // No array.
            if (is_array($query['access'])) {
                $query['access'] = reset($query['access']);
            }

            if (in_array($query['access'], AccessStatusRepresentation::LEVELS)) {
                $accessStatusAlias = $adapter->createAlias();
                $qb
                    ->innerJoin(
                        AccessStatus::class,
                        $accessStatusAlias,
                        \Doctrine\ORM\Query\Expr\Join::WITH,
                        "$accessStatusAlias.id = omeka_root.id"
                    )
                    ->andWhere($expr->eq(
                        "$accessStatusAlias.level",
                        $adapter->createNamedParameter($qb, $query['access'])
                    ));
            } else {
                $qb
                    ->andWhere('"no" = "access"');
            }
        }
    }

    public function handleViewAdvancedSearch(Event $event): void
    {
        $partials = $event->getParam('partials');
        $partials[] = 'common/advanced-search/access';
        $event->setParam('partials', $partials);
    }

    /**
     * Complete the list of search filters for the browse page.
     */
    public function filterSearchFilters(Event $event): void
    {
        $filters = $event->getParam('filters');
        $query = $event->getParam('query', []);

        if (isset($query['access']) && $query['access'] !== '') {
            $services = $this->getServiceLocator();
            $translator = $services->get('MvcTranslator');
            $settings = $services->get('Omeka\Settings');
            $value = $query['access'];
            if ($value) {
                $filterLabel = $translator->translate('Access'); // @translate
                $accessViaProperty = (bool) $settings->get('access_property');
                if ($accessViaProperty) {
                    $accessLevels = $settings->get('access_property_levels', AccessStatusRepresentation::LEVELS);
                    $filters[$filterLabel][] = $accessLevels[$value] ?? $value;
                } else {
                    $filters[$filterLabel][] = $translator->translate($value);
                }
            }
        }

        $event->setParam('filters', $filters);
    }

    public function handleGuestWidgets(Event $event): void
    {
        $widgets = $event->getParam('widgets');
        $viewHelpers = $this->getServiceLocator()->get('ViewHelperManager');
        $translate = $viewHelpers->get('translate');
        $partial = $viewHelpers->get('partial');

        $widget = [];
        $widget['label'] = $translate('Access requests'); // @translate
        $widget['content'] = $partial('guest/site/guest/widget/access-requests');
        $widgets['access'] = $widget;

        $event->setParam('widgets', $widgets);
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/access-admin.css', 'Access'));
        $view->headScript()
            ->appendFile($assetUrl('js/access-admin.js', 'Access'), 'text/javascript', ['defer' => 'defer']);
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Form\ResourceBatchUpdateForm $form
         * @var \Access\Form\Admin\BatchEditFieldset $fieldset
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $form = $event->getTarget();
        $formElementManager = $services->get('FormElementManager');

        $fieldset = $formElementManager->get(BatchEditFieldset::class, [
            'full_access' => (bool) $settings->get('access_full'),
            'resource_type' => $event->getTarget()->getOption('resource_type'),
            'access_via_property' => (bool) $settings->get('access_property'),
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
        $accessViaProperty = (bool) $this->getServiceLocator()->get('Omeka\Settings')->get('access_property');
        if ($accessViaProperty) {
            return;
        }

        $inputFilter = $event->getParam('inputFilter');
        $inputFilter
            ->get('access')
            ->add([
                'name' => 'o-access:level',
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
     * @see \Access\Module::warnConfig()
     * @see \Statistics\Module::warnConfig()
     */
    protected function warnConfig(): void
    {
        $htaccess = file_get_contents(OMEKA_PATH . '/.htaccess');
        if (strpos($htaccess, '/access/')) {
            return;
        }

        $services = $this->getServiceLocator();
        $message = new PsrMessage(
            'To control access to files, you must add a rule in file .htaccess at the root of Omeka. See {link}readme{link_end}.', // @translate
            [
                'link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-Access" target="_blank" rel="noopener">',
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $messenger->addError($message);
    }

    /**
     * Store the query arg "access" to check individual http requests to files.
     *
     * This process avoids to use a specific url to get access by the end user,
     * but requires to check rights each time.
     *
     * @todo This process is not enable for now.
     */
    /*
    protected function storeSingleAccess(Event $event)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $modes = $settings->get('access_modes');
        // Mode "user" is managed directly via authentication.
        $singleModes = array_intersect(['email', 'token'], $modes);
        if (!count($singleModes)) {
            return;
        }

        // The access is refreshed on each browse or show page, but only when
        // the argument is present in the query, so it's not lost for subsequent
        // http requests.
        if (!array_key_exists('access', $_GET)) {
            return;
        }

        $access = $_GET['access'];

        $session = new \Laminas\Session\Container('Access');
        $session->offsetSet('access', $access);
    }
    */

    protected function processUpdateStatus(array $args, bool $useForeground = false): void
    {
        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $args += [
            'recursive' => [],
            'sync' => 'skip',
            'missing' => 'skip',
        ];

        /** @var \Omeka\Job\Dispatcher $dispatcher */
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $useForeground
            ? $dispatcher->dispatch(\Access\Job\AccessStatusUpdate::class, $args, $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class))
            : $dispatcher->dispatch(\Access\Job\AccessStatusUpdate::class, $args);
        $message = new PsrMessage(
            'A job was launched in background to update access statuses according to parameters: ({link_job}job #{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf('<a href="%s">',
                    htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => sprintf('<a href="%1$s">', $this->isModuleActive('Log')
                    ? $urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])
                    : $urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
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

        $ipItemSets = $settings->get('access_ip_item_sets') ?: [];

        $listIps = [];
        $hasError = false;
        foreach ($ipItemSets as $ip => $itemSetIdsString) {
            $allow = [];
            $forbid = [];
            if (!$ip && !$itemSetIdsString) {
                continue;
            } elseif (!$ip || !filter_var(strtok($ip, '/'), FILTER_VALIDATE_IP)) {
                $message = new PsrMessage(
                    'The ip "{ip}" is empty or invalid.', // @translate
                    ['ip' => $ip]
                );
                $messenger->addError($message);
                $hasError = true;
                continue;
            } elseif ($itemSetIdsString) {
                $itemSetIdsArray = array_unique(array_filter(explode(' ', preg_replace('/[^\d-]/', ' ', $itemSetIdsString))));
                if (!$itemSetIdsArray) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for ip {ip} is invalid: they should be numeric ids, optionaly prepended with a "-".', // @translate
                        ['input' => $itemSetIdsString, 'ip' => $ip]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Check for duplicate absolute item set ids.
                $absolutes = array_map('abs', $itemSetIdsArray);
                if (count($absolutes) !== count($itemSetIdsArray)) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for ip {ip} contains duplicate item sets ({list}).', // @translate
                        ['input' => $itemSetIdsString, 'ip' => $ip, 'list' => implode(', ', array_diff($itemSetIdsArray, $absolutes))]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Check valid item sets.
                $itemSetIdsArray = array_combine($absolutes, $itemSetIdsArray);
                $itemSetIdsChecked = $api->search('item_sets', ['id' => $absolutes], ['returnScalar' => 'id'])->getContent();
                if (count($itemSetIdsChecked) !== count($itemSetIdsArray)) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for ip {ip} contains unknown item sets ({list}).', // @translate
                        ['input' => $itemSetIdsString, 'ip' => $ip, 'list' => implode(', ', array_diff_key($itemSetIdsArray, $itemSetIdsChecked))]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Prepare an associative array of item set id and
                // included/excluded item set id.
                $allow = array_keys(array_filter($itemSetIdsArray, fn ($v) => $v > 0));
                $forbid = array_keys(array_filter($itemSetIdsArray, fn ($v) => $v < 0));
            }
            $listIps[$ip] = $this->cidrToRange($ip);
            $listIps[$ip]['allow'] = $allow;
            $listIps[$ip]['forbid'] = $forbid;
        }

        if ($hasError) {
            return false;
        }

        // Move the ip 0.0.0.0/0 as last ip, it will be possible to find a more
        // precise rule if any.
        foreach (['0.0.0.0', '0.0.0.0/0', '::'] as $ip) {
            if (isset($listIps[$ip])) {
                $v = $listIps[$ip];
                unset($listIps[$ip]);
                $listIps[$ip] = $v;
            }
        }

        $settings->set('access_ip_item_sets_by_ip', $listIps);

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

    /**
     * Check idps and item sets and prepare the quick hidden setting.
     */
    protected function prepareAuthSsoIdpItemSets(): bool
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

        $idpItemSets = $settings->get('access_auth_sso_idp_item_sets') ?: [];
        if (!$idpItemSets) {
            return true;
        }

        $idps = $settings->get('singlesignon_idps');
        if (!$idps) {
            $message = new PsrMessage(
                'No idps are defined. Check the config of module Single Sign-On first.' // @translate
            );
            $messenger->addError($message);
            return false;
        }

        $listIdps = [];
        $hasError = false;
        foreach ($idpItemSets as $idpName => $itemSetIdsString) {
            $allow = [];
            $forbid = [];
            $isFederation = $idpName === 'federation';
            // Empty line.
            if (!$idpName && !$itemSetIdsString) {
                continue;
            } elseif (!$isFederation && (!$idpName || !isset($idps[$idpName]))) {
                $message = new PsrMessage(
                    'The idp "{idp}" is empty or not defined in module Single Sign-On.', // @translate
                    ['idp' => $idpName]
                );
                $messenger->addError($message);
                $hasError = true;
                continue;
            } elseif ($itemSetIdsString) {
                $itemSetIdsArray = array_unique(array_filter(explode(' ', preg_replace('/[^\d-]/', ' ', $itemSetIdsString))));
                if (!$itemSetIdsArray) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for idp {idp} is invalid: they should be numeric ids, optionaly prepended with a "-".', // @translate
                        ['input' => $itemSetIdsString, 'idp' => $idpName]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Check for duplicate absolute item set ids.
                $absolutes = array_map('abs', $itemSetIdsArray);
                if (count($absolutes) !== count($itemSetIdsArray)) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for idp {idp} contains duplicate item sets ({list}).', // @translate
                        ['input' => $itemSetIdsString, 'idp' => $idpName, 'list' => implode(', ', array_diff($itemSetIdsArray, $absolutes))]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Check valid item sets.
                $itemSetIdsArray = array_combine($absolutes, $itemSetIdsArray);
                $itemSetIdsChecked = $api->search('item_sets', ['id' => $absolutes], ['returnScalar' => 'id'])->getContent();
                if (count($itemSetIdsChecked) !== count($itemSetIdsArray)) {
                    $message = new PsrMessage(
                        'The item sets list "{input}" for idp {idp} contains unknown item sets ({list}).', // @translate
                        ['input' => $itemSetIdsString, 'idp' => $idpName, 'list' => implode(', ', array_diff($itemSetIdsArray, $itemSetIdsChecked))]
                    );
                    $messenger->addError($message);
                    $hasError = true;
                    continue;
                }
                // Prepare an associative array of item set id and
                // included/excluded item set id.
                $allow = array_keys(array_filter($itemSetIdsArray, fn ($v) => $v > 0));
                $forbid = array_keys(array_filter($itemSetIdsArray, fn ($v) => $v < 0));
            }
            $listIdps[$idpName] = [];
            $listIdps[$idpName]['allow'] = $allow;
            $listIdps[$idpName]['forbid'] = $forbid;
        }

        if ($hasError) {
            return false;
        }

        $settings->set('access_auth_sso_idp_item_sets_by_idp', $listIdps);

        return true;
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

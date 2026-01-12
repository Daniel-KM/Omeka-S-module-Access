<?php declare(strict_types=1);

namespace AccessTest;

use Access\Entity\AccessStatus;
use DateTime;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Entity\Job;
use Omeka\Entity\User;

/**
 * Shared test helpers for Access module tests.
 *
 * Provides methods to create resources with various combinations of:
 * - Visibility (public/private)
 * - Access status (free/reserved/protected/forbidden)
 * - Embargo dates
 * - Resource hierarchy (item sets -> items -> media)
 */
trait AccessTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array List of created resource IDs for cleanup.
     */
    protected $createdResources = [];

    /**
     * @var \Exception|null Last exception from job execution.
     */
    protected $lastJobException;

    /**
     * @var User|null The test guest user.
     */
    protected $guestUser;

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if ($this->services === null) {
            $this->services = $this->getApplication()->getServiceManager();
        }
        return $this->services;
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the settings service.
     */
    protected function getSettings()
    {
        return $this->getServiceLocator()->get('Omeka\Settings');
    }

    /**
     * Get the connection.
     */
    protected function getConnection()
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        // Clear any existing identity first.
        $auth->clearIdentity();
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Login as a specific user.
     */
    protected function loginUser(string $email, string $password = 'test'): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity($email);
        $adapter->setCredential($password);
        $auth->authenticate();
    }

    /**
     * Create and login as a guest user.
     *
     * Creates a user with the 'guest' role if it doesn't exist.
     * Note: The 'guest' role requires the Guest module, but for testing we can
     * create a user with 'researcher' role to simulate a non-admin authenticated user.
     *
     * @param string $role The role to use (default: 'researcher' as guest may not exist).
     * @return User
     */
    protected function loginAsGuest(string $role = 'researcher'): User
    {
        if (!$this->guestUser) {
            $this->guestUser = $this->createGuestUser($role);
        }

        // Clear any existing identity and login as guest.
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
        $auth->getStorage()->write($this->guestUser);

        return $this->guestUser;
    }

    /**
     * Create a guest/researcher user for testing.
     *
     * @param string $role The role (default: 'researcher').
     * @return User
     */
    protected function createGuestUser(string $role = 'researcher'): User
    {
        $entityManager = $this->getEntityManager();

        // Check if user already exists.
        $existingUser = $entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => 'guest@test.example.com']);

        if ($existingUser) {
            return $existingUser;
        }

        $user = new User();
        $user->setEmail('guest@test.example.com');
        $user->setName('Test Guest User');
        $user->setRole($role);
        $user->setIsActive(true);

        $entityManager->persist($user);
        $entityManager->flush();

        $this->createdResources[] = ['type' => 'users', 'id' => $user->getId()];

        return $user;
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Get the currently logged in user.
     */
    protected function getCurrentUser(): ?User
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        return $auth->getIdentity();
    }

    /**
     * Create a test user.
     *
     * @param string $email User email.
     * @param string $role User role (default: researcher).
     * @return User
     */
    protected function createUser(string $email, string $role = 'researcher'): User
    {
        $entityManager = $this->getEntityManager();

        $user = new User();
        $user->setEmail($email);
        $user->setName('Test User');
        $user->setRole($role);
        $user->setIsActive(true);

        $entityManager->persist($user);
        $entityManager->flush();

        $this->createdResources[] = ['type' => 'users', 'id' => $user->getId()];

        return $user;
    }

    /**
     * Create a test item set (collection).
     *
     * @param array $options Options:
     *   - title: string (default: 'Test Item Set')
     *   - is_public: bool (default: true)
     *   - access_level: string|null (free/reserved/protected/forbidden)
     *   - embargo_start: DateTime|null
     *   - embargo_end: DateTime|null
     * @return ItemSetRepresentation
     */
    protected function createItemSet(array $options = []): ItemSetRepresentation
    {
        $title = $options['title'] ?? 'Test Item Set';
        $isPublic = $options['is_public'] ?? true;

        $data = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => $this->getPropertyId('dcterms:title'),
                    '@value' => $title,
                ],
            ],
            'o:is_public' => $isPublic,
        ];

        $response = $this->api()->create('item_sets', $data);
        $itemSet = $response->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        // Set access status if provided.
        if (isset($options['access_level'])) {
            $this->setAccessStatus(
                $itemSet->id(),
                $options['access_level'],
                $options['embargo_start'] ?? null,
                $options['embargo_end'] ?? null
            );
        }

        return $itemSet;
    }

    /**
     * Create a test item.
     *
     * @param array $options Options:
     *   - title: string (default: 'Test Item')
     *   - is_public: bool (default: true)
     *   - item_set: ItemSetRepresentation|int|null
     *   - access_level: string|null (free/reserved/protected/forbidden)
     *   - embargo_start: DateTime|null
     *   - embargo_end: DateTime|null
     * @return ItemRepresentation
     */
    protected function createItem(array $options = []): ItemRepresentation
    {
        $title = $options['title'] ?? 'Test Item';
        $isPublic = $options['is_public'] ?? true;

        $data = [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => $this->getPropertyId('dcterms:title'),
                    '@value' => $title,
                ],
            ],
            'o:is_public' => $isPublic,
        ];

        // Link to item set if provided.
        if (isset($options['item_set'])) {
            $itemSetId = $options['item_set'] instanceof ItemSetRepresentation
                ? $options['item_set']->id()
                : $options['item_set'];
            $data['o:item_set'] = [['o:id' => $itemSetId]];
        }

        $response = $this->api()->create('items', $data);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        // Set access status if provided.
        if (isset($options['access_level'])) {
            $this->setAccessStatus(
                $item->id(),
                $options['access_level'],
                $options['embargo_start'] ?? null,
                $options['embargo_end'] ?? null
            );
        }

        return $item;
    }

    /**
     * Create a test media attached to an item.
     *
     * @param ItemRepresentation|int $item The parent item.
     * @param array $options Options:
     *   - is_public: bool (default: true)
     *   - access_level: string|null (free/reserved/protected/forbidden)
     *   - embargo_start: DateTime|null
     *   - embargo_end: DateTime|null
     *   - ingester: string (default: 'url')
     *   - url: string (default: test image URL)
     * @return MediaRepresentation
     */
    protected function createMedia($item, array $options = []): MediaRepresentation
    {
        $itemId = $item instanceof ItemRepresentation ? $item->id() : $item;
        $isPublic = $options['is_public'] ?? true;
        $ingester = $options['ingester'] ?? 'html';

        $data = [
            'o:item' => ['o:id' => $itemId],
            'o:is_public' => $isPublic,
            'o:ingester' => $ingester,
        ];

        // Use HTML ingester for simplicity (no file upload needed).
        if ($ingester === 'html') {
            $data['html'] = $options['html'] ?? '<p>Test content</p>';
        } elseif ($ingester === 'url') {
            $data['ingest_url'] = $options['url'] ?? 'https://example.com/test.jpg';
        }

        $response = $this->api()->create('media', $data);
        $media = $response->getContent();
        $this->createdResources[] = ['type' => 'media', 'id' => $media->id()];

        // Set access status if provided.
        if (isset($options['access_level'])) {
            $this->setAccessStatus(
                $media->id(),
                $options['access_level'],
                $options['embargo_start'] ?? null,
                $options['embargo_end'] ?? null
            );
        }

        return $media;
    }

    /**
     * Set the access status for a resource using Doctrine.
     *
     * @param int $resourceId Resource ID.
     * @param string $level Access level (free/reserved/protected/forbidden).
     * @param DateTime|null $embargoStart Embargo start date.
     * @param DateTime|null $embargoEnd Embargo end date.
     */
    protected function setAccessStatus(
        int $resourceId,
        string $level,
        ?DateTime $embargoStart = null,
        ?DateTime $embargoEnd = null
    ): void {
        $entityManager = $this->getEntityManager();

        // Get the resource entity.
        $resourceEntity = $entityManager->find(\Omeka\Entity\Resource::class, $resourceId);
        if (!$resourceEntity) {
            throw new \RuntimeException("Resource not found: $resourceId");
        }

        // Check if access status already exists.
        $accessStatus = $entityManager
            ->getRepository(AccessStatus::class)
            ->find($resourceId);

        if ($accessStatus) {
            // Update existing record.
            $accessStatus->setLevel($level);
            $accessStatus->setEmbargoStart($embargoStart);
            $accessStatus->setEmbargoEnd($embargoEnd);
        } else {
            // Create new access status.
            $accessStatus = new AccessStatus();
            $accessStatus->setId($resourceEntity);
            $accessStatus->setLevel($level);
            $accessStatus->setEmbargoStart($embargoStart);
            $accessStatus->setEmbargoEnd($embargoEnd);
            $entityManager->persist($accessStatus);
        }

        $entityManager->flush();
    }

    /**
     * Get the access status for a resource.
     *
     * @param int $resourceId Resource ID.
     * @return AccessStatus|null
     */
    protected function getAccessStatus(int $resourceId): ?AccessStatus
    {
        $entityManager = $this->getEntityManager();
        return $entityManager
            ->getRepository(AccessStatus::class)
            ->find($resourceId);
    }

    /**
     * Get the access level for a resource.
     *
     * @param int $resourceId Resource ID.
     * @return string|null
     */
    protected function getAccessLevel(int $resourceId): ?string
    {
        $entityManager = $this->getEntityManager();

        // Clear the entity manager cache to get fresh data.
        $entityManager->clear();

        $accessStatus = $entityManager
            ->getRepository(AccessStatus::class)
            ->find($resourceId);

        return $accessStatus ? $accessStatus->getLevel() : null;
    }

    /**
     * Check if media content is allowed for the current user.
     *
     * @param MediaRepresentation $media The media to check.
     * @return bool
     */
    protected function isAllowedMediaContent(MediaRepresentation $media): bool
    {
        $plugin = $this->getServiceLocator()
            ->get('ControllerPluginManager')
            ->get('isAllowedMediaContent');
        return $plugin($media);
    }

    /**
     * Get a property ID by term.
     *
     * @param string $term Property term (e.g., 'dcterms:title').
     * @return int|null
     */
    protected function getPropertyId(string $term): ?int
    {
        static $propertyIds = [];

        if (!isset($propertyIds[$term])) {
            $entityManager = $this->getEntityManager();
            [$prefix, $localName] = explode(':', $term);

            $dql = <<<DQL
                SELECT p.id
                FROM Omeka\Entity\Property p
                JOIN p.vocabulary v
                WHERE v.prefix = :prefix AND p.localName = :localName
                DQL;

            $result = $entityManager->createQuery($dql)
                ->setParameter('prefix', $prefix)
                ->setParameter('localName', $localName)
                ->getOneOrNullResult();

            $propertyIds[$term] = $result ? $result['id'] : null;
        }

        return $propertyIds[$term];
    }

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions.
     * @return Job
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $this->lastJobException = null;
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        // Get the owner - need to merge/find in case the entity was detached.
        $identity = $auth->getIdentity();
        $owner = $identity
            ? $entityManager->find(\Omeka\Entity\User::class, $identity->getId())
            : null;

        // Create job entity.
        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($owner);

        $entityManager->persist($job);
        $entityManager->flush();

        // Run job synchronously.
        $jobClass = $job->getClass();
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $this->lastJobException = $e;
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Get the last exception from job execution.
     */
    protected function getLastJobException(): ?\Exception
    {
        return $this->lastJobException;
    }

    /**
     * Configure access modes for testing.
     *
     * @param array $modes List of access modes to enable.
     */
    protected function setAccessModes(array $modes): void
    {
        $this->getSettings()->set('access_modes', $modes);
    }

    /**
     * Configure embargo bypass setting.
     *
     * @param bool $bypass Whether to bypass embargo checks.
     */
    protected function setEmbargoBypass(bool $bypass): void
    {
        $this->getSettings()->set('access_embargo_bypass', $bypass);
    }

    /**
     * Configure what happens when embargo ends.
     *
     * @param string $modeLevel One of 'free', 'under', 'keep'
     * @param string $modeDate One of 'clear', 'keep'
     */
    protected function setEmbargoEndedMode(string $modeLevel, string $modeDate): void
    {
        $this->getSettings()->set('access_embargo_ended_level', $modeLevel);
        $this->getSettings()->set('access_embargo_ended_date', $modeDate);
    }

    /**
     * Get embargo dates for a resource.
     *
     * @param int $resourceId Resource ID.
     * @return array ['embargo_start' => DateTime|null, 'embargo_end' => DateTime|null]
     */
    protected function getEmbargoDates(int $resourceId): array
    {
        $entityManager = $this->getEntityManager();
        $entityManager->clear();

        $accessStatus = $entityManager
            ->getRepository(AccessStatus::class)
            ->find($resourceId);

        if (!$accessStatus) {
            return ['embargo_start' => null, 'embargo_end' => null];
        }

        return [
            'embargo_start' => $accessStatus->getEmbargoStart(),
            'embargo_end' => $accessStatus->getEmbargoEnd(),
        ];
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete resources in reverse order (media -> items -> item_sets -> users).
        // Access statuses will be deleted via ON DELETE CASCADE.
        $resourceTypes = ['media', 'items', 'item_sets', 'users'];
        foreach ($resourceTypes as $type) {
            foreach ($this->createdResources as $resource) {
                if ($resource['type'] === $type) {
                    try {
                        $this->api()->delete($resource['type'], $resource['id']);
                    } catch (\Exception $e) {
                        // Ignore errors during cleanup.
                    }
                }
            }
        }

        $this->createdResources = [];
        $this->guestUser = null;

        // Clear entity manager and close connection to prevent connection leaks.
        if ($this->services) {
            try {
                $entityManager = $this->getEntityManager();
                $entityManager->clear();

                // Close the database connection to prevent "Too many connections" errors.
                $connection = $entityManager->getConnection();
                if ($connection->isConnected()) {
                    $connection->close();
                }
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }

        // Reset services to force new application instance.
        $this->services = null;
    }

    /**
     * Create a complete resource hierarchy for testing.
     *
     * Creates an item set, item, and media with specified access levels.
     *
     * @param array $options Options for each level:
     *   - item_set: array with is_public, access_level, embargo_start, embargo_end
     *   - item: array with is_public, access_level, embargo_start, embargo_end
     *   - media: array with is_public, access_level, embargo_start, embargo_end
     * @return array ['item_set' => ItemSetRepresentation, 'item' => ItemRepresentation, 'media' => MediaRepresentation]
     */
    protected function createResourceHierarchy(array $options = []): array
    {
        $itemSetOptions = $options['item_set'] ?? [];
        $itemOptions = $options['item'] ?? [];
        $mediaOptions = $options['media'] ?? [];

        $itemSet = null;
        if (!empty($itemSetOptions) || isset($options['item_set'])) {
            $itemSet = $this->createItemSet($itemSetOptions);
            $itemOptions['item_set'] = $itemSet;
        }

        $item = $this->createItem($itemOptions);

        $mediaOptions['item'] = $item;
        $media = $this->createMedia($item, $mediaOptions);

        return [
            'item_set' => $itemSet,
            'item' => $item,
            'media' => $media,
        ];
    }

    /**
     * Assert that media content is allowed for the current user.
     */
    protected function assertMediaContentAllowed(MediaRepresentation $media, string $message = ''): void
    {
        $this->assertTrue(
            $this->isAllowedMediaContent($media),
            $message ?: 'Media content should be allowed'
        );
    }

    /**
     * Assert that media content is denied for the current user.
     */
    protected function assertMediaContentDenied(MediaRepresentation $media, string $message = ''): void
    {
        $this->assertFalse(
            $this->isAllowedMediaContent($media),
            $message ?: 'Media content should be denied'
        );
    }

    /**
     * Get a date in the past.
     */
    protected function getPastDate(int $daysAgo = 30): DateTime
    {
        return new DateTime("-{$daysAgo} days");
    }

    /**
     * Get a date in the future.
     */
    protected function getFutureDate(int $daysAhead = 30): DateTime
    {
        return new DateTime("+{$daysAhead} days");
    }
}

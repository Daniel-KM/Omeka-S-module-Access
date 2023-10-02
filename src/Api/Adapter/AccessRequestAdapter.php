<?php declare(strict_types=1);

namespace Access\Api\Adapter;

use Access\Entity\AccessRequest;
use DateTime;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class AccessRequestAdapter extends AbstractEntityAdapter
{
    /**
     * @var array
     */
    protected $statuses = [
        AccessRequest::STATUS_NEW => AccessRequest::STATUS_NEW,
        AccessRequest::STATUS_RENEW => AccessRequest::STATUS_RENEW,
        AccessRequest::STATUS_ACCEPTED => AccessRequest::STATUS_ACCEPTED,
        AccessRequest::STATUS_REJECTED => AccessRequest::STATUS_REJECTED,
    ];

    protected $sortFields = [
        'id' => 'id',
        'user_id' => 'user',
        'email' => 'email',
        'token' => 'token',
        'status' => 'status',
        'enabled' => 'enabled',
        'temporal' => 'temporal',
        'start' => 'start',
        'end' => 'end',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'user' => 'user',
        'email' => 'email',
        'token' => 'token',
        'status' => 'status',
        'enabled' => 'enabled',
        'temporal' => 'temporal',
        'start' => 'start',
        'end' => 'end',
        'fields' => 'fields',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'access_requests';
    }

    public function getRepresentationClass()
    {
        return \Access\Api\Representation\AccessRequestRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Access\Entity\AccessRequest::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        // Don't allow a non-admin user to query other users accesses.
        // TODO Add a db filter.
        $isAdmin = $this->isAdmin();
        if (!$isAdmin) {
            $query['access'] = isset($query['access']) && $query['access'] !== ''
                ? (is_array($query['access']) ? $query['access'] : [$query['access']])
                : [];
            $query['access'][] = $query['email'] ?? null;
            $query['access'][] = $query['token'] ?? null;
            $query['access'] = array_filter($query['access']);
            $query['access'] = array_diff($query['access'], array_filter($query['access'], 'is_numeric'));
            unset($query['user_id'], $query['email'], $query['token']);
            $user = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
            if ($user) {
                $query['access'] = array_diff($query['access'], array_filter($query['access'], 'is_numeric'));
                $query['access'][] = $user->getId();
            } else {
                $query['user_id'] = 0;
            }
        }

        $hasArg = false;

        if (isset($query['resource_id'])) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $query['resource_id'] = array_filter(array_map('intval', $query['resource_id']));
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.resources',
                $resourceAlias
            );
            if (!$query['resource_id']) {
                $qb->andWhere($expr->eq(
                    $resourceAlias . '.id',
                    $this->createNamedParameter($qb, 0)
                ));
            } else {
                $qb->andWhere($expr->in(
                    $resourceAlias . '.id',
                    $this->createNamedParameter($qb, $query['resource_id'])
                ));
            }
        }

        if (isset($query['user_id'])) {
            $hasArg = true;
            $userAlias = $this->createAlias();
            if (empty($query['user_id'])) {
                $qb->leftJoin(
                    'omeka_root.user',
                    $userAlias
                );
                $qb->andWhere($expr->isNull($userAlias . '.id', ));
            } else {
                $qb->innerJoin(
                    'omeka_root.user',
                    $userAlias
                );
                $qb->andWhere($expr->eq(
                    $userAlias . '.id',
                    $this->createNamedParameter($qb, $query['user_id'])
                ));
            }
        }

        if (isset($query['email'])) {
            $hasArg = true;
            $qb->andWhere($expr->eq(
                'omeka_root.email',
                $this->createNamedParameter($qb, $query['email'])
            ));
        }

        if (isset($query['token'])) {
            $hasArg = true;
            $qb->andWhere($expr->eq(
                'omeka_root.token',
                $this->createNamedParameter($qb, $query['token'])
            ));
        }

        if (isset($query['access']) && $query['access']) {
            $hasArg = true;
            $accesses = is_array($query['access']) ? $query['access'] : [$query['access']];
            $accesses = array_filter($accesses);
            $or = [];
            foreach ($accesses as $access) {
                if (is_numeric($access)) {
                    $or[] = $expr->eq('omeka_root.user', $this->createNamedParameter($qb, $access));
                } elseif (strpos($access, '@')) {
                    $or[] = $expr->eq('omeka_root.email', $this->createNamedParameter($qb, $access));
                } else {
                    $or[] = $expr->eq('omeka_root.token', $this->createNamedParameter($qb, $access));
                }
            }
            if (count($or) === 1) {
                $qb->andWhere(reset($or));
            } elseif (count($or)) {
                $qb->andWhere($expr->orX(...$or));
            } else {
                // Invalid query does not return any output.
                $qb->andWhere($expr->eq(
                    'omeka_root.id',
                    $this->createNamedParameter($qb, 0)
                ));
            }
        }

        if (isset($query['status'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.status',
                $this->createNamedParameter($qb, $query['status'])
            ));
        }

        if (isset($query['enabled']) && (is_numeric($query['enabled']) || is_bool($query['enabled']))) {
            $qb->andWhere($expr->eq(
                'omeka_root.enabled',
                $this->createNamedParameter($qb, (bool) $query['enabled'])
            ));
        }

        if (isset($query['temporal']) && (is_numeric($query['temporal']) || is_bool($query['temporal']))) {
            $qb->andWhere($expr->eq(
                'omeka_root.temporal',
                $this->createNamedParameter($qb, (bool) $query['temporal'])
            ));
        }

        if (isset($query['start'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.start',
                $this->createNamedParameter($qb, $query['start'])
            ));
        }

        if (isset($query['end'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.end',
                $this->createNamedParameter($qb, $query['end'])
            ));
        }

        if (isset($query['created'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.created',
                $this->createNamedParameter($qb, $query['created'])
            ));
        }

        if (isset($query['modified'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.modified',
                $this->createNamedParameter($qb, $query['modified'])
            ));
        }

        // Do not return anything when there is no arg for non-admin.
        if (!$isAdmin && !$hasArg) {
            $qb->andWhere($expr->isNull('omeka_root.id'));
        }
    }

    public function validateRequest(Request $request, ErrorStore $errorStore): void
    {
        $operation = $request->getOperation();
        $data = $request->getContent();

        if (array_key_exists('o:resource', $data)
            && !is_array($data['o:resource'])
        ) {
            $errorStore->addError('o:resource', 'The requested resources must be an array'); // @translate
        }

        if ($operation === Request::CREATE
            && !$request->getValue('o:resource')
        ) {
            $errorStore->addError('o:resource', 'The requested resources cannot be empty.'); // @translate
        }

        if (array_key_exists('o:status', $data)
            && (!is_string($data['o:status']) || !isset($this->statuses[$data['o:status']]))
        ) {
            $errorStore->addError('o:status', 'The status must be one of the list of statuses.'); // @translate
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \Access\Entity\AccessRequest $entity */

        $data = $request->getContent();

        // Only admins can update requests and manage specific params of the
        // requests (resources, status and dates).
        $operation = $request->getOperation();

        $identity = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        $isAdmin = $this->isAdmin();
        $isAdminOrCreate = $isAdmin || $operation === $request::CREATE;

        if (!$isAdmin) {
            if ($identity) {
                $data['o:user'] = $identity;
                $data['o:email'] = null;
                $data['o:token'] = null;
            } else {
                $data['o:user'] = null;
            }
        }

        if (isset($data['o:resource']) && is_array($data['o:resource']) && $isAdminOrCreate) {
            $resources = $entity->getResources();
            if (!count($data['o:resource'])) {
                $resources->clear();
            } else {
                $existingResourceIds = [];
                foreach ($data['o:resource'] as $resource) {
                    if (is_numeric($resource)) {
                        $resource = $this->getAdapter('resources')->findEntity($resource);
                    } elseif (is_array($resource)) {
                        $resourceId = isset($resource['o:id']) ? (int) $resource['o:id'] : null;
                        $resource = $resourceId ? $this->getAdapter('resources')->findEntity($resourceId) : null;
                    } elseif ($resource instanceof \Omeka\Entity\Resource) {
                        $resource = $resource;
                    } elseif ($resource instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
                        $resource = $this->getAdapter('resources')->findEntity($resource->id());
                    } else {
                        $resource = null;
                    }
                    if ($resource) {
                        $resourceId = $resource->getId();
                        if (isset($existingResourceIds[$resourceId])) {
                            continue;
                        }
                        $existingResourceIds[$resourceId] = $resourceId;
                        $resources->add($resource);
                    }
                }
                // Remove old resource ids.
                foreach ($resources as $key => $resource) {
                    if (!isset($existingResourceIds[$resource->getId()])) {
                        $resources->remove($key);
                    }
                }
            }
        }

        if (array_key_exists('o:user', $data) && $data['o:user'] !== '' && $isAdminOrCreate) {
            if ($data['o:user'] === null) {
                $user = null;
            } elseif (is_numeric($data['o:user'])) {
                $user = $this->getAdapter('users')->findEntity($data['o:user']);
            } elseif (is_array($data['o:user'])) {
                $userId = isset($data['o:user']['o:id']) ? (int) $data['o:user']['o:id'] : null;
                $user = $userId ? $this->getAdapter('users')->findEntity($userId) : null;
            } elseif ($data['o:user'] instanceof \Omeka\Entity\User) {
                $user = $data['o:user'];
            } elseif ($data['o:user'] instanceof \Omeka\Api\Representation\UserRepresentation) {
                $user = $this->getAdapter('users')->findEntity($data['o:user']->id());
            } else {
                $user = null;
            }
            $entity->setUser($user);
        }

        if (array_key_exists('o:email', $data) && $data['o:email'] !== '' && $isAdminOrCreate) {
            $entity->setEmail($data['o:email']);
        }

        // Don't update an existing token.
        // Only an admin can create a token.
        if (array_key_exists('o-access:token', $data) && $data['o-access:token'] !== '' && !$entity->getToken() && $isAdmin) {
            $entity->setToken($data['o-access:token']);
        }

        // Only admin can modify status and set something else than "new".
        // TODO Check status earlier and add Acl.
        if (isset($data['o:status']) && $data['o:status'] !== '' && is_string($data['o:status']) && isset($this->statuses[$data['o:status']]) && $isAdminOrCreate) {
            if (!$isAdmin && !in_array($data['o:status'], [AccessRequest::STATUS_NEW, AccessRequest::STATUS_RENEW])) {
                $data['o:status'] = AccessRequest::STATUS_NEW;
            }
            $entity->setStatus($data['o:status']);
        }

        if (isset($data['o-access:recursive']) && in_array($data['o-access:recursive'], [false, true, 0, 1, '0', '1'], true) && $isAdminOrCreate) {
            $entity->setRecursive((bool) $data['o-access:recursive']);
        }

        /*
        if (isset($data['o-access:enabled']) && $data['o-access:enabled'] !== '' && in_array($data['o-access:enabled'], [0, 1, true, false])) {
            $entity->setEnabled((bool) $data['o-access:enabled']);
        }
        */
        $entity->setEnabled($entity->getStatus() === 'accepted');

        if (array_key_exists('o-access:start', $data) && $data['o-access:start'] !== '' && $isAdminOrCreate) {
            $startDate = null;
            if (is_string($data['o-access:start'])) {
                try {
                    $startDate = new DateTime($data['o-access:start']);
                } catch (\Exception $e) {
                }
            } elseif ($data['o-access:start'] instanceof DateTime) {
                $startDate = $data['o-access:start'];
            }
            $entity->setStart($startDate);
        }

        if (array_key_exists('o-access:end', $data) && $data['o-access:end'] !== '' && $isAdminOrCreate) {
            $endDate = null;
            if (is_string($data['o-access:end'])) {
                try {
                    $endDate = new DateTime($data['o-access:end']);
                } catch (\Exception $e) {
                }
            } elseif ($data['o-access:end'] instanceof DateTime) {
                $endDate = $data['o-access:end'];
            }
            $entity->setEnd($endDate);
        }

        /*
        if (isset($data['o-access:temporal']) && $data['o-access:temporal'] !== '' && in_array($data['o-access:temporal'], [0, 1, true, false])) {
            $entity->setTemporal((bool) $data['o-access:temporal']);
        }
        */
        $entity->setTemporal($entity->getStart() || $entity->getEnd());

        $name = $data['o:name'] ?? null;
        if (!$entity->getUser() && $name) {
            $entity->setName($name);
        }

        $message = $data['o:message'] ?? null;
        if ($message = trim(strip_tags((string) $message))) {
            $entity->setMessage($message);
        }

        $fields = $data['o-access:fields'] ?? null;
        if ($fields && is_array($fields)) {
            $entity->setFields($fields);
        }

        $this->updateTimestamps($request, $entity);
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \Access\Entity\AccessRequest $entity */
        $resources = $entity->getResources();
        if (!$resources->count()) {
            $errorStore->addError('o:resource', 'At least one resource must be requested.'); // @translate
        }

        if (!isset($this->statuses[$entity->getStatus()])) {
            $errorStore->addError('o:status', 'The status must be one of the list of statuses.'); // @translate
        }
    }

    protected function isAdmin(): bool
    {
        $rolesAdmins = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];
        $identity = $this->getServiceLocator()->get('Omeka\AuthenticationService')->getIdentity();
        return $identity && in_array($identity->getRole(), $rolesAdmins);
    }
}

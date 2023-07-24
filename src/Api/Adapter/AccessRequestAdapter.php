<?php declare(strict_types=1);

namespace AccessResource\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class AccessRequestAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'user' => 'user',
        'status' => 'status',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'resource' => 'resource',
        'user' => 'user',
        'status' => 'status',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'access_requests';
    }

    public function getRepresentationClass()
    {
        return \AccessResource\Api\Representation\AccessRequestRepresentation::class;
    }

    public function getEntityClass()
    {
        return \AccessResource\Entity\AccessRequest::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['resource_id'])) {
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.resource',
                $resourceAlias
            );
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['user_id'])) {
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'omeka_root.user',
                $userAlias
            );
            $qb->andWhere($expr->eq(
                $userAlias . '.id',
                $this->createNamedParameter($qb, $query['user_id'])
            ));
        }

        if (isset($query['status'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.status',
                $this->createNamedParameter($qb, $query['status'])
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
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        /** @var \AccessResource\Entity\AccessRequest $entity */
        $data = $request->getContent();

        if (isset($data['o:resource']) && $data['o:resource'] !== '') {
            $resource = null;
            if (is_numeric($data['o:resource'])) {
                $resource = $this->getAdapter('resources')->findEntity($data['o:resource']);
            } elseif (is_array($data['o:resource'])) {
                $resourceId = isset($data['o:resource']['o:id']) ? (int) $data['o:resource']['o:id'] : null;
                $resource = $resourceId ? $this->getAdapter('resources')->findEntity($resourceId) : null;
            } elseif ($data['o:resource'] instanceof \Omeka\Entity\Resource) {
                $resource = $data['o:resource'];
            } elseif ($data['o:resource'] instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation) {
                $resource = $this->getAdapter('resources')->findEntity($data['o:resource']->id());
            }
            if ($resource) {
                $entity->setResource($resource);
            }
        }

        if (isset($data['o:user']) && $data['o:user'] !== '') {
            $user = null;
            if (is_numeric($data['o:user'])) {
                $user = $this->getAdapter('users')->findEntity($data['o:user']);
            } elseif (is_array($data['o:user'])) {
                $userId = isset($data['o:user']['o:id']) ? (int) $data['o:user']['o:id'] : null;
                $user = $userId ? $this->getAdapter('users')->findEntity($userId) : null;
            } elseif ($data['o:user'] instanceof \Omeka\Entity\User) {
                $user = $data['o:user'];
            } elseif ($data['o:user'] instanceof \Omeka\Api\Representation\UserRepresentation) {
                $user = $this->getAdapter('users')->findEntity($data['o:user']->id());
            }
            if (!$user) {
                $user = $this->getServiceLocator()
                    ->get('Omeka\AuthenticationService')->getIdentity();
            }
            if ($user) {
                $entity->setUser($user);
            }
        }

        if (isset($data['o:status']) && !$data['o:status'] === '' && is_string($data['o:status'])) {
            $entity->setStatus($data['o:status']);
        }

        $this->updateTimestamps($request, $entity);
    }
}

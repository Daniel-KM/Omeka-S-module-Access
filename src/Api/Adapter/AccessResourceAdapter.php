<?php
namespace AccessResource\Api\Adapter;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class AccessResourceAdapter extends AbstractEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'resource' => 'resource',
        'user' => 'user',
        'enabled' => 'enabled',
        'created' => 'created',
        'modified' => 'modified',
    ];

    public function getResourceName()
    {
        return 'access_resources';
    }

    public function getRepresentationClass()
    {
        return \AccessResource\Api\Representation\AccessResourceRepresentation::class;
    }

    public function getEntityClass()
    {
        return \AccessResource\Entity\AccessResource::class;
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore)
    {
        /** @var \AccessResource\Entity\AccessResource $entity */
        $data = $request->getContent();
        foreach ($data as $key => $value) {
            $method = 'set' . ucfirst(Inflector::camelize($key));
            if (!method_exists($entity, $method)) {
                continue;
            }
            $entity->$method($value);
        }

        if (isset($data['resource_id'])) {
            $resource = $this->getAdapter('resources')->findEntity($data['resource_id']);
            $entity->setResource($resource);
        }

        if (isset($data['user_id'])) {
            $user = $this->getAdapter('users')->findEntity($data['user_id']);
            $entity->setUser($user);
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        $expr = $qb->expr();

        if (isset($query['id'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.id',
                $this->createNamedParameter($qb, $query['id'])
            ));
        }

        if (isset($query['resource_id'])) {
            $resourceAlias = $this->createAlias();
            $qb->innerJoin(
                $this->getEntityClass() . '.resource',
                $resourceAlias
            );
            if (!is_array($query['resource_id'])) {
                $query['resource_id'] = [$query['resource_id']];
            }
            $qb->andWhere($expr->in(
                $resourceAlias . '.id',
                $this->createNamedParameter($qb, $query['resource_id'])
            ));
        }

        if (isset($query['user_id'])) {
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                $this->getEntityClass() . '.user',
                $userAlias
            );
            $qb->andWhere($expr->eq(
                $userAlias . '.id',
                $this->createNamedParameter($qb, $query['user_id'])
            ));
        }

        if (isset($query['enabled'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.enabled',
                $this->createNamedParameter($qb, $query['enabled'])
            ));
        }

        if (isset($query['modified'])) {
            $qb->andWhere($expr->eq(
                $this->getEntityClass() . '.modified',
                $this->createNamedParameter($qb, $query['modified'])
            ));
        }
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Api\Adapter;

use DateTime;
use Doctrine\Inflector\InflectorFactory;
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
        'temporal' => 'temporal',
        'start_date' => 'startDate',
        'end_date' => 'endDate',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'resource' => 'resource',
        'user' => 'user',
        'token' => 'token',
        'enabled' => 'enabled',
        'temporal' => 'temporal',
        'start_date' => 'startDate',
        'end_date' => 'endDate',
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

        if (isset($query['token'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.token',
                $this->createNamedParameter($qb, $query['token'])
            ));
        }

        if (isset($query['enabled'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.enabled',
                $this->createNamedParameter($qb, $query['enabled'])
            ));
        }

        if (isset($query['temporal'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.temporal',
                $this->createNamedParameter($qb, $query['temporal'])
            ));
        }

        if (isset($query['start_date'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.startDate',
                $this->createNamedParameter($qb, $query['start_date'])
            ));
        }

        if (isset($query['end_date'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.endDate',
                $this->createNamedParameter($qb, $query['end_date'])
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
        /** @var \AccessResource\Entity\AccessResource $entity */
        $data = $request->getContent();
        $inflector = InflectorFactory::create()->build();
        foreach ($data as $key => $value) {
            if (in_array($key, ['o:resource', 'o:user', 'o-access:startDate', 'o-access:endDate', 'o:created', 'o:modified'])) {
                continue;
            }
            $posColon = strpos($key, ':');
            $keyName = $posColon === false ? $key : substr($key, $posColon + 1);
            $method = 'set' . ucfirst($inflector->camelize($keyName));
            if (!method_exists($entity, $method)) {
                continue;
            }
            $entity->$method($value);
        }

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

        if (array_key_exists('o:user', $data) && $data['o:user'] !== '') {
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
            $entity->setUser($user);
        }

        if (array_key_exists('o-access:startDate', $data)) {
            $startDate = null;
            if (is_string($data['o-access:startDate'])) {
                $startDate = new DateTime($data['o-access:startDate']);
            } elseif ($data['o-access:startDate'] instanceof DateTime) {
                $startDate = $data['o-access:startDate'];
            }
            $entity->setStartDate($startDate);
        }

        if (array_key_exists('o-access:endDate', $data)) {
            $endDate = null;
            if (is_string($data['o-access:endDate'])) {
                $endDate = new DateTime($data['o-access:endDate']);
            } elseif ($data['o-access:endDate'] instanceof DateTime) {
                $endDate = $data['o-access:endDate'];
            }
            $entity->setEndDate($endDate);
        }

        $this->updateTimestamps($request, $entity);
    }
}

<?php declare(strict_types=1);

namespace Access\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;

/**
 * Store access to reserved resources for individual users.
 *
 * @todo Store email or token too.
 * @todo Remove this whole feature?
 *
 * @Entity
 */
class AccessLog extends AbstractEntity
{
    /**#@+
     * Access type.
     *
     * TYPE_ACCESS: A user or anonymous acceded a reserved resource.
     * TYPE_REQUEST: A user or anonymous request access to a reserved resource.
     */
    const TYPE_ACCESS = 'access'; // @translate
    const TYPE_REQUEST = 'request'; // @translate
    /**#@-*/

    /**
     * @var int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * This is a log, so no need to join User.
     *
     * @var int
     *
     * @Column(
     *     type="integer",
     *     nullable=false
     * )
     */
    protected $userId;

    /**
     * May be an access resource (create, update, delete) or an access request
     * for other actions.
     *
     * @var int
     *
     * @Column(
     *     type="integer",
     *     nullable=false
     * )
     */
    protected $accessId;

    /**
     * This is "access" for access_resource or "request" for access_request.
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=7
     * )
     */
    protected $accessType;

    /**
     * "update", "create", "delete", "no_access", "accessed", "update_to_" + action.
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=31
     * )
     */
    protected $action;

    /**
     * @var \DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=false,
     *     options={
     *         "default": "CURRENT_TIMESTAMP"
     *     }
     * )
     */
    protected $date;

    public function getId()
    {
        return $this->id;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setAccessId(int $accessId): self
    {
        $this->accessId = $accessId;
        return $this;
    }

    public function getAccessId(): int
    {
        return $this->accessId;
    }

    public function setAccessType(string $accessType): self
    {
        $this->accessType = $accessType;
        return $this;
    }

    public function getAccessType(): string
    {
        return $this->accessType;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setDate(DateTime $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getDate(): DateTime
    {
        return $this->date;
    }
}

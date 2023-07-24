<?php declare(strict_types=1);

namespace AccessResource\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;

/**
 * Store access to restricted resources for individual users.
 *
 * @Entity
 */
class AccessLog extends AbstractEntity
{
    /**#@+
     * Access type.
     *
     * TYPE_ACCESS: A user or anonymous acceded a restricted resource.
     * TYPE_REQUEST: A user or anonymous request access to a restricted resource.
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
     * @var int
     *
     * @Column(
     *     type="integer"
     * )
     */
    protected $recordId;

    /**
     * This is "access" or "request".
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=7
     * )
     */
    protected $type;

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

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setRecordId(int $recordId): self
    {
        $this->recordId = $recordId;
        return $this;
    }

    public function getRecordId(): ?int
    {
        return $this->recordId;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
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

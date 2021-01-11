<?php declare(strict_types=1);

namespace AccessResource\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * @Entity
 */
class AccessLog extends AbstractEntity
{
    const TYPE_ACCESS = 'access'; // @translate
    const TYPE_REQUEST = 'request'; // @translate

    /**
     * @var int
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @todo Use simple id? This is a log.
     * @var \Omeka\Entity\User
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\User"
     * )
     * @JoinColumn(
     *     onDelete="SET NULL",
     *    nullable=true
     * )
     */
    protected $user;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $action;

    /**
     * @todo Use true record as id and make it nullable? Or deletable? (this is the access or the request id).
     * @var int
     * @Column(
     *     type="integer"
     * )
     */
    protected $recordId;

    /**
     * This is "access" or "request".
     * @var string
     * @Column(
     *     type="string",
     *     length=190
     * )
     */
    protected $type;

    /**
     * @var \DateTime
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $date;

    public function getId()
    {
        return $this->id;
    }

    public function setUser(?User $user = null): self
    {
        $this->user = $user;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setDate(?DateTime $date = null): self
    {
        $this->date = $date;
        return $this;
    }

    public function getDate(): ?DateTime
    {
        return $this->date;
    }
}

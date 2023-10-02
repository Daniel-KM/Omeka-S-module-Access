<?php declare(strict_types=1);

namespace Access\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * @todo Clarify options enabled and temporal. Temporal is useful only with a cron task.
 *
 * @Entity
 * @Table(
 *      indexes={
 *          @Index(
 *              columns={"token"}
 *          )
 *      }
 * )
 */
class AccessRequest extends AbstractEntity
{
    /**#@+
     * Access type.
     *
     * STATUS_NEW: New request.
     * STATUS_RENEW: Renew request for prolongation or after reject.
     * STATUS_ACCEPTED: Accepted by admin.
     * STATUS_REJECTED: Reject by admin.
     */
    const STATUS_NEW = 'new'; // @translate
    const STATUS_RENEW = 'renew'; // @translate
    const STATUS_ACCEPTED = 'accepted'; // @translate
    const STATUS_REJECTED = 'rejected'; // @translate
    /**#@-*/

    /**
     * @int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var \Omeka\Entity\User
     *
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\User"
     * )
     * @JoinColumn(
     *     onDelete="CASCADE",
     *     nullable=true
     * )
     */
    protected $user;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=190,
     *     nullable=true
     * )
     */
    protected $email;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=16,
     *     nullable=true
     * )
     */
    protected $token;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     nullable=false,
     *     length=8,
     *     options={
     *         "default": "new"
     *     }
     * )
     */
    protected $status = self::STATUS_NEW;

    /**
     * @var bool
     *
     * @Column(
     *     name="`recursive`",
     *     type="boolean",
     *     nullable=false,
     *     options={
     *         "default": false
     *     }
     * )
     */
    protected $recursive = false;

    /**
     * @var bool
     *
     * Shortcut to check if the request is accepted without checking status.
     * For now, automatically set when updated.
     *
     * @Column(
     *     type="boolean",
     *     nullable=false,
     *     options={
     *         "default": false
     *     }
     * )
     */
    protected $enabled = false;

    /**
     * @var bool
     *
     * Shortcut to check if the request is temporal without checking dates.
     * Require a cron task. For now, automatically set when there is a date.
     *
     * @Column(
     *     type="boolean",
     *     nullable=false,
     *     options={
     *         "default": false
     *     }
     * )
     */
    protected $temporal = false;

    /**
     * @var \DateTime
     *
     * @Column(
     *     name="`start`",
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $start;

    /**
     * @var \DateTime
     *
     * @Column(
     *     name="`end`",
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $end;

    /**
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=190,
     *     nullable=true
     * )
     */
    protected $name;

    /**
     * @var string
     *
     * @Column(
     *     type="text",
     *     nullable=true
     * )
     */
    protected $message;

    /**
     * @var array
     *
     * @Column(
     *     type="json_array",
     *     nullable=true
     * )
     */
    protected $fields;

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
    protected $created;

    /**
     * @var \DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $modified;

    /**
     * This relation cannot be set in the core, so it is unidirectional.
     *
     * @ManyToMany(
     *     targetEntity="Omeka\Entity\Resource",
     *     indexBy="id"
     * )
     * @JoinTable(
     *     name="access_resource"
     * )
     */
    protected $resources;

    public function __construct()
    {
        $this->resources = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setToken(?string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setRecursive($recursive): self
    {
        $this->recursive = (bool) $recursive;
        return $this;
    }

    public function getRecursive(): bool
    {
        return $this->recursive;
    }

    public function setEnabled($enabled): self
    {
        $this->enabled = (bool) $enabled;
        return $this;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setTemporal($temporal): self
    {
        $this->temporal = (bool) $temporal;
        return $this;
    }

    public function getTemporal(): bool
    {
        return $this->temporal;
    }

    public function setStart(?DateTime $start = null): self
    {
        $this->start = $start;
        return $this;
    }

    public function getStart(): ?DateTime
    {
        return $this->start;
    }

    public function setEnd(?DateTime $end = null): self
    {
        $this->end = $end;
        return $this;
    }

    public function getEnd(): ?DateTime
    {
        return $this->end;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setFields(?array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    public function getFields(): ?array
    {
        return $this->fields;
    }

    public function setCreated(DateTime $dateTime): self
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setModified(?DateTime $dateTime): self
    {
        $this->modified = $dateTime;
        return $this;
    }

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }
}

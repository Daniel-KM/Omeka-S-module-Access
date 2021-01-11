<?php declare(strict_types=1);

namespace AccessResource\Entity;

use DateTime;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;
use Omeka\Entity\User;

/**
 * @Entity
 * @HasLifecycleCallbacks
 */
class AccessRequest extends AbstractEntity
{
    const STATUS_NEW = 'new'; // @translate
    const STATUS_RENEW = 'renew'; // @translate
    const STATUS_ACCEPTED = 'accepted'; // @translate
    const STATUS_REJECTED = 'rejected'; // @translate

    /**
     * @int
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var \Omeka\Entity\Resource
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Resource"
     * )
     * @JoinColumn(
     *     onDelete="CASCADE",
     *     nullable=false
     * )
     */
    protected $resource;

    /**
     * @var \Omeka\Entity\User
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\User"
     * )
     * @JoinColumn(
     *     onDelete="CASCADE",
     *     nullable=false
     * )
     */
    protected $user;

    /**
     * @var string
     * @Column(
     *     type="string",
     *     nullable=false,
     *     length=190,
     *     options={"default": "new"}
     * )
     */
    protected $status = self::STATUS_NEW;

    /**
     * @var \DateTime
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * @var \DateTime
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $modified;

    public function getId()
    {
        return $this->id;
    }

    public function setResource(Resource $resource): self
    {
        $this->resource = $resource;
        return $this;
    }

    public function getResource(): Resource
    {
        return $this->resource;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
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

    public function setCreated(DateTime $dateTime): self
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setModified(?DateTime $dateTime = null): self
    {
        $this->modified = $dateTime;
        return $this;
    }

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }

    /**
     * @PrePersist
     */
    public function prePersist(LifecycleEventArgs $eventArgs): void
    {
        $this->created = $this->modified = new DateTime('now');
    }

    /**
     * @PreUpdate
     */
    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        $this->modified = new DateTime('now');
    }
}

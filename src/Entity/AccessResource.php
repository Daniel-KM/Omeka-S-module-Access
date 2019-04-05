<?php
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
class AccessResource extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
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
     *     onDelete="SET NULL",
     *     nullable=true
     * )
     */
    protected $user;

    /**
     * @var string
     * @Column(type="string", nullable=true)
     */
    protected $token;

    /**
     * @var bool
     * @Column(type="boolean", nullable=false, options={"default": false})
     */
    protected $enabled = false;

    /**
     * @var bool
     * @Column(type="boolean", nullable=false, options={"default": false})
     */
    protected $temporal = false;

    /**
     * @var DateTime
     * @Column(type="datetime", nullable=true)
     */
    protected $startDate;

    /**
     * @var DateTime
     * @Column(type="datetime", nullable=true)
     */
    protected $endDate;

    /**
     * @var \DateTime
     * @Column(type="datetime")
     */
    protected $created;

    /**
     * @var \DateTime
     * @Column(type="datetime", nullable=true)
     */
    protected $modified;

    public function getId()
    {
        return $this->id;
    }

    public function setResource(Resource $resource)
    {
        $this->resource = $resource;
        return $this;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setUser(User $user = null)
    {
        $this->user = $user;
        return $this;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setToken($token)
    {
        $this->token = $token;
        return $this;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getEnabled()
    {
        return $this->enabled;
    }

    public function setTemporal($temporal)
    {
        $this->temporal = $temporal;
        return $this;
    }

    public function getTemporal()
    {
        return $this->temporal;
    }

    public function setStartDate(DateTime $startDate = null)
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getStartDate()
    {
        return $this->startDate;
    }

    public function setEndDate(DateTime $endDate = null)
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getEndDate()
    {
        return $this->endDate;
    }

    public function setCreated(DateTime $dateTime)
    {
        $this->created = $dateTime;
        return $this;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setModified(DateTime $dateTime = null)
    {
        $this->modified = $dateTime;
        return $this;
    }

    public function getModified()
    {
        return $this->modified;
    }

    /**
     * @PrePersist
     */
    public function prePersist(LifecycleEventArgs $eventArgs)
    {
        $this->created = $this->modified = new DateTime('now');
    }

    /**
     * @PreUpdate
     */
    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $this->modified = new \DateTime('now');
    }
}

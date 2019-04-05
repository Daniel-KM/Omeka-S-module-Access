<?php

namespace AccessResource\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * @Entity
 */
class AccessLog extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
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
     *     onDelete="SET NULL"),
     *    nullable=true
     * )
     */
    protected $user;

    /**
     * @var string
     * @Column(type="string")
     */
    protected $action;

    /**
     * @todo Use true record as id and make it nullable? Or deletable? (this is the access or the request id).
     * @var int
     * @Column(type="integer")
     */
    protected $recordId;

    /**
     * This is "access" or "request".
     * @var string
     * @Column(type="string")
     */
    protected $type;

    /**
     * @var \DateTime
     * @Column(type="datetime", nullable=true)
     */
    protected $date;

    public function getId()
    {
        return $this->id;
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

    public function setAction($action)
    {
        $this->action = $action;
        return $this;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function setRecordId($recordId)
    {
        $this->recordId = $recordId;
        return $this;
    }

    public function getRecordId()
    {
        return $this->recordId;
    }

    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setDate(DateTime $date = null)
    {
        $this->date = $date;
        return $this;
    }

    public function getDate()
    {
        return $this->date;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;

/**
 * A resource is restricted when it is stored in this table.
 * The resource must be private.
 *
 * @Entity
 */
class AccessReserved extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @OneToOne(
     *     targetEntity="Omeka\Entity\Resource"
     * )
     * @JoinColumn(
     *     name="id",
     *     referencedColumnName="id",
     *     onDelete="CASCADE",
     *     nullable=false
     * )
     */
    protected $id;

    /**
     * The embargo starts at this date.
     *
     * @var DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $startDate;

    /**
     * The embargo ends at this date.
     *
     * @var DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $endDate;

    /**
     * @var \Omeka\Entity\Resource
     */
    protected $resource;

    public function __construct(\Omeka\Entity\Resource $resource)
    {
        $this->id = $resource->getId();
        $this->resource = $resource;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setStartDate(?DateTime $startDate = null): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getStartDate(): ?DateTime
    {
        return $this->startDate;
    }

    public function setEndDate(?DateTime $endDate = null): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getEndDate(): ?DateTime
    {
        return $this->endDate;
    }
}

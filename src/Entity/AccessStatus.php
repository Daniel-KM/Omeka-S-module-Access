<?php declare(strict_types=1);

namespace AccessResource\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;

/**
 * Access status of resources.
 *
 * Resources that don't have an access status are defined according to
 * visibility public/private. The status and the visibility must be compliant.
 *
 * To store all statuses (free, reserved, forbidden) for all selected resources
 * (item sets, items and media), and not only "reserved", allows to get it
 * directly for any mode and any param (with or without item/media…) and without
 * php processing.
 *
 * @Entity
 * @Table(
 *      indexes={
 *          @Index(
 *              columns={"status"}
 *          )
 *      }
 * )
 */
class AccessStatus extends AbstractEntity
{
    /**#@+
     * Access statuses.
     *
     * The status is set according to modes.
     * There may be a fourth mode in the future.
     *
     * FREE Free for all (public).
     * RESERVED Restricted access (private, except for some people).
     * FORBIDDEN Not available (private).
     */
    const FREE = 'free';
    const RESERVED = 'reserved';
    const FORBIDDEN = 'forbidden';
    /**#@-*/

    /**
     * Warning: the id is the resource, because this is a one-to-one relation.
     *
     * @var \Omeka\Entity\Resource
     *
     * @Id
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
     * @ŧodo There is no tinyint in doctrine.
     *
     * @Column(
     *     type="string",
     *     length=15,
     *     nullable=false
     * )
     */
    protected $status;

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

    public function setId(Resource $resource): self
    {
        $this->id = $resource;
        return $this;
    }

    public function getId(): Resource
    {
        return $this->id;
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

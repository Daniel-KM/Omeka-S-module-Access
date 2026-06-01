<?php declare(strict_types=1);

namespace Access\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;

/**
 * Access status of resources.
 *
 * To store all levels (free, reserved, protected, forbidden) for all selected
 * resources (item sets, items and media), and not only "reserved", allows to
 * get it directly for any mode and any param (with or without item/media…) and
 * without php processing.
 *
 * The indexes on dates allow to update accesses quicker when embargo ends.
 *
 * @Entity
 * @Table(
 *      indexes={
 *          @Index(
 *              columns={"level"}
 *          ),
 *          @Index(
 *              columns={"embargo_start"}
 *          ),
 *          @Index(
 *              columns={"embargo_end"}
 *          )
 *      }
 * )
 */
class AccessStatus extends AbstractEntity
{
    /**#@+
     * Access levels.
     *
     * The access level applies to FILE content only, never to the notice.
     * Notice visibility follows Omeka core resource->is_public. The access
     * level on an item set or item carries no direct gating semantic; its only
     * role is to act as a default propagated to children media when the admin
     * runs the reindex job.
     *
     * On a media, the effective level is the strictest of (media, parent item,
     * parent item sets) after propagation, and gates the file:
     *
     * FREE:      file accessible to all.
     * RESERVED:  file restricted; debloqued by any active bypass mode (IP,
     *            SSO IDP, guest, CAS, LDAP, external, email regex) or by an
     *            approved individual access request.
     * PROTECTED: file restricted; ONLY an approved individual access request
     *            grants access. No global bypass applies.
     * FORBIDDEN: file restricted; no recourse via the access request flow.
     *            The recommended pattern is to expose a separate "contact the author"
     *            channel (theme-side, optional).
     */
    const FREE = 'free';
    const RESERVED = 'reserved';
    const PROTECTED = 'protected';
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
     * One of the constants free/reserved/protected/forbidden above.
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=15,
     *     nullable=false
     * )
     */
    protected $level;

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
    protected $embargoStart;

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
    protected $embargoEnd;

    public function setId(Resource $resource): self
    {
        $this->id = $resource;
        return $this;
    }

    public function getId(): Resource
    {
        return $this->id;
    }

    public function getIdResource(): ?int
    {
        return $this->id->getId();
    }

    public function setLevel(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setEmbargoStart(?DateTime $embargoStart): self
    {
        $this->embargoStart = $embargoStart;
        return $this;
    }

    public function getEmbargoStart(): ?DateTime
    {
        return $this->embargoStart;
    }

    public function setEmbargoEnd(?DateTime $embargoEnd): self
    {
        $this->embargoEnd = $embargoEnd;
        return $this;
    }

    public function getEmbargoEnd(): ?DateTime
    {
        return $this->embargoEnd;
    }

    /**
     * Check if embargo is set and check it.
     *
     * @return bool|null Null if the embargo dates are not set, true if resource
     * is under embargo, else false.
     */
    public function isUnderEmbargo(): ?bool
    {
        if (!$this->embargoStart && !$this->embargoEnd) {
            return null;
        }
        $now = time();
        if ($this->embargoStart && $this->embargoEnd) {
            return $now >= $this->embargoStart->format('U')
                && $now <= $this->embargoEnd->format('U');
        } elseif ($this->embargoStart) {
            return $now >= $this->embargoStart->format('U');
        } elseif ($this->embargoEnd) {
            return $now <= $this->embargoEnd->format('U');
        } else {
            return null;
        }
    }
}

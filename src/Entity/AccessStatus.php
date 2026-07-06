<?php declare(strict_types=1);

namespace Access\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Resource;

/**
 * Access status of resources.
 *
 * Two levels are stored per resource, both for the access level and the embargo
 * dates:
 *
 * - The "effective" columns (level, embargo_start, embargo_end) hold the
 *   materialized result of the cascade item set > item > media. They are
 *   maintained by events and by the rebuild job, never edited directly by the
 *   admin. Every reader (file gating, facets in Reference / Solr, and the
 *   future notice filter) reads these columns: a single indexed read, no
 *   runtime JOIN.
 * - The "set" columns (level_set, embargo_start_set, embargo_end_set) hold
 *   the admin decision on this resource, before inheritance. They are the only
 *   columns edited via the form or the API, and the only source used to
 *   recompute the effective columns.
 *
 * The cascade is: item_set.level = level_set; item.level = MAX(item.level_set,
 * item_sets.level); media.level = MAX(media.level_set, item.level), on the
 * order free < reserved < protected < forbidden. Embargo cascades the same way
 * (earliest start, latest end) only when the setting access_embargo_cascade is
 * enabled; otherwise the effective embargo equals the set embargo.
 *
 * The indexes on the effective columns allow fast faceting and to update
 * accesses quicker when embargo ends.
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
     * Notice visibility follows Omeka core resource->is_public. A level set on
     * an item set or an item cascades automatically to the effective level of
     * its children (materialized in the "level" column), so it gates their
     * files without any propagation step.
     *
     * On a media, the effective level is the strictest of (media, parent item,
     * parent item sets), and gates the file:
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
     * Effective access level: the materialized cascade over the resource, its
     * parent item (for media) and its item sets. One of the constants
     * free/reserved/protected/forbidden. Read by file gating, facets and the
     * future notice filter; maintained by events and the rebuild job, never
     * edited directly.
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
     * Set access level: the admin decision on this resource before inheritance.
     * Edited via the form or the API; used to recompute $level.
     *
     * @var string
     *
     * @Column(
     *     type="string",
     *     length=15,
     *     nullable=false,
     *     options={"default":"free"}
     * )
     */
    protected $levelSet = self::FREE;

    /**
     * Effective embargo start (materialized). Equal to $embargoStartSet unless
     * the setting access_embargo_cascade is enabled, in which case it is the
     * earliest start of the cascade.
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
     * Set embargo start: the admin decision on this resource before
     * inheritance.
     *
     * @var DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $embargoStartSet;

    /**
     * Effective embargo end (materialized). Equal to $embargoEndSet unless the
     * setting access_embargo_cascade is enabled, in which case it is the latest
     * end of the cascade.
     *
     * @var DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $embargoEnd;

    /**
     * Set embargo end: the admin decision on this resource before inheritance.
     *
     * @var DateTime
     *
     * @Column(
     *     type="datetime",
     *     nullable=true
     * )
     */
    protected $embargoEndSet;

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

    public function setLevelSet(string $levelSet): self
    {
        $this->levelSet = $levelSet;
        return $this;
    }

    public function getLevelSet(): string
    {
        return $this->levelSet;
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

    public function setEmbargoStartSet(?DateTime $embargoStartSet): self
    {
        $this->embargoStartSet = $embargoStartSet;
        return $this;
    }

    public function getEmbargoStartSet(): ?DateTime
    {
        return $this->embargoStartSet;
    }

    public function setEmbargoEnd(?DateTime $embargoEnd): self
    {
        $this->embargoEnd = $embargoEnd;
        return $this;
    }

    public function setEmbargoEndSet(?DateTime $embargoEndSet): self
    {
        $this->embargoEndSet = $embargoEndSet;
        return $this;
    }

    public function getEmbargoEndSet(): ?DateTime
    {
        return $this->embargoEndSet;
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

<?php declare(strict_types=1);

namespace Access\View\Helper;

use Access\Mvc\Controller\Plugin\IsAllowedMediaContent as IsAllowedMediaContentPlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class IsAllowedMediaContent extends AbstractHelper
{
    /**
     * @var \Access\Mvc\Controller\Plugin\IsAllowedMediaContent
     */
    protected $isAllowedMediaContentPlugin;

    public function __construct(IsAllowedMediaContentPlugin $isAllowedMediaContent)
    {
        $this->isAllowedMediaContentPlugin = $isAllowedMediaContent;
    }

    /**
     * Check if access to media content is allowed for the current user.
     *
     * The check is done on level and embargo.
     *
     * Accessibility and visibility are decorrelated, so, for example, a visitor
     * cannot see a private media or a public media with reserved content.
     *
     * Here, the media is readable by the user or visitor: it should be loaded
     * via api to check the visibility first.
     *
     * Can access to public resources that are reserved or protected:
     * - global modes
     *   - IP: anonymous with IP.
     *   - External: authenticated externally (cas for now, ldap or sso later).
     *   - Guest: guest users.
     * - individual modes
     *   - User: authenticated users via a request .
     *   - Email: visitor identified by email via a request.
     *   - Token: user or visitor with a token via a request.
     *
     * The embargo is checked first.
     *
     *@uses \Access\Mvc\Controller\Plugin\IsAllowedMediaContent
     */
    public function __invoke(?MediaRepresentation $media): bool
    {
        return $this->isAllowedMediaContentPlugin->__invoke($media);
    }
}

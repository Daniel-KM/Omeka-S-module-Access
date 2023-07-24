<?php declare(strict_types=1);

namespace AccessResource\View\Helper;

use AccessResource\Mvc\Controller\Plugin\IsAllowedMediaContent as IsAllowedMediaContentPlugin;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\MediaRepresentation;

class IsAllowedMediaContent extends AbstractHelper
{
    /**
     * @var \AccessResource\Mvc\Controller\Plugin\IsAllowedMediaContent
     */
    protected $isAllowedMediaContentPlugin;

    protected function __construct(IsAllowedMediaContentPlugin $isAllowedMediaContent)
    {
        $this->isAllowedMediaContentPlugin = $isAllowedMediaContent;
    }

    /**
     * Check if access to media content is allowed for the current user.
     *
     * The check is done on level and embargo.
     *
     * Accessibility and visibility are decorrelated, so, for example, a visitor
     * cannot see a private media or a public media with restricted content.
     *
     * Here, the media is readable by the user or visitor: it should be loaded
     * via api to check the visibility first.
     *
     * Can access to public resources that are restricted or protected:
     * - IP: anonymous with IP.
     * - External: authenticated externally (cas for now, ldap or sso later).
     * - Guest: guest users.
     * - Individual: users with requests and anonymous with token.
     * - Token: visitor with a request token.
     * - Email: visitor identified by email with a request.
     *
     * The embargo is checked first.
     *
     *@uses \AccessResource\Mvc\Controller\Plugin\IsAllowedMediaContent
     */
    public function __invoke(?MediaRepresentation $media): bool
    {
        return $this->isAllowedMediaContentPlugin->__invoke($media);
    }
}

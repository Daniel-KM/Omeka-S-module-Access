<?php declare(strict_types=1);

namespace Access\Mvc\Controller\Plugin;

use CAS\Mvc\Controller\Plugin\IsCasUser;
use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Ldap\Mvc\Controller\Plugin\IsLdapUser;
use Omeka\Entity\User;
use SingleSignOn\Mvc\Controller\Plugin\IsSsoUser;

class IsExternalUser extends AbstractPlugin
{
    /**
     * @var Laminas\Authentication\AuthenticationService;
     */
    protected $authenticationService;

    /**
     * @var \CAS\Mvc\Controller\Plugin\IsCasUser
     */
    protected $isCasUser;

    /**
     * @var \Ldap\Mvc\Controller\Plugin\IsLdapUser
     */
    protected $isLdapUser;

    /**
     * @var \SingleSignOn\Mvc\Controller\Plugin\IsSsoUser
     */
    protected $isSsoUser;

    public function __construct(
        AuthenticationService $authenticationService,
        ?IsCasUser $isCasUser,
        ?IsLdapUser $isLdapUser,
        ?IsSsoUser $isSsoUser
    ) {
        $this->authenticationService = $authenticationService;
        $this->isCasUser = $isCasUser;
        $this->isLdapUser = $isLdapUser;
        $this->isSsoUser = $isSsoUser;
    }

    /**
     * Check if a user is authenticated via an external identity provider (sso, cas, ldap).
     */
    public function __invoke(?User $user = null): ?bool
    {
        $user ??= $this->authenticationService->getIdentity();
        if (!$user) {
            return false;
        }

        if ($this->isSsoUser && $this->isSsoUser->__invoke($user)) {
            return true;
        }

        if ($this->isCasUser && $this->isCasUser->__invoke($user)) {
            return true;
        }

        if ($this->isLdapUser && $this->isLdapUser->__invoke($user)) {
            return true;
        }

        return false;
    }
}

<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use CAS\Mvc\Controller\Plugin\IsCasUser;
use Ldap\Mvc\Controller\Plugin\IsLdapUser;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Entity\User;
use SingleSignOn\Mvc\Controller\Plugin\IsSsoUser;
use Laminas\Authentication\AuthenticationService;

class IsExternalUser extends AbstractPlugin
{
    /**
     * @var Laminas\Authentication\AuthenticationService;
     */
    protected $authenticationService;

    /**
     * @var \SingleSignOn\Mvc\Controller\Plugin\IsSsoUser
     */
    protected $isSsoUser;

    /**
     * @var \CAS\Mvc\Controller\Plugin\IsCasUser
     */
    protected $isCasUser;

    /**
     * @var \Ldap\Mvc\Controller\Plugin\IsLdapUser
     */
    protected $isLdapUser;

    public function __construct(
        AuthenticationService $authenticationService,
        ?IsSsoUser $isSsoUser,
        ?IsCasUser $isCasUser,
        ?IsLdapUser $isLdapUser
    ) {
        $this->authenticationService = $authenticationService;
        $this->isSsoUser = $isSsoUser;
        $this->isCasUser = $isCasUser;
        $this->isLdapUser = $isLdapUser;
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
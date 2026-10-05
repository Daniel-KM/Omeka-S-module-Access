<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Entity\AccessStatus;
use AccessTest\AccessTestTrait;
use Omeka\Entity\User;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Global modes "auth_any" (any authenticated user) and "auth_guest" (guest
 * roles only, included the roles of module GuestPrivate).
 *
 * @group authmodes
 * @group integration
 */
class AuthModesTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function modeRoleProvider(): array
    {
        return [
            'auth_any, guest' => [['auth_any'], 'guest', true],
            'auth_any, guest_private' => [['auth_any'], 'guest_private', true],
            'auth_guest, guest' => [['auth_guest'], 'guest', true],
            'auth_guest, guest_private' => [['auth_guest'], 'guest_private', true],
            'auth_guest, guest_private_site' => [['auth_guest'], 'guest_private_site', true],
            // The individual mode "user" requires a request for each resource.
            'user, guest' => [['user'], 'guest', false],
            'user, guest_private_site' => [['user'], 'guest_private_site', false],
            // With module GuestPrivate, the role guest_private has the
            // permission view-all on resources, so it is allowed whatever the
            // modes, like the backend roles.
            'user, guest_private' => [['user'], 'guest_private', null],
            // The removed mode "guest" grants nothing anymore.
            'guest, guest' => [['guest'], 'guest', false],
        ];
    }

    /**
     * @dataProvider modeRoleProvider
     */
    public function testReservedMediaByModeAndRole(array $modes, string $role, ?bool $expected): void
    {
        $media = $this->createReservedMedia();
        $this->setAccessModes($modes);
        $this->loginWithRole($role);
        $expected ??= $this->getServiceLocator()->get('Omeka\Acl')
            ->isAllowed($role, \Omeka\Entity\Resource::class, 'view-all');

        $this->assertSame($expected, $this->isAllowedMediaContentFresh($media));
    }

    public function testAnonymousIsDeniedWithAuthModes(): void
    {
        $media = $this->createReservedMedia();
        $this->setAccessModes(['auth_any', 'auth_guest']);
        $this->logout();

        $this->assertFalse($this->isAllowedMediaContentFresh($media));
    }

    public function testForbiddenMediaIsDeniedWithAuthAny(): void
    {
        $item = $this->createItem();
        $media = $this->createMedia($item, ['access_level' => AccessStatus::FORBIDDEN]);
        $this->setAccessModes(['auth_any']);
        $this->loginWithRole('guest');

        $this->assertFalse($this->isAllowedMediaContentFresh($media));
    }

    private function createReservedMedia(): \Omeka\Api\Representation\MediaRepresentation
    {
        $item = $this->createItem();
        return $this->createMedia($item, ['access_level' => AccessStatus::RESERVED]);
    }

    private function loginWithRole(string $role): void
    {
        if (!$this->getServiceLocator()->get('Omeka\Acl')->hasRole($role)) {
            $this->markTestSkipped(sprintf('The role "%s" is not available.', $role));
        }
        $entityManager = $this->getEntityManager();
        $user = new User();
        $user->setEmail(sprintf('authmodes-%s-%s@test.example.com', $role, uniqid()));
        $user->setName('Auth modes ' . $role);
        $user->setRole($role);
        $user->setIsActive(true);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->createdResources[] = ['type' => 'users', 'id' => $user->getId()];

        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
        $auth->getStorage()->write($user);
    }
}

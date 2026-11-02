<?php declare(strict_types=1);

namespace AccessTest\Controller;

use Access\Stdlib\IpRange;
use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the checks of the ip rules when the config form is displayed.
 *
 * @group config
 */
class ConfigFormIpCheckTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    protected $ipRules;

    protected ?string $remoteAddr;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->ipRules = $this->getSettings()->get('access_ip_rules');
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    public function tearDown(): void
    {
        $this->getSettings()->set('access_ip_rules', $this->ipRules);
        $_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
        $this->logout();
        parent::tearDown();
    }

    protected function setIpRules(array $sources): void
    {
        $rules = [];
        foreach ($sources as $source) {
            $rules[$source] = ['source' => $source] + IpRange::fromCidr($source) + ['allow' => [], 'forbid' => []];
        }
        $this->getSettings()->set('access_ip_rules', $rules);
    }

    public function testWarnsAboutPrivateAddressInRules(): void
    {
        $this->setIpRules(['10.0.0.0/8']);
        $this->dispatch('/admin/module/configure?id=Access');
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('private or loopback addresses (10.0.0.0/8)', $this->getResponse()->getContent());
    }

    public function testWarnsWhenClientIsIpv6AndRulesAreIpv4Only(): void
    {
        $this->setIpRules(['192.0.2.0/24']);
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $this->dispatch('/admin/module/configure?id=Access');
        $this->assertStringContainsString('ipv6 address 2001:db8::1', $this->getResponse()->getContent());
    }

    public function testNoIpv6WarningWhenRulesContainIpv6(): void
    {
        $this->setIpRules(['192.0.2.0/24', '2001:db8::/32']);
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        $this->dispatch('/admin/module/configure?id=Access');
        $this->assertStringNotContainsString('contain only ipv4 addresses', $this->getResponse()->getContent());
    }
}

<?php declare(strict_types=1);

namespace AccessTest\Controller;

use AccessTest\AccessTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the rules with several sources in the config form.
 *
 * @group config
 */
class ConfigFormRulesTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    protected array $saved = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        foreach (['access_ip_rules', 'access_auth_sso_idp_rules'] as $name) {
            $this->saved[$name] = $this->getSettings()->get($name);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->getSettings()->set($name, $value);
        }
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    protected function callModule(string $method, ...$args)
    {
        $module = new \Access\Module();
        $module->setServiceLocator($this->getServiceLocator());
        $reflection = new \ReflectionMethod($module, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($module, ...$args);
    }

    public function testIpRuleWithSeveralSourcesIsStoredByIp(): void
    {
        $itemSet = $this->createItemSet();
        $this->getSettings()->set('access_ip_rules', [
            ['source' => '192.0.2.0/24  2001:DB8::/32, 198.51.100.7', 'allow' => [$itemSet->id()], 'forbid' => []],
        ]);

        $this->assertTrue($this->callModule('prepareIpItemSets'));

        $rules = $this->getSettings()->get('access_ip_rules');
        $this->assertSame(['192.0.2.0/24', '2001:db8::/32', '198.51.100.7'], array_keys($rules));
        foreach ($rules as $rule) {
            $this->assertSame([$itemSet->id()], $rule['allow']);
        }
    }

    public function testInvalidSourceInRuleIsRejected(): void
    {
        $this->getSettings()->set('access_ip_rules', [
            ['source' => '192.0.2.0/24 not-an-ip', 'allow' => [], 'forbid' => []],
        ]);
        $this->assertFalse($this->callModule('prepareIpItemSets'));
    }

    public function testSsoRuleWithSeveralIdpsIsStoredByIdp(): void
    {
        $this->getSettings()->set('access_auth_sso_idp_rules', [
            ['source' => '', 'source_manual' => 'https://idp.example.org https://idp.example.net', 'allow' => [], 'forbid' => []],
        ]);

        $this->assertTrue($this->callModule('prepareAuthSsoIdpItemSets'));

        $this->assertSame(
            ['https://idp.example.org', 'https://idp.example.net'],
            array_keys($this->getSettings()->get('access_auth_sso_idp_rules'))
        );
    }

    public function testRulesWithSameItemSetsAreGrouped(): void
    {
        $rules = [
            '192.0.2.0/24' => ['source' => '192.0.2.0/24', 'allow' => [2, 1], 'forbid' => []],
            '10.1.0.0/16' => ['source' => '10.1.0.0/16', 'allow' => [3], 'forbid' => []],
            '2001:db8::/32' => ['source' => '2001:db8::/32', 'allow' => [1, 2], 'forbid' => []],
        ];
        $grouped = $this->callModule('groupRulesBySources', $rules, false);
        $this->assertSame(['192.0.2.0/24 2001:db8::/32', '10.1.0.0/16'], array_column($grouped, 'source'));

        $idps = [
            'https://a.example.org' => ['source' => 'https://a.example.org', 'allow' => [], 'forbid' => []],
            'https://b.example.org' => ['source' => 'https://b.example.org', 'allow' => [], 'forbid' => []],
        ];
        $grouped = $this->callModule('groupRulesBySources', $idps, true);
        $this->assertSame('', $grouped[0]['source']);
        $this->assertSame('https://a.example.org https://b.example.org', $grouped[0]['source_manual']);
    }

    public function testConfigFormDisplaysGroupedSources(): void
    {
        $this->getSettings()->set('access_ip_rules', [
            ['source' => '192.0.2.0/24 2001:db8::/32', 'allow' => [], 'forbid' => []],
        ]);
        $this->callModule('prepareIpItemSets');

        $this->dispatch('/admin/module/configure?id=Access');
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('192.0.2.0/24 2001:db8::/32', html_entity_decode($this->getResponse()->getContent(), ENT_QUOTES | ENT_HTML5));
    }
}

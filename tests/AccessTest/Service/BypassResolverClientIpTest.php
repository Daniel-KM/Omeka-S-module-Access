<?php declare(strict_types=1);

namespace AccessTest\Service;

use Access\Service\BypassResolver;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The ip grants rights, so the proxy headers are ignored when no trusted proxy
 * is set in the main settings, even from a private address.
 */
class BypassResolverClientIpTest extends TestCase
{
    protected $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 203.0.113.50';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    protected function resolve(array $trustedProxies): string
    {
        $settings = $this->createMock(Settings::class);
        $clientIpClass = class_exists(\Omeka\Stdlib\ClientIp::class) ? \Omeka\Stdlib\ClientIp::class : \Common\Stdlib\ClientIp::class;
        return (new BypassResolver($settings, null, new $clientIpClass($trustedProxies)))->resolveClientIp();
    }

    public function testHeadersIgnoredWithoutTrustedProxies(): void
    {
        $this->assertSame('172.18.0.2', $this->resolve([]));
    }

    public function testTrustedProxiesAreUsed(): void
    {
        $this->assertSame('203.0.113.50', $this->resolve(['172.18.0.0/16']));
        $this->assertSame('172.18.0.2', $this->resolve(['10.0.0.1']));
    }
}

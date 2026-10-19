<?php declare(strict_types=1);

namespace AccessTest\Stdlib;

use Access\Stdlib\IpRange;
use PHPUnit\Framework\TestCase;

/**
 * Ranges of ipv4 and ipv6, storable in a json setting.
 *
 * @group ip
 */
class IpRangeTest extends TestCase
{
    public function testIpv4SingleIp(): void
    {
        $range = IpRange::fromCidr('192.0.2.10');
        $this->assertSame(['ipv6' => false, 'low' => ip2long('192.0.2.10'), 'high' => ip2long('192.0.2.10')], $range);
        $this->assertTrue(IpRange::contains($range, '192.0.2.10'));
        $this->assertFalse(IpRange::contains($range, '192.0.2.11'));
    }

    public function testIpv4Cidr(): void
    {
        $range = IpRange::fromCidr('130.79.250.0/23');
        $this->assertTrue(IpRange::contains($range, '130.79.250.0'));
        $this->assertTrue(IpRange::contains($range, '130.79.251.255'));
        $this->assertFalse(IpRange::contains($range, '130.79.252.0'));
        $this->assertFalse(IpRange::contains($range, '130.79.249.255'));
    }

    public function testIpv6SingleIp(): void
    {
        $range = IpRange::fromCidr('2001:db8::10');
        $this->assertTrue($range['ipv6']);
        $this->assertTrue(IpRange::contains($range, '2001:db8::10'));
        $this->assertTrue(IpRange::contains($range, '2001:0DB8:0000:0000:0000:0000:0000:0010'));
        $this->assertFalse(IpRange::contains($range, '2001:db8::11'));
    }

    /**
     * @dataProvider ipv6CidrProvider
     */
    public function testIpv6Cidr(string $cidr, string $inside, string $outside): void
    {
        $range = IpRange::fromCidr($cidr);
        $this->assertTrue(IpRange::contains($range, $inside), "$inside in $cidr");
        $this->assertFalse(IpRange::contains($range, $outside), "$outside not in $cidr");
    }

    public function ipv6CidrProvider(): array
    {
        return [
            '/32' => ['2001:db8::/32', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', '2001:db9::'],
            '/48' => ['2a01:cb00:1234::/48', '2a01:cb00:1234:ffff::1', '2a01:cb00:1235::'],
            '/64' => ['2a01:cb00:1234:5678::/64', '2a01:cb00:1234:5678:abcd::1', '2a01:cb00:1234:5679::'],
            '/63' => ['2a01:cb00:1234:5678::/63', '2a01:cb00:1234:5679::1', '2a01:cb00:1234:567a::'],
            '/128' => ['2001:db8::1/128', '2001:db8::1', '2001:db8::2'],
            '/0' => ['::/0', 'ffff::1', '192.0.2.1'],
        ];
    }

    public function testFamiliesDoNotMatch(): void
    {
        $this->assertFalse(IpRange::contains(IpRange::fromCidr('0.0.0.0/0'), '2001:db8::1'));
        $this->assertFalse(IpRange::contains(IpRange::fromCidr('::/0'), '192.0.2.1'));
    }

    public function testRangeIsStorableInJson(): void
    {
        foreach (['2001:db8::/32', '2a01:cb00::1', '192.0.2.0/24'] as $cidr) {
            $range = IpRange::fromCidr($cidr);
            $json = json_encode($range);
            $this->assertNotFalse($json, $cidr);
            $this->assertSame($range, json_decode($json, true));
        }
    }

    public function testIpv4MappedInIpv6IsIpv4(): void
    {
        $this->assertSame('192.0.2.10', IpRange::normalizeIp('::ffff:192.0.2.10'));
        $this->assertTrue(IpRange::contains(IpRange::fromCidr('192.0.2.0/24'), '::ffff:192.0.2.10'));
    }

    public function testNormalizeIp(): void
    {
        $this->assertSame('2001:db8::10', IpRange::normalizeIp('2001:0DB8:0:0:0:0:0:10'));
        $this->assertSame('192.0.2.10', IpRange::normalizeIp(' 192.0.2.10 '));
        $this->assertNull(IpRange::normalizeIp('not an ip'));
    }

    public function testRangeFromSourceWhenMissing(): void
    {
        $this->assertTrue(IpRange::contains(['source' => '2001:db8::/32'], '2001:db8::1'));
        $this->assertFalse(IpRange::contains(['source' => '2001:db8::/32'], '2001:db9::1'));
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidCidr(string $cidr): void
    {
        $this->assertNull(IpRange::fromCidr($cidr));
    }

    public function invalidProvider(): array
    {
        return [
            'empty' => [''],
            'text' => ['not an ip'],
            'ipv4 prefix too big' => ['192.0.2.0/33'],
            'ipv6 prefix too big' => ['2001:db8::/129'],
            'prefix not a number' => ['2001:db8::/a'],
            'negative prefix' => ['192.0.2.0/-1'],
        ];
    }
}

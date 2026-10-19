<?php declare(strict_types=1);

namespace Access\Stdlib;

/**
 * Range of an ip or a cidr, ipv4 or ipv6, storable in a json setting.
 *
 * The ipv4 bounds are integers. The ipv6 bounds are the hexadecimal of the 16
 * bytes, so they can be stored in json, unlike the binary string, and they are
 * compared as strings of the same length, like the bytes.
 */
class IpRange
{
    /**
     * Get the range of an ip or a cidr.
     *
     * @return array|null Keys "ipv6", then "low" and "high" (ipv4, integers) or
     *   "low_hex" and "high_hex" (ipv6). Null when invalid.
     */
    public static function fromCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        $ip = strtok($cidr, '/');
        $prefix = strpos($cidr, '/') === false ? null : substr($cidr, strpos($cidr, '/') + 1);
        $packed = $ip === false ? false : @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;
        if ($prefix === null) {
            $prefix = $bits;
        } elseif (!ctype_digit($prefix) || (int) $prefix > $bits) {
            return null;
        }
        $prefix = (int) $prefix;

        $mask = str_repeat("\xff", intdiv($prefix, 8));
        if ($prefix % 8) {
            $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
        }
        $mask = str_pad($mask, strlen($packed), "\x00");
        $low = $packed & $mask;
        $high = $packed | ~$mask;

        if ($bits === 32) {
            return [
                'ipv6' => false,
                'low' => (int) sprintf('%u', ip2long(inet_ntop($low))),
                'high' => (int) sprintf('%u', ip2long(inet_ntop($high))),
            ];
        }
        return [
            'ipv6' => true,
            'low_hex' => bin2hex($low),
            'high_hex' => bin2hex($high),
        ];
    }

    /**
     * Normalize an ip: canonical form, ipv4 for an ipv4 mapped in ipv6.
     */
    public static function normalizeIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }
        // An ipv4 mapped in ipv6 (::ffff:192.0.2.1), sent by dual stack
        // proxies.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return inet_ntop($packed);
    }

    /**
     * Check if an ip is inside a range, computed from its source if missing.
     */
    public static function contains(array $range, string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return false;
        }
        if (!isset($range['low'], $range['high']) && !isset($range['low_hex'], $range['high_hex'])) {
            $range = self::fromCidr((string) ($range['source'] ?? '')) ?? [];
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 4) {
            if (!empty($range['ipv6']) || !isset($range['low'], $range['high'])) {
                return false;
            }
            $long = (int) sprintf('%u', ip2long($ip));
            return $long >= (int) $range['low'] && $long <= (int) $range['high'];
        }
        if (empty($range['ipv6']) || !isset($range['low_hex'], $range['high_hex'])) {
            return false;
        }
        $hex = bin2hex($packed);
        return strcmp($hex, (string) $range['low_hex']) >= 0
            && strcmp($hex, (string) $range['high_hex']) <= 0;
    }
}

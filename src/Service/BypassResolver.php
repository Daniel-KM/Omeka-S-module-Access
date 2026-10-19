<?php declare(strict_types=1);

namespace Access\Service;

use Access\Stdlib\IpRange;
use Laminas\Http\PhpEnvironment\RemoteAddress;
use Omeka\Settings\Settings;
use Omeka\Settings\UserSettings;

/**
 * Shared resolver for the bypass primitives used by both the access level
 * filter and IsAllowedMediaContent: client IP under trusted-proxy rules, IP to
 * item-set allow/forbid map, and SSO IDP to item-set allow/forbid map.
 *
 * Pulled out of those two classes to remove a ~150 line duplication.
 */
class BypassResolver
{
    private Settings $settings;

    private ?UserSettings $userSettings;

    public function __construct(Settings $settings, ?UserSettings $userSettings = null)
    {
        $this->settings = $settings;
        $this->userSettings = $userSettings;
    }

    /**
     * Real client IP under trusted-proxy rules. Returns "::" when no usable
     * address is found.
     */
    public function resolveClientIp(): string
    {
        $remoteAddress = new RemoteAddress();
        $remote = $remoteAddress->getIpAddress();
        if (!$remote || !filter_var($remote, FILTER_VALIDATE_IP)) {
            return '::';
        }
        $trusted = $this->settings->get('access_ip_proxy_trusted', []);
        if (!is_array($trusted)) {
            $trusted = preg_split('/[\s,]+/', (string) $trusted) ?: [];
        }
        $trusted = array_values(array_filter(
            array_map('trim', $trusted),
            fn ($v) => $v !== '' && filter_var(
                strpos($v, '/') === false ? $v : strtok($v, '/'),
                FILTER_VALIDATE_IP
            )
        ));
        if (!$trusted || !in_array($remote, $trusted, true)) {
            return $remote;
        }
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])
            && !empty($_SERVER['HTTP_X_REAL_IP'])
        ) {
            $realIp = trim((string) $_SERVER['HTTP_X_REAL_IP']);
            return filter_var($realIp, FILTER_VALIDATE_IP) ? $realIp : $remote;
        }
        $remoteAddress->setUseProxy(true)->setTrustedProxies($trusted);
        $ip = $remoteAddress->getIpAddress();
        return $ip && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $remote;
    }

    /**
     * @return null|array{allow:int[],forbid:int[]}
     */
    public function definedItemSetsForClientIp(): ?array
    {
        $ip = $this->resolveClientIp();
        if ($ip === '::' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $listIps = $this->settings->get('access_ip_rules', []);
        if (empty($listIps)) {
            return null;
        }

        // The sources are normalized, so the exact ip matches directly.
        $ip = IpRange::normalizeIp($ip);
        if ($ip === null) {
            return null;
        }
        if (isset($listIps[$ip])) {
            return $this->normalise($listIps[$ip]);
        }

        foreach ($listIps as $source => $range) {
            if (IpRange::contains($range + ['source' => (string) $source], $ip)) {
                return $this->normalise($range);
            }
        }
        return null;
    }

    /**
     * @return null|array{allow:int[],forbid:int[]}
     */
    public function definedItemSetsForAuthSsoIdp(): ?array
    {
        if (!$this->userSettings) {
            return null;
        }
        $reservedIdps = $this->settings->get('access_auth_sso_idp_rules', []);
        if (empty($reservedIdps)) {
            return null;
        }
        if ($this->userSettings->get('connection_authenticator') !== 'SingleSignOn') {
            return null;
        }
        $idpName = $this->userSettings->get('connection_idp');
        $def = ($idpName && isset($reservedIdps[$idpName]))
            ? $reservedIdps[$idpName]
            : ($reservedIdps['federation'] ?? null);
        return $def === null ? null : $this->normalise($def);
    }

    /**
     * @return array{allow:int[],forbid:int[]}
     */
    private function normalise(array $def): array
    {
        $allow = isset($def['allow']) && is_array($def['allow']) ? $def['allow'] : [];
        $forbid = isset($def['forbid']) && is_array($def['forbid']) ? $def['forbid'] : [];
        return [
            'allow' => array_values(array_map('intval', $allow)),
            'forbid' => array_values(array_map('intval', $forbid)),
        ];
    }
}

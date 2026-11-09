<?php declare(strict_types=1);

namespace Access\Service;

use Access\Stdlib\IpRange;
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

    /**
     * @var \Omeka\Stdlib\ClientIp|\Common\Stdlib\ClientIp
     */
    private $clientIp;

    public function __construct(Settings $settings, ?UserSettings $userSettings, $clientIp)
    {
        $this->settings = $settings;
        $this->userSettings = $userSettings;
        $this->clientIp = $clientIp;
    }

    /**
     * Real client IP under trusted-proxy rules. Returns "::" when no usable
     * address is found.
     *
     * The trusted proxies of the module take precedence over the main ones.
     * The ip grants rights, so the headers are ignored when no proxy is set.
     */
    public function resolveClientIp(): string
    {
        $trusted = $this->clientIp::normalizeTrustedProxies($this->settings->get('access_ip_proxy_trusted', []));
        return $this->clientIp->getIp(null, $trusted ?: null, true);
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

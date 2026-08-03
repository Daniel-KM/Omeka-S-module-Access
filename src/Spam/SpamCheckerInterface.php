<?php declare(strict_types=1);

namespace Access\Spam;

/**
 * Abstraction for the spam check applied to an anonymous access request.
 *
 * Access ships no local spam engine: the only implementation delegates to the
 * SpamGuard module when it is active, and a null checker is used otherwise.
 */
interface SpamCheckerInterface
{
    /**
     * Return the list of matched spam reasons for the given submission context.
     *
     * The context contains the request snapshot: ip, userAgent, email, subject,
     * body, formLoadedAt, honeypot, powSalt, powNonce, prevSubmitAt,
     * prevSubmitIp.
     *
     * @return string[] Reason keys, e.g. ['dnsbl', 'bannedIp']. Empty when the
     *   submission is not spam.
     */
    public function check(array $context): array;
}

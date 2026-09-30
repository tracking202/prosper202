<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * Whether a request may be handed the install token for a click id, and the
 * token when it may.
 *
 * InstallToken signs whatever click id it is given; the MAC only helps if
 * the server never signs a click id the requester merely named. A redirect
 * holds a click id either because this request allocated it, or because the
 * visitor sent it — lp.php reads it from `tracking202subid_a_<campaign>`, a
 * cookie JavaScript can write and a raw `Cookie:` header can set to any
 * sequential id. Signing the second kind hands an attacker a valid token for
 * someone else's click, and with it their install attribution and payout
 * (CLAUDE.md #16: identity is what the attacker cannot choose).
 *
 * So a token is granted for a click id in exactly two cases:
 *
 * 1. this request allocated the click (Prosper202\Click\RecordedClicks);
 * 2. the visitor presents that click's token in a proof cookie
 *    (`tracking202itok`, `tracking202itok_a_<campaign>`), httponly, which
 *    only the request that recorded the click sets (setClickIdCookie()).
 *    It is the token itself: holding it is holding what it grants, and it
 *    cannot be computed for another click without the key.
 *
 * Anything else — a forged click cookie, a proof for another click, a proof
 * with a wrong MAC, a missing or unreadable key — expands EMPTY, and the
 * install is recorded unattributed: the failure that credits no one.
 */
final class InstallTokenGrant
{
    /** The proof cookie; per campaign it is PROOF_COOKIE . '_a_' . <aff_campaign_id>. */
    public const PROOF_COOKIE = 'tracking202itok';

    private const PROOF_NAME = '/^tracking202itok(?:_a_[0-9]{1,10})?$/D';

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $cookies the request's cookies ($_COOKIE)
     * @param callable(): ?string $key the 32-byte key, read on demand; read
     *        only when a token is actually granted or a proof is checked
     */
    public static function forRequest(mixed $clickId, bool $recordedHere, array $cookies, callable $key): string
    {
        $text = is_int($clickId) ? (string) $clickId : (is_string($clickId) ? $clickId : '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $text) !== 1 || (string) (int) $text !== $text) {
            return '';
        }
        if ($recordedHere) {
            return InstallToken::expand($text, $key);
        }

        $proofs = [];
        foreach ($cookies as $name => $value) {
            if (is_string($name) && is_string($value) && preg_match(self::PROOF_NAME, $name) === 1
                && InstallToken::clickIdOf($value) === (int) $text) {
                $proofs[] = $value;
            }
        }
        if ($proofs === []) {
            return '';
        }
        $expected = InstallToken::expand($text, $key);
        if ($expected === '') {
            return '';
        }
        foreach ($proofs as $proof) {
            if (hash_equals($expected, $proof)) {
                return $expected;
            }
        }

        return '';
    }
}

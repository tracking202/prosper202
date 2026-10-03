<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * `[[p202_install_token]]`: the click id in the Play store link, signed
 * (plan §5.1).
 *
 * The token is `<click_id>.<mac>`, where mac is the first 12 bytes of
 * HMAC-SHA256(K_install, "p202-install-v1|" . click_id) in base64url
 * without padding (16 characters). A bare click id would let anyone credit
 * an install — and its payout — to any click, because click ids are
 * sequential (CLAUDE.md #16); the MAC is what the sender cannot choose.
 *
 * The alphabet (digits, ".", base64url) survives rawurlencode() unchanged,
 * so the token rides through the link builder's encoding and Play's
 * `referrer=` parameter byte for byte.
 *
 * Parsing is exact: a canonical positive click id (no sign, no leading
 * zero, within PHP_INT_MAX) and exactly 16 base64url characters. Anything
 * else is not a token, so no two spellings verify as one click (#17).
 */
final class InstallToken
{
    public const DOMAIN = 'p202-install-v1|';
    public const MAC_BYTES = 12;
    private const SHAPE = '/^([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{16})$/D';

    private function __construct()
    {
    }

    /** The token for a click, under a 32-byte key. */
    public static function forClick(int $clickId, string $key): string
    {
        if ($clickId <= 0) {
            throw new \InvalidArgumentException('A click id to sign is positive');
        }
        self::assertKey($key);

        return $clickId . '.' . self::mac($clickId, $key);
    }

    /**
     * What `[[p202_install_token]]` expands to for a click id as the redirect
     * holds it: the token, or '' — never the bare click id — when the id is
     * not a canonical positive integer (the fallback redirect's "p202", a
     * placeholder) or the key is missing ($key answers null) or unreadable
     * ($key throws). An empty token records the install unattributed, which
     * is the failure that cannot credit a guessable click (CLAUDE.md #11).
     *
     * @param callable(): ?string $key the 32-byte key, read on demand
     */
    public static function expand(mixed $clickId, callable $key): string
    {
        $text = is_int($clickId) ? (string) $clickId : (is_string($clickId) ? $clickId : '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $text) !== 1 || (string) (int) $text !== $text) {
            return '';
        }
        try {
            $bytes = $key();
        } catch (\Throwable $e) {
            error_log('p202 install token: the key could not be read, so the token expands empty: ' . $e->getMessage());

            return '';
        }
        if ($bytes === null) {
            error_log('p202 install token: this installation has no install-token key; run the upgrade to mint it. The token expands empty.');

            return '';
        }
        if (strlen($bytes) !== 32) {
            // Never a throw: this runs inside the redirect.
            error_log('p202 install token: the install-token key is not 32 bytes, so the token expands empty.');

            return '';
        }

        return self::forClick((int) $text, $bytes);
    }

    /**
     * The click id a well-formed token names, before any MAC check, or null
     * when the value is not shaped like a token.
     */
    public static function clickIdOf(string $token): ?int
    {
        if (preg_match(self::SHAPE, $token, $m) !== 1) {
            return null;
        }
        $clickId = (int) $m[1];
        // 19 digits can exceed PHP_INT_MAX, where the cast saturates; the
        // round trip refuses every value the cast rewrote.
        return (string) $clickId === $m[1] ? $clickId : null;
    }

    /**
     * The click id a token vouches for, or null when it is malformed or its
     * MAC does not verify under the key.
     */
    public static function verify(string $token, string $key): ?int
    {
        self::assertKey($key);
        $clickId = self::clickIdOf($token);
        if ($clickId === null) {
            return null;
        }
        $given = substr($token, strpos($token, '.') + 1);

        return hash_equals(self::mac($clickId, $key), $given) ? $clickId : null;
    }

    private static function mac(int $clickId, string $key): string
    {
        $raw = substr(hash_hmac('sha256', self::DOMAIN . $clickId, $key, true), 0, self::MAC_BYTES);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('The install-token key is 32 bytes');
        }
    }
}

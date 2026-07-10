<?php

declare(strict_types=1);

namespace Prosper202\Bandit;

use RuntimeException;

/**
 * Mints the `t202ctx` signed URL token — the per-click context Prosper202
 * appends to rotator→landing-page redirects for the Landing Page Optimizer
 * edge (p202-edge-sync spec §3.2/§3.3):
 *
 *   token  = b64url(payloadJSON) . '.' . b64url(mac)
 *   mac    = first 16 bytes of HMAC-SHA256(K, ascii bytes of b64url(payload))
 *   K      = HMAC-SHA256(key = webhook_secret bytes, msg = 't202ctx-v1')
 *   b64url = URL-safe alphabet, NO padding
 *
 * The byte layout is a cross-language contract: the SaaS Worker verifies
 * tokens byte-for-byte (bandit-app src/auth/t202ctx.ts) against the pinned
 * vectors mirrored in tests/Bandit/fixtures/t202ctx-vectors.json. That is
 * why the payload is encoded with JSON_UNESCAPED_UNICODE and
 * JSON_UNESCAPED_SLASHES (matching JS JSON.stringify output) and claims are
 * always emitted in the canonical §3.2 order — same inputs, same bytes.
 *
 * Key derivation, not key reuse: the fixed label domain-separates URL tokens
 * from webhook body signatures, and rotating the webhook secret rotates K.
 * The token authenticates nothing (threat model: stats pollution only), so
 * callers treat every mint failure as "no token", never as an error.
 */
final class CtxToken
{
    /** URL parameter carrying the token (stoplisted in getPrePopVars). */
    public const PARAM = 't202ctx';

    private const KDF_LABEL = 't202ctx-v1';
    private const MAC_BYTES = 16;

    /** Spec §3.2 size budget for the whole encoded token. */
    private const MAX_TOKEN_CHARS = 400;
    private const MAX_KW_CHARS = 64;
    private const MAX_CVAR_CHARS = 40;

    /** Canonical claim order (§3.2) — mirrors the Worker's CLAIM_KEYS. */
    private const CLAIM_ORDER = ['sid', 'acc', 'cmp', 'lp', 'kw', 'c1', 'c2', 'c3', 'c4', 'cc'];

    /** Claims carried as JSON numbers; everything else is a string. */
    private const INT_CLAIMS = ['acc', 'cmp', 'lp'];

    /** Over-budget drop order (§3.2): kw, then c4→c1, then lp. v/t/sid/acc/cmp never drop. */
    private const DROP_ORDER = ['kw', 'c4', 'c3', 'c2', 'c1', 'lp'];

    /**
     * K = HMAC-SHA256(key = webhook secret bytes, msg = 't202ctx-v1') as raw
     * 32 bytes. Cached hex in the bandit_bridge_config pref as `ctx_key` so
     * the redirect hot path never re-derives it or queries 202_ltv_webhooks.
     */
    public static function deriveKey(string $webhookSecret): string
    {
        return hash_hmac('sha256', self::KDF_LABEL, $webhookSecret, true);
    }

    /**
     * Mint a token. Claims are emitted in the canonical §3.2 order with
     * null/'' values omitted (mirroring the Worker's reference mint), kw is
     * truncated to 64 chars and c1..c4 to 40 chars before encoding, and an
     * over-budget token re-mints with fields dropped in DROP_ORDER until it
     * fits the 400-char budget.
     *
     * @param array<string, mixed> $claims payload claims; `v` (default 1)
     *        and `t` (default time()) may be supplied for deterministic
     *        minting, unknown keys are ignored
     * @param string $derivedKey the raw 32-byte K from deriveKey()
     * @throws RuntimeException on a bad key or unencodable payload — callers
     *         on the redirect path catch and skip the token (failure-open)
     */
    public static function mint(array $claims, string $derivedKey): string
    {
        if (strlen($derivedKey) !== 32) {
            throw new RuntimeException('t202ctx derived key must be the raw 32-byte HMAC-SHA256 output');
        }

        $payload = [
            'v' => (int) ($claims['v'] ?? 1),
            't' => (int) ($claims['t'] ?? time()),
        ];
        foreach (self::CLAIM_ORDER as $key) {
            $value = $claims[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($key, self::INT_CLAIMS, true)) {
                $payload[$key] = (int) $value;
                continue;
            }
            $value = (string) $value;
            if ($key === 'kw') {
                $value = self::truncate($value, self::MAX_KW_CHARS);
            } elseif (in_array($key, ['c1', 'c2', 'c3', 'c4'], true)) {
                $value = self::truncate($value, self::MAX_CVAR_CHARS);
            }
            if ($value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        $token = self::encode($payload, $derivedKey);
        foreach (self::DROP_ORDER as $drop) {
            if (strlen($token) <= self::MAX_TOKEN_CHARS) {
                break;
            }
            if (!array_key_exists($drop, $payload)) {
                continue;
            }
            unset($payload[$drop]);
            $token = self::encode($payload, $derivedKey);
        }

        return $token;
    }

    /**
     * Encode payload → b64url(json) . '.' . b64url(first 16 MAC bytes). The
     * MAC is computed over the ASCII bytes of the ENCODED payload string,
     * not the raw JSON — exactly what the Worker verifies.
     *
     * @param array<string, int|string> $payload claims in canonical order
     */
    private static function encode(array $payload, string $derivedKey): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new RuntimeException('t202ctx payload failed to JSON-encode: ' . json_last_error_msg());
        }
        $encPayload = self::b64url($json);
        $mac = substr(hash_hmac('sha256', $encPayload, $derivedKey, true), 0, self::MAC_BYTES);

        return $encPayload . '.' . self::b64url($mac);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Character-based truncation (spec truncates chars, not bytes). */
    private static function truncate(string $value, int $chars): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $chars, 'UTF-8');
        }

        // Byte fallback; JSON_INVALID_UTF8_SUBSTITUTE cleans a split char.
        return substr($value, 0, $chars);
    }
}

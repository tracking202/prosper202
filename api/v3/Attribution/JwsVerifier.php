<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * Verifies the compact JSON Web Signature (RFC 7515) that wraps an
 * AdAttributionKit postback.
 *
 * Apple signs the JWS with ES256 (ECDSA over NIST P-256 with SHA-256) and
 * names the key in the protected header's `kid`. Three keys exist, listed
 * verbatim in Apple's "Verifying a postback" (AdAttributionKit):
 *
 *   apple-cas-identifier/0          production — the same key SKAdNetwork
 *                                   postbacks are signed with
 *   apple-development-identifier/0  development, end-to-end test flows
 *   apple-development-identifier/1  development, postbacks generated from
 *                                   the device's Developer settings
 *
 * The verdict distinguishes the development keys (SignatureState::DEVELOPMENT)
 * from the production key (VALID): a signature that checks out against a
 * development key proves only that some phone in Developer Mode signed it,
 * so what it is worth is the app registration's decision, not this class's.
 *
 * The header's `alg` is never used to choose the algorithm — that is the
 * classic JWS "alg: none" hole. ES256 is required and the key is chosen by
 * `kid` alone; any other `alg` is a forgery attempt and INVALID. A `kid`
 * this class does not know is UNVERIFIABLE: it cannot be judged, and a key
 * Apple has not published cannot be genuine here either way.
 *
 * ES256 signatures in a JWS are the raw 64-byte R||S concatenation
 * (RFC 7518 §3.4); OpenSSL verifies DER-encoded ECDSA signatures, so the
 * raw form is re-encoded before verification. The signing input is the
 * ASCII of the first two base64url segments joined by "." exactly as
 * received (RFC 7515 §5.2), never a re-serialization of the decoded JSON.
 */
final class JwsVerifier
{
    public const PRODUCTION_KEY_ID = 'apple-cas-identifier/0';

    /** Keys Apple uses only outside production. */
    public const DEVELOPMENT_KEY_IDS = [
        'apple-development-identifier/0',
        'apple-development-identifier/1',
    ];

    /**
     * kid => base64 X.509 SubjectPublicKeyInfo (NIST P-256), copied verbatim
     * from Apple's "Verifying a postback" for AdAttributionKit.
     */
    public const APPLE_KEYS = [
        self::PRODUCTION_KEY_ID => PostbackVerifier::APPLE_PUBLIC_KEY_B64,
        'apple-development-identifier/0' =>
            'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAELeEDzpJEP+/qRSE5hJVC1p1J0ssUnQGMzBBbvnACBok8'
            . 'OVGGLgxL0myrKiy6lvRtSlLRsWit87i+vftD8AEqeQ==',
        'apple-development-identifier/1' =>
            'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE8YzdO7eM97s/IJ25kdW5CZ3A14USE5IJ5Ha/vhWaxI6U'
            . 'BI1ZxCEvjrKxVluVGe6qWwF1BDFq+QHqKfH5u+wxHQ==',
    ];

    /** The only algorithm Apple signs postbacks with. */
    public const ALGORITHM = 'ES256';

    /** @var array<string, string> */
    private readonly array $keys;

    /** @var list<string> */
    private readonly array $developmentKeyIds;

    /**
     * @param array<string, string>|null $keys              Override the key
     *                                   set (kid => base64 SPKI) — tests sign
     *                                   fixtures with their own P-256 key.
     *                                   Production uses Apple's.
     * @param list<string>|null          $developmentKeyIds Which of those kids
     *                                   are development keys.
     */
    public function __construct(?array $keys = null, ?array $developmentKeyIds = null)
    {
        $this->keys = $keys ?? self::APPLE_KEYS;
        $this->developmentKeyIds = $developmentKeyIds ?? self::DEVELOPMENT_KEY_IDS;
    }

    /**
     * Split and decode a compact JWS without judging its signature.
     *
     * Returns the decoded parts, or a message saying why the string is not
     * a JWS at all (wrong segment count, non-base64url characters, a header
     * or payload that is not a JSON object, a header without string `alg`
     * and `kid`). Those are structural: no genuine device sends them, and
     * without a payload there is nothing to store, so the caller answers
     * 400 rather than storing a flagged row.
     *
     * @return array{header: array<string, mixed>, payload: array<string, mixed>, signature: string, signing_input: string}|string
     */
    public static function decode(string $jws): array|string
    {
        $segments = explode('.', $jws);
        if (count($segments) !== 3) {
            return 'Must be a compact JWS: three base64url segments separated by dots';
        }
        foreach ($segments as $index => $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $segment) !== 1) {
                return sprintf('Segment %d is not unpadded base64url', $index + 1);
            }
        }

        $header = self::decodeJsonObject($segments[0]);
        if ($header === null) {
            return 'Header is not a base64url-encoded JSON object';
        }
        if (!is_string($header['alg'] ?? null) || !is_string($header['kid'] ?? null)) {
            return 'Header must carry string "alg" and "kid" parameters';
        }

        $payload = self::decodeJsonObject($segments[1]);
        if ($payload === null) {
            return 'Payload is not a base64url-encoded JSON object';
        }

        $signature = self::base64UrlDecode($segments[2]);
        if ($signature === null) {
            return 'Signature is not base64url';
        }

        return [
            'header' => $header,
            'payload' => $payload,
            'signature' => $signature,
            'signing_input' => $segments[0] . '.' . $segments[1],
        ];
    }

    /**
     * Judge a decoded JWS (from decode()). Returns a SignatureState value:
     * VALID or DEVELOPMENT when the signature checks out against the named
     * key, INVALID when it does not (or the header asks for anything but
     * ES256), UNVERIFIABLE when the key is unknown or OpenSSL is missing.
     *
     * @param array{header: array<string, mixed>, payload: array<string, mixed>, signature: string, signing_input: string} $decoded
     */
    public function verify(array $decoded): string
    {
        $header = $decoded['header'];
        $kid = $header['kid'] ?? null;
        $alg = $header['alg'] ?? null;
        if (!is_string($kid) || !is_string($alg)) {
            return SignatureState::INVALID;
        }
        if ($alg !== self::ALGORITHM || array_key_exists('crit', $header)) {
            // Apple signs with ES256 and nothing else; a header naming
            // another algorithm (or demanding extensions, RFC 7515 §4.1.11)
            // is a forgery attempt, not something this class cannot judge.
            return SignatureState::INVALID;
        }
        if (!isset($this->keys[$kid])) {
            return SignatureState::UNVERIFIABLE;
        }
        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
            return SignatureState::UNVERIFIABLE;
        }

        $publicKey = openssl_pkey_get_public(self::publicKeyPem($this->keys[$kid]));
        if ($publicKey === false) {
            // A bad key is an installation problem, not evidence about the
            // postback; never let it read as "invalid signature".
            return SignatureState::UNVERIFIABLE;
        }

        $der = self::rawSignatureToDer($decoded['signature']);
        if ($der === null) {
            // Not 64 bytes: whatever it is, it is not an ES256 signature.
            return SignatureState::INVALID;
        }

        // openssl_verify: 1 = valid, 0 = mismatch, -1/false = OpenSSL error.
        // With a loaded key and a well-formed DER blob the error case means
        // a corrupt signature, so anything but 1 is INVALID.
        $result = openssl_verify($decoded['signing_input'], $der, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            return SignatureState::INVALID;
        }
        return in_array($kid, $this->developmentKeyIds, true)
            ? SignatureState::DEVELOPMENT
            : SignatureState::VALID;
    }

    /**
     * Re-encode a JWS ES256 signature (raw R||S, 32 bytes each, RFC 7518
     * §3.4) as the DER ECDSA-Sig-Value OpenSSL verifies:
     *
     *   SEQUENCE { INTEGER r, INTEGER s }
     *
     * DER INTEGERs are minimal two's-complement: leading zero bytes are
     * dropped, and a 0x00 is prepended when the top bit is set so the value
     * stays positive. Null when the input is not 64 bytes.
     */
    public static function rawSignatureToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }
        $body = self::derInteger(substr($raw, 0, 32)) . self::derInteger(substr($raw, 32));
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        } elseif ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        // Every length here is under 128 (two 33-byte integers at most), so
        // the short form always applies; the long form is spelled out so
        // the function is correct rather than merely sufficient.
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /** @return array<string, mixed>|null */
    private static function decodeJsonObject(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);
        if ($json === null) {
            return null;
        }
        // Decoded as objects first because with assoc decoding "{}" and
        // "[]" both become [] and a list could pass as an object; the
        // associative decode of the same bytes is what the caller gets.
        if (!json_decode($json, false, 32) instanceof \stdClass) {
            return null;
        }
        $value = json_decode($json, true, 32);
        return is_array($value) ? $value : null;
    }

    private static function base64UrlDecode(string $segment): ?string
    {
        $base64 = strtr($segment, '-_', '+/');
        $remainder = strlen($base64) % 4;
        if ($remainder === 1) {
            return null; // no base64 string has this length
        }
        if ($remainder !== 0) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($base64, true);
        return $decoded === false ? null : $decoded;
    }

    private static function publicKeyPem(string $publicKeyB64): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($publicKeyB64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}

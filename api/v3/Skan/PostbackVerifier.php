<?php

declare(strict_types=1);

namespace Api\V3\Skan;

/**
 * Verifies Apple's ECDSA signature on SKAdNetwork install-validation
 * postbacks.
 *
 * Apple signs each postback over a subset of its parameters joined with the
 * invisible separator U+2063, in a version-specific order. The composition
 * below follows Apple's "Verifying an install-validation postback",
 * "Combining parameters for SKAdNetwork 3 postbacks", and "Combining
 * parameters for previous SKAdNetwork postback versions":
 *
 *   4.0: version, ad-network-id, source-identifier, app-id, transaction-id,
 *        redownload, [source-app-id | source-domain], fidelity-type,
 *        did-win, postback-sequence-index
 *   3.0: version, ad-network-id, campaign-id, app-id, transaction-id,
 *        redownload, [source-app-id], fidelity-type, did-win
 *   2.2: version, ad-network-id, campaign-id, app-id, transaction-id,
 *        redownload, [source-app-id], fidelity-type
 *   2.1: version, ad-network-id, campaign-id, app-id, transaction-id,
 *        redownload, [source-app-id]
 *
 * Booleans render as the strings "true"/"false"; numbers as decimal strings;
 * bracketed parameters join only when present in the postback. The
 * conversion values (fine and coarse) and country-code are never part of the
 * signature in any version.
 *
 * Versions 2.1 and later are signed with Apple's NIST P-256 key
 * (SHA-256/ECDSA). Versions 1.0 and 2.0 used an older P-192 key and have not
 * been emitted by devices since iOS 14.5 (2021); postbacks claiming those
 * versions — or versions newer than 4.0, whose composition is unknown — are
 * reported as UNVERIFIABLE rather than guessed at.
 */
final class PostbackVerifier
{
    /**
     * Apple's SKAdNetwork public key for postback versions 2.1 and later
     * (base64 X.509 SubjectPublicKeyInfo, NIST P-256), copied verbatim from
     * "Verifying an install-validation postback".
     */
    public const APPLE_PUBLIC_KEY_B64 =
        'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEWdp8GPcGqmhgzEFj9Z2nSpQVddayaPe4'
        . 'FMzqM9wib1+aHaaIzoHoLN9zW4K8y4SPykE3YVK3sVqW6Af0lfx3gg==';

    /** The signature checks out against the verification key. */
    public const RESULT_VALID = 'valid';

    /** The signature is wrong, or the payload lacks the signed fields it claims. */
    public const RESULT_INVALID = 'invalid';

    /**
     * This verifier cannot judge the postback: the version's signing scheme
     * is unknown here (1.0/2.0's retired P-192 key, or a version newer than
     * 4.0), or OpenSSL support is missing.
     */
    public const RESULT_UNVERIFIABLE = 'unverifiable';

    /** Versions whose signature composition and key this verifier knows. */
    public const VERIFIABLE_VERSIONS = ['2.1', '2.2', '3.0', '4.0'];

    private const SEPARATOR = "\u{2063}";

    private readonly string $publicKeyB64;

    /**
     * @param string|null $publicKeyB64 Override the verification key
     *                                  (base64 SubjectPublicKeyInfo) — tests
     *                                  sign fixtures with their own P-256
     *                                  key. Production uses Apple's.
     */
    public function __construct(?string $publicKeyB64 = null)
    {
        $this->publicKeyB64 = $publicKeyB64 ?? self::APPLE_PUBLIC_KEY_B64;
    }

    /**
     * Verify a decoded postback. Returns one of the RESULT_* constants.
     *
     * @param array<string, mixed> $postback Decoded postback JSON.
     */
    public function verify(array $postback): string
    {
        $version = $postback['version'] ?? null;
        if (!is_string($version) || !in_array($version, self::VERIFIABLE_VERSIONS, true)) {
            return self::RESULT_UNVERIFIABLE;
        }

        $message = $this->buildSignedMessage($postback);
        if ($message === null) {
            // The payload claims a version whose signed fields it does not
            // carry (or carries with the wrong types). Apple never emits
            // that, so it cannot be genuine.
            return self::RESULT_INVALID;
        }

        $signatureB64 = $postback['attribution-signature'] ?? null;
        if (!is_string($signatureB64) || trim($signatureB64) === '') {
            return self::RESULT_INVALID;
        }
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || $signature === '') {
            return self::RESULT_INVALID;
        }

        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
            return self::RESULT_UNVERIFIABLE;
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyPem());
        if ($publicKey === false) {
            // A bad key is an installation problem, not evidence about the
            // postback; never let it read as "invalid signature".
            return self::RESULT_UNVERIFIABLE;
        }

        // openssl_verify: 1 = valid, 0 = signature does not match, -1/false =
        // OpenSSL error. With a loaded key and a built-in algorithm, the
        // error case means the signature blob itself is not parseable ECDSA
        // DER — an artifact of a forged or corrupted postback, so it is
        // INVALID, not "cannot judge". (Environment problems — missing
        // extension, unparseable key — were returned as UNVERIFIABLE above,
        // before the signature was consulted.)
        $result = openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1 ? self::RESULT_VALID : self::RESULT_INVALID;
    }

    /**
     * Build the exact UTF-8 string Apple signed, or null when the postback
     * is missing (or mis-types) a field its version signs over.
     *
     * @param array<string, mixed> $postback Decoded postback JSON.
     */
    public function buildSignedMessage(array $postback): ?string
    {
        $version = $postback['version'] ?? null;
        if (!is_string($version)) {
            return null;
        }

        $parts = match ($version) {
            '4.0' => $this->partsV4($postback),
            '3.0' => $this->partsV3($postback),
            '2.2' => $this->partsV2($postback, withFidelity: true),
            '2.1' => $this->partsV2($postback, withFidelity: false),
            default => null,
        };

        if ($parts === null) {
            return null;
        }
        return implode(self::SEPARATOR, $parts);
    }

    /** @param array<string, mixed> $postback
     *  @return list<string>|null */
    private function partsV4(array $postback): ?array
    {
        $parts = [];
        foreach (['version', 'ad-network-id', 'source-identifier'] as $key) {
            $value = self::asString($postback[$key] ?? null);
            if ($value === null) {
                return null;
            }
            $parts[] = $value;
        }
        $appId = self::asNumberString($postback['app-id'] ?? null);
        $transactionId = self::asString($postback['transaction-id'] ?? null);
        $redownload = self::asBoolString($postback['redownload'] ?? null);
        if ($appId === null || $transactionId === null || $redownload === null) {
            return null;
        }
        $parts[] = $appId;
        $parts[] = $transactionId;
        $parts[] = $redownload;

        // Winning app-install ads carry source-app-id; SKAdNetwork web ads
        // carry source-domain instead; the privacy tier can withhold both.
        if (array_key_exists('source-app-id', $postback)) {
            $sourceAppId = self::asNumberString($postback['source-app-id']);
            if ($sourceAppId === null) {
                return null;
            }
            $parts[] = $sourceAppId;
        } elseif (array_key_exists('source-domain', $postback)) {
            $sourceDomain = self::asString($postback['source-domain']);
            if ($sourceDomain === null) {
                return null;
            }
            $parts[] = $sourceDomain;
        }

        $fidelityType = self::asNumberString($postback['fidelity-type'] ?? null);
        $didWin = self::asBoolString($postback['did-win'] ?? null);
        $sequenceIndex = self::asNumberString($postback['postback-sequence-index'] ?? null);
        if ($fidelityType === null || $didWin === null || $sequenceIndex === null) {
            return null;
        }
        $parts[] = $fidelityType;
        $parts[] = $didWin;
        $parts[] = $sequenceIndex;

        return $parts;
    }

    /** @param array<string, mixed> $postback
     *  @return list<string>|null */
    private function partsV3(array $postback): ?array
    {
        $parts = $this->partsV2($postback, withFidelity: true);
        if ($parts === null) {
            return null;
        }
        $didWin = self::asBoolString($postback['did-win'] ?? null);
        if ($didWin === null) {
            return null;
        }
        $parts[] = $didWin;
        return $parts;
    }

    /** @param array<string, mixed> $postback
     *  @return list<string>|null */
    private function partsV2(array $postback, bool $withFidelity): ?array
    {
        $version = self::asString($postback['version'] ?? null);
        $adNetworkId = self::asString($postback['ad-network-id'] ?? null);
        $campaignId = self::asNumberString($postback['campaign-id'] ?? null);
        $appId = self::asNumberString($postback['app-id'] ?? null);
        $transactionId = self::asString($postback['transaction-id'] ?? null);
        $redownload = self::asBoolString($postback['redownload'] ?? null);
        if (
            $version === null || $adNetworkId === null || $campaignId === null
            || $appId === null || $transactionId === null || $redownload === null
        ) {
            return null;
        }

        $parts = [$version, $adNetworkId, $campaignId, $appId, $transactionId, $redownload];

        if (array_key_exists('source-app-id', $postback)) {
            $sourceAppId = self::asNumberString($postback['source-app-id']);
            if ($sourceAppId === null) {
                return null;
            }
            $parts[] = $sourceAppId;
        }

        if ($withFidelity) {
            $fidelityType = self::asNumberString($postback['fidelity-type'] ?? null);
            if ($fidelityType === null) {
                return null;
            }
            $parts[] = $fidelityType;
        }

        return $parts;
    }

    private function publicKeyPem(): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($this->publicKeyB64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function asNumberString(mixed $value): ?string
    {
        // Postback numbers are JSON integers. Floats, numeric strings, and
        // booleans are not what Apple emits, so they void the message rather
        // than being coerced into one that "verifies" differently than the
        // payload reads.
        return is_int($value) ? (string)$value : null;
    }

    private static function asBoolString(mixed $value): ?string
    {
        if (!is_bool($value)) {
            return null;
        }
        return $value ? 'true' : 'false';
    }
}

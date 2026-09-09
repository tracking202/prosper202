<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * The one ECDSA P-256 / SHA-256 signature check both postback verifiers run.
 *
 * SKAdNetwork postbacks (PostbackVerifier) and AdAttributionKit's compact JWS
 * (JwsVerifier) are signed with the same primitive over the same Apple key
 * material — JwsVerifier's production entry is literally
 * PostbackVerifier::APPLE_PUBLIC_KEY_B64. Both are the same trust boundary, so
 * they have to agree byte-for-byte about what counts as "the signature is
 * wrong" versus "this installation is in no position to say". Keeping the
 * primitive and the PEM construction here means that boundary is defined once
 * instead of being rediscovered in each verifier.
 *
 * The answer is deliberately three-valued, because two of the ways this can
 * fail say nothing whatsoever about the message:
 *
 *   true   the signature verifies against the key.
 *   false  the signature does not verify — a forged or corrupted message.
 *   null   this installation cannot judge: OpenSSL is missing, or the
 *          verification key itself will not load. Neither is evidence about
 *          the message, so a caller must map null onto its own "unverifiable"
 *          verdict and never fold it into either boolean — least of all into
 *          the verified one.
 */
final class EcdsaP256
{
    /**
     * @param string      $message      The exact bytes that were signed.
     * @param string|null $derSignature The signature as a DER ECDSA-Sig-Value.
     *                                  Null when the caller has already
     *                                  established the blob cannot be one (a
     *                                  JWS signature segment that is not 64
     *                                  bytes, say). That is answered false —
     *                                  but only after the environment checks
     *                                  below have passed, so a host that
     *                                  cannot verify anything still reports
     *                                  that it cannot judge instead of
     *                                  pronouncing the signature wrong.
     * @param string      $publicKeyB64 base64 X.509 SubjectPublicKeyInfo for
     *                                  the NIST P-256 verification key.
     */
    public static function verify(string $message, ?string $derSignature, string $publicKeyB64): ?bool
    {
        if (!function_exists('openssl_verify') || !function_exists('openssl_pkey_get_public')) {
            return null;
        }

        $publicKey = openssl_pkey_get_public(self::publicKeyPem($publicKeyB64));
        if ($publicKey === false) {
            // A bad key is an installation problem, not evidence about the
            // message; never let it read as "invalid signature".
            return null;
        }

        if ($derSignature === null) {
            return false;
        }

        // openssl_verify: 1 = valid, 0 = signature does not match, -1/false =
        // OpenSSL error. With a loaded key and a built-in algorithm the error
        // case means the signature blob itself is not parseable ECDSA DER — an
        // artifact of a forged or corrupted message, so it is a rejection, not
        // "cannot judge". (Environment problems were answered null above,
        // before the signature was consulted.)
        return openssl_verify($message, $derSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** The PEM OpenSSL wants, built from the base64 SubjectPublicKeyInfo. */
    private static function publicKeyPem(string $publicKeyB64): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($publicKeyB64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}

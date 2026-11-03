<?php

declare(strict_types=1);

namespace Prosper202\Update;

/**
 * The 1-click upgraders install only a zip signed by Tracking202's release key
 * (Ed25519, detached), downloaded over HTTPS from my.tracking202.com.
 */
final class ReleaseSignature
{
    /**
     * Base64 Ed25519 public key of the release signing key.
     * PLACEHOLDER until the owner sets it: no valid key, so every upgrade is refused.
     */
    public const string PUBLIC_KEY = 'PLACEHOLDER-SET-THE-RELEASE-PUBLIC-KEY';

    public const string HOST = 'my.tracking202.com';
    public const int MAX_REDIRECTS = 3;
    private const string NO_KEY =
        'This install has no release signing key to check the download with. Upgrade by hand.';
    private const string ONLY_HOST = 'updates come only from https://' . self::HOST . '.';

    /**
     * Why $zip may not be installed, or null when $signature (base64) is a
     * valid signature of it by $publicKey (base64).
     */
    public static function problem(string $zip, ?string $signature, string $publicKey = self::PUBLIC_KEY): ?string
    {
        $notReady = self::notReady($publicKey);
        if ($notReady !== null) {
            return $notReady;
        }
        $key = self::decode($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($key === null) {
            return self::NO_KEY;
        }
        if ($signature === null || trim($signature) === '') {
            return 'The update server sent no signature for this release, so it was not installed.';
        }
        $sig = self::decode($signature, SODIUM_CRYPTO_SIGN_BYTES);
        if ($sig === null) {
            return 'The release signature is malformed, so the release was not installed.';
        }
        if ($zip === '') {
            return 'The download was empty, so nothing was installed.';
        }
        try {
            $valid = sodium_crypto_sign_verify_detached($sig, $zip, $key);
        } catch (\SodiumException) {
            $valid = false;
        }
        return $valid ? null : 'The download is not signed by Tracking202, so it was not installed.';
    }

    /** Why no download could be checked (no sodium, no key), or null; checked before downloading. */
    public static function notReady(string $publicKey = self::PUBLIC_KEY): ?string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return "This server's PHP has no sodium extension, so the download cannot be checked. Upgrade by hand.";
        }
        return self::decode($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) === null ? self::NO_KEY : null;
    }

    /**
     * Base64 detached signature of $zip by $secretKey (base64, 64 bytes). Throws
     * unless the key's public half is $expectedPublicKey, so a release can never
     * be signed by a key installs do not check against.
     */
    public static function sign(string $zip, string $secretKey, string $expectedPublicKey = self::PUBLIC_KEY): string
    {
        $secret = self::decode($secretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        if ($secret === null) {
            throw new \RuntimeException('the signing key is not a base64 Ed25519 secret key (64 bytes)');
        }
        $public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        if (!hash_equals(trim($expectedPublicKey), $public)) {
            throw new \RuntimeException(
                'the signing key does not match ReleaseSignature::PUBLIC_KEY (public key ' . $public . ')'
            );
        }
        $signature = base64_encode(sodium_crypto_sign_detached($zip, $secret));
        sodium_memzero($secret);
        if (self::problem($zip, $signature, $expectedPublicKey) !== null) {
            throw new \RuntimeException('the signature does not verify');
        }
        return $signature;
    }

    /**
     * $url when it is https://my.tracking202.com at the default port with no credentials; else null.
     *
     * @return non-empty-string|null
     */
    public static function allowedUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== self::HOST
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }
        return $url;
    }

    /**
     * Download $url, following redirects only to allowedUrl() targets.
     *
     * @param (callable(string, int): ?array{int, string, ?string})|null $hop one
     *        request without redirects: [status, body, redirect URL], or null
     *        when nothing came back; the default is curl, HTTPS only
     * @return array{body: ?string, error: ?string} body set only on HTTP 200
     */
    public static function download(string $url, int $timeoutSeconds = 600, ?callable $hop = null): array
    {
        $hop ??= self::curlHop(...);
        $deadline = microtime(true) + $timeoutSeconds;
        for ($hops = 0;; $hops++) {
            $allowed = self::allowedUrl($url);
            if ($allowed === null) {
                return ['body' => null, 'error' => 'Refused to download from ' . $url . ': ' . self::ONLY_HOST];
            }
            $remaining = (int)ceil($deadline - microtime(true));
            if ($remaining <= 0) {
                return ['body' => null, 'error' => 'The download timed out.'];
            }
            $answer = $hop($allowed, $remaining);
            if ($answer === null) {
                return ['body' => null, 'error' => 'The update server did not answer.'];
            }
            [$status, $body, $redirect] = $answer;
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($hops >= self::MAX_REDIRECTS) {
                    return ['body' => null, 'error' => 'The update server redirected too many times.'];
                }
                if ($redirect === null || self::allowedUrl($redirect) === null) {
                    $target = $redirect ?? '(nowhere)';
                    return ['body' => null, 'error' => 'Refused a redirect to ' . $target . ': ' . self::ONLY_HOST];
                }
                $url = $redirect;
                continue;
            }
            if ($status !== 200) {
                return ['body' => null, 'error' => 'The update server answered HTTP ' . $status . '.'];
            }
            return ['body' => $body, 'error' => null];
        }
    }

    /**
     * @param non-empty-string $url
     * @return array{int, string, ?string}|null
     */
    private static function curlHop(string $url, int $timeoutSeconds): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        $body = curl_exec($ch);
        if (curl_errno($ch) !== 0 || !is_string($body)) {
            error_log('ReleaseSignature: no answer from ' . $url . ': ' . curl_error($ch));
            return null;
        }
        $redirect = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        return [
            (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            $body,
            is_string($redirect) && $redirect !== '' ? $redirect : null,
        ];
    }

    /** @return non-empty-string|null exactly $bytes raw bytes */
    private static function decode(string $base64, int $bytes): ?string
    {
        $raw = base64_decode(trim($base64), true);
        return is_string($raw) && $raw !== '' && strlen($raw) === $bytes ? $raw : null;
    }
}

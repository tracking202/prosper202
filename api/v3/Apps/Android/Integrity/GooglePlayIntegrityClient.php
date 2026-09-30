<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * The real Play Integrity client: an OAuth 2.0 access token from the
 * service account (the JWT bearer grant, RFC 7523, signed RS256 with
 * openssl_sign — no new dependency), then
 * `POST /v1/{packageName}:decodeIntegrityToken`.
 *
 * Transport rules, all fixed here rather than configurable:
 *  - the endpoints are pinned (TOKEN_URL, DECODE_ORIGIN): a service
 *    account's own `token_uri` is refused at configuration unless it is
 *    TOKEN_URL, so a signed assertion and the access token it buys only
 *    ever go to Google;
 *  - HTTPS only, the peer's certificate and host name verified, redirects
 *    never followed (a 3xx is a retryable failure, not a new destination);
 *  - 5 s to connect, 10 s in all, and at most 64 KB read back;
 *  - nothing Google or the network does throws: every outcome is a
 *    DecodeResult, with a reason sentence the operator sees.
 *
 * The one exception to the pinning is for tests and the live pass, which
 * cannot reach Google: `P202_PLAY_INTEGRITY_ENDPOINT` replaces the origin
 * of both endpoints — accepted only as `https://` on a loopback address, so
 * a stray setting in production cannot send a credential's access token
 * anywhere but this machine — with `P202_PLAY_INTEGRITY_CA_FILE` as the CA
 * that signs that server's certificate. Verification stays on. An invalid
 * override throws when the client is built: the worker stops and says so
 * rather than quietly using Google or nothing.
 *
 * Access tokens are cached on this object for their lifetime less a minute,
 * keyed by the exact key (a rotated key gets a new token), and dropped on a
 * 401. A failure is never cached.
 */
final class GooglePlayIntegrityClient implements PlayIntegrityClient
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const DECODE_ORIGIN = 'https://playintegrity.googleapis.com';
    public const SCOPE = 'https://www.googleapis.com/auth/playintegrity';
    public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 10;
    public const MAX_RESPONSE_BYTES = 65536;
    public const ENV_ENDPOINT = 'P202_PLAY_INTEGRITY_ENDPOINT';
    public const ENV_CA_FILE = 'P202_PLAY_INTEGRITY_CA_FILE';
    private const LOOPBACK = ['127.0.0.1', '[::1]', 'localhost'];

    private string $tokenUrl;
    private string $decodeOrigin;
    /** @var array<string, array{token: string, expires: int}> */
    private array $accessTokens = [];

    /**
     * @param string|null $loopbackOrigin a test server's `https://127.0.0.1:<port>` in place of Google's origins
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        ?string $loopbackOrigin = null,
        private readonly ?string $caFile = null,
        private readonly int $timeout = self::TIMEOUT,
        private $clock = null,
    ) {
        if ($loopbackOrigin === null) {
            if ($caFile !== null) {
                throw new \InvalidArgumentException('A CA file is only used with a loopback test endpoint; Google is verified against the system trust store');
            }
            $this->tokenUrl = self::TOKEN_URL;
            $this->decodeOrigin = self::DECODE_ORIGIN;
        } else {
            $origin = self::loopbackOrigin($loopbackOrigin);
            $this->tokenUrl = $origin . '/token';
            $this->decodeOrigin = $origin;
        }
    }

    /** The client the worker uses: Google, or the loopback test endpoint the environment names. */
    public static function fromEnvironment(): self
    {
        $endpoint = getenv(self::ENV_ENDPOINT);
        if ($endpoint === false || $endpoint === '') {
            return new self();
        }
        $ca = getenv(self::ENV_CA_FILE);

        return new self($endpoint, $ca === false || $ca === '' ? null : $ca);
    }

    public function decode(ServiceAccountCredential $credential, string $packageName, string $integrityToken): DecodeResult
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/D', $packageName) !== 1) {
            return DecodeResult::rejected('"' . mb_strimwidth($packageName, 0, 80, '…') . '" is not an Android package name Google can decode for.');
        }
        $access = $this->accessToken($credential);
        if ($access instanceof DecodeResult) {
            return $access;
        }

        $response = $this->post(
            $this->decodeOrigin . '/v1/' . rawurlencode($packageName) . ':decodeIntegrityToken',
            ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
            json_encode(['integrity_token' => $integrityToken], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        if ($response['error'] !== null) {
            return DecodeResult::retry('Could not reach Play Integrity: ' . $response['error']);
        }
        $status = $response['status'];
        if ($status === 200) {
            $decoded = self::json($response['body']);
            $payload = $decoded['tokenPayloadExternal'] ?? null;
            if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
                return DecodeResult::retry('Play Integrity answered 200 without a tokenPayloadExternal object.', 200);
            }

            return DecodeResult::decoded($payload);
        }
        $said = self::googleMessage($response['body']);
        if ($status === 400) {
            return DecodeResult::rejected('Google could not decode the token (400' . $said . ').', 400);
        }
        if ($status === 401) {
            unset($this->accessTokens[$credential->cacheKey()]);

            return DecodeResult::retry('Play Integrity refused the access token (401' . $said . '); a new one is minted next attempt.', 401);
        }
        if ($status === 403 || $status === 404) {
            return DecodeResult::retry('Play Integrity refused the service account for ' . $packageName . ' (' . $status . $said
                . '): link the Google Cloud project to the app in Play Console and enable the Play Integrity API for it.', $status);
        }
        if ($status === 429) {
            return DecodeResult::retry('Play Integrity quota exhausted (429' . $said . '); the install stays pending, it is never waved through.', 429);
        }
        if ($status >= 300 && $status < 400) {
            return DecodeResult::retry('Play Integrity answered a redirect (' . $status . '), which is never followed.', $status);
        }

        return DecodeResult::retry('Play Integrity answered ' . $status . $said . '.', $status);
    }

    /**
     * An access token for the account, from the cache or the token endpoint.
     *
     * @return string|DecodeResult the token, or the retry that explains why there is none
     */
    private function accessToken(ServiceAccountCredential $credential): string|DecodeResult
    {
        $now = $this->now();
        $cached = $this->accessTokens[$credential->cacheKey()] ?? null;
        if ($cached !== null && $cached['expires'] > $now) {
            return $cached['token'];
        }
        $assertion = self::assertion($credential, $now);
        if ($assertion === null) {
            return DecodeResult::retry('The service account\'s private key could not sign the token request.');
        }
        $response = $this->post(
            $this->tokenUrl,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => self::GRANT_TYPE, 'assertion' => $assertion], '', '&', PHP_QUERY_RFC3986),
        );
        if ($response['error'] !== null) {
            return DecodeResult::retry('Could not reach Google\'s token endpoint: ' . $response['error']);
        }
        $body = self::json($response['body']);
        if ($response['status'] !== 200) {
            $what = is_string($body['error'] ?? null) ? ': ' . self::clean((string) $body['error'])
                . (is_string($body['error_description'] ?? null) ? ' — ' . self::clean((string) $body['error_description']) : '') : '';

            return DecodeResult::retry('Google refused the service account ' . $credential->clientEmail . ' (' . $response['status'] . $what
                . '); check or rotate the credential.', $response['status']);
        }
        $token = $body['access_token'] ?? null;
        $expires = $body['expires_in'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096 || preg_match('/^[\x21-\x7E]+$/D', $token) !== 1 || !is_int($expires) || $expires <= 0) {
            return DecodeResult::retry('Google\'s token endpoint answered 200 without a usable access_token.', 200);
        }
        $this->accessTokens[$credential->cacheKey()] = ['token' => $token, 'expires' => $now + max(0, $expires - 60)];

        return $token;
    }

    /** The signed JWT (RS256) the token endpoint exchanges for an access token; null when signing failed. */
    public static function assertion(ServiceAccountCredential $credential, int $now): ?string
    {
        $segment = static fn (array $part): string => self::base64Url(json_encode($part, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signingInput = $segment(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $credential->privateKeyId]) . '.' . $segment([
            'iss' => $credential->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $key = openssl_pkey_get_private($credential->privateKey);
        $signature = '';
        if ($key === false || !openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    /**
     * One HTTPS POST under the transport rules above.
     *
     * @param list<string> $headers
     * @return array{status: int, body: string, error: string|null}
     */
    private function post(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl could not start'];
        }
        $received = '';
        $overflow = false;
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [...$headers, 'Accept: application/json', 'User-Agent: Prosper202-PlayIntegrity/1'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received, &$overflow): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $overflow = true;

                    return 0; // aborts the transfer
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($this->decodeOrigin !== self::DECODE_ORIGIN) {
            // The loopback test endpoint: never through a proxy, and trusted
            // through the CA the test named (verification stays on).
            $options[CURLOPT_NOPROXY] = '*';
            if ($this->caFile !== null) {
                $options[CURLOPT_CAINFO] = $this->caFile;
            }
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? ($overflow ? 'the answer exceeded ' . self::MAX_RESPONSE_BYTES . ' bytes' : self::clean(curl_error($ch))) : null;
        curl_close($ch);

        return ['status' => $status, 'body' => $received, 'error' => $error];
    }

    private static function loopbackOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !in_array($host, self::LOOPBACK, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException(self::ENV_ENDPOINT . ' must be https://127.0.0.1:<port> (or [::1], localhost): '
                . 'it exists for tests, and a Play Integrity endpoint off this machine is refused');
        }

        return 'https://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /** @return array<mixed> */
    private static function json(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function googleMessage(string $body): string
    {
        $error = self::json($body)['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return ': ' . self::clean((string) $error['message']);
        }

        return '';
    }

    private static function clean(string $text): string
    {
        return mb_strimwidth(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '', 0, 160, '…', 'UTF-8');
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}

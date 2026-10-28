<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

/**
 * MaxMind's direct download of a GeoLite edition, as its account portal
 * gives the permalinks (dev.maxmind.com/geoip/updating-databases):
 *
 *   GET https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz
 *   GET …?suffix=tar.gz.sha256   "<sha256>  GeoLite2-City_YYYYMMDD.tar.gz"
 *
 * with the account ID and licence key as HTTP Basic authentication. Both
 * answer a redirect to a presigned Cloudflare R2 URL, which is followed by
 * hand (the transport never follows one), through the guard again, and
 * without the credentials: they go with the permalink request alone. MaxMind
 * answers 401 for a wrong account ID or key and 429 past the account's
 * download limit (30 a day for GeoLite).
 */
final class MaxMindDownload
{
    public const ORIGIN = 'https://download.maxmind.com';
    public const ENV_ORIGIN = 'P202_GEOIP_DOWNLOAD_ORIGIN';
    public const ENV_CA_FILE = 'P202_GEOIP_CA_FILE';
    public const MAX_REDIRECTS = 3;
    public const MAX_CHECKSUM_BYTES = 1024;
    public const MAX_ARCHIVE_BYTES = 268435456;

    private const LOOPBACK_HOSTS = ['127.0.0.1', '[::1]'];

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $origin = self::ORIGIN,
        private readonly string $edition = CityDatabase::EDITION,
    ) {
    }

    /**
     * MaxMind, or the loopback test server the environment names. A value
     * that is not an https:// loopback origin throws rather than fall back
     * to MaxMind or to nothing.
     *
     * @throws RefreshFailed
     */
    public static function fromEnvironment(?int $deadline = null): self
    {
        $override = getenv(self::ENV_ORIGIN);
        if ($override === false || $override === '') {
            return new self(new GuardedCurlTransport(null, null, $deadline));
        }
        $origin = self::loopbackOrigin($override);
        $ca = getenv(self::ENV_CA_FILE);

        return new self(
            new GuardedCurlTransport($origin, $ca === false || $ca === '' ? null : $ca, $deadline),
            $origin
        );
    }

    /** @throws RefreshFailed */
    public static function loopbackOrigin(string $value): string
    {
        $parts = parse_url($value);
        $origin = is_array($parts) ? self::originOf($value) : null;
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($parts['host'] ?? '', self::LOOPBACK_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || $origin === null
        ) {
            // The value is not repeated: it may carry user info.
            throw new RefreshFailed(self::ENV_ORIGIN . ' is set, and only an https:// origin on 127.0.0.1 or [::1]'
                . ' is taken there (it is for tests), so nothing was downloaded.');
        }

        return $origin;
    }

    /** scheme://host[:port] of a URL, the port left out when it is the scheme's default; null when there is none. */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ['https' => 443, 'http' => 80][$scheme] ?? null;

        return $scheme . '://' . strtolower($parts['host']) . ($port === null || $port === $default ? '' : ':' . $port);
    }

    public function url(string $suffix): string
    {
        return $this->origin . '/geoip/databases/' . rawurlencode($this->edition) . '/download?suffix='
            . rawurlencode($suffix);
    }

    /**
     * The SHA-256 MaxMind publishes for the current archive.
     *
     * @return array{sha256: string, filename: string}
     * @throws RefreshFailed
     */
    public function checksum(Credentials $credentials): array
    {
        $response = $this->fetch($this->url('tar.gz.sha256'), $credentials, null, self::MAX_CHECKSUM_BYTES);
        $line = trim($response->body);
        if (preg_match('/^([0-9a-fA-F]{64})\s+(\S+)$/D', $line, $match) !== 1) {
            throw new RefreshFailed('MaxMind\'s checksum file does not read as a SHA-256 and a file name ("'
                . self::excerpt($line) . '"), so nothing was downloaded.');
        }
        if (preg_match('/^' . preg_quote($this->edition, '/') . '_[0-9]{8}\.tar\.gz$/D', $match[2]) !== 1) {
            throw new RefreshFailed('MaxMind\'s checksum names ' . self::excerpt($match[2]) . ', not a '
                . $this->edition . ' archive, so nothing was downloaded.');
        }

        return ['sha256' => strtolower($match[1]), 'filename' => $match[2]];
    }

    /**
     * The current archive, written to $sink.
     *
     * @param resource $sink
     * @return int bytes written
     * @throws RefreshFailed
     */
    public function archive(Credentials $credentials, $sink): int
    {
        return $this->fetch($this->url('tar.gz'), $credentials, $sink, self::MAX_ARCHIVE_BYTES)->bytes;
    }

    /**
     * @param resource|null $sink
     * @throws RefreshFailed
     */
    private function fetch(string $url, Credentials $credentials, $sink, int $maxBytes): HttpResponse
    {
        $current = $url;
        for ($hop = 0;; $hop++) {
            // The permalink only: a presigned URL carries its own authorization,
            // and storage behind it refuses a second one.
            $auth = $hop === 0 ? [$credentials->accountId, $credentials->licenseKey] : null;
            $response = $this->transport->get($current, $auth, $sink, $maxBytes);
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new RefreshFailed('MaxMind\'s download redirected more than ' . self::MAX_REDIRECTS
                        . ' times, so it was stopped.');
                }
                $next = self::resolve($current, $response->location);
                if ($next === null) {
                    throw new RefreshFailed('MaxMind answered ' . $response->status
                        . ' with no usable Location, so nothing was downloaded.');
                }
                $current = $next;
                continue;
            }
            if ($response->status === 200) {
                return $response;
            }
            throw new RefreshFailed(self::refusal($response, $this->edition));
        }
    }

    /** An absolute Location, or a path on the current origin; anything else is null. */
    private static function resolve(string $current, ?string $location): ?string
    {
        if ($location === null || $location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $origin = self::originOf($current);

            return $origin === null ? null : $origin . $location;
        }

        return null;
    }

    private static function refusal(HttpResponse $response, string $edition): string
    {
        $said = trim(self::excerpt($response->body));
        $said = $said === '' ? '' : ': "' . $said . '"';

        return match (true) {
            $response->status === 401 => 'MaxMind did not accept the account ID and licence key (401' . $said
                . '). Check both in Account › Settings › GeoIP database; a licence key is shown only once, when it'
                . ' is made, under Manage License Keys on maxmind.com.',
            $response->status === 403 => 'MaxMind refused the download (403' . $said . '): the licence key may have'
                . ' been revoked, or the account may not have GeoLite access.',
            $response->status === 404 => 'MaxMind has no ' . $edition . ' download at that address (404' . $said . ').',
            $response->status === 429 => 'MaxMind\'s download limit for this account is reached (429' . $said
                . '); the next refresh tries again.',
            $response->status >= 500 => 'MaxMind answered ' . $response->status . $said
                . '; the next refresh tries again.',
            default => 'MaxMind answered ' . $response->status . $said . ', so nothing was downloaded.',
        };
    }

    private static function excerpt(string $text): string
    {
        $clean = preg_replace('/[^\x20-\x7E]+/', ' ', $text) ?? '';

        return mb_strimwidth($clean, 0, 200, '…');
    }
}

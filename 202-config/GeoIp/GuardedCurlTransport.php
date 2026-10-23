<?php

declare(strict_types=1);

namespace Prosper202\GeoIp;

use Prosper202\Validation\OutboundUrlException;
use Prosper202\Validation\OutboundUrlGuard;

/**
 * The GeoIP download's HTTP: every URL, MaxMind's and each one it
 * redirects to, is approved by OutboundUrlGuard::assertAllowed() (https, port
 * 443, every address it resolves to public) and fetched with the guard's
 * curlOptions(), the set the webhook senders use: pinned to an approved
 * address, no proxy, no redirects, TLS verified. The one value changed is
 * the time limit, which the guard sets at 15 seconds for a webhook and a
 * download of tens of megabytes needs longer; a transfer slower than 1 KB/s
 * for a minute is stopped instead.
 *
 * Tests and the live pass cannot reach MaxMind: constructed with a loopback
 * origin (MaxMindDownload::fromEnvironment(), P202_GEOIP_DOWNLOAD_ORIGIN),
 * only that origin is fetched, pinned to its own address, over TLS verified
 * against the CA file given. Any other URL is refused, so a stray setting
 * cannot send the licence key anywhere but this machine.
 */
final class GuardedCurlTransport implements HttpTransport
{
    public const TIMEOUT = 600;
    public const LOW_SPEED_LIMIT = 1024;
    public const LOW_SPEED_TIME = 60;
    private const ERROR_BODY_BYTES = 4096;

    public function __construct(
        private readonly ?string $loopbackOrigin = null,
        private readonly ?string $caFile = null,
    ) {
    }

    #[\Override]
    public function get(string $url, ?array $basicAuth, $sink, int $maxBytes): HttpResponse
    {
        if ($url === '') {
            throw new RefreshFailed('No URL to download from.');
        }
        $pinned = $this->pinnedOptions($url);
        $ch = curl_init();
        if ($ch === false) {
            throw new RefreshFailed('curl could not be started, so nothing was downloaded.');
        }
        $status = 0;
        $location = null;
        $body = '';
        $bytes = 0;
        $tooBig = false;
        $unwritten = false;
        $request = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => 'Prosper202-GeoIP-Refresh/1.0',
            CURLOPT_HEADERFUNCTION => static function (
                \CurlHandle $handle,
                string $line,
            ) use (
                &$status,
                &$location,
            ): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                    $status = (int) $match[1];
                    $location = null;
                } elseif (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (
                \CurlHandle $handle,
                string $data,
            ) use (
                &$status,
                &$body,
                &$bytes,
                &$tooBig,
                &$unwritten,
                $sink,
                $maxBytes,
            ): int {
                $length = strlen($data);
                if ($status !== 200) {
                    $body .= substr($data, 0, max(0, self::ERROR_BODY_BYTES - strlen($body)));

                    return $length;
                }
                if ($bytes + $length > $maxBytes) {
                    $tooBig = true;

                    return 0;
                }
                $bytes += $length;
                if ($sink === null) {
                    $body .= $data;

                    return $length;
                }
                if (fwrite($sink, $data) !== $length) {
                    $unwritten = true;

                    return 0;
                }

                return $length;
            },
        ];
        if ($basicAuth !== null) {
            $request[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $request[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
        }
        curl_setopt_array($ch, $request);
        // Last, so nothing set above can loosen the pin (CLAUDE.md #24).
        curl_setopt_array($ch, $pinned);
        $done = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($tooBig) {
            throw new RefreshFailed('The download from ' . $host . ' passed ' . $maxBytes
                . ' bytes, so it was stopped.');
        }
        if ($unwritten) {
            throw new RefreshFailed('The download from ' . $host . ' could not be written to disk (is it full?).');
        }
        if ($done === false) {
            throw new RefreshFailed('Could not download from ' . $host . ': '
                . ($error !== '' ? $error : 'no reason given') . '.');
        }

        return new HttpResponse($status, $location, $body, $bytes);
    }

    /**
     * Every curl option the request is pinned and hardened by: the guard's
     * set with the download's time limit (and, on the loopback test origin,
     * its CA file) put in place of the guard's.
     *
     * @return array<int, mixed>
     * @throws RefreshFailed when the guard, or the loopback origin, refuses the URL
     */
    public function pinnedOptions(string $url): array
    {
        $download = [
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
        ];
        $addresses = null;
        if ($this->loopbackOrigin !== null) {
            if (MaxMindDownload::originOf($url) !== $this->loopbackOrigin) {
                throw new RefreshFailed('In test mode (' . MaxMindDownload::ENV_ORIGIN . ') only '
                    . $this->loopbackOrigin . ' is fetched, and ' . $url . ' is not on it.');
            }
            $addresses = [trim((string) parse_url($url, PHP_URL_HOST), '[]')];
            if ($this->caFile !== null) {
                $download[CURLOPT_CAINFO] = $this->caFile;
            }
        }
        try {
            $addresses ??= OutboundUrlGuard::assertAllowed($url, 'The GeoIP download URL', [443]);

            return array_replace(OutboundUrlGuard::curlOptions($url, $addresses), $download);
        } catch (OutboundUrlException $e) {
            throw new RefreshFailed('The outbound guard refused ' . $url . ': ' . $e->getMessage() . '.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Tracking202\Apps;

use Api\V3\Apps\AppIdentity;

/**
 * What the stores say about an app: its name and its icon, so Setup ›
 * Mobile Apps never asks for what it can find (UI standard, rule 3).
 *
 *  - iOS: the App Store lookup service (`/lookup?id=`), `trackName` and
 *    `artworkUrl100`.
 *  - Android: the Play listing (`/store/apps/details?id=`), its
 *    `og:title` (less Play's " - Apps on Google Play") and `og:image`.
 *
 * Best effort by design: the stores are third parties on the far side of a
 * short timeout, and neither a name nor an icon is worth failing a
 * registration over. Every failure answers "nothing found" and the page
 * asks for the name instead.
 *
 * The icon is fetched here, once, and kept as a data: URI (AppIcons), so a
 * page that shows it never makes the viewer's browser call Apple or Google.
 * It is fetched only over HTTPS from the stores' own image hosts, at most
 * MAX_ICON_BYTES, and only when its bytes are a PNG, JPEG, GIF or WebP —
 * the listing is not trusted to name what it serves.
 *
 * P202_APP_STORE_LOOKUP_ORIGIN points both lookups (and the icons) at a
 * loopback origin, for the live pass's fake store; any other value is
 * refused and logged, so it cannot become a way to make the server fetch
 * an arbitrary host.
 */
final class StoreListing
{
    public const MAX_ICON_BYTES = 40000;
    private const MAX_PAGE_BYTES = 2_000_000;
    private const APPLE_ORIGIN = 'https://itunes.apple.com';
    private const PLAY_ORIGIN = 'https://play.google.com';
    private const ICON_HOSTS = ['/\.mzstatic\.com$/D', '/^play-lh\.googleusercontent\.com$/D'];

    /** @var callable(string, int): (array{status: int, body: string}|null) */
    private $fetch;
    private ?string $override;

    /**
     * @param (callable(string, int): (array{status: int, body: string}|null))|null $fetch
     *   GET a URL, reading at most the given bytes; null on any failure
     */
    public function __construct(?callable $fetch = null, ?string $overrideOrigin = null)
    {
        $this->fetch = $fetch ?? self::curl(...);
        $this->override = self::usableOverride($overrideOrigin ?? (getenv('P202_APP_STORE_LOOKUP_ORIGIN') ?: null));
    }

    /**
     * @return array{name: string, icon: string|null} name '' when none was found
     */
    public function lookUp(AppIdentity $identity): array
    {
        [$name, $iconUrl] = $identity->platform === AppIdentity::IOS
            ? $this->apple($identity->appleAppId())
            : $this->play($identity->appKey);
        if ($name === '' && $identity->platform === AppIdentity::IOS && $identity->slug !== '') {
            $name = ucwords(str_replace('-', ' ', $identity->slug));
        }

        return ['name' => mb_substr(trim($name), 0, 255), 'icon' => $iconUrl === null ? null : $this->icon($iconUrl)];
    }

    /** @return array{0: string, 1: string|null} */
    private function apple(int $appId): array
    {
        $answer = ($this->fetch)(($this->override ?? self::APPLE_ORIGIN) . '/lookup?id=' . $appId, 200_000);
        if ($answer === null || $answer['status'] !== 200) {
            return ['', null];
        }
        $decoded = json_decode($answer['body'], true);
        $result = is_array($decoded) && is_array($decoded['results'][0] ?? null) ? $decoded['results'][0] : [];
        $name = is_string($result['trackName'] ?? null) ? $result['trackName'] : '';
        $icon = null;
        foreach (['artworkUrl100', 'artworkUrl60', 'artworkUrl512'] as $key) {
            if (is_string($result[$key] ?? null) && $result[$key] !== '') {
                $icon = $result[$key];
                break;
            }
        }

        return [$name, $icon];
    }

    /** @return array{0: string, 1: string|null} */
    private function play(string $package): array
    {
        $answer = ($this->fetch)(($this->override ?? self::PLAY_ORIGIN) . '/store/apps/details?id=' . rawurlencode($package) . '&hl=en', self::MAX_PAGE_BYTES);
        if ($answer === null || $answer['status'] !== 200) {
            return ['', null];
        }
        $meta = static function (string $property) use ($answer): ?string {
            // Attribute order varies, so both orders are read.
            foreach ([
                '/<meta[^>]+property=["\']' . preg_quote($property, '/') . '["\'][^>]*content=["\']([^"\']*)["\']/i',
                '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*property=["\']' . preg_quote($property, '/') . '["\']/i',
            ] as $pattern) {
                if (preg_match($pattern, $answer['body'], $m) === 1) {
                    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            return null;
        };
        $name = (string)($meta('og:title') ?? '');
        $name = (string)preg_replace('/\s+[-–]\s+Apps on Google Play$/u', '', $name);

        return [$name, $meta('og:image')];
    }

    /** The icon as a data: URI, or null when it cannot be had safely. */
    private function icon(string $url): ?string
    {
        if (!$this->iconHostAllowed($url)) {
            return null;
        }
        $answer = ($this->fetch)($url, self::MAX_ICON_BYTES + 1);
        if ($answer === null || $answer['status'] !== 200 || strlen($answer['body']) > self::MAX_ICON_BYTES || $answer['body'] === '') {
            return null;
        }
        $type = self::imageType($answer['body']);

        return $type === null ? null : 'data:' . $type . ';base64,' . base64_encode($answer['body']);
    }

    private function iconHostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if ($this->override !== null) {
            $origin = parse_url($this->override);
            return is_array($origin) && strtolower($parts['host']) === strtolower((string)$origin['host'])
                && ($parts['port'] ?? null) === ($origin['port'] ?? null);
        }
        if (strtolower($parts['scheme']) !== 'https' || isset($parts['port'])) {
            return false;
        }
        foreach (self::ICON_HOSTS as $pattern) {
            if (preg_match($pattern, strtolower($parts['host'])) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The image type the bytes are, by their magic number; null for anything else. */
    public static function imageType(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** A loopback origin (http or https, host and port only), or null. */
    private static function usableOverride(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }
        $parts = parse_url($origin);
        $ok = is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && in_array(strtolower((string)($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '[::1]'], true)
            && !isset($parts['path'], $parts['query'], $parts['user']);
        if (!$ok) {
            error_log('p202 store lookup: ignoring P202_APP_STORE_LOOKUP_ORIGIN "' . $origin . '"; only a loopback origin is accepted');
            return null;
        }

        return rtrim($origin, '/');
    }

    /** @return array{status: int, body: string}|null */
    private static function curl(string $url, int $maxBytes): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Prosper202',
            // Stop reading past the cap: returning a short count aborts the
            // transfer, which curl_exec then reports as a failure.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $ok === false ? null : ['status' => $status, 'body' => $body];
    }
}

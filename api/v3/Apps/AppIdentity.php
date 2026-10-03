<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Exception\ValidationException;

/**
 * Which app a request names: the one implementation of "is this a valid app
 * id, and what does this store link name" (plan §4.2).
 *
 * An app is (platform, app_key):
 *
 *  - ios: the App Store item id as canonical decimal — digits only,
 *    positive, no leading zero, and exactly the value PHP's int reproduces.
 *    That last rule is the saturation guard: (int) turns a twenty-digit
 *    string into PHP_INT_MAX, a positive number that would register an app
 *    nobody named (CLAUDE.md #18).
 *  - android: the application id (package name) — at least two
 *    dot-separated segments, each a letter followed by letters, digits or
 *    underscores. Case-sensitive: com.Example.app is not com.example.app.
 *    The package is the identity rather than the store listing, because one
 *    package ships through Play, Galaxy Store and AppGallery alike.
 *
 * Every entry point calls this on the RAW value, before anything casts it:
 * the API (`app_key` / `store_link`), the Setup page, and the CLI's
 * `--store-link` (which the server parses, so there is one parser).
 */
final class AppIdentity
{
    public const IOS = 'ios';
    public const ANDROID = 'android';

    /** @var list<string> */
    public const PLATFORMS = [self::IOS, self::ANDROID];

    /** The app_key column's width. */
    public const MAX_KEY_LENGTH = 255;

    private const ANDROID_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D';

    private const LINK_HINT = 'Must be an App Store link (https://apps.apple.com/…/id123456789), a Google Play link '
        . '(https://play.google.com/store/apps/details?id=com.example.app), a market://details?id=… link, '
        . 'a numeric App Store id or an Android package name.';

    private function __construct(
        public readonly string $platform,
        public readonly string $appKey,
        /** The human-readable slug an App Store link carried, for naming the app; '' otherwise. */
        public readonly string $slug = '',
    ) {
    }

    public static function apple(int $appStoreId): self
    {
        if ($appStoreId < 1) {
            throw new \InvalidArgumentException('An App Store id is a positive integer');
        }
        return new self(self::IOS, (string)$appStoreId);
    }

    /** The App Store item id of an iOS identity. */
    public function appleAppId(): int
    {
        if ($this->platform !== self::IOS) {
            throw new \LogicException('Only an iOS app has an App Store id');
        }
        return (int)$this->appKey;
    }

    /**
     * An App Store id's canonical key, or null when the value is not one.
     *
     * Two spellings are accepted: a PHP int (a JSON number) and a string of
     * digits (the CLI and form bodies send it that way). 1.5, '1e2', ' 1',
     * '0525463029', true and a twenty-digit string are each some OTHER
     * number after a cast, so each is refused rather than registered.
     */
    public static function appleKey(mixed $raw): ?string
    {
        if (is_int($raw)) {
            return $raw >= 1 ? (string)$raw : null;
        }
        if (!is_string($raw) || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            return null;
        }
        return (string)(int)$raw === $raw ? $raw : null;
    }

    /** An Android application id, or null when the value is not one. */
    public static function androidKey(mixed $raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_KEY_LENGTH) {
            return null;
        }
        return preg_match(self::ANDROID_PATTERN, $raw) === 1 ? $raw : null;
    }

    /**
     * The platform a value names, or null when it names none this install
     * knows. Case-insensitive and trimmed, because it is a vocabulary word
     * rather than an identifier; the stored spelling is always the constant.
     */
    public static function normalizePlatform(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        return in_array($value, self::PLATFORMS, true) ? $value : null;
    }

    /**
     * The identity a key names on a platform — or, with no platform, on the
     * one platform whose rule the key satisfies. The two rules cannot both
     * match (a package name has a letter in every segment, an App Store id
     * has none), so the inference is never a guess.
     *
     * @throws ValidationException naming `platform` or `app_key`
     */
    public static function fromKey(mixed $platform, mixed $appKey): self
    {
        if ($platform === null) {
            $apple = self::appleKey($appKey);
            if ($apple !== null) {
                return new self(self::IOS, $apple);
            }
            $android = self::androidKey($appKey);
            if ($android !== null) {
                return new self(self::ANDROID, $android);
            }
            throw new ValidationException('Invalid app_key', [
                'app_key' => 'Must be a positive App Store id (the number after "id" in the app\'s App Store '
                    . 'link, with no leading zero) or an Android package name such as com.example.app',
            ]);
        }

        $normalized = self::normalizePlatform($platform);
        if ($normalized === null) {
            throw new ValidationException('Invalid platform', [
                'platform' => 'Must be one of: ' . implode(', ', self::PLATFORMS),
            ]);
        }

        if ($normalized === self::IOS) {
            $key = self::appleKey($appKey);
            if ($key === null) {
                throw new ValidationException('Invalid app_key', [
                    'app_key' => 'Must be a positive App Store id (the number after "id" in the app\'s App Store '
                        . 'link), written as digits with no leading zero',
                ]);
            }
            return new self(self::IOS, $key);
        }

        $key = self::androidKey($appKey);
        if ($key === null) {
            throw new ValidationException('Invalid app_key', [
                'app_key' => 'Must be an Android application id: at least two dot-separated segments, each a letter '
                    . 'followed by letters, digits or underscores (e.g. com.example.app), at most '
                    . self::MAX_KEY_LENGTH . ' characters. It is case-sensitive.',
            ]);
        }
        return new self(self::ANDROID, $key);
    }

    /**
     * The app a store link names. Accepts, after trimming the whole value:
     *
     *   https://apps.apple.com/us/app/summit-run/id990077001 (and itunes.apple.com)
     *   id990077001, 990077001
     *   https://play.google.com/store/apps/details?id=com.example.app
     *   market://details?id=com.example.app
     *   com.example.app
     *
     * @throws ValidationException naming `store_link`
     */
    public static function fromStoreLink(mixed $link): self
    {
        if (!is_string($link) || trim($link) === '') {
            throw new ValidationException('Invalid store_link', [
                'store_link' => 'Paste the app\'s store link, its numeric App Store id or its package name.',
            ]);
        }
        $link = trim($link);

        if (preg_match('/^[0-9]+$/D', $link) === 1) {
            return self::appleFromLink($link, '');
        }
        if (preg_match('/^id([0-9]+)$/Di', $link, $m) === 1) {
            return self::appleFromLink($m[1], '');
        }
        if (self::androidKey($link) !== null) {
            return new self(self::ANDROID, $link);
        }

        // The address bar's copy often drops the scheme; the host alone says
        // which store it is.
        if (preg_match('~^(?:apps\.apple\.com|itunes\.apple\.com|play\.google\.com)/~i', $link) === 1) {
            $link = 'https://' . $link;
        }

        $parts = parse_url($link);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            throw new ValidationException('Invalid store_link', ['store_link' => self::LINK_HINT]);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        if ($scheme === 'market') {
            if ($host !== 'details') {
                throw new ValidationException('Invalid store_link', ['store_link' => self::LINK_HINT]);
            }
            return self::androidFromLink($query['id'] ?? null);
        }

        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new ValidationException('Invalid store_link', ['store_link' => self::LINK_HINT]);
        }

        if ($host === 'apps.apple.com' || $host === 'itunes.apple.com') {
            // Anchored to a path segment so "covid19" is not an app id.
            if (preg_match('~(?:^|/)id([0-9]+)(?:/|$)~', $path, $m) !== 1) {
                throw new ValidationException('Invalid store_link', [
                    'store_link' => 'That App Store link names no app: expected a path ending in /id followed by the '
                        . 'app\'s number, e.g. https://apps.apple.com/us/app/summit-run/id990077001',
                ]);
            }
            $slug = '';
            if (preg_match('~/app/([^/]+)/id[0-9]+~', $path, $s) === 1) {
                $slug = urldecode($s[1]);
            }
            return self::appleFromLink($m[1], $slug);
        }

        if ($host === 'play.google.com') {
            if (rtrim($path, '/') !== '/store/apps/details') {
                throw new ValidationException('Invalid store_link', [
                    'store_link' => 'That Google Play link names no app: expected '
                        . 'https://play.google.com/store/apps/details?id=<package name>',
                ]);
            }
            return self::androidFromLink($query['id'] ?? null);
        }

        throw new ValidationException('Invalid store_link', ['store_link' => self::LINK_HINT]);
    }

    /**
     * Resolve the app a create request names: `store_link`, or `app_key`
     * with an optional `platform`. Reads the RAW payload; nothing may cast
     * it first (CLAUDE.md #18).
     *
     * @param array<string, mixed> $payload
     * @throws ValidationException
     */
    public static function fromPayload(array $payload): self
    {
        $hasLink = array_key_exists('store_link', $payload) && $payload['store_link'] !== null;
        $hasKey = array_key_exists('app_key', $payload) && $payload['app_key'] !== null;
        $platform = array_key_exists('platform', $payload) ? $payload['platform'] : null;

        if ($hasLink && $hasKey) {
            throw new ValidationException('Validation failed', [
                'store_link' => 'Send store_link or app_key, not both: the link is read for the app it names.',
            ]);
        }
        if ($hasLink) {
            $identity = self::fromStoreLink($payload['store_link']);
            if ($platform !== null && self::normalizePlatform($platform) !== $identity->platform) {
                throw new ValidationException('Validation failed', [
                    'platform' => 'The store link names a ' . $identity->platform . ' app, not '
                        . (is_scalar($platform) ? (string)$platform : 'that platform') . '. Leave platform out; the link decides it.',
                ]);
            }
            return $identity;
        }
        if (!$hasKey) {
            throw new ValidationException('Validation failed', [
                'app_key' => 'Required: the App Store id or Android package name (or send store_link instead)',
            ]);
        }
        return self::fromKey($platform, $payload['app_key']);
    }

    private static function appleFromLink(string $digits, string $slug): self
    {
        $key = self::appleKey($digits);
        if ($key === null) {
            throw new ValidationException('Invalid store_link', [
                'store_link' => 'That is not a usable App Store id: it must be positive, have no leading zero, '
                    . 'and fit in 63 bits.',
            ]);
        }
        return new self(self::IOS, $key, $slug);
    }

    private static function androidFromLink(mixed $id): self
    {
        $key = self::androidKey($id);
        if ($key === null) {
            throw new ValidationException('Invalid store_link', [
                'store_link' => 'The link\'s id= parameter is not an Android package name (e.g. com.example.app).',
            ]);
        }
        return new self(self::ANDROID, $key);
    }
}

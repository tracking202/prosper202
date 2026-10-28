<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

use Prosper202\Database\Connection;

/**
 * The URLs an ad platform fetches an account's offline conversion file from
 * on a schedule (202_offline_conversion_feeds).
 *
 * The URL is the only credential. Google Ads and Microsoft Advertising each
 * ask for a user name and password beside it, but how they send them is not
 * documented, and trackers that publish such URLs (Matomo) say Google does
 * not check them; so the key is in the URL's path and nothing reads the
 * user name or password. The key is 256 random bits, shown once when it is
 * made and never again: the table keeps its SHA-256, so neither a read of
 * the table nor of a page can hand it out, and a lost URL is replaced, which
 * ends the old one. A fetch finds its feed by that hash and compares the
 * stored hash with hash_equals(). Every refusal (a key of the wrong shape,
 * no such key, a key replaced or deleted) answers the same 404.
 *
 * @phpstan-type FeedSettings array{format: Format, days: int, conversion_name: ?string,
 *     ad_user_data: ?Consent, ad_personalization: ?Consent}
 */
final class FeedStore
{
    public const MAX_FEEDS = 20;
    public const DEFAULT_DAYS = 7;
    /**
     * A conversion held for its click's first hours (OfflineExport::HOLD)
     * has to still be in the window at the next daily fetch.
     */
    public const MIN_DAYS = 2;
    public const PATH = 'api/v3/offline-conversions/';

    private const COLUMNS = 'feed_id, user_id, format, days, conversion_name, ad_user_data, ad_personalization,'
        . ' created_at, key_created_at, last_fetched_at, last_fetch_rows';

    public function __construct(private Connection $conn)
    {
    }

    /**
     * A feed's settings from a form or a body: format, days (2 to 90),
     * conversion_name (only conversions of that name; '' for all) and, for
     * a Google file, ad_user_data and ad_personalization ('granted',
     * 'denied' or '' for not stated). Every key is required, and a value
     * that is not one of these is refused naming it.
     *
     * @param array<array-key, mixed> $input
     * @return array{0: FeedSettings|null, 1: array<string, string>}
     */
    public static function settings(array $input): array
    {
        $errors = [];
        $text = static function (string $key) use ($input, &$errors): ?string {
            $value = $input[$key] ?? null;
            if (!is_string($value)) {
                $errors[$key] = $key . ' is required.';
                return null;
            }

            return $value;
        };
        $formatText = $text('format');
        $format = $formatText === null ? null : Format::tryFrom($formatText);
        if ($formatText !== null && $format === null) {
            $errors['format'] = 'format must be one of: ' . implode(', ', Format::values()) . '.';
        }
        $daysText = $text('days');
        $days = null;
        if ($daysText !== null) {
            if (
                preg_match('/\A[0-9]{1,3}\z/D', $daysText) !== 1
                || (int) $daysText < self::MIN_DAYS
                || (int) $daysText > ExportOptions::MAX_DAYS
            ) {
                $errors['days'] = 'days must be a whole number from ' . self::MIN_DAYS
                    . ' to ' . ExportOptions::MAX_DAYS . '.';
            } else {
                $days = (int) $daysText;
            }
        }
        $name = $text('conversion_name');
        if ($name !== null && $name !== '' && ($problem = ConversionNames::problem($name)) !== null) {
            $errors['conversion_name'] = $problem;
        }
        $consent = [];
        foreach (['ad_user_data', 'ad_personalization'] as $key) {
            $value = $text($key);
            $consent[$key] = null;
            if ($value === null || $value === '') {
                continue;
            }
            $consent[$key] = Consent::tryFrom($value);
            if ($consent[$key] === null) {
                $errors[$key] = $key . ' must be granted, denied or empty.';
            } elseif ($format === Format::MICROSOFT) {
                $errors[$key] = 'Microsoft Advertising\'s file has no consent columns; leave ' . $key . ' empty.';
            }
        }
        if ($errors !== [] || $format === null || $days === null) {
            return [null, $errors];
        }

        return [[
            'format' => $format,
            'days' => $days,
            'conversion_name' => $name === '' ? null : $name,
            'ad_user_data' => $consent['ad_user_data'],
            'ad_personalization' => $consent['ad_personalization'],
        ], []];
    }

    /** @return list<Feed> the account's feeds, oldest first */
    public function list(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT ' . self::COLUMNS . ' FROM 202_offline_conversion_feeds WHERE user_id = ? ORDER BY feed_id'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return array_map(self::feed(...), $this->conn->fetchAll($stmt));
    }

    /**
     * Make a feed. Its URL is the one time the key is known.
     *
     * @param FeedSettings $settings
     * @return array{0: Feed, 1: string} the feed and its key
     * @throws \OverflowException when the account has MAX_FEEDS already
     */
    public function create(int $userId, array $settings, int $now): array
    {
        $key = self::newKey();

        return $this->conn->transaction(function () use ($userId, $settings, $now, $key): array {
            $count = $this->conn->prepareWrite(
                'SELECT COUNT(*) AS feeds FROM 202_offline_conversion_feeds WHERE user_id = ? FOR UPDATE'
            );
            $this->conn->bind($count, 'i', [$userId]);
            if (StoredRow::int($this->conn->fetchOne($count) ?? [], 'feeds') >= self::MAX_FEEDS) {
                throw new \OverflowException(
                    'An account can have ' . self::MAX_FEEDS . ' fetch URLs. Delete one first.'
                );
            }
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_offline_conversion_feeds'
                . ' (user_id, format, key_hash, days, conversion_name, ad_user_data,'
                . ' ad_personalization, created_at, key_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $this->conn->bind($stmt, 'ississsii', [
                $userId,
                $settings['format']->value,
                self::hash($key),
                $settings['days'],
                $settings['conversion_name'],
                $settings['ad_user_data']->value ?? '',
                $settings['ad_personalization']->value ?? '',
                $now,
                $now,
            ]);
            $feedId = $this->conn->executeInsert($stmt);
            $feed = $this->find($userId, $feedId, true);
            if ($feed === null) {
                throw new \RuntimeException('fetch URL ' . $feedId . ' was not read back after it was written');
            }

            return [$feed, $key];
        });
    }

    /**
     * Replace a feed's key: the old URL stops answering at once.
     *
     * @return string|null the new key, or null when the account has no such feed
     */
    public function replaceKey(int $userId, int $feedId, int $now): ?string
    {
        $key = self::newKey();
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_offline_conversion_feeds SET key_hash = ?, key_created_at = ? WHERE feed_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'siii', [self::hash($key), $now, $feedId, $userId]);

        return $this->conn->executeUpdate($stmt) === 1 ? $key : null;
    }

    /** @return bool false when the account has no such feed */
    public function delete(int $userId, int $feedId): bool
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_offline_conversion_feeds WHERE feed_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'ii', [$feedId, $userId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    public function find(int $userId, int $feedId, bool $primary = false): ?Feed
    {
        $sql = 'SELECT ' . self::COLUMNS
            . ' FROM 202_offline_conversion_feeds WHERE feed_id = ? AND user_id = ? LIMIT 1';
        $stmt = $primary ? $this->conn->prepareWrite($sql) : $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 'ii', [$feedId, $userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::feed($row);
    }

    /**
     * The feed a fetch URL's key opens, or null for any key that opens none:
     * a URL is a way the account reads its conversions, so it opens nothing
     * once the account is deactivated or deleted, or its role no longer
     * reads campaign data, as an API key or a sign-in would not (CLAUDE.md
     * #5). On the primary, so a key replaced a moment ago is refused at once.
     */
    public function findByKey(string $key): ?Feed
    {
        if (!self::isKey($key)) {
            return null;
        }
        $hash = self::hash($key);
        $columns = implode(', ', array_map(
            static fn (string $c): string => 'f.' . trim($c),
            explode(',', self::COLUMNS)
        ));
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . $columns . ', f.key_hash FROM 202_offline_conversion_feeds AS f'
            . ' INNER JOIN 202_users AS u ON u.user_id = f.user_id AND u.user_active = 1 AND u.user_deleted = 0'
            . ' WHERE f.key_hash = ? AND EXISTS (SELECT 1 FROM 202_user_role AS ur'
            . ' INNER JOIN 202_role_permission AS rp ON rp.role_id = ur.role_id'
            . ' INNER JOIN 202_permissions AS p ON p.permission_id = rp.permission_id'
            . " WHERE ur.user_id = f.user_id AND p.permission_description = 'access_to_campaign_data')"
            . ' LIMIT 1'
        );
        $this->conn->bind($stmt, 's', [$hash]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || !hash_equals(StoredRow::text($row, 'key_hash'), $hash)) {
            return null;
        }

        return self::feed($row);
    }

    /** Note a fetch that was answered, for the Setup page. */
    public function recordFetch(int $feedId, int $rows, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_offline_conversion_feeds SET last_fetched_at = ?, last_fetch_rows = ? WHERE feed_id = ?'
        );
        $this->conn->bind($stmt, 'iii', [$now, $rows, $feedId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The URL a platform fetches: on $base, the install's own tracking base,
     * never a request's Host. It ends in .csv, as Google asks, in a query
     * (OfflineConversionsController::serveFeed() says why).
     */
    public static function url(string $base, string $key): string
    {
        return rtrim($base, '/') . '/' . self::PATH . $key . '?file=conversions.csv';
    }

    /** 43 base64url characters: 32 random bytes. */
    public static function isKey(string $key): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $key) === 1;
    }

    private static function newKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key, true);
    }

    /** @param array<string, mixed> $row */
    private static function feed(array $row): Feed
    {
        $format = Format::tryFrom(StoredRow::text($row, 'format'));
        if ($format === null) {
            throw new \UnexpectedValueException(
                'fetch URL ' . StoredRow::int($row, 'feed_id') . ' has a format that is not one'
            );
        }
        $consent = static function (string $column) use ($row): ?Consent {
            $stored = StoredRow::text($row, $column);
            if ($stored === '') {
                return null;
            }

            return Consent::tryFrom($stored)
                ?? throw new \UnexpectedValueException(
                    'fetch URL ' . StoredRow::int($row, 'feed_id') . ' has ' . $column . ' ' . var_export($stored, true)
                );
        };

        return new Feed(
            StoredRow::int($row, 'feed_id'),
            StoredRow::int($row, 'user_id'),
            $format,
            StoredRow::int($row, 'days'),
            StoredRow::nullableText($row, 'conversion_name'),
            $consent('ad_user_data'),
            $consent('ad_personalization'),
            StoredRow::int($row, 'created_at'),
            StoredRow::int($row, 'key_created_at'),
            StoredRow::nullableInt($row, 'last_fetched_at'),
            StoredRow::nullableInt($row, 'last_fetch_rows'),
        );
    }
}

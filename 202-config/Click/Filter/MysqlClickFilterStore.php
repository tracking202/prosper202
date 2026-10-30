<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * An account's click filter settings (202_click_filter_settings, one row, or
 * none for the defaults) and address list (202_click_filter_entries).
 *
 * The writes are the page's and the API's; the click path only reads, once
 * per click, through FILTER::verdict(). A stored value this code cannot read
 * is thrown, never read as a default (CLAUDE.md #11): the click path's
 * caller falls back to the defaults and says so in the log, and the page and
 * the API answer the error.
 */
final class MysqlClickFilterStore
{
    public const SETTINGS_TABLE = '202_click_filter_settings';
    public const ENTRIES_TABLE = '202_click_filter_entries';

    public function __construct(private readonly Connection $conn)
    {
    }

    public function settings(int $userId): ClickFilterSettings
    {
        $stmt = $this->conn->prepareRead(
            'SELECT sign_in_addresses, crawler_ranges, repeat_visits, repeat_window'
            . ' FROM 202_click_filter_settings WHERE user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return ClickFilterSettings::defaults();
        }
        $window = ClickFilterSettings::windowOf(self::int($row, 'repeat_window'));
        if ($window === null) {
            throw new \UnexpectedValueException(
                "Account $userId's click filter repeat window is stored as " . var_export($row['repeat_window'], true)
                . ', which is not one of the windows offered'
            );
        }

        return new ClickFilterSettings(
            self::switch($row, 'sign_in_addresses', $userId),
            self::switch($row, 'crawler_ranges', $userId),
            self::switch($row, 'repeat_visits', $userId),
            $window,
        );
    }

    public function saveSettings(int $userId, ClickFilterSettings $settings): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_click_filter_settings'
            . ' (user_id, sign_in_addresses, crawler_ranges, repeat_visits, repeat_window, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE sign_in_addresses = VALUES(sign_in_addresses),'
            . ' crawler_ranges = VALUES(crawler_ranges), repeat_visits = VALUES(repeat_visits),'
            . ' repeat_window = VALUES(repeat_window), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iiiiii', [
            $userId,
            $settings->signInAddresses ? 1 : 0,
            $settings->crawlerRanges ? 1 : 0,
            $settings->repeatVisits ? 1 : 0,
            $settings->windowSeconds(),
            time(),
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /** @return list<ClickFilterEntry> in the order they were added */
    public function entries(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT entry_id, family, range_start, prefix, action, note, created_at'
            . ' FROM 202_click_filter_entries WHERE user_id = ? ORDER BY entry_id'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return array_map(self::entryOf(...), $this->conn->fetchAll($stmt));
    }

    public function entry(int $userId, int $entryId): ?ClickFilterEntry
    {
        $stmt = $this->conn->prepareRead(
            'SELECT entry_id, family, range_start, prefix, action, note, created_at'
            . ' FROM 202_click_filter_entries WHERE user_id = ? AND entry_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $entryId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::entryOf($row);
    }

    /**
     * The entry that decides a click from $packed (AddressRange::packedForMatch()):
     * an "always count" entry before an "always filter" one, then the
     * narrowest block, then the oldest entry.
     */
    public function match(int $userId, string $packed): ?ClickFilterEntry
    {
        $family = strlen($packed) === 4 ? 4 : 6;
        $stmt = $this->conn->prepareRead(
            'SELECT entry_id, family, range_start, prefix, action, note, created_at'
            . ' FROM 202_click_filter_entries'
            . ' WHERE user_id = ? AND family = ? AND range_start <= ? AND range_end >= ?'
            . " ORDER BY action = 'count' DESC, prefix DESC, entry_id ASC LIMIT 1"
        );
        $this->conn->bind($stmt, 'iiss', [$userId, $family, $packed, $packed]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : self::entryOf($row);
    }

    /**
     * Add an entry. Refused when the list is full or holds the same
     * addresses already; both are asked under the account's settings row's
     * lock, so two adds at once cannot pass either together.
     *
     * @throws ClickFilterListRefusal
     */
    public function addEntry(int $userId, AddressRange $range, string $action, string $note): ClickFilterEntry
    {
        return $this->conn->transaction(function () use ($userId, $range, $action, $note): ClickFilterEntry {
            $this->lockAccount($userId);
            $count = $this->entryCount($userId);
            if ($count >= ClickFilterRules::MAX_ENTRIES) {
                throw new ClickFilterListRefusal(
                    'address',
                    'the list is full: it holds ' . ClickFilterRules::MAX_ENTRIES . ' entries at most; remove one first'
                );
            }
            $this->refuseDuplicate($userId, $range, null);
            $now = time();
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_click_filter_entries'
                . ' (user_id, family, range_start, range_end, prefix, action, note, created_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $this->conn->bind($stmt, 'iississi', [
                $userId, $range->family, $range->start, $range->end, $range->prefix, $action, $note, $now,
            ]);
            $id = $this->conn->executeInsert($stmt);

            return new ClickFilterEntry($id, $range, $action, $note, $now);
        });
    }

    /** @throws ClickFilterListRefusal */
    public function updateEntry(int $userId, ClickFilterEntry $entry): bool
    {
        return $this->conn->transaction(function () use ($userId, $entry): bool {
            $this->lockAccount($userId);
            $this->refuseDuplicate($userId, $entry->range, $entry->id);
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_click_filter_entries SET family = ?, range_start = ?, range_end = ?, prefix = ?,'
                . ' action = ?, note = ? WHERE user_id = ? AND entry_id = ?'
            );
            $this->conn->bind($stmt, 'ississii', [
                $entry->range->family, $entry->range->start, $entry->range->end, $entry->range->prefix,
                $entry->action, $entry->note, $userId, $entry->id,
            ]);
            $this->conn->executeUpdate($stmt);

            return $this->entry($userId, $entry->id) !== null;
        });
    }

    public function removeEntry(int $userId, int $entryId): bool
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_click_filter_entries WHERE user_id = ? AND entry_id = ?');
        $this->conn->bind($stmt, 'ii', [$userId, $entryId]);

        return $this->conn->executeUpdate($stmt) > 0;
    }

    /**
     * The account's settings row, made with the defaults when it has none,
     * locked for the rest of the transaction: what serializes the list's
     * writers. A row of defaults filters exactly as no row does.
     */
    private function lockAccount(int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT IGNORE INTO 202_click_filter_settings'
            . ' (user_id, sign_in_addresses, crawler_ranges, repeat_visits, repeat_window, updated_at)'
            . ' VALUES (?, 1, 1, 1, ?, ?)'
        );
        $this->conn->bind($stmt, 'iii', [$userId, ClickFilterSettings::WINDOWS[ClickFilterSettings::DEFAULT_WINDOW], time()]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite('SELECT user_id FROM 202_click_filter_settings WHERE user_id = ? FOR UPDATE');
        $this->conn->bind($stmt, 'i', [$userId]);
        if ($this->conn->fetchOne($stmt) === null) {
            throw new QueryException("Account $userId's click filter settings row could not be locked");
        }
    }

    private function entryCount(int $userId): int
    {
        $stmt = $this->conn->prepareWrite('SELECT COUNT(*) AS n FROM 202_click_filter_entries WHERE user_id = ?');
        $this->conn->bind($stmt, 'i', [$userId]);

        return self::int($this->conn->fetchOne($stmt) ?? [], 'n');
    }

    /** @throws ClickFilterListRefusal */
    private function refuseDuplicate(int $userId, AddressRange $range, ?int $except): void
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT entry_id FROM 202_click_filter_entries'
            . ' WHERE user_id = ? AND family = ? AND range_start = ? AND prefix = ? AND entry_id <> ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'iisii', [$userId, $range->family, $range->start, $range->prefix, $except ?? 0]);
        $row = $this->conn->fetchOne($stmt);
        if ($row !== null) {
            throw new ClickFilterListRefusal(
                'address',
                $range->text() . ' is on the list already, as entry ' . self::int($row, 'entry_id')
                . ': change that entry instead',
                self::int($row, 'entry_id')
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function entryOf(array $row): ClickFilterEntry
    {
        return new ClickFilterEntry(
            self::int($row, 'entry_id'),
            AddressRange::fromStored(self::int($row, 'family'), (string) ($row['range_start'] ?? ''), self::int($row, 'prefix')),
            (string) ($row['action'] ?? ''),
            (string) ($row['note'] ?? ''),
            self::int($row, 'created_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function switch(array $row, string $column, int $userId): bool
    {
        $value = self::int($row, $column);
        if ($value !== 0 && $value !== 1) {
            throw new \UnexpectedValueException("Account $userId's click filter $column is stored as $value, not 0 or 1");
        }

        return $value === 1;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("A click filter row's $column is not a number");
    }
}

<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

use Prosper202\Database\Connection;
use Prosper202\Spend\SpendLedger;

/**
 * ChatGPT Ads spend, pulled hourly from OpenAI into the spend ledger.
 *
 * Off by default per connection (no 202_ad_spend_sync row, or enabled 0).
 * When on, each run reads GET /ad_account/insights one row per ad per hour
 * and writes the rows through SpendLedger, the class POST
 * /api/v3/spend-ledger writes through. The first run after it is turned on
 * reads the 30 days OpenAI keeps hourly; every later run re-reads the
 * trailing 72 hours, since spend settles after the clicks, and the ledger
 * replaces each hour it is sent again.
 *
 * Refused by name, with nothing pulled: an ad account whose currency is
 * not the Prosper202 account's (nothing here converts currencies). A 429
 * leaves the connection alone for an hour, doubling with each one in a row
 * up to a day. A row OpenAI sends that the ledger cannot read exactly is
 * left out and named in the run's note; the readable rows are written and
 * the run counts as done (a cron run has nobody to answer, CLAUDE.md #4).
 */
final class SpendSync
{
    public const string PLATFORM = 'openai_ads';

    /** The first run's reach: the hourly history OpenAI keeps. */
    public const int BACKFILL_SECONDS = 30 * 86400;

    /** Every later run's reach: spend settles after the clicks. */
    public const int TRAILING_SECONDS = 72 * 3600;

    /** A run reads at most this many pages of one connection. */
    public const int MAX_PAGES = 1000;

    private const int BACKOFF_SECONDS = 3600;
    private const int BACKOFF_MAX_SECONDS = 86400;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Connection $conn,
        private readonly SpendConnections $connections,
        private readonly InsightsClient $insights = new InsightsClient(),
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Turn the connection's sync on or off. False when the account has no
     * such connection.
     */
    public function setEnabled(int $userId, int $connectionId, bool $enabled): bool
    {
        if (!isset($this->connections->ofAccount($userId)[$connectionId])) {
            return false;
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_ad_spend_sync (connection_id, user_id, enabled, updated_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), enabled = VALUES(enabled),'
            . ' retry_after = IF(VALUES(enabled) = 1, 0, retry_after), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iiii', [$connectionId, $userId, $enabled ? 1 : 0, ($this->clock)()]);
        $this->conn->executeUpdate($stmt);

        return true;
    }

    /**
     * Each of the account's ChatGPT Ads connections with its sync's state.
     *
     * @return list<array<string, mixed>>
     */
    public function status(int $userId): array
    {
        $accounts = $this->connections->ofAccount($userId);
        $states = $this->states($userId);
        $out = [];
        foreach ($accounts as $connectionId => $accountRef) {
            $state = $states[$connectionId] ?? null;
            $out[] = [
                'connection_id' => $connectionId,
                'account_ref' => $accountRef,
                'enabled' => $state !== null && (int) $state['enabled'] === 1,
                'backfilled_at' => self::time($state['backfilled_at'] ?? null),
                'last_run_at' => self::time($state['last_run_at'] ?? null),
                'last_success_at' => self::time($state['last_success_at'] ?? null),
                'last_rows' => (int) ($state['last_rows'] ?? 0),
                'last_error' => $state === null || $state['last_error'] === null ? null : (string) $state['last_error'],
                'last_note' => $state === null || $state['last_note'] === null ? null : (string) $state['last_note'],
                'retry_after' => $state === null || (int) $state['retry_after'] === 0 ? null : (int) $state['retry_after'],
            ];
        }

        return $out;
    }

    /**
     * Pull every connection whose sync is on and is not waiting out a rate
     * limit, until $deadline (unix seconds). One connection's failure is
     * recorded on it and the rest go on.
     *
     * @return array{synced: int, failed: int, rows: int}
     */
    public function runDue(int $deadline): array
    {
        $now = ($this->clock)();
        $stmt = $this->conn->prepareWrite(
            'SELECT connection_id, user_id FROM 202_ad_spend_sync WHERE enabled = 1 AND retry_after <= ?'
            . ' ORDER BY COALESCE(last_run_at, 0), connection_id'
        );
        $this->conn->bind($stmt, 'i', [$now]);
        $due = $this->conn->fetchAll($stmt);
        $done = ['synced' => 0, 'failed' => 0, 'rows' => 0];
        foreach ($due as $row) {
            if (($this->clock)() >= $deadline) {
                break;
            }
            $connectionId = (int) $row['connection_id'];
            $userId = (int) $row['user_id'];
            try {
                // Read with the sync row's account: a connection that is not
                // that account's any more reads as none (CLAUDE.md #27).
                $connection = $this->connections->find($userId, $connectionId);
            } catch (\RuntimeException $e) {
                $this->recordUnreachable($connectionId, $userId, 'the Advertiser API key cannot be read: ' . $e->getMessage(), false);
                $done['failed']++;
                continue;
            }
            if ($connection === null) {
                $this->recordUnreachable($connectionId, $userId, 'the ChatGPT Ads connection was removed; spend sync is off', true);
                $done['failed']++;
                continue;
            }
            $result = $this->run($connection);
            $done[$result['error'] === null ? 'synced' : 'failed']++;
            $done['rows'] += $result['rows'];
        }

        return $done;
    }

    /**
     * Pull one connection now, whatever its schedule, and record the run.
     *
     * @return array{rows: int, from: int, to: int, error: ?string, note: ?string}
     */
    public function run(SpendConnection $connection): array
    {
        $now = ($this->clock)();
        $state = $this->states($connection->userId)[$connection->connectionId] ?? null;
        $backfill = $state === null || $state['backfilled_at'] === null;
        $from = $now - $now % 3600 - ($backfill ? self::BACKFILL_SECONDS : self::TRAILING_SECONDS);
        $ledger = new SpendLedger($this->conn, $this->clock);

        $rows = 0;
        $error = null;
        $note = null;
        $rateLimited = false;
        try {
            $rows = $this->pull($connection, $ledger, $from, $now, $note);
            $more = $this->pullConversions($connection, $from, $now);
            if ($more !== null) {
                $note = ($note === null ? '' : $note . '; ') . $more;
            }
        } catch (InsightsFailure $e) {
            $error = $e->getMessage();
            $rateLimited = $e->rateLimited();
        } catch (SpendSyncRefused | \Prosper202\Spend\SpendLedgerRefused $e) {
            // A refused page wrote nothing; the pages before it stand.
            $error = $e->getMessage();
        }
        $this->record($connection, $now, $rows, $error, $note, $rateLimited, $backfill && $error === null, (int) ($state['rate_limited'] ?? 0));

        return ['rows' => $rows, 'from' => $from, 'to' => $now, 'error' => $error, 'note' => $note];
    }

    /**
     * Every page of [$from, $to), written to the ledger page by page. A row
     * the ledger cannot read is left out and named in $error.
     */
    private function pull(SpendConnection $connection, SpendLedger $ledger, int $from, int $to, ?string &$note): int
    {
        $currency = $ledger->accountCurrency($connection->userId);
        if (strtoupper($connection->currency) !== $currency) {
            throw new SpendSyncRefused('the ad account ' . $connection->accountRef . ' is billed in ' . $connection->currency
                . ' and this Prosper202 account records money in ' . $currency
                . '; spend is not converted between currencies, so it is not pulled');
        }
        $written = 0;
        $skipped = 0;
        $firstSkip = null;
        $after = null;
        $seen = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $this->insights->page($connection->apiKey, $from, $to, $after);
            [$rows, $refused] = self::ledgerRows($answer['rows'], $connection, $currency);
            [$parsed, $errors] = $rows === [] ? [[], []] : $ledger->parse($connection->userId, $rows);
            if ($errors !== []) {
                // One unreadable row must not cost the page: keep the rows
                // that read on their own and name what was left out.
                [$parsed, $more] = self::readable($ledger, $connection->userId, $rows);
                $refused = array_merge($refused, $more);
            }
            if ($refused !== []) {
                $skipped += count($refused);
                $firstSkip ??= $refused[0];
            }
            if ($parsed !== []) {
                $counts = $ledger->write($connection->userId, $parsed);
                $written += $counts['inserted'] + $counts['replaced'] + $counts['unchanged'];
            }
            $after = $answer['next'];
            if ($after === null) {
                break;
            }
            if (isset($seen[$after])) {
                throw new InsightsFailure('OpenAI handed back a cursor it had already given (' . $after . '); the pull stopped there');
            }
            $seen[$after] = true;
        }
        if ($after !== null) {
            throw new InsightsFailure('more than ' . self::MAX_PAGES . ' pages of insights; what was read is kept and the rest is read on the next run');
        }
        if ($skipped > 0) {
            $note = $skipped . ' row(s) OpenAI sent were left out: ' . $firstSkip;
        }

        return $written;
    }

    /**
     * The conversions OpenAI attributes to the connection's ads per day of
     * the ad account's zone, over the days [$from, $to] touches, beside
     * Prosper202's for reconciliation (Prosper202\Spend\PlatformConversions).
     * The ads are the ones the ledger holds for the connection in those
     * hours. OpenAI answers at most 2000 rows (ads x days) and refuses more
     * with 413: the ads are asked for in groups that fit, halved on a 413.
     * What could not be read is answered as a note; a 429 or a refused key
     * fails the run as the spend pull does.
     */
    private function pullConversions(SpendConnection $connection, int $from, int $to): ?string
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT ad_ref FROM 202_spend_ledger WHERE user_id = ? AND platform = ? AND account_ref = ?'
            . ' AND hour_start >= ? AND hour_start <= ? ORDER BY ad_ref'
        );
        $this->conn->bind($stmt, 'issii', [$connection->userId, self::PLATFORM, $connection->accountRef, $from, $to]);
        $ads = array_map(static fn (array $row): string => (string) $row['ad_ref'], $this->conn->fetchAll($stmt));
        if ($ads === []) {
            return null;
        }
        $zone = new \DateTimeZone($connection->timezone);
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->setTime(0, 0)->modify('+1 day');
        $days = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
        $store = new \Prosper202\Spend\PlatformConversions($this->conn, $this->clock);
        $size = max(1, intdiv(2000, $days));
        $refused = [];
        $unread = 0;
        for ($i = 0; $i < count($ads);) {
            $group = array_slice($ads, $i, $size);
            try {
                $rows = $this->insights->conversions($connection->apiKey, $start->getTimestamp(), $end->getTimestamp(), $group);
            } catch (InsightsFailure $e) {
                if ($e->tooLarge() && count($group) > 1) {
                    $size = max(1, intdiv(count($group), 2));
                    continue;
                }
                if ($e->tooLarge()) {
                    $unread++;
                    $i++;
                    continue;
                }
                throw $e;
            }
            $done = $store->write($connection->userId, self::PLATFORM, $connection->accountRef, $connection->timezone, $rows);
            $refused = [...$refused, ...$done['refused']];
            $i += count($group);
        }
        $notes = [];
        if ($refused !== []) {
            $notes[] = count($refused) . ' conversion row(s) OpenAI sent were left out: ' . $refused[0];
        }
        if ($unread > 0) {
            $notes[] = $unread . ' ad(s)\' conversions were more than OpenAI answers in one request (413) and were not read';
        }

        return $notes === [] ? null : implode('; ', $notes);
    }

    /**
     * OpenAI's rows as the ledger's, and why each one that cannot be is not.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function ledgerRows(array $rows, SpendConnection $connection, string $currency): array
    {
        $out = [];
        $refused = [];
        foreach ($rows as $row) {
            $start = $row['start_time'] ?? null;
            $end = $row['end_time'] ?? null;
            if (!is_int($start) || !is_int($end) || $end - $start !== 3600) {
                $refused[] = 'row ' . self::rowName($row) . ' is not one hour (start_time ' . json_encode($start) . ', end_time ' . json_encode($end) . ')';
                continue;
            }
            $out[] = [
                'platform' => self::PLATFORM,
                'account_ref' => $connection->accountRef,
                'campaign_ref' => $row['campaign_id'] ?? null,
                'ad_group_ref' => $row['ad_group_id'] ?? null,
                'ad_ref' => $row['ad_id'] ?? null,
                'hour_start' => $start,
                'impressions' => $row['impressions'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'spend' => $row['spend'] ?? null,
                'currency' => $currency,
            ];
        }

        return [$out, $refused];
    }

    /**
     * The rows that read on their own, and why each other one does not.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<\Prosper202\Spend\SpendRow>, 1: list<string>}
     */
    private static function readable(SpendLedger $ledger, int $userId, array $rows): array
    {
        $good = [];
        $refused = [];
        foreach ($rows as $row) {
            [$parsed, $errors] = $ledger->parse($userId, [$row]);
            if ($errors === []) {
                $good[$parsed[0]->key()] = $row;
                continue;
            }
            $refused[] = 'ad ' . json_encode($row['ad_ref']) . ' at ' . json_encode($row['hour_start']) . ': '
                . implode('; ', array_map(static fn (string $f, string $why): string => substr($f, strlen('rows.0.')) . ' ' . $why, array_keys($errors), $errors));
        }
        if ($good === []) {
            return [[], $refused];
        }
        [$parsed, $errors] = $ledger->parse($userId, array_values($good));
        if ($errors !== []) {
            // Rows that read alone but not together name one hour twice.
            return [[], [...$refused, 'the page named one ad and hour twice: ' . implode('; ', $errors)]];
        }

        return [$parsed, $refused];
    }

    /** @param array<string, mixed> $row */
    private static function rowName(array $row): string
    {
        $id = $row['id'] ?? null;

        return is_string($id) ? $id : (string) json_encode($row['ad_id'] ?? null);
    }

    /**
     * A due sync whose connection cannot be used, saying why; turned off
     * when the connection is gone, left on when its key can be replaced.
     */
    private function recordUnreachable(int $connectionId, int $userId, string $why, bool $off): void
    {
        $now = ($this->clock)();
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_ad_spend_sync SET enabled = IF(? = 1, 0, enabled), last_run_at = ?, last_rows = 0, last_error = ?,'
            . ' last_note = NULL, updated_at = ? WHERE connection_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'iisiii', [$off ? 1 : 0, $now, mb_substr($why, 0, 2000), $now, $connectionId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The run's outcome on the sync row: a failure in last_error (and no
     * success), a note on a run that succeeded in last_note.
     */
    private function record(SpendConnection $connection, int $now, int $rows, ?string $error, ?string $note, bool $rateLimited, bool $backfilled, int $limitedBefore): void
    {
        $limited = $rateLimited ? min($limitedBefore + 1, 60) : 0;
        $retryAfter = $rateLimited ? $now + min(self::BACKOFF_SECONDS * (2 ** ($limited - 1)), self::BACKOFF_MAX_SECONDS) : 0;
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_ad_spend_sync SET last_run_at = ?, last_rows = ?, last_error = ?, last_note = ?, rate_limited = ?,'
            . ' retry_after = ?, last_success_at = IF(? = 1, ?, last_success_at),'
            . ' backfilled_at = IF(? = 1, ?, backfilled_at), updated_at = ?'
            . ' WHERE connection_id = ? AND user_id = ?'
        );
        $cut = static fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, 2000);
        $this->conn->bind($stmt, 'iissiiiiiiiii', [
            $now, $rows, $cut($error), $cut($note), $limited, $retryAfter,
            $error === null ? 1 : 0, $now, $backfilled ? 1 : 0, $now, $now, $connection->connectionId, $connection->userId,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The account's sync rows, keyed by connection id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function states(int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT connection_id, enabled, backfilled_at, last_run_at, last_success_at, last_rows, last_error, last_note,'
            . ' rate_limited, retry_after FROM 202_ad_spend_sync WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['connection_id']] = $row;
        }

        return $out;
    }

    private static function time(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

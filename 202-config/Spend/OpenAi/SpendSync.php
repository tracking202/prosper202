<?php

declare(strict_types=1);

namespace Prosper202\Spend\OpenAi;

use Prosper202\Database\Connection;
use Prosper202\Spend\SixDecimals;
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
 * A run reads its window a day at a time, oldest first, and stops between
 * pages once its page budget or deadline is spent. The first hour it did not
 * read cleanly (a day it stopped in, failed in, or left a row out of) is
 * kept in pending_from, and every later run starts there (within the 30
 * days OpenAI keeps), so a long backfill or an outage loses nothing.
 *
 * Refused by name, with nothing pulled: an ad account whose currency is
 * not the Prosper202 account's (nothing here converts currencies). A 429 or
 * a refused key leaves the connection alone for an hour, doubling with each
 * one in a row up to a day, or for as long as a 429's Retry-After asks when
 * that is longer (up to a week); a new key for the connection ends the wait
 * (AdConnectionStore::putCredential()). Spend with more than six decimals is
 * rounded half up from its decimal text. A row the ledger still cannot read
 * is left out, the rest of its page is written, and the run is not
 * complete: left_out says why until a run reads those hours again without
 * leaving a row out (a cron run has nobody to answer, CLAUDE.md #4). One
 * connection's run that throws anything else is recorded on its row, and
 * the next connection still runs.
 */
final class SpendSync
{
    public const string PLATFORM = 'openai_ads';

    /** A run's outcome when it failed: refused here (another currency, a page the ledger refused). */
    public const string REFUSED = 'refused';
    public const string RATE_LIMITED = 'rate_limited';
    public const string KEY_REFUSED = 'key_refused';
    public const string UNAVAILABLE = 'unavailable';
    public const string UNREADABLE = 'unreadable_answer';

    /** The first run's reach: the hourly history OpenAI keeps. */
    public const int BACKFILL_SECONDS = 30 * 86400;

    /** Every later run's reach: spend settles after the clicks. */
    public const int TRAILING_SECONDS = 72 * 3600;

    /** A run asks for its window this much at a time. */
    public const int CHUNK_SECONDS = 86400;

    /** A run reads at most this many pages; the next run goes on from where it stopped. */
    public const int PAGE_BUDGET = 500;

    private const int BACKOFF_SECONDS = 3600;
    private const int BACKOFF_MAX_SECONDS = 86400;
    /** The longest Retry-After honoured: a longer one would read as the sync turned off. */
    private const int RETRY_AFTER_MAX_SECONDS = 7 * 86400;

    /** Added once to left_out when its hours fall out of OpenAI's reach. */
    private const string OUT_OF_REACH = '; those hours are older than the 30 days OpenAI keeps and cannot be read again:'
        . ' enter their spend with POST /spend-ledger (p202 spend import), then turn the sync off and on to clear this';

    /** @var \Closure(): int */
    private \Closure $clock;

    private readonly InsightsClient $insights;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly Connection $conn,
        private readonly SpendConnections $connections,
        ?InsightsClient $insights = null,
        ?\Closure $clock = null,
        private readonly int $pageBudget = self::PAGE_BUDGET,
    ) {
        if ($pageBudget < 1) {
            throw new \InvalidArgumentException('a run reads at least one page');
        }
        $this->insights = $insights ?? InsightsClient::forInstall($conn->writeConnection());
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Turn the connection's sync on or off. False when the account has no
     * such connection. Turning it on clears left_out (a person has seen it);
     * pending_from stays, so hours still in reach are read again.
     */
    public function setEnabled(int $userId, int $connectionId, bool $enabled): bool
    {
        if (!isset($this->connections->ofAccount($userId)[$connectionId])) {
            return false;
        }
        // left_out first: it reads the row's enabled before it is replaced.
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_ad_spend_sync (connection_id, user_id, enabled, updated_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE left_out = IF(VALUES(enabled) = 1 AND enabled = 0, NULL, left_out),'
            . ' user_id = VALUES(user_id), enabled = VALUES(enabled),'
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
        $text = static fn (?array $state, string $field): ?string => $state === null || $state[$field] === null ? null : (string) $state[$field];
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
                'last_error' => $text($state, 'last_error'),
                'last_note' => $text($state, 'last_note'),
                'left_out' => $text($state, 'left_out'),
                'pending_from' => self::time($state['pending_from'] ?? null),
                'retry_after' => $state === null || (int) $state['retry_after'] === 0 ? null : (int) $state['retry_after'],
            ];
        }

        return $out;
    }

    /**
     * Pull every connection whose sync is on and is not waiting out a back-
     * off, until $deadline (unix seconds), checked between pages. One
     * connection's failure is recorded on it and the rest go on.
     *
     * @return array{synced: int, incomplete: int, failed: int, rows: int}
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
        $done = ['synced' => 0, 'incomplete' => 0, 'failed' => 0, 'rows' => 0];
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
            try {
                $result = $this->run($connection, $deadline);
            } catch (\Throwable $e) {
                // run() holds no transaction open here: the ledger's and the
                // conversions' writes each end theirs before they throw.
                $this->recordCrash($connectionId, $userId, $e, $connection->apiKey);
                $done['failed']++;
                continue;
            }
            $done[$result['error'] !== null ? 'failed' : ($result['complete'] ? 'synced' : 'incomplete')]++;
            $done['rows'] += $result['rows'];
        }

        return $done;
    }

    /**
     * Pull one connection now, whatever its schedule, until $deadline (unix
     * seconds, null for none) or the page budget, and record the run.
     * `complete` is true when the whole window was read and nothing left
     * out; `outcome` names a failure (REFUSED, RATE_LIMITED, KEY_REFUSED,
     * UNAVAILABLE, UNREADABLE), `wait` the seconds OpenAI's Retry-After
     * asked, and `retry_after` when the hourly run tries again.
     *
     * @return array{rows: int, from: int, to: int, complete: bool, error: ?string, note: ?string,
     *     outcome: ?string, wait: ?int, retry_after: int}
     */
    public function run(SpendConnection $connection, ?int $deadline = null): array
    {
        $now = ($this->clock)();
        $state = $this->states($connection->userId)[$connection->connectionId] ?? null;
        $hour = $now - $now % 3600;
        $reach = $hour - self::BACKFILL_SECONDS;
        $backfilled = $state !== null && $state['backfilled_at'] !== null;
        $pending = $state === null || $state['pending_from'] === null ? null : (int) $state['pending_from'];
        $lost = $pending !== null && $pending < $reach;
        $from = $backfilled ? $hour - self::TRAILING_SECONDS : $reach;
        if ($pending !== null) {
            $from = $backfilled ? min($from, max($pending, $reach)) : max($pending, $reach);
        }
        $ledger = new SpendLedger($this->conn, $this->clock);

        $read = ['rows' => 0, 'through' => $from, 'stopped' => null, 'skipped' => 0, 'first_skip' => null, 'skipped_from' => null];
        $error = null;
        $outcome = null;
        $wait = null;
        $notes = [];
        try {
            $this->pull($connection, $ledger, $from, $now, $deadline, $read);
            if ($read['stopped'] === null) {
                $more = $this->pullConversions($connection, $from, $now);
                if ($more !== null) {
                    $notes[] = $more;
                }
            }
        } catch (InsightsFailure $e) {
            $error = $e->getMessage();
            $outcome = $e->outcome();
            $wait = $e->retryAfter;
        } catch (SpendSyncRefused | \Prosper202\Spend\SpendLedgerRefused $e) {
            // A refused page wrote nothing; the pages before it stand.
            $error = $e->getMessage();
            $outcome = self::REFUSED;
        }
        if ($read['skipped'] > 0) {
            array_unshift($notes, $read['skipped'] . ' row(s) OpenAI sent were left out: ' . $read['first_skip']);
        }
        if ($read['stopped'] !== null) {
            $notes[] = $read['stopped'] . ': read through ' . gmdate('Y-m-d H:i', $read['through'])
                . ' UTC; the next run goes on from there';
        }
        $stoppedEarly = $error !== null || $read['stopped'] !== null;
        $nextPending = $read['skipped_from'];
        if ($stoppedEarly) {
            // On the hour: the hour it stopped in is read again whole.
            $nextPending = min($nextPending ?? PHP_INT_MAX, $read['through'] - $read['through'] % 3600);
        }
        $complete = !$stoppedEarly && $read['skipped'] === 0;
        $leftOut = $state === null || $state['left_out'] === null ? null : (string) $state['left_out'];
        if ($read['skipped'] > 0) {
            $leftOut = $read['skipped'] . ' row(s) of hours from ' . gmdate('Y-m-d H:i', (int) $read['skipped_from'])
                . ' UTC were left out of the spend ledger, the first: ' . $read['first_skip']
                . '; those hours are read again every run until OpenAI sends them readably';
        } elseif ($complete && $pending !== null && !$lost) {
            // The hours it named were read again, and nothing was left out.
            $leftOut = null;
        } elseif ($lost && $leftOut !== null && !str_contains($leftOut, self::OUT_OF_REACH)) {
            $leftOut .= self::OUT_OF_REACH;
        }

        $retryAfter = $this->recordRun($connection, [
            'now' => $now,
            'rows' => $read['rows'],
            'error' => $error,
            'note' => $notes === [] ? null : implode('; ', $notes),
            'left_out' => $leftOut,
            'pending_from' => $nextPending,
            'back_off' => $outcome === self::RATE_LIMITED || $outcome === self::KEY_REFUSED,
            'wait' => $outcome === self::RATE_LIMITED ? $wait : null,
            'complete' => $complete,
            'backfilled' => !$backfilled && !$stoppedEarly && $nextPending === null,
            'limited_before' => (int) ($state['rate_limited'] ?? 0),
        ]);

        return ['rows' => $read['rows'], 'from' => $from, 'to' => $stoppedEarly ? $read['through'] : $now, 'complete' => $complete,
            'error' => $error, 'note' => $notes === [] ? null : implode('; ', $notes),
            'outcome' => $outcome, 'wait' => $wait, 'retry_after' => $retryAfter];
    }

    /**
     * [$from, $to) a day at a time, oldest first, each day's pages written to
     * the ledger page by page. $read carries what was done when it returns
     * or throws: rows written, `through` (the end of the last day read
     * whole), `stopped` (why it stopped before $to, or null), and the rows
     * left out (`skipped`, the first one's reason, the first day with one).
     *
     * @param array{rows: int, through: int, stopped: ?string, skipped: int, first_skip: ?string, skipped_from: ?int} $read
     */
    private function pull(SpendConnection $connection, SpendLedger $ledger, int $from, int $to, ?int $deadline, array &$read): void
    {
        $currency = $ledger->accountCurrency($connection->userId);
        if (strtoupper($connection->currency) !== $currency) {
            throw new SpendSyncRefused('the ad account ' . $connection->accountRef . ' is billed in ' . $connection->currency
                . ' and this Prosper202 account records money in ' . $currency
                . '; spend is not converted between currencies, so it is not pulled');
        }
        $pages = 0;
        for ($start = $from; $start < $to; $start = $end) {
            $end = min($start + self::CHUNK_SECONDS, $to);
            $after = null;
            $seen = [];
            do {
                if ($pages >= $this->pageBudget) {
                    $read['stopped'] = 'stopped after ' . $pages . ' page(s), the most one run reads';

                    return;
                }
                if ($deadline !== null && ($this->clock)() >= $deadline) {
                    $read['stopped'] = 'stopped at the run\'s time limit';

                    return;
                }
                $answer = $this->insights->page($connection->apiKey, $start, $end, $after);
                $pages++;
                [$rows, $refused] = self::ledgerRows($answer['rows'], $connection, $currency);
                // A row that is not one hour has no hour to read again from but its day's start.
                $skippedFrom = $refused === [] ? null : $start;
                [$parsed, $errors] = $rows === [] ? [[], []] : $ledger->parse($connection->userId, $rows);
                if ($errors !== []) {
                    // One unreadable row must not cost the page: keep the rows
                    // that read on their own and name what was left out.
                    [$parsed, $more, $hour] = self::readable($ledger, $connection->userId, $rows);
                    $refused = array_merge($refused, $more);
                    if ($hour !== null) {
                        $skippedFrom = min($skippedFrom ?? PHP_INT_MAX, max(0, $hour - $hour % 3600));
                    }
                }
                if ($refused !== []) {
                    $read['skipped'] += count($refused);
                    $read['first_skip'] ??= $refused[0];
                    $read['skipped_from'] = min($read['skipped_from'] ?? PHP_INT_MAX, $skippedFrom ?? $start);
                }
                if ($parsed !== []) {
                    $counts = $ledger->write($connection->userId, $parsed);
                    $read['rows'] += $counts['inserted'] + $counts['replaced'] + $counts['unchanged'];
                }
                $after = $answer['next'];
                if ($after !== null) {
                    if (isset($seen[$after])) {
                        throw new InsightsFailure('OpenAI handed back a cursor it had already given (' . $after . '); the pull stopped there', InsightsFailure::UNREADABLE);
                    }
                    $seen[$after] = true;
                }
            } while ($after !== null);
            $read['through'] = $end;
        }
    }

    /**
     * The conversions OpenAI attributes to the connection's ads per day of
     * the ad account's zone, over the whole days [$from, $to] touches that
     * have ended (OpenAI takes conversions over whole days only, and no bound
     * in the future: today's are read once today is over), beside
     * Prosper202's for reconciliation (Prosper202\Spend\PlatformConversions).
     * The ads are the ones the ledger holds for the connection in those
     * hours. OpenAI answers at most 2000 rows (ads x days) and refuses more
     * with 413: the ads are asked for in groups that fit, halved on a 413.
     * What could not be read is answered as a note (the spend stands); a 429
     * or a refused key fails the run as the spend pull does.
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
        $end = (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->setTime(0, 0);
        if ($end <= $start) {
            return null;
        }
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
                if ($e->rateLimited() || $e->keyRefused()) {
                    throw $e;
                }
                // The spend is written; the conversions are a note, not a failed run.
                return 'conversions not read: ' . $e->getMessage();
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
                'spend' => self::spendOf($row['spend'] ?? null),
                'currency' => $currency,
            ];
        }

        return [$out, $refused];
    }

    /**
     * OpenAI's spend, a JSON number in major units, as the ledger reads it:
     * at six decimals, rounded half up from its decimal text (a double's is
     * the shortest text that reads back as it, so 12.340000000000002 is
     * 12.340000 and 0.1234567 is 0.123457). Anything that is not an amount
     * of at least 0 that fits is passed on as sent, for the ledger to refuse
     * by name.
     */
    public static function spendOf(mixed $spend): mixed
    {
        if (is_float($spend) && is_finite($spend)) {
            return SixDecimals::fromText(SixDecimals::textOf($spend)) ?? $spend;
        }
        if (is_string($spend)) {
            return SixDecimals::fromText($spend) ?? $spend;
        }

        return $spend;
    }

    /**
     * The rows that read on their own, why each other one does not, and the
     * earliest hour among those (null when it is not known).
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<\Prosper202\Spend\SpendRow>, 1: list<string>, 2: ?int}
     */
    private static function readable(SpendLedger $ledger, int $userId, array $rows): array
    {
        $good = [];
        $refused = [];
        $hour = null;
        foreach ($rows as $row) {
            [$parsed, $errors] = $ledger->parse($userId, [$row]);
            if ($errors === []) {
                $good[$parsed[0]->key()] = $row;
                continue;
            }
            $hour = is_int($row['hour_start']) ? min($hour ?? PHP_INT_MAX, $row['hour_start']) : $hour;
            $refused[] = 'ad ' . json_encode($row['ad_ref']) . ' at ' . json_encode($row['hour_start']) . ': '
                . implode('; ', array_map(static fn (string $f, string $why): string => substr($f, strlen('rows.0.')) . ' ' . $why, array_keys($errors), $errors));
        }
        if ($good === []) {
            return [[], $refused, $hour];
        }
        [$parsed, $errors] = $ledger->parse($userId, array_values($good));
        if ($errors !== []) {
            // Rows that read alone but not together name one hour twice.
            return [[], [...$refused, 'the page named one ad and hour twice: ' . implode('; ', $errors)], null];
        }

        return [$parsed, $refused, $hour];
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
     * A run that threw something no failure of OpenAI's or the ledger's
     * explains: recorded on its row as last_error, and logged when even that
     * write fails, so the next connection still runs.
     */
    private function recordCrash(int $connectionId, int $userId, \Throwable $e, #[\SensitiveParameter] string $apiKey): void
    {
        $why = \Prosper202\Destinations\GoogleAdsOAuth::redact('the run stopped: ' . get_class($e) . ': ' . $e->getMessage(), [$apiKey]);
        try {
            $this->recordUnreachable($connectionId, $userId, $why, false);
        } catch (\Throwable $again) {
            error_log('ChatGPT Ads spend sync, connection ' . $connectionId . ': ' . $why . '; and it could not be recorded: ' . $again->getMessage());
        }
    }

    /**
     * The run's outcome on the sync row: a failure in last_error, this run's
     * notes in last_note, what is still left out in left_out, and when the
     * hourly run tries again after a back-off (returned, 0 for none).
     * last_success_at is a complete run's.
     *
     * @param array{now: int, rows: int, error: ?string, note: ?string, left_out: ?string, pending_from: ?int,
     *     back_off: bool, wait: ?int, complete: bool, backfilled: bool, limited_before: int} $run
     */
    private function recordRun(SpendConnection $connection, array $run): int
    {
        $now = $run['now'];
        $limited = $run['back_off'] ? min($run['limited_before'] + 1, 60) : 0;
        $wait = $limited === 0 ? 0 : (int) min(self::BACKOFF_SECONDS * (2 ** min($limited - 1, 5)), self::BACKOFF_MAX_SECONDS);
        $retryAfter = $run['back_off'] ? $now + max($wait, min($run['wait'] ?? 0, self::RETRY_AFTER_MAX_SECONDS)) : 0;
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_ad_spend_sync SET last_run_at = ?, last_rows = ?, last_error = ?, last_note = ?, left_out = ?,'
            . ' pending_from = ?, rate_limited = ?, retry_after = ?, last_success_at = IF(? = 1, ?, last_success_at),'
            . ' backfilled_at = IF(? = 1, ?, backfilled_at), updated_at = ?'
            . ' WHERE connection_id = ? AND user_id = ?'
        );
        $cut = static fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, 2000);
        $this->conn->bind($stmt, 'iisssiiiiiiiiii', [
            $now, $run['rows'], $cut($run['error']), $cut($run['note']), $cut($run['left_out']), $run['pending_from'], $limited, $retryAfter,
            $run['complete'] ? 1 : 0, $now, $run['backfilled'] ? 1 : 0, $now, $now, $connection->connectionId, $connection->userId,
        ]);
        $this->conn->executeUpdate($stmt);

        return $retryAfter;
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
            . ' left_out, pending_from, rate_limited, retry_after FROM 202_ad_spend_sync WHERE user_id = ?'
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

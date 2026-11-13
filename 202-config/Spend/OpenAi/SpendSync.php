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
 * reach (a day it stopped in or failed in) is kept in pending_from, recorded
 * after each day, and every later run starts there (within the 30 days
 * OpenAI keeps), so a long backfill or an outage loses nothing. An hour it
 * left a row out of does not hold that point back: it goes to
 * left_out_hours and is read again once the run has reached the present.
 * Conversions are owed from conversions_from until a run reads them.
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
 * leaving a row out (a cron run has nobody to answer, CLAUDE.md #4). A
 * refused key also marks the connection key_refused, as its check does. One
 * connection's run that throws anything else is recorded on its row, and
 * the next connection still runs.
 */
final class SpendSync
{
    public const string PLATFORM = 'openai_ads';

    /** A run's outcome when it failed: refused here (another currency), a page the ledger refused. */
    public const string REFUSED = 'refused';
    public const string PAGE_REFUSED = 'page_refused';
    public const string RATE_LIMITED = 'rate_limited';
    public const string KEY_REFUSED = 'key_refused';
    public const string UNAVAILABLE = 'unavailable';
    public const string UNREADABLE = 'unreadable_answer';
    /** OpenAI refused the request (a 4xx other than a key, a size or a limit): sending it again changes nothing. */
    public const string REQUEST_REFUSED = 'request_refused';

    /** The first run's reach: the hourly history OpenAI keeps. */
    public const int BACKFILL_SECONDS = 30 * 86400;

    /** Every later run's reach: spend settles after the clicks. */
    public const int TRAILING_SECONDS = 72 * 3600;

    /** A run asks for its window this much at a time. */
    public const int CHUNK_SECONDS = 86400;

    /** A run reads at most this many pages; the next run goes on from where it stopped. */
    public const int PAGE_BUDGET = 500;

    /** Left-out hours a run reads again: at most this many windows of a day, within its page budget. */
    public const int REREAD_WINDOWS = 3;

    private const int BACKOFF_SECONDS = 3600;
    private const int BACKOFF_MAX_SECONDS = 86400;
    /** The longest Retry-After honoured: a longer one would read as the sync turned off. */
    private const int RETRY_AFTER_MAX_SECONDS = 7 * 86400;

    /** left_out's endings: one says what is still read again, one what cannot be any more. */
    private const string READ_AGAIN = '; those hours are read again every run until OpenAI sends them readably';
    private const string OUT_OF_REACH = '; those hours are older than the 30 days OpenAI keeps and cannot be read again:'
        . ' enter their spend with POST /spend-ledger (p202 spend import), then turn the sync off and on to clear this';
    private const string SOME_OUT_OF_REACH = '; some of them are older than the 30 days OpenAI keeps and cannot be read again:'
        . ' enter their spend with POST /spend-ledger (p202 spend import); once the rest read cleanly, turn the sync off and on to clear this';
    private const string OUT_OF_REACH_MARK = 'older than the 30 days OpenAI keeps';

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
     * pending_from and left_out_hours stay, so hours still in reach are read
     * again.
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
                // The last run read through to now and left nothing out.
                'complete' => $state !== null && (int) $state['last_complete'] === 1,
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
     * `complete` is true when the run reached the present and no hour is
     * left out; `outcome` names a failure (REFUSED, RATE_LIMITED,
     * KEY_REFUSED, UNAVAILABLE, UNREADABLE), `wait` the seconds OpenAI's
     * Retry-After asked, and `retry_after` when the hourly run tries again.
     *
     * In order, each pass only once the one before it got through: the
     * forward read from pending_from (or the usual window) to now, whose
     * resume point moves with every day read, rows left out or not (CLAUDE.md
     * #37); the trailing 72 hours it did not cover; the hours left out by
     * earlier runs, each read again once, oldest first, one still left out
     * going to the back; then the conversions owed since conversions_from.
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
        $trail = $hour - self::TRAILING_SECONDS;
        $backfilled = $state !== null && $state['backfilled_at'] !== null;
        $pending = $state === null || $state['pending_from'] === null ? null : (int) $state['pending_from'];
        $lost = $pending !== null && $pending < $reach;
        $from = $pending !== null ? max($pending, $reach) : ($backfilled ? $trail : $reach);
        $queue = self::hoursOf($state['left_out_hours'] ?? null);
        $kept = array_values(array_filter($queue, static fn (int $h): bool => $h >= $reach));
        $lost = $lost || count($kept) < count($queue);
        $owed = $state === null || $state['conversions_from'] === null ? null : (int) $state['conversions_from'];
        // The spend this run reads owes its conversions until they are read.
        $runFrom = min($from, $trail);
        $ledger = new SpendLedger($this->conn, $this->clock);

        $read = ['rows' => 0, 'pages' => 0, 'stopped' => null, 'skipped' => 0, 'first_skip' => null, 'skipped_hours' => []];
        $through = $from;
        $caughtUp = false;
        $rereading = null;
        $conversionsRead = false;
        $error = null;
        $outcome = null;
        $wait = null;
        $notes = [];
        $checkpoint = function (int $at) use ($connection, $owed, $runFrom): void {
            // Recorded as it goes: a run PHP or the server ends early keeps how far it read.
            $this->checkpoint($connection, $at - $at % 3600, min($owed ?? PHP_INT_MAX, $runFrom));
        };
        try {
            $this->pull($connection, $ledger, $from, $now, $deadline, $read, $through, $checkpoint);
            $caughtUp = $read['stopped'] === null;
            if ($caughtUp && $from > $trail) {
                $settled = $trail;
                $this->pull($connection, $ledger, $trail, $from, $deadline, $read, $settled);
            }
            $windows = 0;
            while ($read['stopped'] === null && $kept !== [] && $windows++ < self::REREAD_WINDOWS) {
                $rereading = array_shift($kept);
                $end = min($rereading + self::CHUNK_SECONDS, $now);
                $done = $rereading;
                $this->pull($connection, $ledger, $rereading, $end, $deadline, $read, $done);
                if ($read['stopped'] !== null) {
                    break;
                }
                // Every left-out hour in the window was read again with it.
                $kept = array_values(array_filter($kept, static fn (int $h): bool => $h < $rereading || $h >= $end));
                $rereading = null;
            }
            if ($read['stopped'] === null) {
                [$more, $conversionsRead] = $this->pullConversions($connection, max($reach, min($owed ?? PHP_INT_MAX, $runFrom)), $now);
                if ($more !== null) {
                    $notes[] = $more;
                }
            }
        } catch (InsightsFailure $e) {
            $error = $e->getMessage();
            $outcome = $e->outcome();
            $wait = $e->retryAfter;
        } catch (SpendSyncRefused $e) {
            $error = $e->getMessage();
            $outcome = self::REFUSED;
        } catch (\Prosper202\Spend\SpendLedgerRefused $e) {
            // A refused page wrote nothing; the pages before it stand.
            $error = $e->getMessage();
            $outcome = self::PAGE_REFUSED;
        }
        if ($read['skipped'] > 0) {
            array_unshift($notes, $read['skipped'] . ' row(s) OpenAI sent were left out: ' . $read['first_skip']);
        }
        if ($read['stopped'] !== null) {
            $notes[] = $read['stopped'] . ($caughtUp ? '' : ': read through ' . gmdate('Y-m-d H:i', $through) . ' UTC')
                . '; the next run goes on from there';
        }
        // Not read again yet first, then this run's: one that stays bad goes to the back.
        $left = array_values(array_unique([...($rereading === null ? [] : [$rereading]), ...$kept, ...array_keys($read['skipped_hours'])]));
        $stoppedEarly = $error !== null || $read['stopped'] !== null;
        $complete = !$stoppedEarly && $left === [];
        $leftOut = $state === null || $state['left_out'] === null ? null : (string) $state['left_out'];
        $marked = $lost || ($leftOut !== null && str_contains($leftOut, self::OUT_OF_REACH_MARK));
        if ($read['skipped'] > 0) {
            $leftOut = $read['skipped'] . ' row(s) of hours from ' . gmdate('Y-m-d H:i', min(array_keys($read['skipped_hours'])))
                . ' UTC were left out of the spend ledger, the first: ' . $read['first_skip'];
        }
        $leftOut = $leftOut === null ? null : self::leftOutText($leftOut, $left !== [], $marked);

        $retryAfter = $this->recordRun($connection, [
            'now' => $now,
            'rows' => $read['rows'],
            'error' => $error,
            'note' => $notes === [] ? null : implode('; ', $notes),
            'left_out' => $leftOut,
            'left_out_hours' => $left === [] ? null : implode(',', $left),
            'pending_from' => $caughtUp ? null : $through - $through % 3600,
            'conversions_from' => $conversionsRead ? null : max($reach, min($owed ?? PHP_INT_MAX, $runFrom)),
            'back_off' => $outcome === self::RATE_LIMITED || $outcome === self::KEY_REFUSED ? $outcome : null,
            'wait' => $outcome === self::RATE_LIMITED ? $wait : null,
            'complete' => $complete,
            'backfilled' => !$backfilled && $caughtUp,
            'limited_before' => (int) ($state['rate_limited'] ?? 0),
            'refused_before' => (int) ($state['key_refusals'] ?? 0),
        ]);
        if ($outcome === self::KEY_REFUSED) {
            $this->connections->keyRefused($connection->userId, $connection->connectionId, (string) $error, $now);
        }

        return ['rows' => $read['rows'], 'from' => $caughtUp ? $runFrom : $from, 'to' => $caughtUp ? $now : $through, 'complete' => $complete,
            'error' => $error, 'note' => $notes === [] ? null : implode('; ', $notes),
            'outcome' => $outcome, 'wait' => $wait, 'retry_after' => $retryAfter];
    }

    /**
     * [$from, $to) a day at a time, oldest first, each day's pages written to
     * the ledger page by page. $through is the end of the last day read
     * whole, passed to $dayDone after each. $read carries what was done when
     * it returns or throws: rows written, pages read (the budget is the
     * run's), `stopped` (why it stopped before $to, or null), and the rows
     * left out (`skipped`, the first one's reason, the hours to read again).
     *
     * @param array{rows: int, pages: int, stopped: ?string, skipped: int, first_skip: ?string, skipped_hours: array<int, true>} $read
     * @param (\Closure(int): void)|null $dayDone
     */
    private function pull(SpendConnection $connection, SpendLedger $ledger, int $from, int $to, ?int $deadline, array &$read, int &$through, ?\Closure $dayDone = null): void
    {
        $currency = $ledger->accountCurrency($connection->userId);
        if (strtoupper($connection->currency) !== $currency) {
            throw new SpendSyncRefused('the ad account ' . $connection->accountRef . ' is billed in ' . $connection->currency
                . ' and this Prosper202 account records money in ' . $currency
                . '; spend is not converted between currencies, so it is not pulled');
        }
        for ($start = $from; $start < $to; $start = $end) {
            $end = min($start + self::CHUNK_SECONDS, $to);
            $after = null;
            $seen = [];
            do {
                if ($read['pages'] >= $this->pageBudget) {
                    $read['stopped'] = 'stopped after ' . $read['pages'] . ' page(s), the most one run reads';

                    return;
                }
                if ($deadline !== null && ($this->clock)() >= $deadline) {
                    $read['stopped'] = 'stopped at the run\'s time limit';

                    return;
                }
                $answer = $this->insights->page($connection->apiKey, $start, $end, $after);
                $read['pages']++;
                [$rows, $refused] = self::ledgerRows($answer['rows'], $connection, $currency);
                // A row that is not one hour has no hour to read again from but its day's start.
                $skippedFrom = $refused === [] ? null : $start;
                [$parsed, $errors] = $rows === [] ? [[], []] : $ledger->parse($connection->userId, $rows);
                if ($errors !== []) {
                    // One unreadable row must not cost the page: keep the rows
                    // that read on their own and name what was left out.
                    [$parsed, $more, $hour] = self::readable($ledger, $connection->userId, $rows);
                    $refused = array_merge($refused, $more);
                    // Read again from the earliest: the page's rows end within a day of it.
                    $skippedFrom = min($skippedFrom ?? PHP_INT_MAX, $hour === null ? $start : max($start, $hour - $hour % 3600));
                }
                if ($refused !== []) {
                    $read['skipped'] += count($refused);
                    $read['first_skip'] ??= $refused[0];
                    $read['skipped_hours'][$skippedFrom ?? $start] = true;
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
            $through = $end;
            if ($dayDone !== null) {
                $dayDone($end);
            }
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
     * or a refused key fails the run as the spend pull does. The second value
     * is whether they were read: until then the run keeps them owed.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function pullConversions(SpendConnection $connection, int $from, int $to): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT ad_ref FROM 202_spend_ledger WHERE user_id = ? AND platform = ? AND account_ref = ?'
            . ' AND hour_start >= ? AND hour_start <= ? ORDER BY ad_ref'
        );
        $this->conn->bind($stmt, 'issii', [$connection->userId, self::PLATFORM, $connection->accountRef, $from, $to]);
        $ads = array_map(static fn (array $row): string => (string) $row['ad_ref'], $this->conn->fetchAll($stmt));
        if ($ads === []) {
            return [null, true];
        }
        $zone = new \DateTimeZone($connection->timezone);
        $start = (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime(0, 0);
        $end = (new \DateTimeImmutable('@' . $to))->setTimezone($zone)->setTime(0, 0);
        if ($end <= $start) {
            return [null, true];
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
                return ['conversions not read: ' . $e->getMessage(), false];
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

        return [$notes === [] ? null : implode('; ', $notes), true];
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
            'UPDATE 202_ad_spend_sync SET enabled = IF(? = 1, 0, enabled), last_run_at = ?, last_complete = 0, last_rows = 0, last_error = ?,'
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
     * notes in last_note, what is still left out in left_out and the hours
     * to read again in left_out_hours, and when the hourly run tries again
     * after a back-off (returned, 0 for none). A 429 and a refused key each
     * count their own back-offs in a row. last_success_at is a complete run's.
     *
     * @param array{now: int, rows: int, error: ?string, note: ?string, left_out: ?string, left_out_hours: ?string,
     *     pending_from: ?int, conversions_from: ?int, back_off: ?string, wait: ?int, complete: bool, backfilled: bool,
     *     limited_before: int, refused_before: int} $run
     */
    private function recordRun(SpendConnection $connection, array $run): int
    {
        $now = $run['now'];
        $limited = $run['back_off'] === self::RATE_LIMITED ? min($run['limited_before'] + 1, 60) : 0;
        $refused = $run['back_off'] === self::KEY_REFUSED ? min($run['refused_before'] + 1, 60) : 0;
        $times = max($limited, $refused);
        $wait = $times === 0 ? 0 : (int) min(self::BACKOFF_SECONDS * (2 ** min($times - 1, 5)), self::BACKOFF_MAX_SECONDS);
        $retryAfter = $times > 0 ? $now + max($wait, min($run['wait'] ?? 0, self::RETRY_AFTER_MAX_SECONDS)) : 0;
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_ad_spend_sync SET last_run_at = ?, last_rows = ?, last_error = ?, last_note = ?, left_out = ?,'
            . ' left_out_hours = ?, pending_from = ?, conversions_from = ?, rate_limited = ?, key_refusals = ?, retry_after = ?,'
            . ' last_complete = ?, last_success_at = IF(? = 1, ?, last_success_at), backfilled_at = IF(? = 1, ?, backfilled_at), updated_at = ?'
            . ' WHERE connection_id = ? AND user_id = ?'
        );
        $cut = static fn (?string $text): ?string => $text === null ? null : mb_substr($text, 0, 2000);
        $this->conn->bind($stmt, 'iissssiiiiiiiiiiiii', [
            $now, $run['rows'], $cut($run['error']), $cut($run['note']), $cut($run['left_out']), $run['left_out_hours'],
            $run['pending_from'], $run['conversions_from'], $limited, $refused, $retryAfter,
            $run['complete'] ? 1 : 0, $run['complete'] ? 1 : 0, $now, $run['backfilled'] ? 1 : 0, $now, $now, $connection->connectionId, $connection->userId,
        ]);
        $this->conn->executeUpdate($stmt);

        return $retryAfter;
    }

    /** How far the forward read got, and the conversions it owes, while the run goes on. */
    private function checkpoint(SpendConnection $connection, int $pendingFrom, int $conversionsFrom): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_ad_spend_sync SET pending_from = ?, conversions_from = LEAST(COALESCE(conversions_from, ?), ?), updated_at = ?'
            . ' WHERE connection_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'iiiiii', [$pendingFrom, $conversionsFrom, $conversionsFrom, ($this->clock)(), $connection->connectionId, $connection->userId]);
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
            'SELECT connection_id, enabled, backfilled_at, last_run_at, last_success_at, last_complete, last_rows, last_error, last_note,'
            . ' left_out, left_out_hours, pending_from, conversions_from, rate_limited, key_refusals, retry_after'
            . ' FROM 202_ad_spend_sync WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['connection_id']] = $row;
        }

        return $out;
    }

    /**
     * left_out as one sentence for where its hours stand: its own words, then
     * read again (some left), no longer in reach (some lost), or both; null
     * once every hour it named was read again cleanly and none was lost.
     */
    private static function leftOutText(string $text, bool $left, bool $lost): ?string
    {
        $base = str_replace([self::READ_AGAIN, self::OUT_OF_REACH, self::SOME_OUT_OF_REACH], '', $text);
        if (!$left) {
            return $lost ? $base . self::OUT_OF_REACH : null;
        }

        return $base . self::READ_AGAIN . ($lost ? self::SOME_OUT_OF_REACH : '');
    }

    /**
     * left_out_hours as hour starts, in their order. Written only here, so a
     * value that does not read is a fault, not an empty list (CLAUDE.md #4).
     *
     * @return list<int>
     */
    private static function hoursOf(mixed $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', (string) $text) as $part) {
            if (preg_match('/\A[0-9]{1,10}\z/D', $part) !== 1 || (int) $part % 3600 !== 0) {
                throw new \UnexpectedValueException('left_out_hours holds ' . json_encode($part) . ', not an hour start');
            }
            $out[] = (int) $part;
        }

        return $out;
    }

    private static function time(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

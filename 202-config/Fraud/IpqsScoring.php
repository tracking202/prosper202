<?php

declare(strict_types=1);

namespace Prosper202\Fraud;

use Prosper202\Click\Filter\AddressRange;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\MysqlClickFilterStore;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\IpAddressSql;
use Prosper202\Http\EuropeanCountries;
use Prosper202\Http\PrivacySetting;
use Prosper202\Http\StoredVisitorIp;

/**
 * IPQualityScore fraud scoring, for each account that saved an IPQS key
 * (202_users_pref.ipqs_api_key): the cron job's minute tier, never the click
 * path. Each run walks the account's clicks by time and id from where the
 * last run stopped (202_ipqs_progress), over the last WINDOW_SECONDS and not
 * the last SETTLE_SECONDS, which a click still being written may be in.
 *
 * A counted click that is no lead, placed by GeoIP outside the EU, the EEA,
 * the UK and Switzerland (EuropeanCountries; an unplaced click is held as
 * European), from a public address, takes its address's score: the
 * account's cached one (202_ipqs_scores, for CACHE_SECONDS, then deleted) or
 * one lookup (IpqsClient), at most MAX_LOOKUPS a run across accounts. A
 * score of FRAUD_THRESHOLD or more filters the click
 * (MysqlClickRepository::filterAsFraud(), reason fraud_score) and rolls its
 * report row up again; a click whose address the account's click filter
 * list says always counts is passed over without a lookup. Every other click is passed over, and no held click
 * is sent or recorded anywhere. An account whose privacy setting
 * (PrivacySetting) is 'all' is not scored at all, and a click stored masked
 * (StoredVisitorIp::mask()) is held: that address is no visitor's.
 *
 * A failed lookup ends the account's run at that click, recorded with the
 * reason (never the key), and the account waits RETRY_FIRST seconds,
 * doubling to RETRY_MAX, before its next try, starting with that click. A
 * click still unscored when it leaves the window is not scored. Any other
 * error on a click is recorded and waited out the same way, and that click
 * is passed over.
 */
final class IpqsScoring
{
    /** A score at or above this filters the click: IPQualityScore's "high risk" is 85; this is stricter. */
    public const FRAUD_THRESHOLD = 90;
    public const WINDOW_SECONDS = 86400;
    public const SETTLE_SECONDS = 60;
    public const CACHE_SECONDS = 86400;
    public const RUN_SECONDS = 15;
    public const MAX_LOOKUPS = 50;
    public const ROWS_PER_ACCOUNT = 2000;
    public const RETRY_FIRST = 120;
    public const RETRY_MAX = 1800;

    private const BATCH = 500;
    private const PRUNE_ROWS = 5000;

    /** @var callable(): int */
    private $clock;

    private int $lookups = 0;

    /** @var array<string, bool> "user:ip_id" => the account's list says the address always counts; one run's memory */
    private array $alwaysCounted = [];

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly Connection $conn,
        private readonly IpqsClient $client,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Score until $deadline (unix seconds).
     *
     * @return array{accounts: int, clicks: int, lookups: int, filtered: int, held: int, failed: int, pruned: int}
     */
    public function run(int $deadline): array
    {
        $now = ($this->clock)();
        $report = ['accounts' => 0, 'clicks' => 0, 'lookups' => 0, 'filtered' => 0, 'held' => 0, 'failed' => 0,
            'pruned' => $this->pruneCache($now)];
        $this->lookups = 0;
        $this->alwaysCounted = [];
        foreach ($this->accounts($now) as [$userId, $key]) {
            if (($this->clock)() >= $deadline || $this->lookups >= self::MAX_LOOKUPS) {
                break;
            }
            $report['accounts']++;
            try {
                $one = $this->scoreAccount($userId, $key, $deadline);
            } catch (\Throwable $e) {
                // A database failure: logged, and the next run starts where this one saved.
                error_log('IPQualityScore scoring: account ' . $userId . ' stopped: ' . $e->getMessage());
                $report['failed']++;
                continue;
            }
            foreach (['clicks', 'filtered', 'held', 'failed'] as $count) {
                $report[$count] += $one[$count];
            }
        }
        $report['lookups'] = $this->lookups;

        return $report;
    }

    /**
     * The account's scoring status for its integrations page; null when it
     * has never run.
     *
     * @return array{last_run_at: int, last_lookup_at: ?int, failures: int, retry_at: int,
     *     last_error: ?string, last_error_at: ?int}|null
     */
    public static function status(Connection $conn, int $userId): ?array
    {
        $stmt = $conn->prepareRead(
            'SELECT last_run_at, last_lookup_at, failures, retry_at, last_error, last_error_at'
            . ' FROM 202_ipqs_progress WHERE user_id = ?'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'last_run_at' => (int) $row['last_run_at'],
            'last_lookup_at' => $row['last_lookup_at'] === null ? null : (int) $row['last_lookup_at'],
            'failures' => (int) $row['failures'],
            'retry_at' => (int) $row['retry_at'],
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
            'last_error_at' => $row['last_error_at'] === null ? null : (int) $row['last_error_at'],
        ];
    }

    /** Cached scores past CACHE_SECONDS, every account's: deleted, not kept. */
    private function pruneCache(int $now): int
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_ipqs_scores WHERE scored_at < ? LIMIT ' . self::PRUNE_ROWS);
        $this->conn->bind($stmt, 'i', [$now - self::CACHE_SECONDS]);

        return $this->conn->executeUpdate($stmt);
    }

    /**
     * The accounts with a key that are not waiting after a failure, the
     * longest unscored first, so one busy account cannot hold the others back.
     *
     * @return list<array{int, string}>
     */
    private function accounts(int $now): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT p.user_id, p.ipqs_api_key FROM 202_users_pref AS p'
            . ' JOIN 202_users AS u ON u.user_id = p.user_id'
            . ' LEFT JOIN 202_ipqs_progress AS g ON g.user_id = p.user_id'
            . " WHERE p.ipqs_api_key IS NOT NULL AND p.ipqs_api_key <> '' AND u.user_deleted = 0 AND u.user_active = 1"
            . ' AND (g.user_id IS NULL OR g.retry_at <= ?)'
            . ' ORDER BY COALESCE(g.last_run_at, 0), p.user_id'
        );
        $this->conn->bind($stmt, 'i', [$now]);
        $accounts = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $accounts[] = [(int) $row['user_id'], trim((string) $row['ipqs_api_key'])];
        }

        return $accounts;
    }

    /** @return array{clicks: int, filtered: int, held: int, failed: int} */
    private function scoreAccount(int $userId, string $key, int $deadline): array
    {
        $now = ($this->clock)();
        $counts = ['clicks' => 0, 'filtered' => 0, 'held' => 0, 'failed' => 0];
        if (PrivacySetting::forAccount($this->conn, $userId) === 'all') {
            return $counts;
        }
        [$cursorTime, $cursorId, $failures] = $this->progress($userId);
        $floor = $now - self::WINDOW_SECONDS;
        if ($cursorTime < $floor) {
            [$cursorTime, $cursorId] = [$floor, 0];
        }
        $repository = new MysqlClickRepository($this->conn);
        $seen = [];
        $rows = 0;
        $looked = false;
        $failure = null;
        while ($failure === null && $rows < self::ROWS_PER_ACCOUNT) {
            $batch = $this->clicks($userId, $cursorTime, $cursorId, $now - self::SETTLE_SECONDS);
            foreach ($batch as $click) {
                if (($this->clock)() >= $deadline) {
                    break 2;
                }
                $clickId = (int) $click['click_id'];
                $counted = (int) $click['click_filtered'] === 0 && (int) $click['click_lead'] === 0;
                if (!isset($seen[$clickId]) && $counted) {
                    $seen[$clickId] = true;
                    try {
                        $verdict = $this->scoreClick($userId, $key, $click, $repository, $failure, $looked);
                    } catch (\Throwable $e) {
                        error_log('IPQualityScore scoring: account ' . $userId . ', click ' . $clickId
                            . ': ' . $e->getMessage());
                        $failure = 'A click could not be scored; scoring went on from the next one.';
                        [$cursorTime, $cursorId] = [(int) $click['click_time'], $clickId];
                        break 2;
                    }
                    if ($verdict === null) {
                        break 2; // the lookup budget is spent, or the lookup failed: this click is next
                    }
                    $counts['clicks'] += $verdict === 'held' ? 0 : 1;
                    $counts['held'] += $verdict === 'held' ? 1 : 0;
                    $counts['filtered'] += $verdict === 'filtered' ? 1 : 0;
                }
                [$cursorTime, $cursorId] = [(int) $click['click_time'], $clickId];
                $rows++;
            }
            if (count($batch) < self::BATCH) {
                break;
            }
        }
        $this->saveProgress($userId, $cursorTime, $cursorId, $now, $looked, $failure, $failures);
        $counts['failed'] = $failure === null ? 0 : 1;

        return $counts;
    }

    /**
     * One click: 'held' (European or unplaced, never sent), 'skipped' (no
     * public address), 'counted', 'filtered', or null when it could not be
     * scored now ($failure says why when a lookup failed).
     *
     * @param array<string, mixed> $click
     */
    private function scoreClick(
        int $userId,
        string $key,
        array $click,
        MysqlClickRepository $repository,
        ?string &$failure,
        bool &$looked
    ): ?string {
        if (!EuropeanCountries::isOutside(is_string($click['country_code'] ?? null) ? $click['country_code'] : null)) {
            return 'held';
        }
        $ipId = (int) $click['ip_id'];
        $address = is_string($click['ip_address'] ?? null) ? $click['ip_address'] : '';
        if ($address !== '' && StoredVisitorIp::mask($address) === $address) {
            return 'held';
        }
        $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($ipId <= 0 || $public === false) {
            return 'skipped';
        }
        // An address the account lists as "always count" is not scored: "Not fraud" puts it there (FraudVerdictUndo).
        $packed = AddressRange::packedForMatch($address);
        $this->alwaysCounted[$userId . ':' . $ipId] ??= $packed !== null
            && (new MysqlClickFilterStore($this->conn))->match($userId, $packed)?->action === ClickFilterEntry::COUNT;
        if ($this->alwaysCounted[$userId . ':' . $ipId]) {
            return 'skipped';
        }
        $score = $this->cachedScore($userId, $ipId);
        if ($score === null) {
            if ($this->lookups >= self::MAX_LOOKUPS) {
                return null;
            }
            $this->lookups++;
            try {
                $score = $this->client->fraudScore($key, $address);
            } catch (IpqsLookupFailed $e) {
                $failure = $e->getMessage();

                return null;
            }
            $looked = true;
            $this->cacheScore($userId, $ipId, $score);
        }
        if ($score < self::FRAUD_THRESHOLD) {
            return 'counted';
        }
        $clickId = (int) $click['click_id'];
        if (!$repository->filterAsFraud($userId, $clickId)) {
            return 'counted';
        }
        try {
            ClickRollupSql::rollUpClick($this->conn, $clickId);
        } catch (\Throwable $e) {
            error_log('IPQualityScore scoring: click ' . $clickId . ' was filtered but its report row was not'
                . ' rolled up: ' . $e->getMessage() . '; ' . ClickRollupSql::queueRetry($this->conn, $clickId));
        }

        return 'filtered';
    }

    /**
     * The account's clicks after ($time, $id), up to $until, with what
     * scoring reads: the flags, the address and the GeoIP country.
     *
     * @return list<array<string, mixed>>
     */
    private function clicks(int $userId, int $time, int $id, int $until): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT c.click_id, c.click_time, c.click_filtered, c.click_lead, a.ip_id, lc.country_code, '
            . IpAddressSql::address('i', 'v6') . ' AS ip_address'
            . ' FROM 202_clicks AS c FORCE INDEX (user_click_time)'
            . ' JOIN 202_clicks_advance AS a ON a.click_id = c.click_id'
            . ' LEFT JOIN 202_locations_country AS lc ON lc.country_id = a.country_id'
            . ' LEFT JOIN 202_ips AS i ON i.ip_id = a.ip_id'
            . IpAddressSql::join('i', 'v6')
            . ' WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ?'
            . ' AND (c.click_time > ? OR c.click_id > ?)'
            . ' ORDER BY c.click_time, c.click_id LIMIT ' . self::BATCH
        );
        $this->conn->bind($stmt, 'iiiii', [$userId, $time, $until, $time, $id]);

        return $this->conn->fetchAll($stmt);
    }

    /** @return array{int, int, int} the cursor's time and click id, and the failures in a row */
    private function progress(int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT cursor_time, cursor_click_id, failures FROM 202_ipqs_progress WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? [0, 0, 0]
            : [(int) $row['cursor_time'], (int) $row['cursor_click_id'], (int) $row['failures']];
    }

    private function cachedScore(int $userId, int $ipId): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT fraud_score FROM 202_ipqs_scores WHERE user_id = ? AND ip_id = ? AND scored_at >= ?'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $ipId, ($this->clock)() - self::CACHE_SECONDS]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['fraud_score'];
    }

    private function cacheScore(int $userId, int $ipId, int $score): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_ipqs_scores (user_id, ip_id, fraud_score, scored_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE fraud_score = VALUES(fraud_score), scored_at = VALUES(scored_at)'
        );
        $this->conn->bind($stmt, 'iiii', [$userId, $ipId, $score, ($this->clock)()]);
        $this->conn->executeUpdate($stmt);
    }

    private function saveProgress(
        int $userId,
        int $cursorTime,
        int $cursorId,
        int $now,
        bool $looked,
        ?string $failure,
        int $failures
    ): void {
        if ($failure !== null) {
            $failures = min($failures + 1, 60);
            $wait = min(self::RETRY_MAX, self::RETRY_FIRST * (2 ** min($failures - 1, 10)));
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_ipqs_progress (user_id, cursor_time, cursor_click_id, last_run_at, failures,'
                . ' retry_at, last_error, last_error_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE cursor_time = VALUES(cursor_time),'
                . ' cursor_click_id = VALUES(cursor_click_id),'
                . ' last_run_at = VALUES(last_run_at), failures = VALUES(failures), retry_at = VALUES(retry_at),'
                . ' last_error = VALUES(last_error), last_error_at = VALUES(last_error_at)'
            );
            $this->conn->bind($stmt, 'iiiiiisi', [
                $userId, $cursorTime, $cursorId, $now, $failures, $now + $wait, mb_substr($failure, 0, 255), $now,
            ]);
            $this->conn->executeUpdate($stmt);

            return;
        }
        // A lookup that answered ends a run of failures; a run with none keeps it as it was.
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_ipqs_progress (user_id, cursor_time, cursor_click_id, last_run_at, last_lookup_at)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE cursor_time = VALUES(cursor_time), cursor_click_id = VALUES(cursor_click_id),'
            . ' last_run_at = VALUES(last_run_at),'
            . ' last_lookup_at = COALESCE(VALUES(last_lookup_at), last_lookup_at),'
            . ' failures = IF(VALUES(last_lookup_at) IS NULL, failures, 0),'
            . ' retry_at = IF(VALUES(last_lookup_at) IS NULL, retry_at, 0)'
        );
        $this->conn->bind($stmt, 'iiiii', [$userId, $cursorTime, $cursorId, $now, $looked ? $now : null]);
        $this->conn->executeUpdate($stmt);
    }
}

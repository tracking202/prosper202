<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\Android\Integrity\GooglePlayIntegrityClient;
use Api\V3\Apps\Android\Integrity\IntegrityVerifier;
use Prosper202\Database\Connection;

/**
 * The Android intake's worker half (plan §5.2, §5.3, §5.6): decode Play
 * Integrity verdicts, then settle `pending_click` installs, in that order so
 * an install one settles the other can finish in the same run.
 *
 * Two entry points run it, and a default deployment schedules only the
 * first: the minutely 202-cronjobs/index.php (every documented scheduler —
 * docker-compose.yaml, the installer's cron line, install.sh — names only
 * that file), and 202-cronjobs/app-installs.php for deployments that run
 * workers on their own (docker-compose.coolify.yaml). A job that only the
 * second ran left every `integrity_mode=require` install at
 * pending_integrity and every pending_click install unsettled on a default
 * install; tests/Cron/EveryCronJobIsScheduledByDefaultTest pins that each
 * job's work is reached from index.php.
 *
 * Both entry points take the same MySQL named lock, so they never run this
 * concurrently. (Each row is also claimed by compare-and-set, so an overlap
 * would not double-settle; the lock keeps the two from racing each other
 * for the same batch and spending attempts on rows the other holds.)
 */
final class AndroidIntakeJob
{
    public const LOCK_PREFIX = 'p202_android_intake:';

    /** The tables the job reads; missing on a schema that predates it. */
    public const TABLES = ['202_app_installs', '202_app_registrations'];

    /**
     * Run both passes under the lock, or answer null when another run holds
     * it. A database failure throws; a single install's failure is counted
     * in the report's `failed` and logged by the pass that met it.
     *
     * @return array{
     *     integrity: array{examined: int, verdicts: array<string, int>, retrying: int, failed: int},
     *     settle: array{examined: int, settled: array<string, int>, still_pending: int, failed: int}
     * }|null
     */
    public static function runExclusive(
        \mysqli $db,
        int $verifyLimit = 200,
        int $settleLimit = 500,
        ?GooglePlayIntegrityClient $client = null
    ): ?array {
        $conn = new Connection($db);
        $name = self::lockName($conn);
        $got = $conn->fetchOne(self::lockStatement($conn, 'SELECT GET_LOCK(?, 0) AS got', $name));
        if ($got === null || $got['got'] === null) {
            throw new \RuntimeException('GET_LOCK failed for ' . $name);
        }
        if ((int) $got['got'] !== 1) {
            return null;
        }
        $failure = null;
        try {
            $client ??= GooglePlayIntegrityClient::fromEnvironment();
            $integrity = (new IntegrityVerifier($db, $client))->run($verifyLimit);
            $settle = (new PendingClickSettler($db))->run($settleLimit);

            return ['integrity' => $integrity, 'settle' => $settle];
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            try {
                $conn->fetchOne(self::lockStatement($conn, 'SELECT RELEASE_LOCK(?) AS released', $name));
            } catch (\Throwable $releaseError) {
                if ($failure === null) {
                    throw $releaseError;
                }
            }
        }
    }

    /**
     * Whether the job's tables exist. A failed probe throws: "could not
     * check" is not "not installed" (CLAUDE.md #11).
     */
    public static function installed(\mysqli $db): bool
    {
        foreach (self::TABLES as $table) {
            $result = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException('could not check for ' . $table . ': ' . $db->error);
            }
            $exists = $result->num_rows > 0;
            $result->close();
            if (!$exists) {
                return false;
            }
        }

        return true;
    }

    /**
     * One line per pass, as both entry points print it.
     *
     * @param array{
     *     integrity: array{examined: int, verdicts: array<string, int>, retrying: int, failed: int},
     *     settle: array{examined: int, settled: array<string, int>, still_pending: int, failed: int}
     * } $report
     */
    public static function summary(array $report): string
    {
        $verdicts = [];
        foreach ($report['integrity']['verdicts'] as $state => $n) {
            $verdicts[] = $state . '=' . $n;
        }
        $settled = [];
        foreach ($report['settle']['settled'] as $state => $n) {
            $settled[] = $state . '=' . $n;
        }

        return sprintf(
            "play integrity: %d examined, %s, %d retrying, %d failed\n"
            . 'pending clicks: %d examined, settled %s, %d still pending, %d failed',
            $report['integrity']['examined'],
            $verdicts === [] ? 'no verdicts' : implode(' ', $verdicts),
            $report['integrity']['retrying'],
            $report['integrity']['failed'],
            $report['settle']['examined'],
            $settled === [] ? 'none' : implode(' ', $settled),
            $report['settle']['still_pending'],
            $report['settle']['failed']
        );
    }

    /**
     * Per database, as the attribution worker's: GET_LOCK names are
     * server-wide and at most 64 characters, so the name carries the
     * database's SHA-1 rather than a truncation two databases could share.
     */
    public static function lockName(Connection $conn): string
    {
        $row = $conn->fetchOne($conn->prepareWrite('SELECT DATABASE() AS db'));
        $db = $row['db'] ?? null;
        if (!is_string($db) || $db === '') {
            throw new \RuntimeException('The Android intake job needs a selected database to name its lock.');
        }

        return self::LOCK_PREFIX . sha1($db);
    }

    /** @return \mysqli_stmt */
    private static function lockStatement(Connection $conn, string $sql, string $name)
    {
        $stmt = $conn->prepareWrite($sql);
        $conn->bind($stmt, 's', [$name]);

        return $stmt;
    }
}

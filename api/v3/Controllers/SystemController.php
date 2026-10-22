<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StatementHelpers;
use Api\V3\Support\QueryInt;
use Prosper202\Click\ReClickBackfill;
use Prosper202\Database\Connection;

class SystemController
{
    use StatementHelpers;

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function health(): array
    {
        $dbOk = $this->db->ping();

        return [
            'data' => [
                'status'      => $dbOk ? 'healthy' : 'degraded',
                'database'    => $dbOk ? 'connected' : 'disconnected',
                'timestamp'   => time(),
                'php_version' => PHP_VERSION,
            ],
        ];
    }

    public function version(): array
    {
        // A version that cannot be read is a 500, not 'unknown': that is what
        // an install without a version row answers.
        $result = $this->db->query("SELECT version FROM 202_version LIMIT 1");
        if ($result === false) {
            throw new DatabaseException('Version query failed [errno ' . $this->db->errno . ']');
        }
        $row = $result->fetch_assoc();
        if ($row === false) {
            throw new DatabaseException('Version fetch failed');
        }

        return [
            'data' => [
                'prosper202_version' => $row['version'] ?? 'unknown',
                'php_version'        => PHP_VERSION,
                'mysql_version'      => $this->db->server_info,
                'api_version'        => 'v3',
            ],
        ];
    }

    public function dbStats(): array
    {
        $tables = [
            '202_clicks'             => 'Clicks',
            '202_conversion_logs'    => 'Conversions',
            '202_aff_campaigns'      => 'Campaigns',
            '202_aff_networks'       => 'Affiliate Networks',
            '202_ppc_accounts'       => 'PPC Accounts',
            '202_trackers'           => 'Trackers',
            '202_landing_pages'      => 'Landing Pages',
            '202_rotators'           => 'Rotators',
            '202_users'              => 'Users',
            '202_dataengine'         => 'Data Engine',
            '202_attribution_models' => 'Attribution Models',
        ];

        // Use information_schema for row estimates — avoids expensive COUNT(*) on large tables.
        $stats = [];
        $dbResult = $this->db->query("SELECT DATABASE() as db");
        if ($dbResult === false) {
            throw new DatabaseException('Failed to determine current database');
        }
        $dbRow = $dbResult->fetch_assoc();
        if (!is_array($dbRow) || !array_key_exists('db', $dbRow)) {
            throw new DatabaseException('Failed to determine current database');
        }
        $dbName = (string)$dbRow['db'];

        // One prepared statement re-bound per table, instead of re-preparing
        // the identical query on every iteration. (bind/execute close the
        // statement themselves before throwing, so no finally-close here.)
        $stmt = $this->prepare(
            "SELECT TABLE_ROWS as cnt FROM information_schema.TABLES WHERE table_schema = ? AND table_name = ?"
        );
        foreach ($tables as $table => $label) {
            $this->bind($stmt, 'ss', $dbName, $table);
            $this->execute($stmt, 'Stats query failed');
            $result = $stmt->get_result();
            if ($result === false) {
                $stmt->close();
                throw new DatabaseException('Stats query failed');
            }
            $row = $result->fetch_assoc();
            $stats[] = ['table' => $table, 'label' => $label, 'rows_estimate' => (int)($row['cnt'] ?? 0)];
        }
        $stmt->close();

        $result = $this->db->query(
            "SELECT SUM(data_length + index_length) as size
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()"
        );
        if ($result === false) {
            throw new DatabaseException('Database size query failed [errno ' . $this->db->errno . ']');
        }
        $sizeRow = $result->fetch_assoc();
        if (!is_array($sizeRow)) {
            throw new DatabaseException('Database size fetch failed');
        }

        return [
            'data' => [
                'tables'              => $stats,
                'database_size_bytes' => (int)$sizeRow['size'],
                'database_size_mb'    => round((int)$sizeRow['size'] / 1048576, 2),
            ],
        ];
    }

    public function cronStatus(): array
    {
        // A read that fails is a 500: answered as no jobs, `p202 system cron`
        // would report cron as never having run.
        $result = $this->db->query('SELECT cronjob_type, cronjob_time FROM 202_cronjobs ORDER BY cronjob_type');
        if ($result === false) {
            throw new DatabaseException('Cron jobs query failed [errno ' . $this->db->errno . ']');
        }
        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $row['last_run_human'] = date('Y-m-d H:i:s', (int)$row['cronjob_time']);
            $jobs[] = $row;
        }

        $result = $this->db->query('SELECT id, last_execution_time FROM 202_cronjob_logs ORDER BY id DESC LIMIT 20');
        if ($result === false) {
            throw new DatabaseException('Cron log query failed [errno ' . $this->db->errno . ']');
        }
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $row['time_human'] = date('Y-m-d H:i:s', (int)$row['last_execution_time']);
            $logs[] = $row;
        }

        return ['data' => ['jobs' => $jobs, 'recent_logs' => $logs]];
    }

    public function errors(array $params): array
    {
        $limit = QueryInt::param($params, 'limit', 20, 1, 100, 'errors, newest first');
        $stmt = $this->prepare(
            'SELECT mysql_error_id, mysql_error_time, mysql_error_text AS mysql_error_message, mysql_error_sql '
            . 'FROM 202_mysql_errors ORDER BY mysql_error_id DESC LIMIT ?'
        );
        $this->bind($stmt, 'i', $limit);
        $this->execute($stmt, 'Errors query failed');
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Errors query failed');
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['time_human'] = date('Y-m-d H:i:s', (int)$row['mysql_error_time']);
            $rows[] = $row;
        }
        $stmt->close();

        return ['data' => $rows];
    }

    public function dataengineStatus(): array
    {
        $result = $this->db->query('SELECT time_from, time_to, processing, processed FROM 202_dataengine_job ORDER BY time_from DESC LIMIT 20');
        if ($result === false) {
            throw new DatabaseException('Data engine jobs query failed [errno ' . $this->db->errno . ']');
        }
        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $row['time_from_human'] = date('Y-m-d H:i:s', (int)$row['time_from']);
            $row['time_to_human'] = date('Y-m-d H:i:s', (int)$row['time_to']);
            if ((int)$row['processed'] === 1) {
                $row['status'] = 'completed';
            } elseif ((int)$row['processing'] === 1) {
                $row['status'] = 'processing';
            } else {
                $row['status'] = 'pending';
            }
            $jobs[] = $row;
        }

        // Not 0 when unread: 0 pending hours reads as reports up to date.
        $result = $this->db->query('SELECT COUNT(*) as cnt FROM 202_dirty_hours WHERE processed = 0');
        if ($result === false) {
            throw new DatabaseException('Dirty hours query failed [errno ' . $this->db->errno . ']');
        }
        $dirtyRow = $result->fetch_assoc();
        if (!is_array($dirtyRow)) {
            throw new DatabaseException('Dirty hours fetch failed');
        }

        return [
            'data' => [
                'recent_jobs'        => $jobs,
                'pending_dirty_hours' => (int)$dirtyRow['cnt'],
                // How far the cron job has brought the clicks re-clicked
                // before 1.9.78 to one visit each; null once it is done.
                'reclick_backfill'   => (new ReClickBackfill(new Connection($this->db)))->progress(),
            ],
        ];
    }

    public function metrics(): array
    {
        $store = new ServerStateStore();
        $metrics = $store->metrics();
        $counters = is_array($metrics['counters'] ?? null) ? $metrics['counters'] : [];

        $queued = $store->listJobs(['queued'], 5000);
        $running = $store->listJobs(['running'], 5000);
        $oldestQueueEpoch = null;
        foreach ($queued as $job) {
            $candidate = (int)($job['next_run_at'] ?? $job['created_at_epoch'] ?? 0);
            if ($candidate <= 0) {
                continue;
            }
            if ($oldestQueueEpoch === null || $candidate < $oldestQueueEpoch) {
                $oldestQueueEpoch = $candidate;
            }
        }
        $queueLagSeconds = $oldestQueueEpoch === null ? 0 : max(0, time() - $oldestQueueEpoch);

        $failureSpikeThreshold = $this->intEnv('P202_ALERT_FAILURE_SPIKE', 20);
        $queueLagThreshold = $this->intEnv('P202_ALERT_QUEUE_LAG_SECONDS', 300);
        $failureCount = (int)($counters['jobs_failed'] ?? 0) + (int)($counters['jobs_partial'] ?? 0);

        $alerts = [];
        if ($failureCount >= $failureSpikeThreshold) {
            $alerts[] = [
                'name' => 'failure_spike',
                'status' => 'warn',
                'message' => 'Sync failures exceeded threshold',
                'value' => $failureCount,
                'threshold' => $failureSpikeThreshold,
            ];
        }
        if ($queueLagSeconds >= $queueLagThreshold && count($queued) > 0) {
            $alerts[] = [
                'name' => 'queue_lag',
                'status' => 'warn',
                'message' => 'Sync queue lag exceeded threshold',
                'value' => $queueLagSeconds,
                'threshold' => $queueLagThreshold,
            ];
        }

        return [
            'data' => [
                'counters' => $counters,
                'updated_at' => $metrics['updated_at'] ?? null,
                'queue' => [
                    'queued_jobs' => count($queued),
                    'running_jobs' => count($running),
                    'queue_lag_seconds' => $queueLagSeconds,
                ],
                'tracing' => [
                    'recent_spans' => $store->listSpans(null, 50),
                ],
                'alerts' => [
                    'thresholds' => [
                        'failure_spike' => $failureSpikeThreshold,
                        'queue_lag_seconds' => $queueLagThreshold,
                    ],
                    'active' => $alerts,
                ],
            ],
        ];
    }

    private function intEnv(string $name, int $default): int
    {
        $raw = getenv($name);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $value = (int)$raw;
        if ($value <= 0) {
            return $default;
        }
        return $value;
    }
}

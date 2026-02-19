<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Support\ServerStateStore;

class SystemController
{
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
        $result = $this->db->query("SELECT version FROM 202_version LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;

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

        foreach ($tables as $table => $label) {
            $stmt = $this->prepare(
                "SELECT TABLE_ROWS as cnt FROM information_schema.TABLES WHERE table_schema = ? AND table_name = ?"
            );
            $stmt->bind_param('ss', $dbName, $table);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new DatabaseException('Stats query failed');
            }
            $result = $stmt->get_result();
            if ($result === false) {
                $stmt->close();
                throw new DatabaseException('Stats query failed');
            }
            $row = $result->fetch_assoc();
            $stmt->close();
            $stats[] = ['table' => $table, 'label' => $label, 'rows_estimate' => (int)($row['cnt'] ?? 0)];
        }

        $result = $this->db->query(
            "SELECT SUM(data_length + index_length) as size
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()"
        );
        $sizeRow = $result ? $result->fetch_assoc() : ['size' => 0];

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
        $result = $this->db->query('SELECT cronjob_type, cronjob_time FROM 202_cronjobs ORDER BY cronjob_type');
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['last_run_human'] = date('Y-m-d H:i:s', (int)$row['cronjob_time']);
                $jobs[] = $row;
            }
        }

        $result = $this->db->query('SELECT id, last_execution_time FROM 202_cronjob_logs ORDER BY id DESC LIMIT 20');
        $logs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['time_human'] = date('Y-m-d H:i:s', (int)$row['last_execution_time']);
                $logs[] = $row;
            }
        }

        return ['data' => ['jobs' => $jobs, 'recent_logs' => $logs]];
    }

    public function errors(array $params): array
    {
        $limit = max(1, min(100, (int)($params['limit'] ?? 20)));
        $stmt = $this->prepare(
            'SELECT mysql_error_id, mysql_error_time, mysql_error_text AS mysql_error_message, mysql_error_sql '
            . 'FROM 202_mysql_errors ORDER BY mysql_error_id DESC LIMIT ?'
        );
        $stmt->bind_param('i', $limit);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException('Errors query failed');
        }
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
        $jobs = [];
        if ($result) {
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
        }

        $result = $this->db->query('SELECT COUNT(*) as cnt FROM 202_dirty_hours WHERE processed = 0');
        $dirtyRow = $result ? $result->fetch_assoc() : ['cnt' => 0];

        return [
            'data' => [
                'recent_jobs'        => $jobs,
                'pending_dirty_hours' => (int)$dirtyRow['cnt'],
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

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
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

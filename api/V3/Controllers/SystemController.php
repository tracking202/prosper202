<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;

class SystemController
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
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
        $dbName = $this->db->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
        foreach ($tables as $table => $label) {
            $stmt = $this->prepare(
                "SELECT TABLE_ROWS as cnt FROM information_schema.TABLES WHERE table_schema = ? AND table_name = ?"
            );
            $stmt->bind_param('ss', $dbName, $table);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
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

        $result = $this->db->query('SELECT id, cronjob_type, last_execution_time, duration FROM 202_cronjob_logs ORDER BY id DESC LIMIT 20');
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
        $stmt = $this->prepare('SELECT mysql_error_id, mysql_error_time, mysql_error_message FROM 202_mysql_errors ORDER BY mysql_error_id DESC LIMIT ?');
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
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
        $result = $this->db->query('SELECT job_id, time_from, time_to, status, rows_processed FROM 202_dataengine_job ORDER BY time_from DESC LIMIT 20');
        $jobs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['time_from_human'] = date('Y-m-d H:i:s', (int)$row['time_from']);
                $row['time_to_human'] = date('Y-m-d H:i:s', (int)$row['time_to']);
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

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }
}

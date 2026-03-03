<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

class CapabilitiesController
{
    private const string CLICKSERVER_VALIDATE_URL = 'https://my.tracking202.com/api/v2/validate-customers-key';

    public function __construct(
        private readonly \mysqli $db,
        private readonly ?int $userId = null,
    ) {
    }

    public function versions(): array
    {
        return [
            'data' => [
                'preferred' => 'v3',
                'supported' => ['v3'],
            ],
        ];
    }

    public function capabilities(): array
    {
        return [
            'data' => [
                'api_version' => 'v3',
                'entity_support' => $this->entitySupport(),
                'sync_features' => [
                    'diff' => true,
                    'sync_plan' => true,
                    'async_jobs' => true,
                    'incremental' => true,
                    'prune' => true,
                    'force_update' => true,
                    'server_fk_remap' => true,
                ],
                'limits' => [
                    'max_bulk_rows' => $this->maxBulkRows(),
                    'max_job_concurrency' => 5,
                    'max_job_events_page' => 500,
                    'rate_limits' => [
                        'sync_per_minute' => 30,
                        'bulk_upsert_per_minute' => 60,
                    ],
                ],
                'shell' => $this->shellAccess(),
                'server' => [
                    'build' => $this->resolveBuildVersion(),
                    'commit' => defined('P202_GIT_COMMIT') ? (string)P202_GIT_COMMIT : 'unknown',
                    'environment' => defined('P202_ENV') ? (string)P202_ENV : 'unknown',
                    'timezone_support' => $this->timezoneSupport(),
                ],
            ],
        ];
    }

    private function entitySupport(): array
    {
        $base = [
            'list' => true,
            'get' => true,
            'create' => true,
            'update' => true,
            'delete' => true,
            'bulk_upsert' => true,
        ];

        return [
            'aff-networks' => $base,
            'ppc-networks' => $base,
            'ppc-accounts' => $base,
            'campaigns' => $base,
            'landing-pages' => $base,
            'text-ads' => $base,
            'trackers' => $base,
        ];
    }

    private function resolveBuildVersion(): string
    {
        $result = $this->db->query('SELECT version FROM 202_version LIMIT 1');
        if ($result === false) {
            return 'unknown';
        }
        $row = $result->fetch_assoc();
        return (string)($row['version'] ?? 'unknown');
    }

    private function timezoneSupport(): string
    {
        $stmt = $this->db->prepare("SELECT CONVERT_TZ('2000-01-01 00:00:00', '+00:00', 'UTC') AS tz");
        if (!$stmt) {
            return 'unknown';
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return 'unknown';
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return 'unknown';
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return ($row['tz'] ?? null) === null ? 'fallback-only' : 'named-timezone';
    }

    private function maxBulkRows(): int
    {
        $raw = getenv('P202_MAX_BULK_ROWS');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed > 0) {
                return min(5000, $parsed);
            }
        }
        return 500;
    }

    /**
     * Determine shell access by validating the user's ClickServer API key
     * against my.tracking202.com. Returns false if the user has no key or
     * the key is invalid. On network failure, denies access (fail-closed).
     */
    private function shellAccess(): bool
    {
        if ($this->userId === null) {
            return false;
        }

        $customerKey = $this->loadClickServerKey();
        if ($customerKey === '') {
            return false;
        }

        return $this->validateClickServerKey($customerKey);
    }

    private function loadClickServerKey(): string
    {
        $stmt = $this->db->prepare(
            'SELECT p202_customer_api_key FROM 202_users WHERE user_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $this->userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return '';
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            return '';
        }
        $row = $result->fetch_assoc();
        $stmt->close();

        return trim((string)($row['p202_customer_api_key'] ?? ''));
    }

    private function validateClickServerKey(string $key): bool
    {
        $ch = curl_init();
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_URL, self::CLICKSERVER_VALIDATE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['key' => $key]));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Verify SSL in production; matches the security posture of the API.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $failed = curl_errno($ch) || $response === false;
        curl_close($ch);

        if ($failed) {
            return false;
        }

        $data = json_decode((string)$response, true);
        return is_array($data) && ($data['msg'] ?? '') === 'Key valid';
    }
}

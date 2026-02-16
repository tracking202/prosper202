<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;

class ServerStateStore
{
    private const DEFAULT_RETENTION = 5000;

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = rtrim($baseDir ?? $this->resolveDefaultBaseDir(), '/');
        $this->ensureDir($this->baseDir);
        $this->ensureDir($this->dir('idempotency'));
        $this->ensureDir($this->dir('changes'));
        $this->ensureDir($this->dir('jobs'));
        $this->ensureDir($this->dir('audit'));
        $this->ensureDir($this->dir('locks'));
        $this->ensureDir($this->dir('tokens'));
        $this->ensureDir($this->dir('manifests'));
        $this->ensureDir($this->dir('metrics'));
        $this->ensureDir($this->dir('rate_limits'));
        $this->ensureDir($this->dir('traces'));
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalHash(array $payload): string
    {
        self::sortPayloadRecursive($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return sha1((string)microtime(true));
        }
        return sha1($json);
    }

    public function getIdempotent(string $scope, string $key): ?array
    {
        $data = $this->readJsonFile($this->idempotencyPath($scope), ['items' => []]);
        $items = $data['items'] ?? [];
        if (!isset($items[$key]['response']) || !is_array($items[$key]['response'])) {
            return null;
        }
        return $items[$key]['response'];
    }

    public function putIdempotent(string $scope, string $key, array $response): void
    {
        $path = $this->idempotencyPath($scope);
        $data = $this->readJsonFile($path, ['items' => []]);
        $data['items'][$key] = [
            'stored_at' => time(),
            'response' => $response,
        ];

        if (count($data['items']) > self::DEFAULT_RETENTION) {
            uasort($data['items'], static fn(array $a, array $b): int => ($a['stored_at'] ?? 0) <=> ($b['stored_at'] ?? 0));
            $data['items'] = array_slice($data['items'], -self::DEFAULT_RETENTION, null, true);
        }

        $this->writeJsonFileAtomic($path, $data);
    }

    public function recordChange(string $entity, string $operation, array $record, int $actorUserId): void
    {
        $path = $this->changesPath($entity);
        $state = $this->readJsonFile($path, ['next_seq' => 1, 'items' => []]);

        $seq = (int)($state['next_seq'] ?? 1);
        $state['next_seq'] = $seq + 1;

        $state['items'][] = [
            'seq' => $seq,
            'entity' => $entity,
            'operation' => $operation,
            'changed_at' => gmdate('c'),
            'changed_at_epoch' => time(),
            'actor_user_id' => $actorUserId,
            'natural_key_digest' => sha1(json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'record' => $record,
        ];

        if (count($state['items']) > self::DEFAULT_RETENTION) {
            $state['items'] = array_slice($state['items'], -self::DEFAULT_RETENTION);
        }

        $this->writeJsonFileAtomic($path, $state);
    }

    public function listChanges(string $entity, ?string $cursor, int $limit, int $cursorTtl, ?int $updatedSince = null, ?int $deletedSince = null): array
    {
        $state = $this->readJsonFile($this->changesPath($entity), ['next_seq' => 1, 'items' => []]);
        $items = is_array($state['items'] ?? null) ? $state['items'] : [];

        $startSeq = 1;
        if ($cursor !== null && $cursor !== '') {
            $decoded = $this->decodeCursor($cursor);
            $expiresAt = (int)($decoded['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                throw new DatabaseException('Cursor expired');
            }
            $startSeq = max(1, (int)($decoded['next_seq'] ?? 1));
        }

        $filtered = [];
        foreach ($items as $item) {
            $seq = (int)($item['seq'] ?? 0);
            if ($seq < $startSeq) {
                continue;
            }

            $changedAt = (int)($item['changed_at_epoch'] ?? 0);
            $operation = (string)($item['operation'] ?? '');
            if ($updatedSince !== null && $operation !== 'delete' && $changedAt < $updatedSince) {
                continue;
            }
            if ($deletedSince !== null && ($operation !== 'delete' || $changedAt < $deletedSince)) {
                continue;
            }

            $filtered[] = $item;
        }

        usort($filtered, static fn(array $a, array $b): int => ((int)$a['seq']) <=> ((int)$b['seq']));

        $slice = array_slice($filtered, 0, $limit);
        $nextCursor = null;
        $cursorExpiresAt = null;
        if (count($filtered) > $limit && !empty($slice)) {
            $lastSeq = (int)$slice[count($slice) - 1]['seq'];
            $cursorExpiresAt = time() + $cursorTtl;
            $nextCursor = $this->encodeCursor([
                'next_seq' => $lastSeq + 1,
                'expires_at' => $cursorExpiresAt,
            ]);
        }

        return [
            'data' => $slice,
            'cursor' => $nextCursor,
            'cursor_expires_at' => $cursorExpiresAt,
        ];
    }

    public function createJob(array $payload, int $actorUserId): array
    {
        $jobId = bin2hex(random_bytes(16));
        $job = [
            'job_id' => $jobId,
            'status' => 'queued',
            'created_at' => gmdate('c'),
            'created_at_epoch' => time(),
            'updated_at' => gmdate('c'),
            'actor_user_id' => $actorUserId,
            'request' => $payload,
            'results' => null,
            'error' => null,
            'cancel_requested' => false,
        ];

        $this->writeJsonFileAtomic($this->jobPath($jobId), $job);
        $this->writeJsonFileAtomic($this->jobEventsPath($jobId), ['items' => []]);

        return $job;
    }

    public function getJob(string $jobId): ?array
    {
        $path = $this->jobPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $job = $this->readJsonFile($path, []);
        return is_array($job) ? $job : null;
    }

    public function saveJob(array $job): void
    {
        if (!isset($job['job_id'])) {
            throw new DatabaseException('Job payload missing job_id');
        }
        $job['updated_at'] = gmdate('c');
        $this->writeJsonFileAtomic($this->jobPath((string)$job['job_id']), $job);
    }

    public function appendJobEvent(string $jobId, string $level, string $message, array $data = []): void
    {
        $path = $this->jobEventsPath($jobId);
        $events = $this->readJsonFile($path, ['items' => []]);
        $events['items'][] = [
            'event_id' => bin2hex(random_bytes(8)),
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'data' => $this->sanitizeSensitive($data),
        ];

        if (count($events['items']) > self::DEFAULT_RETENTION) {
            $events['items'] = array_slice($events['items'], -self::DEFAULT_RETENTION);
        }

        $this->writeJsonFileAtomic($path, $events);
    }

    public function listJobEvents(string $jobId, int $offset, int $limit): array
    {
        $events = $this->readJsonFile($this->jobEventsPath($jobId), ['items' => []]);
        $items = is_array($events['items'] ?? null) ? $events['items'] : [];
        $total = count($items);

        return [
            'data' => array_slice($items, $offset, $limit),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    public function appendAudit(array $record): void
    {
        $path = $this->auditPath();
        $audit = $this->readJsonFile($path, ['items' => []]);
        $audit['items'][] = $this->sanitizeSensitive($record);

        if (count($audit['items']) > self::DEFAULT_RETENTION) {
            $audit['items'] = array_slice($audit['items'], -self::DEFAULT_RETENTION);
        }

        $this->writeJsonFileAtomic($path, $audit);
    }

    /** @return array<int, array<string, mixed>> */
    public function listAudit(array $filters): array
    {
        $audit = $this->readJsonFile($this->auditPath(), ['items' => []]);
        $items = is_array($audit['items'] ?? null) ? $audit['items'] : [];

        $actor = trim((string)($filters['actor'] ?? ''));
        $source = trim((string)($filters['source'] ?? ''));
        $target = trim((string)($filters['target'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $from = isset($filters['from_epoch']) ? (int)$filters['from_epoch'] : null;
        $to = isset($filters['to_epoch']) ? (int)$filters['to_epoch'] : null;

        $filtered = [];
        foreach ($items as $item) {
            if ($actor !== '' && (string)($item['actor_user_id'] ?? '') !== $actor) {
                continue;
            }
            if ($source !== '' && (string)($item['source'] ?? '') !== $source) {
                continue;
            }
            if ($target !== '' && (string)($item['target'] ?? '') !== $target) {
                continue;
            }
            if ($status !== '' && (string)($item['status'] ?? '') !== $status) {
                continue;
            }

            $at = (int)($item['created_at_epoch'] ?? 0);
            if ($from !== null && $at < $from) {
                continue;
            }
            if ($to !== null && $at > $to) {
                continue;
            }

            $filtered[] = $item;
        }

        usort($filtered, static function (array $a, array $b): int {
            return ((int)($b['created_at_epoch'] ?? 0)) <=> ((int)($a['created_at_epoch'] ?? 0));
        });

        return $filtered;
    }

    public function getAudit(string $jobId): ?array
    {
        $audit = $this->readJsonFile($this->auditPath(), ['items' => []]);
        $items = is_array($audit['items'] ?? null) ? $audit['items'] : [];
        foreach ($items as $item) {
            if ((string)($item['job_id'] ?? '') === $jobId) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Acquire a non-blocking lock for a source-target pair.
     *
     * @return callable(): void
     */
    public function acquirePairLock(string $sourceKey, string $targetKey): callable
    {
        $name = sha1(strtolower($sourceKey) . '|' . strtolower($targetKey));
        $path = $this->dir('locks') . '/' . $name . '.lock';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new DatabaseException('Unable to open lock file');
        }

        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new DatabaseException('Sync lock is already held for this source/target pair');
        }

        return static function () use ($fh): void {
            flock($fh, LOCK_UN);
            fclose($fh);
        };
    }

    public function issuePruneToken(string $pairKey, int $ttlSeconds = 600): string
    {
        $token = bin2hex(random_bytes(16));
        $path = $this->dir('tokens') . '/prune.json';
        $state = $this->readJsonFile($path, ['items' => []]);
        $state['items'][$token] = [
            'pair_key' => $pairKey,
            'expires_at' => time() + $ttlSeconds,
        ];
        $this->writeJsonFileAtomic($path, $state);

        return $token;
    }

    public function validatePruneToken(string $token, string $pairKey): bool
    {
        $path = $this->dir('tokens') . '/prune.json';
        $state = $this->readJsonFile($path, ['items' => []]);
        $item = $state['items'][$token] ?? null;
        if (!is_array($item)) {
            return false;
        }
        if ((string)($item['pair_key'] ?? '') !== $pairKey) {
            return false;
        }
        if ((int)($item['expires_at'] ?? 0) < time()) {
            return false;
        }

        unset($state['items'][$token]);
        $this->writeJsonFileAtomic($path, $state);
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function listJobs(array $statuses = [], int $limit = 50): array
    {
        $files = glob($this->dir('jobs') . '/*.json') ?: [];
        sort($files, SORT_STRING);

        $rows = [];
        foreach ($files as $path) {
            if (str_ends_with($path, '.events.json')) {
                continue;
            }
            $job = $this->readJsonFile($path, []);
            if (!is_array($job) || empty($job['job_id'])) {
                continue;
            }
            $status = (string)($job['status'] ?? '');
            if ($statuses !== [] && !in_array($status, $statuses, true)) {
                continue;
            }
            $rows[] = $job;
        }

        usort($rows, static function (array $a, array $b): int {
            return ((int)($a['next_run_at'] ?? 0)) <=> ((int)($b['next_run_at'] ?? 0));
        });

        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    }

    /**
     * @return callable(): void
     */
    public function acquireJobLock(string $jobId): callable
    {
        $path = $this->dir('locks') . '/job-' . $this->slug($jobId) . '.lock';
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new DatabaseException('Unable to open job lock');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new DatabaseException('Job is already being processed');
        }

        return static function () use ($fh): void {
            flock($fh, LOCK_UN);
            fclose($fh);
        };
    }

    public function loadSyncManifest(string $pairKey): array
    {
        return $this->readJsonFile($this->manifestPath($pairKey), ['pair_key' => $pairKey, 'last_sync_epoch' => 0, 'mappings' => []]);
    }

    public function saveSyncManifest(string $pairKey, array $manifest): void
    {
        $manifest['pair_key'] = $pairKey;
        $manifest['updated_at'] = gmdate('c');
        $this->writeJsonFileAtomic($this->manifestPath($pairKey), $manifest);
    }

    public function incrementMetric(string $name, int $delta = 1): void
    {
        $path = $this->dir('metrics') . '/metrics.json';
        $state = $this->readJsonFile($path, ['counters' => []]);
        $current = (int)($state['counters'][$name] ?? 0);
        $state['counters'][$name] = $current + $delta;
        $state['updated_at'] = gmdate('c');
        $this->writeJsonFileAtomic($path, $state);
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return $this->readJsonFile($this->dir('metrics') . '/metrics.json', ['counters' => [], 'updated_at' => null]);
    }

    /** @param array<string, mixed> $meta */
    public function startSpan(string $name, array $meta = []): string
    {
        $path = $this->dir('traces') . '/spans.json';
        $state = $this->readJsonFile($path, ['items' => []]);
        $id = bin2hex(random_bytes(8));
        $state['items'][] = [
            'span_id' => $id,
            'name' => $name,
            'status' => 'running',
            'meta' => $this->sanitizeSensitive($meta),
            'started_at' => gmdate('c'),
            'started_at_epoch' => time(),
            'ended_at' => null,
            'ended_at_epoch' => null,
            'duration_ms' => null,
        ];
        if (count($state['items']) > self::DEFAULT_RETENTION) {
            $state['items'] = array_slice($state['items'], -self::DEFAULT_RETENTION);
        }
        $this->writeJsonFileAtomic($path, $state);
        return $id;
    }

    /** @param array<string, mixed> $meta */
    public function endSpan(string $spanId, string $status = 'ok', array $meta = []): void
    {
        $path = $this->dir('traces') . '/spans.json';
        $state = $this->readJsonFile($path, ['items' => []]);
        if (!is_array($state['items'] ?? null)) {
            return;
        }

        $now = time();
        foreach ($state['items'] as &$item) {
            if ((string)($item['span_id'] ?? '') !== $spanId) {
                continue;
            }
            $item['status'] = $status;
            $item['ended_at'] = gmdate('c');
            $item['ended_at_epoch'] = $now;
            $started = (int)($item['started_at_epoch'] ?? $now);
            $item['duration_ms'] = max(0, ($now - $started) * 1000);
            $item['result_meta'] = $this->sanitizeSensitive($meta);
            break;
        }
        unset($item);

        $this->writeJsonFileAtomic($path, $state);
    }

    /** @return array<int, array<string, mixed>> */
    public function listSpans(?string $name = null, int $limit = 200): array
    {
        $state = $this->readJsonFile($this->dir('traces') . '/spans.json', ['items' => []]);
        $items = is_array($state['items'] ?? null) ? $state['items'] : [];
        $filtered = [];
        foreach ($items as $item) {
            if ($name !== null && $name !== '' && (string)($item['name'] ?? '') !== $name) {
                continue;
            }
            $filtered[] = $item;
        }

        usort($filtered, static function (array $a, array $b): int {
            return ((int)($b['started_at_epoch'] ?? 0)) <=> ((int)($a['started_at_epoch'] ?? 0));
        });
        if ($limit > 0 && count($filtered) > $limit) {
            $filtered = array_slice($filtered, 0, $limit);
        }
        return $filtered;
    }

    /** @param array<string, mixed> $payload */
    public function sanitize(array $payload): array
    {
        return $this->sanitizeSensitive($payload);
    }

    /** @return array{allowed: bool, remaining: int, reset_at: int} */
    public function consumeRateLimit(string $bucket, int $maxPerWindow, int $windowSeconds): array
    {
        $path = $this->dir('rate_limits') . '/' . $this->slug($bucket) . '.json';
        $state = $this->readJsonFile($path, ['window_start' => 0, 'count' => 0]);

        $now = time();
        $windowStart = (int)($state['window_start'] ?? 0);
        $count = (int)($state['count'] ?? 0);
        if ($windowStart <= 0 || ($now - $windowStart) >= $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }

        $count++;
        $allowed = $count <= $maxPerWindow;
        $remaining = max(0, $maxPerWindow - $count);
        $resetAt = $windowStart + $windowSeconds;

        $this->writeJsonFileAtomic($path, [
            'window_start' => $windowStart,
            'count' => $count,
            'updated_at' => gmdate('c'),
        ]);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
        ];
    }

    private function resolveDefaultBaseDir(): string
    {
        $env = getenv('P202_SERVER_STATE_DIR');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return rtrim((string)sys_get_temp_dir(), '/') . '/p202-api-v3-state';
    }

    private function idempotencyPath(string $scope): string
    {
        return $this->dir('idempotency') . '/' . sha1($scope) . '.json';
    }

    private function changesPath(string $entity): string
    {
        return $this->dir('changes') . '/' . $this->slug($entity) . '.json';
    }

    private function jobPath(string $jobId): string
    {
        return $this->dir('jobs') . '/' . $this->slug($jobId) . '.json';
    }

    private function jobEventsPath(string $jobId): string
    {
        return $this->dir('jobs') . '/' . $this->slug($jobId) . '.events.json';
    }

    private function auditPath(): string
    {
        return $this->dir('audit') . '/sync_jobs.json';
    }

    private function manifestPath(string $pairKey): string
    {
        return $this->dir('manifests') . '/' . $this->slug($pairKey) . '.json';
    }

    private function dir(string $name): string
    {
        return $this->baseDir . '/' . $name;
    }

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new DatabaseException('Failed to create state directory');
        }
    }

    private function readJsonFile(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }

        return $decoded;
    }

    private function writeJsonFileAtomic(string $path, array $data): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new DatabaseException('Failed to encode state payload');
        }

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new DatabaseException('Failed to write state file');
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new DatabaseException('Failed to finalize state file');
        }
    }

    private function encodeCursor(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): array
    {
        $padded = strtr($cursor, '-_', '+/');
        $padLen = strlen($padded) % 4;
        if ($padLen !== 0) {
            $padded .= str_repeat('=', 4 - $padLen);
        }
        $raw = base64_decode($padded, true);
        if ($raw === false) {
            throw new DatabaseException('Invalid cursor');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new DatabaseException('Invalid cursor');
        }
        return $decoded;
    }

    private function sanitizeSensitive(array $payload): array
    {
        $copy = $payload;
        array_walk_recursive($copy, static function (&$value, $key): void {
            $k = strtolower((string)$key);
            if (str_contains($k, 'api_key') || str_contains($k, 'token') || str_contains($k, 'authorization')) {
                $value = '***REDACTED***';
            }
        });
        return $copy;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /** @param array<string, mixed> $payload */
    private static function sortPayloadRecursive(array &$payload): void
    {
        foreach ($payload as &$value) {
            if (is_array($value)) {
                self::sortPayloadRecursive($value);
            }
        }
        unset($value);

        if (array_keys($payload) !== range(0, count($payload) - 1)) {
            ksort($payload, SORT_STRING);
        }
    }
}

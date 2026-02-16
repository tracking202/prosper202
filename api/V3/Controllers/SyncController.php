<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;

class SyncController
{
    private \mysqli $db;
    private int $userId;
    private ServerStateStore $store;
    private SyncEngine $engine;

    public function __construct(\mysqli $db, int $userId, ?ServerStateStore $store = null, ?SyncEngine $engine = null)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->store = $store ?? new ServerStateStore();
        $this->engine = $engine ?? new SyncEngine($this->store);
    }

    public function plan(array $payload): array
    {
        [$source, $target] = $this->resolveProfiles($payload);
        $entity = trim((string)($payload['entity'] ?? 'all'));
        $collisionMode = strtolower(trim((string)($payload['collision_mode'] ?? 'warn')));
        if (!in_array($collisionMode, ['warn', 'manual'], true)) {
            throw new ValidationException('Validation failed', ['collision_mode' => 'Valid values: warn, manual']);
        }

        $options = [
            'prune_preview' => (bool)($payload['prune_preview'] ?? false),
            'prune' => (bool)($payload['prune'] ?? false),
            'fail_on_collision' => $collisionMode === 'manual',
            'collision_mode' => $collisionMode,
        ];

        $result = $this->engine->buildPlan($source, $target, $entity, $options);

        return ['data' => $result];
    }

    public function createJob(array $payload): array
    {
        [$source, $target] = $this->resolveProfiles($payload);
        $entity = trim((string)($payload['entity'] ?? 'all'));
        $this->enforceQueueLimit($source, $target);

        $options = $this->resolveSyncOptions($payload);
        $this->validatePruneToken($source, $target, $options);
        $idempotencyKey = (string)(\Api\V3\RequestContext::header('idempotency-key') ?? '');

        $jobPayload = [
            'entity' => $entity,
            'source' => $source,
            'target' => $target,
            'options' => $options,
            'idempotency_key' => $idempotencyKey,
        ];
        $requestHash = ServerStateStore::canonicalHash($jobPayload);
        $idempotencyScope = 'sync-job:' . sha1(strtolower((string)$source['url']) . '|' . strtolower((string)$target['url']) . '|' . $entity) . ':request:' . $requestHash;
        if ($idempotencyKey !== '') {
            $cached = $this->store->getIdempotent($idempotencyScope, $idempotencyKey);
            if (is_array($cached)) {
                $cached['idempotent_replay'] = true;
                return ['_status' => 202] + $cached;
            }
        }

        $jobPayload['request_hash'] = $requestHash;
        $job = $this->store->createJob($jobPayload, $this->userId);
        $job['attempts'] = 0;
        $job['max_attempts'] = max(1, min(10, (int)($payload['max_attempts'] ?? 3)));
        $job['next_run_at'] = time();
        $job['status'] = 'queued';
        $this->store->saveJob($job);
        $this->store->incrementMetric('jobs_started', 1);

        $jobId = (string)$job['job_id'];
        $this->store->appendJobEvent($jobId, 'info', 'Job queued', ['entity' => $entity]);

        $response = ['data' => $this->sanitizeJobForResponse($job)];
        if ($idempotencyKey !== '') {
            $this->store->putIdempotent($idempotencyScope, $idempotencyKey, $response);
        }
        return ['_status' => 202] + $response;
    }

    public function getJob(string $jobId): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }

        return ['data' => $this->sanitizeJobForResponse($job)];
    }

    public function cancelJob(string $jobId): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }
        if (in_array((string)($job['status'] ?? ''), ['succeeded', 'failed', 'cancelled', 'partial'], true)) {
            return ['data' => $this->sanitizeJobForResponse($job)];
        }

        $job['cancel_requested'] = true;
        if ((string)($job['status'] ?? '') === 'queued') {
            $job['status'] = 'cancelled';
            $job['next_run_at'] = null;
            $this->store->incrementMetric('jobs_cancelled', 1);
            $this->appendAuditForJob($job);
        }
        $this->store->saveJob($job);
        $this->store->appendJobEvent($jobId, 'warning', 'Job cancelled by request');

        return ['data' => $this->sanitizeJobForResponse($job)];
    }

    public function events(string $jobId, array $params): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }

        $limit = max(1, min(500, (int)($params['limit'] ?? 100)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        return $this->store->listJobEvents($jobId, $offset, $limit);
    }

    public function reSync(array $payload): array
    {
        $payload['incremental'] = true;
        return $this->createJob($payload);
    }

    public function runWorker(array $payload): array
    {
        $limit = max(1, min(100, (int)($payload['limit'] ?? 10)));
        $jobs = $this->store->listJobs(['queued'], $limit);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $requeued = 0;
        $partial = 0;

        foreach ($jobs as $job) {
            if ((int)($job['next_run_at'] ?? 0) > time()) {
                continue;
            }
            $processed++;
            $updated = $this->runJobInternal((string)$job['job_id']);
            $status = (string)($updated['status'] ?? '');
            if ($status === 'succeeded') {
                $succeeded++;
            } elseif ($status === 'partial') {
                $partial++;
            } elseif ($status === 'queued') {
                $requeued++;
            } elseif ($status === 'failed' || $status === 'cancelled') {
                $failed++;
            }
        }

        return [
            'data' => [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'partial' => $partial,
                'failed' => $failed,
                'requeued' => $requeued,
            ],
        ];
    }

    public function runJob(string $jobId): array
    {
        $job = $this->runJobInternal($jobId);
        return ['data' => $this->sanitizeJobForResponse($job)];
    }

    public function status(array $params): array
    {
        [$source, $target] = $this->resolveProfiles($params, false);
        $filters = [
            'source' => $this->profileLabel($source),
            'target' => $this->profileLabel($target),
        ];
        $records = $this->store->listAudit($filters);

        return [
            'data' => [
                'source' => $filters['source'],
                'target' => $filters['target'],
                'latest' => $records[0] ?? null,
            ],
        ];
    }

    public function history(array $params): array
    {
        [$source, $target] = $this->resolveProfiles($params, false);
        $records = $this->store->listAudit([
            'source' => $this->profileLabel($source),
            'target' => $this->profileLabel($target),
        ]);

        return ['data' => $records];
    }

    public function listChanges(string $entity, array $params): array
    {
        if (!in_array($entity, SyncEngine::supportedEntities(), true)) {
            throw new ValidationException('Unsupported entity', ['entity' => 'Valid values: ' . implode(', ', SyncEngine::supportedEntities())]);
        }

        $cursor = isset($params['cursor']) ? (string)$params['cursor'] : null;
        $limit = max(1, min(1000, (int)($params['limit'] ?? 200)));
        $cursorTtl = max(60, min(86400, (int)($params['cursor_ttl'] ?? 3600)));
        $updatedSince = isset($params['updated_since']) ? (int)$params['updated_since'] : null;
        $deletedSince = isset($params['deleted_since']) ? (int)$params['deleted_since'] : null;

        return $this->store->listChanges($entity, $cursor, $limit, $cursorTtl, $updatedSince, $deletedSince);
    }

    public function auditList(array $params): array
    {
        $filters = [
            'actor' => $params['actor'] ?? '',
            'source' => $params['source'] ?? '',
            'target' => $params['target'] ?? '',
            'status' => $params['status'] ?? '',
            'from_epoch' => isset($params['from_epoch']) ? (int)$params['from_epoch'] : null,
            'to_epoch' => isset($params['to_epoch']) ? (int)$params['to_epoch'] : null,
        ];

        $records = $this->store->listAudit($filters);
        $format = strtolower(trim((string)($params['format'] ?? 'json')));

        $response = ['data' => $records];
        if ($format === 'csv') {
            $response['csv'] = $this->toCsv($records);
        }

        return $response;
    }

    public function auditGet(string $jobId, array $params): array
    {
        $record = $this->store->getAudit($jobId);
        if ($record === null) {
            throw new NotFoundException('Audit record not found');
        }

        $format = strtolower(trim((string)($params['format'] ?? 'json')));
        if ($format === 'csv') {
            return ['data' => $record, 'csv' => $this->toCsv([$record])];
        }

        return ['data' => $record];
    }

    private function runJobInternal(string $jobId): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }
        $status = (string)($job['status'] ?? '');
        if (in_array($status, ['succeeded', 'failed', 'cancelled', 'partial'], true)) {
            return $job;
        }
        if (!in_array($status, ['queued', 'running'], true)) {
            return $job;
        }
        if ((int)($job['next_run_at'] ?? 0) > time()) {
            return $job;
        }

        $releaseLock = null;
        try {
            $releaseLock = $this->store->acquireJobLock($jobId);
        } catch (\Throwable) {
            return $job;
        }

        try {
            $job = $this->store->getJob($jobId) ?? $job;
            if ((bool)($job['cancel_requested'] ?? false)) {
                $job['status'] = 'cancelled';
                $this->store->saveJob($job);
                $this->store->appendJobEvent($jobId, 'warning', 'Job cancelled before execution');
                $this->store->incrementMetric('jobs_cancelled', 1);
                $this->appendAuditForJob($job);
                return $job;
            }

            $request = is_array($job['request'] ?? null) ? $job['request'] : [];
            $source = is_array($request['source'] ?? null) ? $request['source'] : [];
            $target = is_array($request['target'] ?? null) ? $request['target'] : [];
            $entity = trim((string)($request['entity'] ?? 'all'));
            $options = is_array($request['options'] ?? null) ? $request['options'] : [];

            $pairKey = sha1(strtolower((string)($source['url'] ?? '')) . '|' . strtolower((string)($target['url'] ?? '')));
            $manifest = $this->store->loadSyncManifest($pairKey);
            if (!empty($options['incremental']) && empty($options['updated_since']) && !empty($manifest['last_sync_epoch'])) {
                $options['updated_since'] = (string)((int)$manifest['last_sync_epoch']);
            }
            $options['manifest'] = $manifest;

            $pairLock = $this->store->acquirePairLock((string)($source['url'] ?? ''), (string)($target['url'] ?? ''));
            try {
                $job['status'] = 'running';
                $job['attempts'] = (int)($job['attempts'] ?? 0) + 1;
                $this->store->saveJob($job);
                $this->store->appendJobEvent($jobId, 'info', 'Job started', ['attempt' => $job['attempts']]);

                $results = $this->engine->execute(
                    $source,
                    $target,
                    $entity,
                    $options,
                    function (string $level, string $message, array $data = []) use ($jobId): void {
                        $this->store->appendJobEvent($jobId, $level, $message, $data);
                    }
                );

                $summary = $this->summarizeJobResults($results);
                $job['results'] = $results;
                $job['error'] = null;
                $job['next_run_at'] = null;
                if ((bool)($job['cancel_requested'] ?? false)) {
                    $job['status'] = 'cancelled';
                    $this->store->incrementMetric('jobs_cancelled', 1);
                } elseif (($summary['failed'] ?? 0) > 0 && (($summary['synced'] ?? 0) > 0 || ($summary['skipped'] ?? 0) > 0)) {
                    $job['status'] = 'partial';
                    $this->store->incrementMetric('jobs_partial', 1);
                } elseif (($summary['failed'] ?? 0) > 0) {
                    $job['status'] = 'failed';
                    $this->store->incrementMetric('jobs_failed', 1);
                } else {
                    $job['status'] = 'succeeded';
                    $this->store->incrementMetric('jobs_succeeded', 1);
                }

                $manifest['last_sync_epoch'] = time();
                if (is_array($results['mappings'] ?? null)) {
                    $manifest['mappings'] = $results['mappings'];
                }
                if (is_array($results['source_hashes'] ?? null)) {
                    $manifest['source_hashes'] = $results['source_hashes'];
                }
                $this->store->saveSyncManifest($pairKey, $manifest);

                $this->store->saveJob($job);
                $this->store->appendJobEvent($jobId, 'info', 'Job completed', ['status' => $job['status']]);
                $this->appendAuditForJob($job);
            } finally {
                $pairLock();
            }
        } catch (\Throwable $e) {
            $job = $this->store->getJob($jobId) ?? $job;
            $attempts = (int)($job['attempts'] ?? 1);
            $maxAttempts = max(1, (int)($job['max_attempts'] ?? 3));
            $job['error'] = $e->getMessage();
            $job['results'] = null;

            if ((bool)($job['cancel_requested'] ?? false)) {
                $job['status'] = 'cancelled';
                $this->store->incrementMetric('jobs_cancelled', 1);
            } elseif ($attempts < $maxAttempts) {
                $job['status'] = 'queued';
                $backoff = min(900, 15 * (2 ** max(0, $attempts - 1)));
                $job['next_run_at'] = time() + $backoff;
                $this->store->incrementMetric('jobs_retried', 1);
            } else {
                $job['status'] = 'failed';
                $job['next_run_at'] = null;
                $this->store->incrementMetric('jobs_failed', 1);
                $this->appendAuditForJob($job);
            }

            $this->store->saveJob($job);
            $this->store->appendJobEvent($jobId, 'error', 'Job execution error', ['error' => $e->getMessage()]);
        } finally {
            if (is_callable($releaseLock)) {
                $releaseLock();
            }
        }

        $saved = $this->store->getJob($jobId);
        if ($saved === null) {
            throw new DatabaseException('Job persistence failed');
        }
        return $saved;
    }

    private function appendAuditForJob(array $job): void
    {
        $request = is_array($job['request'] ?? null) ? $job['request'] : [];
        $source = is_array($request['source'] ?? null) ? $request['source'] : [];
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        $auditRecord = [
            'job_id' => (string)($job['job_id'] ?? ''),
            'created_at' => $job['created_at'] ?? gmdate('c'),
            'created_at_epoch' => (int)($job['created_at_epoch'] ?? time()),
            'actor_user_id' => (int)($job['actor_user_id'] ?? $this->userId),
            'source' => $this->profileLabel($source),
            'target' => $this->profileLabel($target),
            'status' => (string)($job['status'] ?? 'unknown'),
            'entity' => (string)($request['entity'] ?? 'all'),
            'options' => [
                'dry_run' => $options['dry_run'] ?? false,
                'force_update' => $options['force_update'] ?? false,
                'skip_errors' => $options['skip_errors'] ?? false,
                'incremental' => $options['incremental'] ?? false,
                'prune' => $options['prune'] ?? false,
                'prune_preview' => $options['prune_preview'] ?? false,
            ],
            'result_summary' => $this->summarizeJobResults($job['results'] ?? []),
        ];
        $this->store->appendAudit($auditRecord);
    }

    private function resolveSyncOptions(array $payload): array
    {
        return [
            'dry_run' => (bool)($payload['dry_run'] ?? false),
            'skip_errors' => (bool)($payload['skip_errors'] ?? false),
            'force_update' => (bool)($payload['force_update'] ?? false),
            'incremental' => (bool)($payload['incremental'] ?? false),
            'prune' => (bool)($payload['prune'] ?? false),
            'prune_preview' => (bool)($payload['prune_preview'] ?? false),
            'confirmation_token' => (string)($payload['confirmation_token'] ?? ''),
            'prune_allowlist' => is_array($payload['prune_allowlist'] ?? null) ? $payload['prune_allowlist'] : [],
            'prune_denylist' => is_array($payload['prune_denylist'] ?? null) ? $payload['prune_denylist'] : [],
            'updated_since' => isset($payload['updated_since']) ? (string)$payload['updated_since'] : '',
        ];
    }

    private function validatePruneToken(array $source, array $target, array $options): void
    {
        if (empty($options['prune']) || !empty($options['prune_preview'])) {
            return;
        }

        $token = trim((string)($options['confirmation_token'] ?? ''));
        if ($token === '') {
            throw new ValidationException('Prune confirmation token required', ['confirmation_token' => 'Required when prune=true']);
        }

        $pairKey = sha1(strtolower((string)$source['url']) . '|' . strtolower((string)$target['url']));
        if (!$this->store->validatePruneToken($token, $pairKey)) {
            throw new ValidationException('Invalid prune confirmation token', ['confirmation_token' => 'Token is invalid or expired']);
        }
    }

    private function resolveProfiles(array $payload, bool $requireApiKey = true): array
    {
        $source = $this->resolveProfileEntry($payload['source'] ?? $payload['from'] ?? null, 'source', $requireApiKey);
        $target = $this->resolveProfileEntry($payload['target'] ?? $payload['to'] ?? null, 'target', $requireApiKey);

        return [$source, $target];
    }

    private function resolveProfileEntry(mixed $entry, string $label, bool $requireApiKey): array
    {
        if (!is_array($entry)) {
            throw new ValidationException('Validation failed', [$label => 'Must be an object with url/api_key']);
        }

        $url = trim((string)($entry['url'] ?? ''));
        $apiKey = trim((string)($entry['api_key'] ?? ''));
        $name = trim((string)($entry['name'] ?? $url));

        if ($url === '') {
            throw new ValidationException('Validation failed', [$label . '.url' => 'Required']);
        }
        if ($requireApiKey && $apiKey === '') {
            throw new ValidationException('Validation failed', [$label . '.api_key' => 'Required']);
        }

        return [
            'name' => $name,
            'url' => $url,
            'api_key' => $apiKey,
        ];
    }

    private function profileLabel(array $profile): string
    {
        $name = trim((string)($profile['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return (string)($profile['url'] ?? 'unknown');
    }

    private function summarizeJobResults(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }
        $perEntity = is_array($results['results'] ?? null) ? $results['results'] : [];

        $summary = [
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'pruned' => 0,
        ];

        foreach ($perEntity as $entityResult) {
            if (!is_array($entityResult)) {
                continue;
            }
            $summary['synced'] += (int)($entityResult['synced'] ?? 0);
            $summary['skipped'] += (int)($entityResult['skipped'] ?? 0);
            $summary['failed'] += (int)($entityResult['failed'] ?? 0);
            $summary['pruned'] += (int)($entityResult['pruned'] ?? 0);
        }

        return $summary;
    }

    private function sanitizeJobForResponse(array $job): array
    {
        $copy = $job;
        if (isset($copy['request']) && is_array($copy['request'])) {
            $copy['request'] = $this->store->sanitize($copy['request']);
        }
        if (isset($copy['results']) && is_array($copy['results'])) {
            $copy['results'] = $this->store->sanitize($copy['results']);
        }
        return $copy;
    }

    /** @param array<int, array<string, mixed>> $records */
    private function toCsv(array $records): string
    {
        if (empty($records)) {
            return '';
        }

        $headers = [];
        foreach ($records as $row) {
            foreach (array_keys($row) as $key) {
                $headers[$key] = true;
            }
        }
        $columns = array_keys($headers);
        sort($columns, SORT_STRING);

        $lines = [implode(',', $columns)];
        foreach ($records as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $cell = (string)$value;
                $cell = '"' . str_replace('"', '""', $cell) . '"';
                $cells[] = $cell;
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines);
    }

    private function enforceQueueLimit(array $source, array $target): void
    {
        $raw = getenv('P202_MAX_QUEUED_PER_PAIR');
        $maxQueuedPerPair = 20;
        if (is_string($raw) && trim($raw) !== '' && (int)$raw > 0) {
            $maxQueuedPerPair = (int)$raw;
        }

        $sourceUrl = strtolower(trim((string)($source['url'] ?? '')));
        $targetUrl = strtolower(trim((string)($target['url'] ?? '')));
        $activeJobs = $this->store->listJobs(['queued', 'running'], 5000);
        $samePairCount = 0;
        foreach ($activeJobs as $job) {
            $request = is_array($job['request'] ?? null) ? $job['request'] : [];
            $jobSource = is_array($request['source'] ?? null) ? $request['source'] : [];
            $jobTarget = is_array($request['target'] ?? null) ? $request['target'] : [];
            if (
                strtolower(trim((string)($jobSource['url'] ?? ''))) === $sourceUrl
                && strtolower(trim((string)($jobTarget['url'] ?? ''))) === $targetUrl
            ) {
                $samePairCount++;
            }
        }

        if ($samePairCount >= $maxQueuedPerPair) {
            throw new ValidationException(
                'Too many queued jobs for this source/target pair',
                ['queue' => "Limit {$maxQueuedPerPair} reached for pair; wait for current jobs to complete"]
            );
        }
    }
}

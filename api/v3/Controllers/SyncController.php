<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\ErrorCode;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\SyncException;
use Api\V3\Exception\SyncRecordException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\PageRows;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\RemoteApiClient;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use Api\V3\Support\SyncFailure;
use Api\V3\Support\SyncJobState;
use Api\V3\Support\SyncProfileKeys;
use Api\V3\Support\QueryInt;
use Api\V3\Support\RequestFlag;

class SyncController
{
    private readonly SyncEngine $engine;
    private readonly ServerStateStore $store;

    /**
     * How long a job may read 'running' with no process holding its lock
     * before it is taken as abandoned and failed. A live run holds the lock
     * from before 'running' is saved until after its end is, and cannot
     * outlast the sync's own time limit; this is that limit and five minutes
     * for the work between remote calls. The lock alone would say sooner on
     * one host, but a run on another host sharing the state directory holds a
     * lock this host's flock() may not see, and the time cannot be wrong there.
     */
    public const ABANDONED_AFTER = RemoteApiClient::MAX_SECONDS + 300;

    /** The counter each end of a job adds to, by the state it ended in. */
    private const END_METRICS = [
        SyncJobState::Succeeded->value => 'jobs_succeeded',
        SyncJobState::Partial->value => 'jobs_partial',
        SyncJobState::Failed->value => 'jobs_failed',
        SyncJobState::Cancelled->value => 'jobs_cancelled',
    ];

    /** The fatal errors PHP ends a run with before any catch can see them. */
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    public function __construct(
        private readonly \mysqli $db,
        private readonly int $userId,
        ?ServerStateStore $store = null,
        ?SyncEngine $engine = null,
        private ?SyncProfileKeys $keys = null,
    ) {
        if ($db->connect_errno !== 0) {
            throw new DatabaseException('Database connection unavailable for sync controller');
        }
        $this->store = $store ?? ServerStateStore::forInstall($db);
        $this->engine = $engine ?? new SyncEngine($this->store);
    }

    private function keys(): SyncProfileKeys
    {
        return $this->keys ??= SyncProfileKeys::fromDatabase($this->db);
    }

    /** What a sync job body may carry; resolveSyncOptions() and resolveProfiles() read them. */
    private const JOB_FIELDS = [
        'source', 'from', 'target', 'to', 'entity', 'dry_run', 'skip_errors', 'force_update', 'incremental',
        'prune', 'prune_preview', 'confirmation_token', 'prune_allowlist', 'prune_denylist', 'max_attempts',
    ];

    /**
     * A field a job body used to carry, refused saying why. `updated_since`
     * was handed to the source's lists, none of which can filter by update
     * time, so a job given one failed on its first run.
     */
    private const NOT_JOB_FIELDS = [
        'updated_since' => SyncEngine::UPDATED_SINCE_REFUSAL,
    ];

    public function plan(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['source', 'from', 'target', 'to', 'entity', 'collision_mode', 'prune_preview', 'prune'], 'a sync plan');
        [$source, $target] = $this->resolveProfiles($payload);
        $entity = trim((string)($payload['entity'] ?? 'all'));
        $collisionMode = strtolower(trim((string)($payload['collision_mode'] ?? 'warn')));
        if (!in_array($collisionMode, ['warn', 'manual'], true)) {
            throw new ValidationException('Validation failed', ['collision_mode' => 'Valid values: warn, manual']);
        }

        $options = [
            'prune_preview' => RequestFlag::param($payload, 'prune_preview', false),
            'prune' => RequestFlag::param($payload, 'prune', false),
            'fail_on_collision' => $collisionMode === 'manual',
            'collision_mode' => $collisionMode,
        ];

        $result = $this->engine->buildPlan($source, $target, $entity, $options);

        return ['data' => $result];
    }

    public function createJob(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::JOB_FIELDS, 'a sync job', self::NOT_JOB_FIELDS);
        [$source, $target] = $this->resolveProfiles($payload);
        $entity = trim((string)($payload['entity'] ?? 'all'));
        $this->enforceQueueLimit($source, $target);

        $options = $this->resolveSyncOptions($payload);
        $this->validatePruneToken($source, $target, $options);
        $idempotencyKey = (string)(\Api\V3\RequestContext::header('idempotency-key') ?? '');
        // Read before anything is stored, so a refused value queues nothing,
        // and fingerprinted with the rest, so a key reused with another
        // max_attempts is a different request, not a replay.
        $maxAttempts = QueryInt::param($payload, 'max_attempts', 3, 1, 10, 'tries before the job fails');

        $jobPayload = [
            'entity' => $entity,
            'source' => $source,
            'target' => $target,
            'options' => $options,
            'max_attempts' => $maxAttempts,
            'idempotency_key' => $idempotencyKey,
        ];
        // The request hash is a fingerprint recorded beside the response,
        // not part of the scope. Scoping by the request meant a key reused
        // with a different source, target, entity or option set addressed a
        // different record, found nothing, and queued a second job — the
        // duplicate the key was sent to prevent. Scope is the caller alone,
        // so any reuse of the key is seen and a changed request is refused.
        $requestHash = ServerStateStore::idempotencyFingerprint('sync-job', $jobPayload);
        $idempotencyScope = ServerStateStore::idempotencyScopeForUser($this->userId);
        if ($idempotencyKey !== '') {
            $recorded = $this->claimIdempotencyKey($idempotencyScope, $idempotencyKey, $requestHash);
            if ($recorded !== null) {
                $recorded['idempotent_replay'] = true;
                return ['_status' => 202] + $recorded;
            }
        }

        $jobPayload['request_hash'] = $requestHash;
        try {
            // The keys are sealed in the job file and dropped when it ends
            // (SyncProfileKeys); the fingerprint above is of the request as sent.
            $jobPayload['source'] = $this->keys()->seal($source, 'source');
            $jobPayload['target'] = $this->keys()->seal($target, 'target');
            $job = $this->store->createJob($jobPayload, $this->userId, [
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'next_run_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // Nothing was queued: the key is free for the retry.
            if ($idempotencyKey !== '') {
                $this->store->releaseIdempotent($idempotencyScope, $idempotencyKey);
            }
            throw $e;
        }

        $response = ['data' => $this->sanitizeJobForResponse($job)];
        if ($idempotencyKey !== '') {
            try {
                $this->store->putIdempotent($idempotencyScope, $idempotencyKey, $response, $requestHash);
            } catch (\Throwable $e) {
                // The job is queued (CLAUDE.md #13): the key is spent, never
                // freed for a retry that would queue a second one.
                $this->store->markIdempotentIndeterminate($idempotencyScope, $idempotencyKey);
                throw $e;
            }
        }
        $this->store->incrementMetric('jobs_started', 1);
        $this->store->appendJobEvent((string)$job['job_id'], 'info', 'Job queued', ['entity' => $entity]);

        return ['_status' => 202] + $response;
    }

    /**
     * Take the Idempotency-Key for this request in one step with reading it,
     * so two requests under one key cannot both find it unused. The recorded
     * answer when the key's request already queued its job; null when the key
     * is now this request's, to record its answer under or to release.
     *
     * @return array<string, mixed>|null
     */
    private function claimIdempotencyKey(string $scope, string $key, string $requestHash): ?array
    {
        $claim = $this->store->reserveIdempotent($scope, $key, $requestHash);
        if ($claim['state'] === 'mismatch') {
            throw (new ValidationException(
                'This Idempotency-Key was already used for a different request. Resend the original '
                . 'sync request to replay its recorded response, or send a new Idempotency-Key to '
                . 'queue a different job.',
                ['idempotency_key' => 'Already used for a different request']
            ))->coded(ErrorCode::IDEMPOTENCY_KEY_REUSED);
        }
        if ($claim['state'] === 'replay') {
            return $claim['response'] ?? [];
        }
        if ($claim['state'] === 'in_flight') {
            throw (new ConflictException(
                'A request with this Idempotency-Key is still in flight; retry once it completes '
                . 'to receive the job it queued.'
            ))->coded(ErrorCode::IDEMPOTENCY_IN_FLIGHT);
        }
        if ($claim['state'] === 'indeterminate') {
            throw (new ConflictException(
                'A previous request with this Idempotency-Key did not finish, so whether it queued a '
                . 'job is unknown and this key can be neither replayed nor reused. Look at the pair\'s '
                . 'sync history (GET /sync/history) and the queue (GET /system/metrics), then queue the '
                . 'job under a new Idempotency-Key if it is not there.'
            ))->coded(ErrorCode::IDEMPOTENCY_INDETERMINATE);
        }

        return null;
    }

    public function getJob(string $jobId): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }
        $job = $this->failIfAbandoned($job);

        return ['data' => $this->sanitizeJobForResponse($job)];
    }

    public function cancelJob(string $jobId): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }
        $job = $this->failIfAbandoned($job);
        if (SyncJobState::ofRecord($job)?->isTerminal() === true) {
            return ['data' => $this->sanitizeJobForResponse($job)];
        }

        // Decided on the job as stored, not on the copy read above: a run
        // may have started or ended it since. A queued job ends here; a
        // running one is asked to stop, and its run ends it.
        $asked = $this->changed($jobId, static function (array $stored, SyncJobState $state): ?array {
            if ($state->isTerminal()) {
                return null;
            }
            if ($state === SyncJobState::Queued) {
                return ['status' => SyncJobState::Cancelled, 'cancel_requested' => true, 'next_run_at' => null]
                    + self::keysDropped($stored);
            }

            return ['cancel_requested' => true];
        });
        if ($asked['changed']) {
            if ($asked['from'] === SyncJobState::Queued) {
                $this->store->incrementMetric('jobs_cancelled', 1);
                $this->appendAuditForJob($asked['job']);
            }
            $this->store->appendJobEvent($jobId, 'warning', 'Job cancelled by request');
        }

        return ['data' => $this->sanitizeJobForResponse($asked['job'])];
    }

    /**
     * Change the job through the store, which reads it again under its lock
     * and makes only a move SyncJobState holds.
     *
     * @param callable(array<string, mixed>, SyncJobState): ?array<string, mixed> $change
     * @return array{job: array<string, mixed>, from: SyncJobState, changed: bool}
     */
    private function changed(string $jobId, callable $change): array
    {
        $result = $this->store->changeJob($jobId, $change);
        if ($result === null) {
            throw new NotFoundException('Sync job not found');
        }

        return $result;
    }

    /**
     * The fields that take both profiles' keys out of a job's record.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function keysDropped(array $job): array
    {
        return is_array($job['request'] ?? null) ? ['request' => self::withoutKeys($job)['request']] : [];
    }

    public function events(string $jobId, array $params): array
    {
        $job = $this->store->getJob($jobId);
        if ($job === null) {
            throw new NotFoundException('Sync job not found');
        }

        $limit = QueryInt::param($params, 'limit', 100, 1, 500, 'events per page');
        $offset = QueryInt::param($params, 'offset', 0, 0, PHP_INT_MAX, 'events to skip');

        $page = $this->store->listJobEvents($jobId, $offset, PageRows::fetchLimit($limit));
        [$events, $truncated] = PageRows::cut($page['data'], $limit);

        return [
            'data' => $events,
            'truncated' => $truncated,
            'pagination' => array_replace($page['pagination'], ['limit' => $limit]),
        ];
    }

    public function reSync(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, self::JOB_FIELDS, 'a re-sync job', self::NOT_JOB_FIELDS);
        $payload['incremental'] = true;
        return $this->createJob($payload);
    }

    public function runWorker(array $payload): array
    {
        \Api\V3\Support\PayloadKeys::refuseUnknown($payload, ['limit'], 'a worker run');
        $limit = QueryInt::param($payload, 'limit', 10, 1, 100, 'queued jobs to run');
        // A run whose process died left its job 'running', and this picks up
        // only queued ones: without this it read 'running' for ever.
        $all = array_map(fn (array $job): array => $this->failIfAbandoned($job), $this->store->listJobs([], 0));
        // A job that ended before 1.9.79 still holds both keys as sent; a
        // finished job needs none.
        foreach ($all as $job) {
            if (SyncJobState::ofRecord($job)?->isTerminal() === true && self::holdsKey($job)) {
                $this->store->changeJob(
                    (string)$job['job_id'],
                    static fn (array $stored, SyncJobState $state): ?array => $state->isTerminal() && self::holdsKey($stored)
                        ? self::keysDropped($stored)
                        : null
                );
            }
        }
        $jobs = array_slice(array_values(array_filter(
            $all,
            static fn (array $job): bool => SyncJobState::ofRecord($job) === SyncJobState::Queued
        )), 0, $limit);

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
            match (SyncJobState::ofRecord($updated)) {
                SyncJobState::Succeeded => $succeeded++,
                SyncJobState::Partial => $partial++,
                SyncJobState::Queued => $requeued++,
                SyncJobState::Failed, SyncJobState::Cancelled => $failed++,
                // Another run has it, or its record cannot be read: not this pass's to count.
                SyncJobState::Running, null => null,
            };
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
        $limit = QueryInt::param($params, 'limit', 200, 1, 1000, 'changes per page');
        $cursorTtl = QueryInt::param($params, 'cursor_ttl', 3600, 60, 86400, 'seconds the next_cursor stays valid');
        $updatedSince = QueryInt::param($params, 'updated_since', -1, 0, PHP_INT_MAX, 'a unix time');
        $deletedSince = QueryInt::param($params, 'deleted_since', -1, 0, PHP_INT_MAX, 'a unix time');
        $updatedSince = $updatedSince >= 0 ? $updatedSince : null;
        $deletedSince = $deletedSince >= 0 ? $deletedSince : null;

        return $this->store->listChanges($entity, $cursor, $limit, $cursorTtl, $updatedSince, $deletedSince);
    }

    public function auditList(array $params): array
    {
        $format = self::auditFormat($params);
        $status = $params['status'] ?? '';
        // An audit record is written when a job ends, with the state it ended in.
        $ends = array_map(static fn (SyncJobState $state): string => $state->value, SyncJobState::finished());
        if ($status !== '' && (!is_string($status) || !in_array($status, $ends, true))) {
            // A filter nothing can match answered an empty list that read
            // as "no such jobs" (status=sucess, status=success).
            throw new ValidationException(
                'Invalid status',
                ['status' => 'Valid values: ' . implode(', ', $ends)]
            );
        }
        $filters = [
            'actor' => $params['actor'] ?? '',
            'source' => $params['source'] ?? '',
            'target' => $params['target'] ?? '',
            'status' => $params['status'] ?? '',
            'from_epoch' => self::epochOrNull($params, 'from_epoch'),
            'to_epoch' => self::epochOrNull($params, 'to_epoch'),
        ];

        $records = $this->store->listAudit($filters);

        $response = ['data' => $records];
        if ($format === 'csv') {
            $response['csv'] = $this->toCsv($records);
        }

        return $response;
    }

    public function auditGet(string $jobId, array $params): array
    {
        $format = self::auditFormat($params);
        $record = $this->store->getAudit($jobId);
        if ($record === null) {
            throw new NotFoundException('Audit record not found');
        }

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
        $job = $this->failIfAbandoned($job);
        // Only a queued job is started. A finished one runs no more, and one
        // that reads running has its run, here or on a host whose lock this
        // one cannot see, until failIfAbandoned() finds that run gone.
        if (SyncJobState::ofRecord($job) !== SyncJobState::Queued) {
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

        $startedHere = false;
        try {
            $job = $this->store->getJob($jobId) ?? $job;
            if ((bool)($job['cancel_requested'] ?? false)) {
                $cancelled = $this->changed($jobId, static function (array $stored, SyncJobState $state): ?array {
                    if ($state !== SyncJobState::Queued || !(bool)($stored['cancel_requested'] ?? false)) {
                        return null;
                    }

                    return ['status' => SyncJobState::Cancelled] + self::keysDropped($stored);
                });
                if ($cancelled['changed']) {
                    $this->store->appendJobEvent($jobId, 'warning', 'Job cancelled before execution');
                    $this->store->incrementMetric('jobs_cancelled', 1);
                    $this->appendAuditForJob($cancelled['job']);
                }

                return $cancelled['job'];
            }
            // Ended or started between the read above and the lock.
            if (SyncJobState::ofRecord($job) !== SyncJobState::Queued) {
                return $job;
            }

            $request = is_array($job['request'] ?? null) ? $job['request'] : [];
            $source = is_array($request['source'] ?? null) ? $request['source'] : [];
            $target = is_array($request['target'] ?? null) ? $request['target'] : [];
            $entity = trim((string)($request['entity'] ?? 'all'));
            $options = is_array($request['options'] ?? null) ? $request['options'] : [];
            // A job queued before 1.9.79 holds its keys as sent: sealed now,
            // so nothing this run saves holds them.
            if (SyncProfileKeys::holdsPlainKey($source) || SyncProfileKeys::holdsPlainKey($target)) {
                $source = $this->keys()->seal($source, 'source');
                $target = $this->keys()->seal($target, 'target');
                $this->changed($jobId, static function (array $stored, SyncJobState $state) use ($source, $target): ?array {
                    if ($state !== SyncJobState::Queued || !is_array($stored['request'] ?? null)) {
                        return null;
                    }

                    return ['request' => array_replace($stored['request'], ['source' => $source, 'target' => $target])];
                });
            }

            $pairKey = SyncEngine::pairKeyFor($source, $target);
            $manifest = $this->store->loadSyncManifest($pairKey);
            // An incremental sync skips what the manifest's hashes show
            // unchanged (SyncEngine). It also sent last_sync_epoch as the
            // source lists' updated_since, which no list can apply, so every
            // re-sync after the first failed.
            $options['manifest'] = $manifest;

            $pairLock = $this->store->acquirePairLock((string)($source['url'] ?? ''), (string)($target['url'] ?? ''));
            // Set while this process runs the job: a fatal error, a time or
            // memory limit, or an exit ends the process with no catch run,
            // and the shutdown function then fails the job saying so. What
            // ends it without one (a kill) is failIfAbandoned()'s to find.
            $running = new \ArrayObject(['on' => false]);
            register_shutdown_function(function () use ($jobId, $running): void {
                if ($running['on'] === true) {
                    $this->failEndedRun($jobId);
                }
            });
            try {
                // Logged first: what a lost run wrote is counted from it.
                $this->store->appendJobEvent($jobId, 'info', 'Job started', ['attempt' => (int)($job['attempts'] ?? 0) + 1]);
                $started = $this->changed($jobId, static function (array $stored, SyncJobState $state): ?array {
                    if ($state !== SyncJobState::Queued) {
                        return null;
                    }

                    return [
                        'status' => SyncJobState::Running,
                        'attempts' => (int)($stored['attempts'] ?? 0) + 1,
                        'running_since' => time(),
                    ];
                });
                if (!$started['changed']) {
                    // A cancel ended it between the lock and here.
                    $this->store->appendJobEvent($jobId, 'warning', 'Job not started', ['status' => $started['from']->value]);

                    return $started['job'];
                }
                $running['on'] = true;
                $startedHere = true;

                $source = $this->keys()->open($source, 'source');
                $target = $this->keys()->open($target, 'target');
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
                $manifest['last_sync_epoch'] = time();
                if (is_array($results['mappings'] ?? null)) {
                    $manifest['mappings'] = $results['mappings'];
                }
                if (is_array($results['source_hashes'] ?? null)) {
                    $manifest['source_hashes'] = $results['source_hashes'];
                }
                $this->store->saveSyncManifest($pairKey, $manifest);

                $ended = $this->changed($jobId, static function (array $stored, SyncJobState $state) use ($results, $summary): ?array {
                    if ($state !== SyncJobState::Running) {
                        return null;
                    }
                    // Read from the record as stored: a cancel may have been
                    // asked while execute() ran.
                    if ((bool)($stored['cancel_requested'] ?? false)) {
                        $to = SyncJobState::Cancelled;
                    } elseif (($summary['failed'] ?? 0) > 0 && (($summary['synced'] ?? 0) > 0 || ($summary['skipped'] ?? 0) > 0)) {
                        $to = SyncJobState::Partial;
                    } elseif (($summary['failed'] ?? 0) > 0) {
                        $to = SyncJobState::Failed;
                    } else {
                        $to = SyncJobState::Succeeded;
                    }

                    return [
                        'status' => $to,
                        'results' => $results,
                        'error' => null,
                        'error_code' => null,
                        'written' => null,
                        'conflict' => null,
                        'next_run_at' => null,
                    ] + self::keysDropped($stored);
                });
                if ($ended['changed']) {
                    $this->store->incrementMetric(self::END_METRICS[$ended['job']['status']], 1);
                    $this->store->appendJobEvent($jobId, 'info', 'Job completed', ['status' => $ended['job']['status']]);
                    $this->appendAuditForJob($ended['job']);
                } else {
                    // Taken as lost and ended while this run went on (a host
                    // that cannot see this one's lock): that end stands.
                    $this->store->appendJobEvent(
                        $jobId,
                        'warning',
                        'Job run finished after the job had ended',
                        ['status' => $ended['from']->value, 'summary' => $summary]
                    );
                }
            } finally {
                $running['on'] = false;
                $pairLock();
            }
        } catch (\Throwable $e) {
            // Named, not "Internal server error": a remote refusal's own
            // getMessage() is that sentence (RemoteApiException), and so is
            // every DatabaseException's, which this server's own failures
            // are; those are logged with the job's id instead (SyncFailure).
            $error = SyncFailure::report($e, 'job ' . $jobId);
            if (SyncFailure::isInternal($e)) {
                $error = 'The sync stopped on ' . $error . '.';
            }
            // A write the target refused with a 409 is not retried by the
            // job: the retry re-reads the target and force-writes over the
            // change the 409 protected (CLAUDE.md #13). The record is named
            // so the caller can look before re-running.
            $conflict = $e instanceof SyncRecordException ? $e->conflict : null;
            // Nor is a URL this server will not call, a cap the sync reached
            // or a key that does not open: the next try meets the same.
            $cause = SyncException::in($e);
            $retryable = $conflict === null && ($cause === null || $cause->retryable);
            $fields = [
                'error' => $error,
                'results' => null,
                'conflict' => $conflict,
                'error_code' => $cause?->errorCode ?? (SyncFailure::hasInternalCause($e) ? SyncException::INTERNAL : null),
                'written' => $e instanceof SyncRecordException ? $e->written : null,
            ];

            $stopped = $this->changed($jobId, static function (array $stored, SyncJobState $state) use ($fields, $retryable, $startedHere): ?array {
                // The job's end was saved and a step after it threw: the end
                // stands, and it is not tried again.
                if ($state->isTerminal()) {
                    return null;
                }
                // Running, and not by this try, which stopped before it
                // started: the run is another host's to end.
                if ($state === SyncJobState::Running && !$startedHere) {
                    return null;
                }
                $attempts = (int)($stored['attempts'] ?? 1);
                $maxAttempts = max(1, (int)($stored['max_attempts'] ?? 3));
                if ((bool)($stored['cancel_requested'] ?? false)) {
                    return $fields + ['status' => SyncJobState::Cancelled] + self::keysDropped($stored);
                }
                if ($retryable && $attempts < $maxAttempts) {
                    // Queued again after a try; or still queued, when the
                    // run could not start (a busy pair) and waits its turn.
                    $backoff = min(900, 15 * (2 ** max(0, $attempts - 1)));

                    return $fields + ['status' => SyncJobState::Queued, 'next_run_at' => time() + $backoff];
                }

                return $fields + ['status' => SyncJobState::Failed, 'next_run_at' => null] + self::keysDropped($stored);
            });
            if ($stopped['changed']) {
                $state = SyncJobState::ofStored($stopped['job']['status'], 'Sync job ' . $jobId);
                if ($state === SyncJobState::Queued) {
                    $this->store->incrementMetric('jobs_retried', 1);
                } else {
                    $this->store->incrementMetric(self::END_METRICS[$state->value], 1);
                    $this->appendAuditForJob($stopped['job']);
                }
            }
            $this->store->appendJobEvent(
                $jobId,
                'error',
                'Job execution error',
                ['error' => $error] + ($conflict !== null ? ['conflict' => $conflict] : [])
            );
        } finally {
            $releaseLock();
        }

        $saved = $this->store->getJob($jobId);
        if ($saved === null) {
            throw new DatabaseException('Job persistence failed');
        }
        return $saved;
    }

    /**
     * A job read 'running' whose run is gone, failed (or cancelled, when a
     * cancel was asked for) with what its run logged writing. Gone means
     * started more than ABANDONED_AFTER ago and no process holds its lock;
     * a job that is not, or whose lock is held, is returned as it is.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function failIfAbandoned(array $job): array
    {
        if (SyncJobState::ofRecord($job) !== SyncJobState::Running || !self::pastAbandonment($job)) {
            return $job;
        }
        $jobId = (string)($job['job_id'] ?? '');
        try {
            $release = $this->store->acquireJobLock($jobId);
        } catch (\Throwable) {
            return $job;
        }
        try {
            $current = $this->store->getJob($jobId);
            if ($current === null) {
                return $job;
            }
            if (SyncJobState::ofRecord($current) !== SyncJobState::Running || !self::pastAbandonment($current)) {
                return $current;
            }
            $since = self::runningSince($current);
            $when = $since !== null ? ' at ' . gmdate('Y-m-d H:i:s', $since) . ' UTC' : '';

            return $this->abandon(
                $current,
                'The run that started' . $when . ' stopped without finishing: its process ended (killed, or out of '
                    . 'memory or time) and nothing has held the job since; a run may take '
                    . RemoteApiClient::MAX_SECONDS . ' seconds.'
            );
        } finally {
            $release();
        }
    }

    /**
     * The shutdown of a process that was running a job: a fatal error, a
     * limit or an exit ended it before the run could record its end. The job
     * lock is still this process's, held until it exits.
     */
    private function failEndedRun(string $jobId): void
    {
        $fatal = error_get_last();
        $why = is_array($fatal) && in_array($fatal['type'], self::FATAL_ERRORS, true)
            ? 'PHP stopped the run with a fatal error: ' . $fatal['message'] . '.'
            : 'The process running it ended before the run finished.';
        // A memory limit leaves this no room to read the job in.
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1') {
            @ini_set('memory_limit', (string) (memory_get_usage() + 64 * 1024 * 1024));
        }
        try {
            $job = $this->store->getJob($jobId);
            if ($job !== null && SyncJobState::ofRecord($job) === SyncJobState::Running) {
                $this->abandon($job, $why);
            }
        } catch (\Throwable $e) {
            // failIfAbandoned() fails it later; say why it could not now.
            SyncFailure::report($e, 'job ' . $jobId . ' recording its end');
        }
    }

    /**
     * End a job whose run did not: the in-memory results went with the
     * process, so what it wrote is read from its event log.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function abandon(array $job, string $why): array
    {
        $jobId = (string)$job['job_id'];
        $request = is_array($job['request'] ?? null) ? $job['request'] : [];
        $options = is_array($request['options'] ?? null) ? $request['options'] : [];
        $dryRun = ($options['dry_run'] ?? false) === true;
        [$written, $complete] = $dryRun ? [[], true] : $this->writtenFromEvents($jobId);
        if ($dryRun) {
            $what = ' It was a dry run, so it wrote nothing.';
        } elseif ($written === []) {
            $what = ' It logged no write before it stopped.';
        } else {
            $parts = [];
            foreach ($written as $entity => $counts) {
                $done = [];
                foreach ($counts as $kind => $count) {
                    $done[] = $count . ' ' . $kind;
                }
                $parts[] = $entity . ' ' . implode(', ', $done);
            }
            $what = ' Writes it logged before it stopped'
                . ($complete ? '' : ' (at least: the log keeps only its latest events)')
                . ': ' . implode('; ', $parts) . '.';
        }
        $ended = $this->store->changeJob($jobId, static function (array $stored, SyncJobState $state) use ($why, $what, $written): ?array {
            // Only a job still running is lost: one that ended, or was
            // queued again, since the caller read it is left as it is.
            if ($state !== SyncJobState::Running) {
                return null;
            }
            $cancelled = (bool)($stored['cancel_requested'] ?? false);

            return [
                'status' => $cancelled ? SyncJobState::Cancelled : SyncJobState::Failed,
                'error' => $why . $what . ($cancelled ? '' : ' It is not tried again by itself: run the sync again to '
                    . 'finish it (plan first to see what is left).'),
                'error_code' => SyncException::WORKER_LOST,
                'written' => $written,
                'results' => null,
                'conflict' => null,
                'next_run_at' => null,
            ] + self::keysDropped($stored);
        });
        if ($ended === null) {
            return $job;
        }
        if (!$ended['changed']) {
            return $ended['job'];
        }
        $job = $ended['job'];
        $this->store->appendJobEvent($jobId, 'error', 'Job run lost', ['error' => $job['error']]);
        $this->store->incrementMetric(self::END_METRICS[$job['status']], 1);
        $this->store->incrementMetric('jobs_abandoned', 1);
        $this->appendAuditForJob($job);

        return $job;
    }

    /**
     * What the job's last run logged writing, by entity, and whether the log
     * still holds that run's start (it keeps only the latest events).
     *
     * @return array{0: array<string, array<string, int>>, 1: bool}
     */
    private function writtenFromEvents(string $jobId): array
    {
        $kinds = [
            'Created target record' => 'created',
            'Updated target record' => 'updated',
            'Deleted target record' => 'pruned',
        ];
        $events = $this->store->listJobEvents($jobId, 0, PHP_INT_MAX);
        $items = is_array($events['data'] ?? null) ? $events['data'] : [];
        $written = [];
        $complete = false;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            $event = is_array($items[$i]) ? $items[$i] : [];
            $message = (string)($event['message'] ?? '');
            if ($message === 'Job started') {
                $complete = true;
                break;
            }
            if (!isset($kinds[$message])) {
                continue;
            }
            $entity = (string)($event['data']['entity'] ?? '');
            $written[$entity][$kinds[$message]] = ($written[$entity][$kinds[$message]] ?? 0) + 1;
        }
        $order = ['created' => 0, 'updated' => 1, 'pruned' => 2];
        foreach ($written as &$counts) {
            uksort($counts, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        }
        unset($counts);

        return [array_reverse($written, true), $complete];
    }

    /** @param array<string, mixed> $job */
    private static function pastAbandonment(array $job): bool
    {
        $since = self::runningSince($job);

        // A start that cannot be read is no reason to keep it running for
        // ever: the lock is still asked before it is failed.
        return $since === null || time() - $since >= self::ABANDONED_AFTER;
    }

    /**
     * When the job's run started. A job that started before 1.9.81 has no
     * running_since; its last save was the start or a later cancel, so
     * updated_at stands in, at worst late.
     *
     * @param array<string, mixed> $job
     */
    private static function runningSince(array $job): ?int
    {
        if (is_int($job['running_since'] ?? null)) {
            return $job['running_since'];
        }
        $updated = $job['updated_at'] ?? null;
        $time = is_string($updated) ? strtotime($updated) : false;

        return $time === false ? null : $time;
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
            'status' => SyncJobState::ofStored($job['status'] ?? null, 'Sync job ' . (string)($job['job_id'] ?? ''))->value,
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
        // Lists of entity names. Anything that was not an array was read as
        // an empty list, and a name the engine does not know matched
        // nothing, so `"prune_denylist": "campaigns"` protected no
        // campaign from the prune it was sent to stop (CLAUDE.md #4).
        $entities = SyncEngine::supportedEntities();
        $entity = static fn (mixed $name): ?string => is_string($name) && in_array(trim($name), $entities, true)
            ? null
            : 'must be an entity name: one of ' . implode(', ', $entities);
        $what = 'entity names, e.g. ["campaigns"] (one of ' . implode(', ', $entities) . ')';
        PayloadKeys::refuse(
            PayloadKeys::valueListErrors($payload, 'prune_allowlist', $what, $entity)
            + PayloadKeys::valueListErrors($payload, 'prune_denylist', $what, $entity)
        );

        return [
            // Flags, read strictly: (bool) made every non-empty string true,
            // so "force_update": "false" overwrote the target's differing
            // records (RequestFlag).
            'dry_run' => RequestFlag::param($payload, 'dry_run', false),
            'skip_errors' => RequestFlag::param($payload, 'skip_errors', false),
            'force_update' => RequestFlag::param($payload, 'force_update', false),
            'incremental' => RequestFlag::param($payload, 'incremental', false),
            'prune' => RequestFlag::param($payload, 'prune', false),
            'prune_preview' => RequestFlag::param($payload, 'prune_preview', false),
            'confirmation_token' => (string)($payload['confirmation_token'] ?? ''),
            'prune_allowlist' => $payload['prune_allowlist'] ?? [],
            'prune_denylist' => $payload['prune_denylist'] ?? [],
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

        $pairKey = SyncEngine::pairKeyFor($source, $target);
        if (!$this->store->validatePruneToken($token, $pairKey)) {
            throw new ValidationException('Invalid prune confirmation token', ['confirmation_token' => 'Token is invalid or expired']);
        }
    }

    /** What a source or target profile carries; resolveProfileEntry() reads them. */
    private const PROFILE_KEYS = ['url', 'api_key', 'name'];

    /**
     * @param bool $forCalls the profiles will be called (a plan, a job): each
     *        needs a key and a URL this server may call
     */
    private function resolveProfiles(array $payload, bool $forCalls = true): array
    {
        // A profile is an object of PROFILE_KEYS, each a string (or null,
        // read as not given): a key it does not take was dropped (`nmae`
        // named the job by its URL), and each side is named once, by
        // `source` or its alias `from` (`target` or `to`): with both, the
        // alias was dropped without a word.
        $text = static fn (array $profile): array => array_map(
            static fn (): string => 'must be a string',
            array_filter($profile, static fn (mixed $value): bool => $value !== null && !is_string($value))
        );
        $errors = PayloadKeys::objectErrors($payload, 'source', self::PROFILE_KEYS, 'a sync profile', $text)
            + PayloadKeys::objectErrors($payload, 'from', self::PROFILE_KEYS, 'a sync profile', $text)
            + PayloadKeys::objectErrors($payload, 'target', self::PROFILE_KEYS, 'a sync profile', $text)
            + PayloadKeys::objectErrors($payload, 'to', self::PROFILE_KEYS, 'a sync profile', $text);
        foreach (['source' => 'from', 'target' => 'to'] as $side => $alias) {
            if (isset($payload[$side], $payload[$alias])) {
                $errors[$alias] = "is another name for $side: send one of them";
            }
        }
        PayloadKeys::refuse($errors);

        $source = $this->resolveProfileEntry($payload['source'] ?? $payload['from'] ?? null, 'source', $forCalls);
        $target = $this->resolveProfileEntry($payload['target'] ?? $payload['to'] ?? null, 'target', $forCalls);

        return [$source, $target];
    }

    private function resolveProfileEntry(mixed $entry, string $label, bool $forCalls): array
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
        if ($forCalls && $apiKey === '') {
            throw new ValidationException('Validation failed', [$label . '.api_key' => 'Required']);
        }
        // What can be told without a resolver; each request is checked again,
        // resolved and pinned, when it is sent (RemoteApiClient).
        $refused = $forCalls ? RemoteApiClient::profileUrlRefusal($url) : null;
        if ($refused !== null) {
            throw new ValidationException(
                'This server does not call the sync ' . $label . '\'s url',
                [$label . '.url' => $refused]
            );
        }

        return [
            'name' => $name,
            'url' => $url,
            'api_key' => $apiKey,
        ];
    }

    /** @param array<string, mixed> $job */
    private static function holdsKey(array $job): bool
    {
        foreach (['source', 'target'] as $side) {
            $profile = $job['request'][$side] ?? null;
            if (is_array($profile) && SyncProfileKeys::forget($profile) !== $profile) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function withoutKeys(array $job): array
    {
        foreach (['source', 'target'] as $side) {
            if (isset($job['request'][$side]) && is_array($job['request'][$side])) {
                $job['request'][$side] = SyncProfileKeys::forget($job['request'][$side]);
            }
        }

        return $job;
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
            'conflicted' => 0,
        ];

        foreach ($perEntity as $entityResult) {
            if (!is_array($entityResult)) {
                continue;
            }
            $summary['synced'] += (int)($entityResult['synced'] ?? 0);
            $summary['skipped'] += (int)($entityResult['skipped'] ?? 0);
            $summary['failed'] += (int)($entityResult['failed'] ?? 0);
            $summary['pruned'] += (int)($entityResult['pruned'] ?? 0);
            $summary['conflicted'] += (int)($entityResult['conflicted'] ?? 0);
        }

        return $summary;
    }

    private function sanitizeJobForResponse(array $job): array
    {
        $copy = $job;
        if (isset($copy['request']) && is_array($copy['request'])) {
            $copy['request'] = $this->store->sanitize($copy['request']);
            foreach (['source', 'target'] as $side) {
                if (isset($copy['request'][$side]) && is_array($copy['request'][$side])) {
                    $copy['request'][$side] = SyncProfileKeys::forget($copy['request'][$side]);
                }
            }
        }
        if (isset($copy['results']) && is_array($copy['results'])) {
            $copy['results'] = $this->store->sanitize($copy['results']);
        }
        return $copy;
    }

    /**
     * json (the default) or csv, in either case. Anything else used to be
     * answered as json, so format=xml or a typo read as "the format asked
     * for" to a caller that then parsed the wrong thing.
     *
     * @param array<string, mixed> $params
     */
    private static function auditFormat(array $params): string
    {
        $format = $params['format'] ?? '';
        if ($format === '') {
            return 'json';
        }
        $format = is_string($format) ? strtolower($format) : '';
        if ($format !== 'json' && $format !== 'csv') {
            throw new ValidationException('Invalid format', ['format' => 'Valid values: json, csv']);
        }

        return $format;
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
        $activeJobs = array_filter(
            array_map(
                fn (array $job): array => $this->failIfAbandoned($job),
                $this->store->listJobs([SyncJobState::Queued, SyncJobState::Running], 5000)
            ),
            static fn (array $job): bool => in_array(
                SyncJobState::ofRecord($job),
                [SyncJobState::Queued, SyncJobState::Running],
                true
            )
        );
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

    /**
     * An audit-list time bound: a unix time, or null when absent. It was
     * `(int)`, so `from_epoch=yesterday` was 0 and listed everything.
     *
     * @param array<string, mixed> $params
     */
    private static function epochOrNull(array $params, string $name): ?int
    {
        $epoch = QueryInt::param($params, $name, -1, 0, PHP_INT_MAX, 'a unix time');

        return $epoch >= 0 ? $epoch : null;
    }
}

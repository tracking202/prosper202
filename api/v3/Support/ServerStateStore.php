<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\ConflictException;

class ServerStateStore implements QuotaStore
{
    private const int DEFAULT_RETENTION = 5000;

    /**
     * How long a recorded Idempotency-Key stays replayable, and how many may
     * share one shard. Retries happen within seconds or minutes, so a day is
     * generous; the age bound is what keeps a busy caller's shard small,
     * with the count a backstop for a caller that outruns it. Past either
     * bound a key is forgotten and its retry executes again — which is why
     * neither bound is tight.
     */
    private const int IDEMPOTENCY_RETENTION_SECONDS = 86400;
    private const int IDEMPOTENCY_RETENTION = 500;

    /**
     * Staged-change statuses that are finished, and so safe to prune once a
     * user's ledger passes the retention cap. `staged` is awaiting a
     * decision; `applying` is mid-dispatch, and dropping one loses the
     * record its own handler is about to write its outcome into. Only
     * terminal states are prunable.
     */
    private const array PRUNABLE_CHANGE_STATUSES = ['applied', 'discarded', 'apply_interrupted'];

    /**
     * How many proposals one user may have awaiting a decision. Pruning only
     * reclaims resolved and expired records, so without a bound on the live
     * queue a propose-only key can grow its ledger for a whole TTL — and
     * every later stage and list reads and rewrites that file. An approval
     * queue this deep is unusable by a human anyway; the cap turns a latency
     * and disk problem into an explicit refusal naming what to do.
     * P202_STAGED_CHANGE_MAX_PENDING raises it for larger staged imports.
     */
    private const int DEFAULT_MAX_PENDING_CHANGES = 1000;

    private readonly string $baseDir;

    /**
     * @param ?string $instanceIdentity Overrides the ambient $dbname/$dbhost
     *        globals used to scope the default directory. Pass it from any
     *        context that loads configuration inside a function rather than
     *        at file scope — otherwise the globals are unset and this process
     *        silently reads and writes a different store than the web tier.
     */
    public function __construct(?string $baseDir = null, ?string $instanceIdentity = null)
    {
        $this->baseDir = rtrim($baseDir ?? $this->resolveDefaultBaseDir($instanceIdentity), '/');
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
            // A random fallback hash would silently break idempotency replay
            // and incremental-sync diffing (nothing would ever match again).
            throw new DatabaseException('Failed to encode payload for hashing: ' . json_last_error_msg());
        }
        return sha1($json);
    }

    /**
     * Where a caller's Idempotency-Key records live. The scope is the caller
     * and nothing else: a key identifies one request, so reusing it for a
     * different endpoint is the same caller error as reusing it with a
     * changed body, and folding the operation into the scope would file the
     * second request separately and let it execute. What the request *was*
     * belongs in the fingerprint, which is compared rather than looked up.
     */
    public static function idempotencyScopeForUser(int $userId): string
    {
        return 'idempotency:user:' . $userId;
    }

    /**
     * Identify a request for the mismatch check: the operation it targets
     * plus the body it carries. Both matter — the same body sent to two
     * endpoints is two different operations.
     *
     * @param array<string, mixed> $payload
     */
    public static function idempotencyFingerprint(string $operation, array $payload): string
    {
        return self::canonicalHash(['operation' => $operation, 'payload' => $payload]);
    }

    /**
     * How long a reserveIdempotent() claim blocks a concurrent request with
     * the same key. Past this a claim is assumed dead (the request that took
     * it crashed) and may be re-taken, so a failed create cannot wedge a key.
     */
    public const IDEMPOTENCY_CLAIM_TTL_SECONDS = 300;

    /**
     * Read what a request carrying this Idempotency-Key should replay.
     *
     * $fingerprint identifies the request body. A key already recorded
     * against a different body is a caller error rather than a new
     * operation, and is reported as 'mismatch' so the caller can refuse it:
     * the alternative — folding the body hash into $scope — sends the second
     * request to a different file, where it looks like a fresh key and
     * executes, producing exactly the duplicate the key was sent to prevent.
     * An empty $fingerprint disables the check for callers that have no body
     * to hash.
     *
     * @return array{state: 'miss'|'replay'|'mismatch', response: ?array}
     */
    public function lookupIdempotent(string $scope, string $key, string $fingerprint = ''): array
    {
        $data = $this->readJsonFile($this->idempotencyPath($scope, $key), ['items' => []]);
        $items = $data['items'] ?? [];
        $item = is_array($items) ? ($items[$key] ?? null) : null;
        if (!is_array($item)) {
            return ['state' => 'miss', 'response' => null];
        }

        if ($fingerprint !== '') {
            $stored = (string)($item['fingerprint'] ?? '');
            if ($stored !== '' && !hash_equals($stored, $fingerprint)) {
                return ['state' => 'mismatch', 'response' => null];
            }
        }

        if (!isset($item['response']) || !is_array($item['response'])) {
            return ['state' => 'miss', 'response' => null];
        }
        return ['state' => 'replay', 'response' => $item['response']];
    }

    /**
     * Atomically decide what a request carrying this Idempotency-Key should
     * do. lookupIdempotent()/putIdempotent() are separately locked, so a
     * check followed by the write left a window in which two concurrent retries
     * both missed the record and both executed — which is precisely the case
     * idempotency exists to prevent, since automatic retries usually race a
     * still-running request rather than following it.
     *
     * $fingerprint identifies the request body. A key reused with a different
     * body is a caller error, not a new operation: the scope used to include
     * the payload hash, which made the same key with a changed field land in
     * a different file and create a second record — the exact duplicate the
     * key was sent to prevent.
     *
     * @return array{state: 'replay'|'in_flight'|'indeterminate'|'mismatch'|'claimed', response: ?array}
     */
    public function reserveIdempotent(string $scope, string $key, string $fingerprint = ''): array
    {
        $result = ['state' => 'claimed', 'response' => null];
        $this->mutateJsonFile(
            $this->idempotencyPath($scope, $key),
            ['items' => []],
            static function (array $data) use ($key, $fingerprint, &$result): array {
                $item = $data['items'][$key] ?? null;

                // Checked before every other outcome: a body mismatch makes
                // replaying, waiting, or claiming all wrong answers.
                if (is_array($item) && $fingerprint !== '') {
                    $stored = (string)($item['fingerprint'] ?? '');
                    if ($stored !== '' && !hash_equals($stored, $fingerprint)) {
                        $result = ['state' => 'mismatch', 'response' => null];
                        return $data;
                    }
                }

                if (is_array($item) && isset($item['response']) && is_array($item['response'])) {
                    $result = ['state' => 'replay', 'response' => $item['response']];
                    return $data;
                }
                // Marked by a caller that knows its write landed but could
                // not record the response: unknown from the first retry
                // rather than after the claim ages out.
                if (is_array($item) && ($item['indeterminate'] ?? false) === true) {
                    $result = ['state' => 'indeterminate', 'response' => null];
                    return $data;
                }
                $claimedAt = (int)($item['claimed_at'] ?? 0);
                if (is_array($item) && $claimedAt > 0) {
                    if (time() - $claimedAt <= self::IDEMPOTENCY_CLAIM_TTL_SECONDS) {
                        // Another request holds this key and has not finished.
                        $result = ['state' => 'in_flight', 'response' => null];
                        return $data;
                    }
                    // The claim outlived its holder without recording a
                    // response. A caller whose operation merely *failed*
                    // releases the claim (see releaseIdempotent), so getting
                    // here means the process died outright — possibly after
                    // its write committed. Re-executing would duplicate the
                    // record this key exists to prevent, so the claim is kept
                    // and the state reported as unknown rather than reused.
                    $result = ['state' => 'indeterminate', 'response' => null];
                    return $data;
                }
                // Free: take it.
                $data['items'][$key] = [
                    'stored_at' => time(),
                    'claimed_at' => time(),
                    'fingerprint' => $fingerprint,
                ];
                $data['items'] = self::pruneIdempotencyItems($data['items'], $key);
                return $data;
            }
        );
        return $result;
    }

    /**
     * Drop a claim taken by reserveIdempotent() whose operation failed, so
     * the caller can retry instead of being told a request is in flight
     * until the claim expires.
     */
    public function releaseIdempotent(string $scope, string $key): void
    {
        $this->mutateJsonFile(
            $this->idempotencyPath($scope, $key),
            ['items' => []],
            static function (array $data) use ($key): array {
                $item = $data['items'][$key] ?? null;
                if (is_array($item) && !isset($item['response'])) {
                    unset($data['items'][$key]);
                }
                return $data;
            }
        );
    }

    /**
     * Record that this key's operation wrote to the database but never
     * produced a response to replay. Without it the claim looks merely
     * in-flight until it ages out, and a retry in that window is told to
     * wait for a request that is already gone.
     */
    public function markIdempotentIndeterminate(string $scope, string $key): void
    {
        $this->mutateJsonFile(
            $this->idempotencyPath($scope, $key),
            ['items' => []],
            static function (array $data) use ($key): array {
                $item = $data['items'][$key] ?? null;
                if (is_array($item) && !isset($item['response'])) {
                    $item['indeterminate'] = true;
                    $data['items'][$key] = $item;
                }
                return $data;
            }
        );
    }

    /**
     * @param string $fingerprint Body hash to record when no claim already
     *        carries one — required by callers that write without going
     *        through reserveIdempotent(), or a later retry with a changed
     *        body cannot be told apart from a replay.
     */
    public function putIdempotent(string $scope, string $key, array $response, string $fingerprint = ''): void
    {
        $path = $this->idempotencyPath($scope, $key);
        $this->mutateJsonFile($path, ['items' => []], static function (array $data) use ($key, $response, $fingerprint): array {
            // Prefer the fingerprint the claim recorded: without it a replay
            // could no longer tell that a later retry changed the body.
            $existing = $data['items'][$key] ?? null;
            $stored = is_array($existing) ? (string)($existing['fingerprint'] ?? '') : '';
            $data['items'][$key] = [
                'stored_at' => time(),
                'fingerprint' => $stored !== '' ? $stored : $fingerprint,
                'response' => $response,
            ];

            $data['items'] = self::pruneIdempotencyItems($data['items'], $key);

            return $data;
        });
    }

    /**
     * Drop expired and surplus records from one shard. $keepKey is the record
     * the caller just wrote: it is never dropped, so a prune can't discard
     * the claim or response the current request depends on.
     *
     * @param mixed $items
     * @return array<string, mixed>
     */
    private static function pruneIdempotencyItems(mixed $items, string $keepKey): array
    {
        if (!is_array($items)) {
            return [];
        }

        $cutoff = time() - self::IDEMPOTENCY_RETENTION_SECONDS;
        foreach ($items as $itemKey => $item) {
            // Cast before comparing: PHP turns an all-digit array key into an
            // int, so an Idempotency-Key of "12345" arrives here as 12345 and
            // a strict comparison against the string would never match.
            if ((string)$itemKey === $keepKey) {
                continue;
            }
            // A record with no readable timestamp is from a shape this
            // version does not write; treat it as expired rather than
            // keeping it forever.
            $storedAt = is_array($item) ? (int)($item['stored_at'] ?? 0) : 0;
            if ($storedAt <= $cutoff) {
                unset($items[$itemKey]);
            }
        }

        if (count($items) > self::IDEMPOTENCY_RETENTION) {
            $keep = $items[$keepKey] ?? null;
            uasort($items, static function (mixed $a, mixed $b): int {
                $left = is_array($a) ? (int)($a['stored_at'] ?? 0) : 0;
                $right = is_array($b) ? (int)($b['stored_at'] ?? 0) : 0;
                return $left <=> $right;
            });
            $items = array_slice($items, -self::IDEMPOTENCY_RETENTION, null, true);
            if ($keep !== null && !isset($items[$keepKey])) {
                $items[$keepKey] = $keep;
            }
        }

        return $items;
    }

    public function recordChange(string $entity, string $operation, array $record, int $actorUserId): void
    {
        $path = $this->changesPath($entity);
        $this->mutateJsonFile($path, ['next_seq' => 1, 'items' => []], static function (array $state) use ($entity, $operation, $record, $actorUserId): array {
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

            return $state;
        });
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
        if (empty($job)) {
            return null;
        }

        return $job;
    }

    public function saveJob(array $job): void
    {
        if (!isset($job['job_id'])) {
            throw new DatabaseException('Job payload missing job_id');
        }
        $this->mutateJsonFile($this->jobPath((string)$job['job_id']), [], static function (array $current) use ($job): array {
            // The worker saves its whole in-memory copy after long-running
            // work; a cancel flag set concurrently on disk must survive that.
            if (!empty($current['cancel_requested'])) {
                $job['cancel_requested'] = true;
            }
            $job['updated_at'] = gmdate('c');
            return $job;
        });
    }

    public function appendJobEvent(string $jobId, string $level, string $message, array $data = []): void
    {
        $event = [
            'event_id' => bin2hex(random_bytes(8)),
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'data' => $this->sanitizeSensitive($data),
        ];
        $this->mutateJsonFile($this->jobEventsPath($jobId), ['items' => []], static function (array $events) use ($event): array {
            $events['items'][] = $event;
            if (count($events['items']) > self::DEFAULT_RETENTION) {
                $events['items'] = array_slice($events['items'], -self::DEFAULT_RETENTION);
            }
            return $events;
        });
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

    // ─── Staged writes ───────────────────────────────────────────────
    // A staged write is a recorded operator-surface mutation awaiting
    // approval: the model (or any caller) proposes, a person applies. One
    // file per staging user; resolved entries stay as the audit trail and
    // are pruned oldest-first past the retention cap.

    /**
     * The live-queue cap. Read here rather than baked in so a deployment
     * doing large staged imports can raise it without a code change.
     */
    public static function maxPendingChanges(): int
    {
        $raw = getenv('P202_STAGED_CHANGE_MAX_PENDING');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed >= 1) {
                return $parsed;
            }
            error_log(sprintf(
                'p202: ignoring P202_STAGED_CHANGE_MAX_PENDING=%s — expected a whole number >= 1; using %d.',
                $raw,
                self::DEFAULT_MAX_PENDING_CHANGES
            ));
        }
        return self::DEFAULT_MAX_PENDING_CHANGES;
    }

    public function stageWriteChange(int $userId, array $change): void
    {
        $changeId = (string)($change['change_id'] ?? '');
        if ($changeId === '') {
            throw new DatabaseException('Staged change missing change_id');
        }
        $maxPending = self::maxPendingChanges();
        $this->mutateJsonFile($this->stagedChangesPath($userId), ['items' => []], static function (array $state) use ($changeId, $change, $maxPending): array {
            // Counted under the same lock as the write, so concurrent stages
            // cannot both slip past the cap.
            $pending = 0;
            $cutoff = time();
            // Guarded like listChanges() and listJobEvents(): readJsonFile()
            // only promises the top level is an array, so a truncated or
            // hand-edited state file with `"items": null` would TypeError
            // inside the lock and 500 every stage with no clue why.
            $items = is_array($state['items'] ?? null) ? $state['items'] : [];
            $state['items'] = $items;
            foreach ($items as $item) {
                if (($item['status'] ?? '') === 'staged'
                    && $cutoff <= (int)($item['expires_at_epoch'] ?? 0)) {
                    $pending++;
                }
            }
            if ($pending >= $maxPending) {
                throw new ConflictException(sprintf(
                    'You already have %d staged changes awaiting a decision, which is the limit. Apply or '
                    . 'discard some (GET /staged-changes?status=staged) before staging more, or raise '
                    . 'P202_STAGED_CHANGE_MAX_PENDING.',
                    $pending
                ));
            }

            $state['items'][$changeId] = $change;

            $now = time();
            $resolved = [];
            foreach ($state['items'] as $id => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = (string)($item['status'] ?? '');
                // Expired proposals keep the status `staged` but can never be
                // applied, so counting only terminal records would let them
                // accumulate without bound -- every later stage and list would
                // read and rewrite an ever-larger file, which a propose-only
                // key could drive on purpose.
                $expired = $status === 'staged'
                    && $now > (int)($item['expires_at_epoch'] ?? 0);
                if ($expired || in_array($status, self::PRUNABLE_CHANGE_STATUSES, true)) {
                    $resolved[$id] = (int)($item['created_at_epoch'] ?? 0);
                }
            }
            if (count($resolved) > self::DEFAULT_RETENTION) {
                asort($resolved);
                $drop = count($resolved) - self::DEFAULT_RETENTION;
                foreach (array_slice(array_keys($resolved), 0, $drop) as $dropId) {
                    unset($state['items'][$dropId]);
                }
            }

            return $state;
        });
    }

    public function getStagedChangeForUser(int $userId, string $changeId): ?array
    {
        $state = $this->readJsonFile($this->stagedChangesPath($userId), ['items' => []]);
        $item = $state['items'][$changeId] ?? null;
        return is_array($item) ? $item : null;
    }

    /**
     * Locate a staged change regardless of which user staged it (admin
     * access). Scans the per-user files; fine at this store's scale.
     */
    public function findStagedChange(string $changeId): ?array
    {
        foreach ($this->listStagedChangeFiles() as $path) {
            $state = $this->readJsonFile($path, ['items' => []]);
            $item = $state['items'][$changeId] ?? null;
            if (is_array($item)) {
                return $item;
            }
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function listStagedChangesForUser(int $userId): array
    {
        $state = $this->readJsonFile($this->stagedChangesPath($userId), ['items' => []]);
        $items = array_values(array_filter($state['items'] ?? [], 'is_array'));
        usort($items, static fn(array $a, array $b): int => ((int)($b['created_at_epoch'] ?? 0)) <=> ((int)($a['created_at_epoch'] ?? 0)));
        return $items;
    }

    /** @return array<int, array<string, mixed>> newest first, across all users */
    public function listStagedChangesAllUsers(): array
    {
        $items = [];
        foreach ($this->listStagedChangeFiles() as $path) {
            $state = $this->readJsonFile($path, ['items' => []]);
            foreach ($state['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }
        usort($items, static fn(array $a, array $b): int => ((int)($b['created_at_epoch'] ?? 0)) <=> ((int)($a['created_at_epoch'] ?? 0)));
        return $items;
    }

    /**
     * Atomically transform one staged change under the file lock. The
     * mutator receives the current record and returns the updated one, or
     * null to reject the transition (e.g. a status precondition failed).
     * Returns the updated record, or null when the change is missing or the
     * mutator rejected — the caller decides which error that is.
     */
    public function updateStagedChange(int $userId, string $changeId, callable $mutator): ?array
    {
        $result = null;
        $this->mutateJsonFile($this->stagedChangesPath($userId), ['items' => []], static function (array $state) use ($changeId, $mutator, &$result): array {
            $item = $state['items'][$changeId] ?? null;
            if (!is_array($item)) {
                return $state;
            }
            $updated = $mutator($item);
            if (is_array($updated)) {
                $state['items'][$changeId] = $updated;
                $result = $updated;
            }
            return $state;
        });
        return $result;
    }

    /** @return string[] */
    private function listStagedChangeFiles(): array
    {
        $dir = $this->dir('staged_changes');
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/user-*.json');
        return $files === false ? [] : $files;
    }

    private function stagedChangesPath(int $userId): string
    {
        return $this->dir('staged_changes') . '/user-' . $userId . '.json';
    }

    public function appendAudit(array $record): void
    {
        $sanitized = $this->sanitizeSensitive($record);
        $this->mutateJsonFile($this->auditPath(), ['items' => []], static function (array $audit) use ($sanitized): array {
            $audit['items'][] = $sanitized;
            if (count($audit['items']) > self::DEFAULT_RETENTION) {
                $audit['items'] = array_slice($audit['items'], -self::DEFAULT_RETENTION);
            }
            return $audit;
        });
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
        $entry = [
            'pair_key' => $pairKey,
            'expires_at' => time() + $ttlSeconds,
        ];
        $this->mutateJsonFile($this->pruneTokensPath(), ['items' => []], static function (array $state) use ($token, $entry): array {
            $state['items'][$token] = $entry;
            return $state;
        });

        return $token;
    }

    public function validatePruneToken(string $token, string $pairKey): bool
    {
        // Check-and-consume must happen under the state lock: the bare
        // read-then-write version let two concurrent runs both spend the
        // same single-use token (TOCTOU double-prune).
        $valid = false;
        $this->mutateJsonFile($this->pruneTokensPath(), ['items' => []], static function (array $state) use ($token, $pairKey, &$valid): array {
            $item = $state['items'][$token] ?? null;
            if (
                is_array($item)
                && (string)($item['pair_key'] ?? '') === $pairKey
                && (int)($item['expires_at'] ?? 0) >= time()
            ) {
                $valid = true;
                unset($state['items'][$token]);
            }
            return $state;
        });

        return $valid;
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
            if (empty($job['job_id'])) {
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
        $this->mutateJsonFile($path, ['counters' => []], static function (array $state) use ($name, $delta): array {
            $current = (int)($state['counters'][$name] ?? 0);
            $state['counters'][$name] = $current + $delta;
            $state['updated_at'] = gmdate('c');
            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return $this->readJsonFile($this->dir('metrics') . '/metrics.json', ['counters' => [], 'updated_at' => null]);
    }

    /** @param array<string, mixed> $meta */
    public function startSpan(string $name, array $meta = []): string
    {
        $id = bin2hex(random_bytes(8));
        $span = [
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
        $this->mutateJsonFile($this->spansPath(), ['items' => []], static function (array $state) use ($span): array {
            $state['items'][] = $span;
            if (count($state['items']) > self::DEFAULT_RETENTION) {
                $state['items'] = array_slice($state['items'], -self::DEFAULT_RETENTION);
            }
            return $state;
        });
        return $id;
    }

    /** @param array<string, mixed> $meta */
    public function endSpan(string $spanId, string $status = 'ok', array $meta = []): void
    {
        $resultMeta = $this->sanitizeSensitive($meta);
        $this->mutateJsonFile($this->spansPath(), ['items' => []], static function (array $state) use ($spanId, $status, $resultMeta): array {
            if (!is_array($state['items'] ?? null)) {
                return $state;
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
                $item['result_meta'] = $resultMeta;
                break;
            }
            unset($item);

            return $state;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function listSpans(?string $name = null, int $limit = 200): array
    {
        $state = $this->readJsonFile($this->spansPath(), ['items' => []]);
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

    /**
     * Soft per-source rate limit for unauthenticated endpoints.
     *
     * The bucket is keyed on the validated TCP peer address (REMOTE_ADDR),
     * never on client-supplied headers: X-Forwarded-For is attacker-chosen
     * on an open endpoint, so keying on it lets a flooder mint a fresh
     * bucket per request — the limit never fires AND every spoofed value
     * becomes a state file (the p13n endpoints' "never raw spoofable
     * headers" rule). Behind a TLS-terminating proxy the peer is the proxy,
     * so callers size $maxPerWindow as an aggregate ceiling, not a
     * per-device one.
     *
     * Soft means the limiter's own failure never blocks the request:
     * returns the retry-after seconds when the limit is exceeded, and null
     * when the request may proceed — including when the limiter itself
     * errored (logged). Also opportunistically garbage-collects stale
     * bucket files so the directory stays bounded by recent distinct peers.
     */
    public function softIpRateLimit(string $prefix, int $maxPerWindow, int $windowSeconds, ?string $peerIp = null): ?int
    {
        try {
            $ip = $peerIp ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                // No validated peer (CLI, misconfigured SAPI): one shared
                // bucket rather than an attacker-nameable one.
                $ip = 'unknown';
            }
            if (mt_rand(1, 100) === 1) {
                $this->pruneStaleRateLimits(max(3600, $windowSeconds * 10));
            }
            $rate = $this->consumeRateLimit($prefix . ':' . substr($ip, 0, 64), $maxPerWindow, $windowSeconds);
            if (!$rate['allowed']) {
                return max(1, (int)$rate['reset_at'] - time());
            }
            return null;
        } catch (\Throwable $e) {
            error_log('p202: rate limiter unavailable, serving request: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete rate-limit bucket files whose window is long over. Buckets are
     * one small JSON file per distinct source; without collection the
     * directory grows with every source ever seen.
     */
    public function pruneStaleRateLimits(int $maxAgeSeconds): void
    {
        $dir = $this->dir('rate_limits');
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        $cutoff = time() - $maxAgeSeconds;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Buckets, and the temp files a process killed between write and
            // rename leaves behind (`<name>.json.tmp-xxxx`) — collecting only
            // `.json` left those orphans to accumulate forever in the very
            // directory this pass exists to bound.
            $isBucket = str_ends_with($entry, '.json');
            $isLock = str_ends_with($entry, '.json.lock');
            $isOrphanTemp = str_contains($entry, '.json.tmp-');
            if (!$isBucket && !$isLock && !$isOrphanTemp) {
                continue;
            }
            $path = $dir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }
            if ($isLock) {
                // Neither of the cheap tests can decide a lock is free. Its
                // mtime is its CREATION time — taking the lock does not
                // touch it — and a missing bucket file is also the state of
                // the request that revives an idle peer, possibly one this
                // very pass emptied moments earlier. Deleting a held lock
                // leaves two processes holding LOCK_EX on different inodes,
                // which is the lost update the lock exists to prevent, so
                // what protects it is taking it.
                if (is_file(substr($path, 0, -strlen('.lock')))) {
                    continue;
                }
                $this->unlinkUnheldLock($path);
                continue;
            }
            if ($isBucket) {
                $this->unlinkIdleBucket($path, $cutoff);
                continue;
            }
            @unlink($path);
        }
    }

    /**
     * Remove a bucket only under its own lock, and only if it is still idle
     * once the lock is held. The mtime above was read without the lock, so a
     * request could rewrite the bucket between that read and an unlink —
     * deleting a live count, and for a quota (reserveQuota()) handing the
     * rest of the window a fresh budget. Taken non-blocking: a bucket someone
     * is inside is live by definition, and a collection pass that runs on a
     * request path must not wait on one. With the lock held, a writer that
     * comes after reads no file — a fresh window, which is what a bucket idle
     * past $cutoff (never less than an hour, many windows) already was. The
     * lock file goes with the bucket, while still held, as unlinkUnheldLock()
     * removes one; 'c+' because a bucket with no lock file beside it is still
     * one a writer may be about to lock.
     */
    private function unlinkIdleBucket(string $path, int $cutoff): void
    {
        $lockPath = $path . '.lock';
        $fh = @fopen($lockPath, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                return;
            }
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
                @unlink($lockPath);
            }
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
    }

    /**
     * Remove a stale lock file only when no one is inside its critical
     * section: the unlink runs while this pass itself holds LOCK_EX, so a
     * mutateJsonFile() that already has the lock keeps its file. The
     * remaining window is the instant between fopen() and flock() there — a
     * caller that opened this inode microseconds ago still ends up alone on
     * it — versus the whole critical section before. 'r+' rather than 'c+':
     * a collection pass must never create the file it came to delete.
     */
    private function unlinkUnheldLock(string $path): void
    {
        $fh = @fopen($path, 'r+');
        if ($fh === false) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            @unlink($path);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /**
     * Take $cost units of a fixed-window quota, or refuse without taking any.
     *
     * The per-registration install cap and the per-install event cap (plan
     * §7.1): unlike softIpRateLimit(), a refused request consumes nothing —
     * what is over the cap is neither stored nor counted — and the check
     * fails CLOSED: a store that cannot be read or written throws, and the
     * caller answers 503 rather than guessing (the SDK retries 5xx, so an
     * outage of the limiter delays installs and loses none). The bucket file
     * is written under the same exclusive lock as the rate limits, so a
     * burst cannot all read the same count; its name is the rate-limit path
     * (slug plus a hash of the whole bucket), so the caller's bucket string
     * must itself be injective (CLAUDE.md #17).
     *
     * An admitted cost is spent when it is taken, before the caller does the
     * work; a caller whose work then fails (or turns out to have been done
     * already) gives it back with refundQuota(), so the cap counts what was
     * recorded rather than what was attempted. The window is fixed, not
     * sliding: a burst straddling a window boundary can take up to twice
     * the limit in a short span. And the bucket is a file under this store's
     * directory, so web hosts that do not share it each keep their own count.
     *
     * @return int|null seconds until the window resets when refused, null when admitted
     * @throws \RuntimeException when the quota cannot be read or written
     */
    public function reserveQuota(string $bucket, int $limit, int $windowSeconds, int $cost = 1): ?int
    {
        return $this->reserveQuotaWindow($bucket, $limit, $windowSeconds, $cost)['retry_after'];
    }

    /**
     * reserveQuota(), also naming the window the cost was charged to, which
     * refundQuota() needs to give it back to that window and no other.
     *
     * @return array{retry_after: int|null, window_start: int} retry_after null when admitted
     * @throws \RuntimeException when the quota cannot be read or written
     */
    #[\Override]
    public function reserveQuotaWindow(string $bucket, int $limit, int $windowSeconds, int $cost = 1): array
    {
        if ($limit < 1 || $windowSeconds < 1 || $cost < 1) {
            throw new \InvalidArgumentException('a quota needs a positive limit, window and cost');
        }
        $now = time();
        $admitted = false;
        $resetAt = $now + $windowSeconds;
        $charged = $now;
        $this->mutateJsonFile(
            $this->rateLimitPath($bucket),
            ['window_start' => 0, 'count' => 0],
            static function (array $state) use ($now, $limit, $windowSeconds, $cost, &$admitted, &$resetAt, &$charged): array {
                [$windowStart, $count] = self::quotaState($state);
                if ($windowStart <= 0 || ($now - $windowStart) >= $windowSeconds || $windowStart > $now) {
                    $windowStart = $now;
                    $count = 0;
                }
                $resetAt = $windowStart + $windowSeconds;
                $charged = $windowStart;
                if ($count + $cost <= $limit) {
                    $count += $cost;
                    $admitted = true;
                }

                return ['window_start' => $windowStart, 'count' => $count, 'updated_at' => gmdate('c')];
            },
            true
        );

        return ['retry_after' => $admitted ? null : max(1, $resetAt - $now), 'window_start' => $charged];
    }

    /**
     * Give back $cost units that reserveQuotaWindow() charged to the window
     * starting at $windowStart: a request that was admitted and then did not
     * do the work it paid for (it failed, or what it carried was already
     * stored). Under the same exclusive lock and as strict as the charge —
     * a bucket that cannot be read or written throws, and is never
     * overwritten as though it held nothing. It never takes a count below
     * zero, and it refunds nothing once that window has closed: the next
     * window never held the charge, and crediting it would let a failure
     * buy the next minute more than the cap.
     *
     * @return bool whether the units went back (false: the window had already closed)
     * @throws \RuntimeException when the quota cannot be read or written
     */
    #[\Override]
    public function refundQuota(string $bucket, int $windowSeconds, int $cost, int $windowStart): bool
    {
        if ($windowSeconds < 1 || $cost < 1 || $windowStart < 1) {
            throw new \InvalidArgumentException('a refund needs a positive window, cost and window start');
        }
        $refunded = false;
        $path = $this->rateLimitPath($bucket);
        $this->mutateJsonFile(
            $path,
            ['window_start' => 0, 'count' => 0],
            static function (array $state) use ($windowStart, $cost, &$refunded): array {
                [$current, $count] = self::quotaState($state);
                if ($current !== $windowStart) {
                    return $state;
                }
                $refunded = true;

                return ['window_start' => $current, 'count' => max(0, $count - $cost), 'updated_at' => gmdate('c')];
            },
            true
        );

        return $refunded;
    }

    /**
     * A quota bucket's window start and count, or a throw: a malformed
     * bucket is never read as "nothing used" (CLAUDE.md #11).
     *
     * @param array<string, mixed> $state
     * @return array{0: int, 1: int}
     */
    private static function quotaState(array $state): array
    {
        $windowStart = $state['window_start'] ?? 0;
        $count = $state['count'] ?? 0;
        if (!is_int($windowStart) || !is_int($count) || $count < 0) {
            throw new DatabaseException('Quota state is malformed');
        }

        return [$windowStart, $count];
    }

    /** @return array{allowed: bool, remaining: int, reset_at: int} */
    public function consumeRateLimit(string $bucket, int $maxPerWindow, int $windowSeconds): array
    {
        $path = $this->rateLimitPath($bucket);
        $now = time();

        // The increment runs under mutateJsonFile's exclusive lock, not as a
        // bare read-then-write: concurrent callers would otherwise all read
        // the same count and each write count+1, so a burst of N requests
        // advanced the window by 1. A limiter that under-counts under
        // concurrency fails in exactly the situation it exists for, and this
        // one now fronts two unauthenticated endpoints.
        $windowStart = 0;
        $count = 0;
        $this->mutateJsonFile(
            $path,
            ['window_start' => 0, 'count' => 0],
            static function (array $state) use ($now, $windowSeconds, &$windowStart, &$count): array {
                $windowStart = (int)($state['window_start'] ?? 0);
                $count = (int)($state['count'] ?? 0);
                if ($windowStart <= 0 || ($now - $windowStart) >= $windowSeconds) {
                    $windowStart = $now;
                    $count = 0;
                }
                $count++;
                return [
                    'window_start' => $windowStart,
                    'count' => $count,
                    'updated_at' => gmdate('c'),
                ];
            }
        );

        return [
            'allowed' => $count <= $maxPerWindow,
            'remaining' => max(0, $maxPerWindow - $count),
            'reset_at' => $windowStart + $windowSeconds,
        ];
    }

    private function resolveDefaultBaseDir(?string $instanceIdentity = null): string
    {
        $env = getenv('P202_SERVER_STATE_DIR');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        // The temp dir is machine-wide, so the default is scoped by instance
        // identity (DB host + name): without this, two instances served on
        // one host would share idempotency replays, staged changes, sync
        // jobs, and rate-limit buckets across databases. Deployments that
        // want a specific location (e.g. a persistent volume) set
        // P202_SERVER_STATE_DIR explicitly, which wins above.
        $legacy = rtrim((string)sys_get_temp_dir(), '/') . '/p202-api-v3-state';

        $identity = $instanceIdentity !== null ? trim($instanceIdentity) : null;
        if ($identity === null || $identity === '') {
            // Falling back to the globals keeps the six existing call sites
            // working, but nothing enforces that they loaded configuration at
            // file scope. A caller that did not gets the unscoped path and
            // then reads and writes a completely different staged-change,
            // idempotency and rate-limit store than the web tier — with no
            // error at all. Say so once per process rather than diverging in
            // silence (error pattern #3).
            //
            // The unscoped path is NOT $legacy. It was, and $legacy is what a
            // new instance with no directory of its own used to adopt (it no
            // longer adopts anything, below): every process without an
            // identity (a test run, a script that loads the configuration
            // inside a function) kept writing the directory the next instance
            // installed on the host took as its own — its idempotency records
            // replayed to that instance's users, its staged changes listed for
            // them to apply. Measured: an identity-less store's create
            // replayed, and its staged DELETE listed, in a brand-new instance
            // on another database.
            global $dbname, $dbhost;
            if (!is_string($dbname) || trim($dbname) === '') {
                $unscoped = $legacy . '-unscoped';
                static $warned = false;
                if (!$warned) {
                    $warned = true;
                    error_log(
                        'p202: no database identity in scope when resolving the v3 API state directory; '
                        . 'using the unscoped path ' . $unscoped . '. A process that reaches this reads '
                        . 'different state than the web tier — load 202-config.php at file scope, or pass '
                        . 'the identity to ServerStateStore, or set P202_SERVER_STATE_DIR.'
                    );
                }
                return self::privateTempDirectory($unscoped);
            }
            $identity = (is_string($dbhost) ? $dbhost : '') . '|' . $dbname;
        }
        $scoped = $legacy . '-' . substr(sha1($identity), 0, 12);

        // The pre-scoping directory is never adopted. This renamed it into
        // place for an instance with no directory of its own, so in-flight
        // staged changes and sync jobs would survive the upgrade. But nothing
        // in it says whose it is: every install on the host wrote it before
        // the scoping, one still on an older version still does, and in a
        // temp dir anyone can create it. What it holds decides things — an
        // Idempotency-Key recorded there replayed another install's response
        // to this one's caller and executed nothing; a staged change recorded
        // there was listed for this install's users to apply, against this
        // install's database (measured: both, in a brand-new instance on
        // another database). State that cannot be attributed must not
        // resolve to "ours" (CLAUDE.md #11), so it is left where it is and
        // the log says so once a process, naming the way an operator who
        // knows it is theirs carries it over.
        if (!is_dir($scoped) && is_dir($legacy)) {
            static $legacyNoted = [];
            if (!isset($legacyNoted[$scoped])) {
                $legacyNoted[$scoped] = true;
                error_log(sprintf(
                    'p202: the shared API state directory %s is not adopted: nothing in it says which install'
                    . ' wrote it. This instance uses %s. If it holds this install\'s own staged changes and'
                    . ' sync jobs (an upgrade from before 1.9.75), move it there before the next request, or'
                    . ' set P202_SERVER_STATE_DIR to it.',
                    $legacy,
                    $scoped
                ));
            }
        }

        // Not covered, by design of the key: an instance reinstalled into a
        // database of the same name on the same host has the same identity,
        // and so takes over the state its predecessor left (an idempotency
        // key replays the old install's response; its staged changes list).
        // Telling the two installs apart needs something the reinstall
        // changes (202_users.install_hash), read from the database on every
        // construction; until then, a reinstall should set
        // P202_SERVER_STATE_DIR or remove the directory this resolves to.
        return self::privateTempDirectory($scoped);
    }

    /**
     * How many numbered alternatives (`<dir>.1` ...) privateTempDirectory()
     * tries after the directory it was asked for.
     */
    private const PRIVATE_DIR_ALTERNATIVES = 3;

    /**
     * $preferred, a directory in the machine-wide temp dir, if this process
     * can trust it: made here, or already there as a real directory (not a
     * symbolic link) owned by this process's effective user that neither its
     * group nor anyone else can write. Otherwise the first numbered
     * alternative that is, made if absent; the refusal is logged once a
     * process (a request, under a web server), naming the directory and why.
     *
     * The path is predictable -- a hash of the database host and name -- and
     * the temp dir is anyone's, so another local user could make it first and
     * fill it: an idempotency record there replayed its response to this
     * install's caller and created nothing, and a staged change there was
     * listed for this install's users to apply against this database
     * (measured on a live instance: a POST /aff-networks answered 201 with
     * the planted body, and GET /staged-changes listed the planted DELETE).
     * Its owner could also read every response this install recorded there.
     *
     * A process running as root never takes an alternative: it cannot tell
     * the web server's user, who owns the store the web tier writes, from
     * anyone else, and a directory of its own would be a store the web tier
     * never reads. It refuses instead, saying to run as the web server's
     * user. When every candidate is refused the store refuses to start;
     * P202_SERVER_STATE_DIR names a directory the operator vouches for, and
     * is used as named.
     */
    private static function privateTempDirectory(string $preferred): string
    {
        $euid = self::effectiveUid();
        $refused = [];
        for ($n = 0; $n <= self::PRIVATE_DIR_ALTERNATIVES; $n++) {
            $candidate = $n === 0 ? $preferred : $preferred . '.' . $n;
            if (@lstat($candidate) === false) {
                // Nothing there: make it ours. A mkdir that loses a race to
                // someone else's falls through to the same checks.
                @mkdir($candidate, 0700);
            }
            $stat = @lstat($candidate);
            $why = self::untrustedBecause($stat, $euid);
            if ($why === null) {
                if ($refused !== []) {
                    self::logRefusedOnce($preferred, $refused, $candidate);
                }
                return $candidate;
            }
            $refused[$candidate] = $why;
            if ($euid === 0 && is_array($stat) && (int) $stat['uid'] !== 0) {
                break;
            }
        }

        $list = implode('; ', array_map(
            static fn (string $dir, string $why): string => $dir . ' (' . $why . ')',
            array_keys($refused),
            $refused
        ));
        $remedy = $euid === 0
            ? 'This process runs as root: if the owner is the web server\'s user, run it as that user;'
                . ' if not, remove the directory. Or set P202_SERVER_STATE_DIR.'
            : 'Remove the directories that are not this install\'s, or set P202_SERVER_STATE_DIR to a directory'
                . ' only this server\'s user can write.';
        error_log('p202: no API state directory this process can trust: ' . $list . '. ' . $remedy);

        throw new DatabaseException('No API state directory this process can trust: ' . $list . '. ' . $remedy);
    }

    /**
     * Why a directory's lstat() says it is not this user's alone, or null
     * when it is.
     *
     * @param array<int|string, int>|false $stat
     */
    private static function untrustedBecause(array|false $stat, int $euid): ?string
    {
        if ($stat === false) {
            return 'it could not be created';
        }
        $type = $stat['mode'] & 0170000;
        if ($type === 0120000) {
            return 'it is a symbolic link';
        }
        if ($type !== 0040000) {
            return 'it is not a directory';
        }
        if ((int) $stat['uid'] !== $euid) {
            return 'it is owned by uid ' . $stat['uid'] . ', not this process\'s uid ' . $euid;
        }
        if (($stat['mode'] & 0022) !== 0) {
            return sprintf('its group or others can write it (mode %04o)', $stat['mode'] & 07777);
        }

        return null;
    }

    /**
     * @param array<string, string> $refused directory => why
     */
    private static function logRefusedOnce(string $preferred, array $refused, string $used): void
    {
        static $logged = [];
        if (isset($logged[$preferred])) {
            return;
        }
        $logged[$preferred] = true;
        foreach ($refused as $dir => $why) {
            error_log(sprintf(
                'p202: the API state directory %s is not used: %s, so another user may have made it or may'
                . ' change what is in it. This process uses %s. Remove %s if it is not this install\'s, or set'
                . ' P202_SERVER_STATE_DIR.',
                $dir,
                $why,
                $used,
                $dir
            ));
        }
    }

    /**
     * This process's effective uid: posix_geteuid(), else the owner of a
     * file it has just made (getmyuid() is the owner of the script, which
     * is not the same thing).
     */
    private static function effectiveUid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        static $uid = null;
        if ($uid === null) {
            $probe = @tempnam(sys_get_temp_dir(), 'p202-uid-');
            $owner = is_string($probe) ? @fileowner($probe) : false;
            if (is_string($probe)) {
                @unlink($probe);
            }
            if ($owner === false) {
                throw new DatabaseException(
                    'Cannot tell which user this process runs as, so no API state directory in the temp dir'
                    . ' can be trusted: set P202_SERVER_STATE_DIR.'
                );
            }
            $uid = $owner;
        }

        return $uid;
    }

    /**
     * Records are sharded by the Idempotency-Key, not by the request body:
     * a scope now covers a whole caller+operation, and without a shard every
     * keyed create for that caller would read and rewrite one growing file
     * under a lock. The shard depends on the key alone, so the same key
     * always resolves to the same file whatever the body — which is what
     * makes a changed body detectable instead of invisible.
     */
    private function idempotencyPath(string $scope, string $key): string
    {
        return $this->dir('idempotency') . '/' . sha1($scope) . '-' . substr(sha1($key), 0, 2) . '.json';
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

    private function pruneTokensPath(): string
    {
        return $this->dir('tokens') . '/prune.json';
    }

    private function spansPath(): string
    {
        return $this->dir('traces') . '/spans.json';
    }

    /**
     * Bucket file for one rate-limit key. slug() is not injective — it
     * collapses every run of characters outside [a-z0-9._-] to a single '-'
     * — so `<prefix>:2001:db8::1` and `<prefix>:2001:db8:1::`, both forms
     * REMOTE_ADDR really produces, named the same file and shared one
     * ceiling: a flooder's 429 was answered to an unrelated peer. The
     * readable slug stays for whoever reads the directory; a short digest of
     * the RAW key is what makes the name unique.
     */
    private function rateLimitPath(string $bucket): string
    {
        $slug = $this->slug($bucket);
        if ($slug === '') {
            // A key with no slug-safe characters at all would otherwise
            // produce a filename starting with '-'.
            $slug = 'bucket';
        }
        return $this->dir('rate_limits') . '/' . $slug . '-' . substr(hash('sha256', $bucket), 0, 12) . '.json';
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

    /**
     * Read-modify-write a JSON state file under an exclusive lock so concurrent callers
     * cannot lose each other's updates (the bare read-then-write pattern drops feed
     * entries and duplicates sequence numbers under concurrency).
     *
     * @param array<string, mixed> $default
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutateJsonFile(string $path, array $default, callable $mutator, bool $strict = false): void
    {
        $this->ensureDir(dirname($path));
        $lockPath = $path . '.lock';
        $lh = fopen($lockPath, 'c+');
        if ($lh === false) {
            throw new DatabaseException('Unable to open state lock file');
        }
        if (!flock($lh, LOCK_EX)) {
            fclose($lh);
            throw new DatabaseException('Unable to acquire state lock');
        }

        try {
            $data = $this->readJsonFile($path, $default, $strict);
            $data = $mutator($data);
            $this->writeJsonFileAtomic($path, $data);
        } finally {
            flock($lh, LOCK_UN);
            fclose($lh);
        }
    }

    /**
     * A state file's contents, or $default when there is none. Strict, an
     * unreadable or undecodable file throws instead of reading as the
     * default: for a quota the default is "nothing used yet", the most
     * permissive answer there is (CLAUDE.md #11).
     */
    private function readJsonFile(string $path, array $default, bool $strict = false): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            if ($strict) {
                throw new DatabaseException('State file ' . basename($path) . ' could not be read');
            }
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if ($strict) {
                throw new DatabaseException('State file ' . basename($path) . ' is not a JSON object');
            }
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

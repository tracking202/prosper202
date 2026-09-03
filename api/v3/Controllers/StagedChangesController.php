<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;

/**
 * Staged writes: the model (or any caller) proposes an operator-surface
 * mutation; a person applies or discards it. Modeled on the staged-change
 * ledger in Anthropic's commerce-agents reference: server-issued change ids,
 * guardrails re-checked at apply time (here, the write handler re-runs in
 * full against current state and the applier's authorization), an
 * actor-stamped audit trail, and expiry so stale proposals cannot fire.
 *
 * The controller owns record lifecycle and authorization; the router owns
 * dispatch, so apply() takes the dispatcher as a callable.
 */
final class StagedChangesController
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISCARDED = 'discarded';

    public const DEFAULT_TTL_SECONDS = 86400;

    /**
     * A change claimed for apply is re-claimable after this long. Without it
     * a process killed mid-dispatch (fatal, execution timeout, worker kill)
     * strands the change in `applying` forever: apply and discard both
     * require `staged`, so nothing could move it again.
     */
    public const STALE_APPLYING_SECONDS = 900;

    /**
     * Payload keys whose values are secrets. A staged change is persisted as
     * JSON and shown to every reviewer, so these must never be recorded;
     * staging such a write is refused rather than silently redacted, since a
     * redacted proposal could not be applied faithfully.
     */
    private const SECRET_PAYLOAD_KEYS = [
        'user_pass', 'verify_user_pass', 'password', 'passwd', 'pass',
        'api_key', 'apikey', 'secret', 'token', 'private_key',
    ];

    public function __construct(
        private readonly ServerStateStore $store,
        private readonly Auth $auth,
    ) {
    }

    public static function ttlSeconds(): int
    {
        $raw = getenv('P202_STAGED_CHANGE_TTL_SECONDS');
        if (is_string($raw) && trim($raw) !== '') {
            $parsed = (int)$raw;
            if ($parsed >= 60) {
                return $parsed;
            }
        }
        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * Record a proposed write. $preview is the delete dry-run preview when
     * the operation is a DELETE with one available, else null; for creates
     * and updates the payload itself is the preview.
     */
    public function stage(string $method, string $path, array $payload, ?array $preview): array
    {
        $secrets = self::secretKeysIn($payload);
        if ($secrets !== []) {
            throw new ValidationException('This write cannot be staged because it carries a secret', [
                'staged' => 'Staged changes are stored as JSON and shown to every reviewer, so '
                    . implode(', ', $secrets) . ' cannot be recorded. Perform this write directly '
                    . '(without staged), then stage follow-up changes that carry no secrets.',
            ]);
        }

        $now = time();
        $change = [
            'change_id' => 'chg_' . bin2hex(random_bytes(12)),
            'status' => self::STATUS_STAGED,
            'method' => strtoupper($method),
            'path' => $path,
            'payload' => $payload,
            'resource_area' => Auth::scopeAreaForPath($path),
            'summary' => strtoupper($method) . ' ' . $path,
            'preview' => $preview,
            'created_at' => gmdate('c'),
            'created_at_epoch' => $now,
            'created_by' => $this->auth->userId(),
            'expires_at_epoch' => $now + self::ttlSeconds(),
            'applied_at' => null,
            'applied_by' => null,
            'discarded_at' => null,
            'discarded_by' => null,
            'last_error' => null,
        ];

        $this->store->stageWriteChange($this->auth->userId(), $change);

        return [
            'data' => $this->present($change),
            'hint' => 'Apply with POST /staged-changes/' . $change['change_id'] . '/apply, '
                . 'or discard with POST /staged-changes/' . $change['change_id'] . '/discard. '
                . 'The write re-validates against current state when applied.',
        ];
    }

    public function list(array $params): array
    {
        $all = strtolower(trim((string)($params['all'] ?? ''))) === '1';
        if ($all) {
            $this->auth->requireAdmin();
            $items = $this->store->listStagedChangesAllUsers();
        } else {
            $items = $this->store->listStagedChangesForUser($this->auth->userId());
        }

        $status = strtolower(trim((string)($params['status'] ?? '')));
        if ($status !== '') {
            $items = array_values(array_filter(
                $items,
                fn(array $c): bool => ($c['status'] ?? '') === $status
            ));
        }

        return ['data' => array_map($this->present(...), $items)];
    }

    public function get(string $changeId): array
    {
        return ['data' => $this->present($this->requireVisibleChange($changeId))];
    }

    /**
     * Apply a staged change: transition staged→applying atomically (so two
     * concurrent applies cannot both fire), re-dispatch the recorded write
     * through the router via $dispatch — which re-runs validation, route
     * authorization, and guardrails against *current* state and the
     * *applier's* credentials — then record the outcome. A failed dispatch
     * returns the change to staged with the error recorded, so it can be
     * corrected or discarded rather than silently lost.
     *
     * @param callable(string, string, array, int): mixed $dispatch
     *        Receives (method, path, recorded payload, proposer user id).
     *        The write is performed as the proposer so it lands in the
     *        account that proposed it; authorization still runs against the
     *        applier inside the dispatched route.
     */
    public function apply(string $changeId, callable $dispatch): array
    {
        $change = $this->requireVisibleChange($changeId);
        $ownerId = (int)$change['created_by'];

        if (!self::isClaimable($change)) {
            throw new ConflictException(sprintf(
                "Change %s is %s, not staged — nothing to apply.",
                $changeId,
                (string)($change['status'] ?? 'unknown')
            ));
        }
        if (time() > (int)($change['expires_at_epoch'] ?? 0)) {
            throw new ConflictException(sprintf(
                'Change %s expired at %s — stage it again to retry.',
                $changeId,
                gmdate('c', (int)($change['expires_at_epoch'] ?? 0))
            ));
        }

        // The applier needs write access to the area the change touches;
        // a propose-only (`stage`) key cannot apply its own proposals.
        $area = (string)($change['resource_area'] ?? '');
        if ($area !== '') {
            $this->auth->requireScope($area . ':write');
        }

        // Status and expiry are re-checked inside the locked transition so a
        // concurrent apply/discard or an expiry crossing between the read
        // above and this claim cannot slip through.
        $claimed = $this->store->updateStagedChange($ownerId, $changeId, function (array $current): ?array {
            if (!self::isClaimable($current)) {
                return null; // someone else applied or discarded it first
            }
            if (time() > (int)($current['expires_at_epoch'] ?? 0)) {
                return null;
            }
            $current['status'] = self::STATUS_APPLYING;
            $current['applying_since'] = time();
            return $current;
        });
        if ($claimed === null) {
            throw new ConflictException("Change $changeId expired or was applied or discarded concurrently.");
        }

        // A stored payload that is not an array means the record is corrupt.
        // Applying it as an empty body would execute a different write than
        // the one that was reviewed, so fail loudly instead.
        $storedPayload = $change['payload'] ?? null;
        if (!is_array($storedPayload)) {
            $this->store->updateStagedChange($ownerId, $changeId, function (array $current): array {
                $current['status'] = self::STATUS_STAGED;
                $current['last_error'] = 'stored payload is malformed';
                return $current;
            });
            throw new ConflictException(sprintf(
                'Change %s has a malformed stored payload and cannot be applied — discard it and stage the write again.',
                $changeId
            ));
        }

        try {
            $response = $dispatch(
                (string)$change['method'],
                (string)$change['path'],
                $storedPayload,
                $ownerId
            );
        } catch (\Throwable $e) {
            $this->store->updateStagedChange($ownerId, $changeId, function (array $current) use ($e): array {
                $current['status'] = self::STATUS_STAGED;
                $current['last_error'] = $e->getMessage();
                return $current;
            });
            throw $e;
        }

        $applied = $this->store->updateStagedChange($ownerId, $changeId, function (array $current): array {
            $current['status'] = self::STATUS_APPLIED;
            $current['applied_at'] = gmdate('c');
            $current['applied_by'] = $this->auth->userId();
            $current['last_error'] = null;
            return $current;
        });

        $result = null;
        if (is_array($response)) {
            $result = $response['data'] ?? null;
        }

        return [
            'data' => [
                'change' => $this->present($applied ?? $change),
                'result' => $result,
            ],
        ];
    }

    public function discard(string $changeId): array
    {
        $change = $this->requireVisibleChange($changeId);
        $ownerId = (int)$change['created_by'];

        $updated = $this->store->updateStagedChange($ownerId, $changeId, function (array $current): ?array {
            if (!self::isClaimable($current)) {
                return null;
            }
            $current['status'] = self::STATUS_DISCARDED;
            $current['discarded_at'] = gmdate('c');
            $current['discarded_by'] = $this->auth->userId();
            return $current;
        });
        if ($updated === null) {
            throw new ConflictException(sprintf(
                "Change %s is %s, not staged — nothing to discard.",
                $changeId,
                (string)($change['status'] ?? 'unknown')
            ));
        }

        return ['data' => $this->present($updated)];
    }

    /**
     * The change, if the caller may see it: the staging user sees their own,
     * admins see everyone's.
     */
    private function requireVisibleChange(string $changeId): array
    {
        $changeId = trim($changeId);
        if ($changeId === '' || !preg_match('/^chg_[0-9a-f]{24}$/', $changeId)) {
            throw new ValidationException('Invalid change id', [
                'change_id' => 'Change ids look like chg_<24 hex chars> and come from staging a write or GET /staged-changes.',
            ]);
        }

        $own = $this->store->getStagedChangeForUser($this->auth->userId(), $changeId);
        if ($own !== null) {
            return $own;
        }
        if ($this->auth->isAdmin()) {
            $any = $this->store->findStagedChange($changeId);
            if ($any !== null) {
                return $any;
            }
        } elseif ($this->store->findStagedChange($changeId) !== null) {
            throw new AuthException('You can only access your own staged changes.', 403);
        }
        throw new NotFoundException('Staged change not found');
    }

    /**
     * Payload keys that name a secret, in the order given. stage() refuses
     * such writes; present() redacts as defense in depth for records written
     * by an older build.
     */
    /**
     * A change may be claimed for apply or discard when it is still staged,
     * or when a previous apply claimed it and died: without the second case
     * a process killed mid-dispatch strands the change forever, since both
     * transitions otherwise require `staged`.
     */
    private static function isClaimable(array $change): bool
    {
        $status = (string)($change['status'] ?? '');
        if ($status === self::STATUS_STAGED) {
            return true;
        }
        return $status === self::STATUS_APPLYING
            && time() - (int)($change['applying_since'] ?? 0) > self::STALE_APPLYING_SECONDS;
    }

    private static function secretKeysIn(array $payload): array
    {
        $found = [];
        foreach (array_keys($payload) as $key) {
            if (in_array(strtolower(trim((string)$key)), self::SECRET_PAYLOAD_KEYS, true)) {
                $found[] = (string)$key;
            }
        }
        return $found;
    }

    /** Adds the derived expiry flag; never mutates the stored record. */
    private function present(array $change): array
    {
        $change['expired'] = ($change['status'] ?? '') === self::STATUS_STAGED
            && time() > (int)($change['expires_at_epoch'] ?? 0);
        if (is_array($change['payload'] ?? null)) {
            foreach (self::secretKeysIn($change['payload']) as $key) {
                $change['payload'][$key] = '[redacted]';
            }
        }
        return $change;
    }
}

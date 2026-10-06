<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\RequestContext;

/**
 * Single-create idempotency: when a POST create carries an Idempotency-Key
 * header, the response is recorded and replayed on a retry with the same
 * key, so a retried create cannot duplicate the row. Which endpoint was
 * called and what body it carried are a fingerprint stored beside the
 * record, not part of the storage scope: reusing a key for anything else is
 * refused (422), where a scope that varied with the request would have filed
 * it separately and created a second row — the duplicate the key was sent
 * to prevent. Replays carry idempotent_replay: true. API-key creation is
 * deliberately not wrapped — its response contains the secret, which must
 * never persist in the server-state store.
 *
 * Whether a failed create spends its key is decided by what the handler
 * threw: WriteCommittedException (the row exists) spends it, anything else
 * (nothing was written) frees it for a corrected retry.
 */
final class IdempotentCreate
{
    public function __construct(
        private readonly ServerStateStore $stateStore,
        private readonly int $actorUserId,
    ) {
    }

    /**
     * @param array<string, mixed> $requestPayload
     * @param callable(): array<string, mixed> $op
     * @return array<string, mixed>
     */
    public function __invoke(string $operation, array $requestPayload, callable $op): array
    {
        $key = trim((string)(RequestContext::header('idempotency-key') ?? ''));
        if ($key === '') {
            return $op();
        }
        $scope = ServerStateStore::idempotencyScopeForUser($this->actorUserId);
        $fingerprint = ServerStateStore::idempotencyFingerprint('create:' . $operation, $requestPayload);
        // Claim the key atomically: a plain read-then-write left a
        // window where two concurrent retries both missed the record and
        // both created a row.
        $reservation = $this->stateStore->reserveIdempotent($scope, $key, $fingerprint);
        if ($reservation['state'] === 'mismatch') {
            throw new ValidationException(
                'This Idempotency-Key was already used for a different request. Resend the original '
                . 'request — same endpoint, same body — to replay its recorded response, or send a '
                . 'new Idempotency-Key to create something different.',
                ['idempotency_key' => 'Already used for a different request']
            );
        }
        if ($reservation['state'] === 'replay') {
            $existing = $reservation['response'] ?? [];
            $existing['idempotent_replay'] = true;
            return $existing;
        }
        if ($reservation['state'] === 'in_flight') {
            throw new ConflictException(
                'A request with this Idempotency-Key is still in flight; retry once it completes '
                . 'to receive the recorded response.'
            );
        }
        if ($reservation['state'] === 'indeterminate') {
            // A previous holder died without recording a response, so
            // whether it created the record is unknowable. Replaying is
            // impossible and re-executing could duplicate exactly what
            // the key exists to prevent, so the key stays spent.
            throw new ConflictException(
                'A previous request with this Idempotency-Key did not finish, so whether it created '
                . 'the record is unknown and this key can be neither replayed nor reused. Check '
                . 'whether the record exists, then retry with a new Idempotency-Key if it does not.'
            );
        }
        try {
            $response = $op();
        } catch (WriteCommittedException $e) {
            // The row exists; only the steps after it failed. Releasing
            // the claim would invite a retry that creates a second row,
            // so the key is marked spent — from the very next retry, not
            // once the claim ages out.
            $this->stateStore->markIdempotentIndeterminate($scope, $key);
            throw $e;
        } catch (\Throwable $e) {
            // Nothing was written, so free the key: the caller can
            // correct and retry rather than being told a request is in
            // flight.
            $this->stateStore->releaseIdempotent($scope, $key);
            throw $e;
        }
        $this->stateStore->putIdempotent($scope, $key, $response, $fingerprint);
        return $response;
    }
}

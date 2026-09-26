<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

use Prosper202\Database\Connection;

/**
 * Installs whose Play Integrity verdict can never be had because their
 * registration is gone, settled to the states the deadline would have left
 * them in — terminal, and never paid:
 *
 *   integrity_state  pending            → error  (integrity_next_at NULL)
 *   match_state      pending_integrity  → integrity_unverified (unvouched;
 *                                          trust, click and conversion stay
 *                                          NULL, as the gate leaves them)
 *
 * Three callers:
 *
 *  - deleting a registration (AppRegistrationsController::beforeDelete),
 *    inside the delete's own transaction, so the registration, its
 *    credential and its queue go together: the credential is deleted there,
 *    and the verdict worker reads installs only through their registration,
 *    so a queue left behind could never be decoded or settled;
 *  - the verdict worker, before it selects (IntegrityVerifier::run()), for
 *    installs whose registration disappeared some other way — a row deleted
 *    by hand, or a delete made before this class existed. Its selection
 *    joins the registration as well, so such a row can never occupy a slot
 *    in the oldest-first batch and starve every other app's verdicts;
 *  - the verdict worker again, for one install past its deadline or its
 *    attempts whose normal settlement keeps failing
 *    (IntegrityVerifier::retire(), settleExhausted()): the one path that
 *    must end without a key or a readable body.
 *
 * The goal engine is not run for these installs: the registration's goals
 * are archived with it (or, for an exhausted install, its settlement is
 * what failed), and nothing about an unverified install is payable.
 * The user purge deletes installs outright (AppDataPurge), so it leaves
 * nothing for this class.
 *
 * Only these two constant transitions are written here; the match state of
 * every other install moves through InstallIntake::settle() alone
 * (AttributedInstallHasConversionTest pins both).
 */
final class UnverifiableInstalls
{
    public const DELETED_REASON = 'The app registration was deleted before a Play Integrity verdict was obtained; the token was not decoded.';
    public const ORPHANED_REASON = 'The install\'s app registration no longer exists, so no Play Integrity verdict can be obtained; the token was not decoded.';

    private const ORPHAN_SCOPE = 'NOT EXISTS (SELECT 1 FROM 202_app_registrations r WHERE r.registration_id = 202_app_installs.registration_id)';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Settle the queue of a registration being deleted. Runs inside the
     * caller's transaction and opens none (a nested begin would commit the
     * caller's). Returns how many installs were settled.
     */
    public function settleForDeletedRegistration(int $userId, int $registrationId, int $now): int
    {
        return $this->retire('registration_id = ? AND user_id = ?', 'ii', [$registrationId, $userId], self::DELETED_REASON, $now);
    }

    /**
     * Settle installs whose registration no longer exists, up to $limit a
     * call (the worker runs every minute, so a backlog drains), in one
     * transaction. Returns how many.
     *
     * Found first by a plain read, so the usual answer — none — takes no
     * lock: an UPDATE scanning the queue would hold next-key locks on the
     * integrity_due range, and every intake INSERT of a pending install
     * would wait on it each minute. The updates then name the rows found,
     * and still re-check both the pending state and the missing
     * registration, so a row that changed in between is left alone.
     */
    public function settleOrphans(int $now, int $limit = 500): int
    {
        $find = $this->conn->prepareWrite(
            "SELECT install_row_id FROM 202_app_installs WHERE integrity_state = 'pending' AND " . self::ORPHAN_SCOPE . ' LIMIT ?'
        );
        $this->conn->bind($find, 'i', [max(1, $limit)]);
        $ids = array_map(static fn (array $r): int => (int) $r['install_row_id'], $this->conn->fetchAll($find));
        if ($ids === []) {
            return 0;
        }
        $scope = 'install_row_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') AND ' . self::ORPHAN_SCOPE;

        return (int) $this->conn->transaction(
            fn (): int => $this->retire($scope, str_repeat('i', count($ids)), $ids, self::ORPHANED_REASON, $now)
        );
    }

    /**
     * Retire one install the verdict worker has run out of time or
     * attempts for and cannot settle normally (its settle keeps failing):
     * the same two constant transitions, scoped to that install at the
     * attempt the worker claimed, so a newer claim is left alone. Opens its
     * own transaction. Returns how many rows changed.
     */
    public function settleExhausted(int $installRowId, int $attempt, string $why, int $now): int
    {
        return (int) $this->conn->transaction(
            fn (): int => $this->retire('install_row_id = ? AND integrity_attempts = ?', 'ii', [$installRowId, $attempt], $why, $now)
        );
    }

    /** @param list<int> $values */
    private function retire(string $scope, string $types, array $values, string $why, int $now): int
    {
        // The match state first: the gate would have read `error` and
        // written integrity_unverified, and both statements select by the
        // pending state they end, so a rerun after a failure is harmless.
        $held = $this->conn->prepareWrite(
            "UPDATE 202_app_installs SET match_state = 'integrity_unverified', match_reason = ?, settled_at = ?
             WHERE match_state = 'pending_integrity' AND " . $scope
        );
        $this->conn->bind($held, 'si' . $types, ['This app requires Play Integrity and no verdict could be obtained: ' . $why, $now, ...$values]);
        $this->conn->executeUpdate($held);

        $queued = $this->conn->prepareWrite(
            "UPDATE 202_app_installs SET integrity_state = 'error', integrity_reason = ?, integrity_next_at = NULL
             WHERE integrity_state = 'pending' AND " . $scope
        );
        $this->conn->bind($queued, 's' . $types, [$why, ...$values]);

        return $this->conn->executeUpdate($queued);
    }
}

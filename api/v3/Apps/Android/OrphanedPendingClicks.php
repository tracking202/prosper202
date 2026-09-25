<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Prosper202\Database\Connection;

/**
 * Installs left `pending_click` whose registration is gone, settled to the
 * state the pending-click deadline leaves an install whose click never
 * appeared — terminal, refuted, never paid:
 *
 *   match_state  pending_click → bad_token (trusted 0; click and
 *                                conversion stay NULL, as a pending install
 *                                has neither)
 *
 * Nothing else can settle them: PendingClickSettler reads an install only
 * through its registration (there is no policy to settle it under without
 * one), retention never prunes a pending install, and no app token reaches
 * one any more. Left as they were they would stay pending for good.
 *
 * Two callers:
 *
 *  - deleting a registration (AppRegistrationsController::beforeDelete),
 *    inside the delete's own transaction, so the registration and its
 *    undecided installs go together;
 *  - the pending-click settler, before it selects (PendingClickSettler::
 *    run()), for installs whose registration disappeared some other way —
 *    a row deleted by hand, or a delete made before this class existed.
 *
 * The goal engine is not run for these installs: a pending install reached
 * no goal, and a refuted one never does. Bad-token rows are pruned by the
 * `installs/refuted` retention class. The user purge deletes installs
 * outright (AppDataPurge), so it leaves nothing for this class.
 *
 * Only this one constant transition is written here; the match state of
 * every other install moves through InstallIntake::settle()
 * (AttributedInstallHasConversionTest pins both).
 */
final class OrphanedPendingClicks
{
    public const DELETED_REASON = 'The app registration was deleted before the click this install\'s token names was recorded; it can no longer be settled.';
    public const ORPHANED_REASON = 'The install\'s app registration no longer exists, so the click its token names can no longer be settled against it.';

    private const ORPHAN_SCOPE = 'NOT EXISTS (SELECT 1 FROM 202_app_registrations r WHERE r.registration_id = 202_app_installs.registration_id)';

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * Settle the pending clicks of a registration being deleted. Runs inside
     * the caller's transaction and opens none (a nested begin would commit
     * the caller's). Returns how many installs were settled.
     */
    public function settleForDeletedRegistration(int $userId, int $registrationId, int $now): int
    {
        return $this->retire('registration_id = ? AND user_id = ?', 'ii', [$registrationId, $userId], self::DELETED_REASON, $now);
    }

    /**
     * Settle pending clicks whose registration no longer exists, up to
     * $limit a call, in one transaction. Returns how many.
     *
     * Found first by a plain read, so the usual answer — none — takes no
     * lock; the update then names the rows found and still re-checks both
     * the pending state and the missing registration, so a row that changed
     * in between is left alone.
     */
    public function settleOrphans(int $now, int $limit = 500): int
    {
        $find = $this->conn->prepareWrite(
            "SELECT install_row_id FROM 202_app_installs WHERE match_state = 'pending_click' AND " . self::ORPHAN_SCOPE . ' LIMIT ?'
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

    /** @param list<int> $values */
    private function retire(string $scope, string $types, array $values, string $why, int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_app_installs SET match_state = 'bad_token', match_reason = ?, trusted = 0, settled_at = ?
             WHERE match_state = 'pending_click' AND " . $scope
        );
        $this->conn->bind($stmt, 'si' . $types, [$why, $now, ...$values]);

        return $this->conn->executeUpdate($stmt);
    }
}

<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Apps\AppDataPurge;
use Prosper202\Attribution\ExportFiles;

/**
 * Deleting a user: the soft delete of the 202_users row and the purge of the
 * data that must not outlive it, committed together.
 *
 * Every delete path — Account › User Management, DELETE /api/v3/users/{id}
 * and MysqlUserRepository::softDelete() — goes through deleteUser(), so the
 * cascade is one list rather than three (CLAUDE.md #5; UserDeletionPurgeTest
 * refuses any other writer of user_deleted = 1). The cascade, table by table, and what later PRs add to
 * it, is written down in documentation/features/measurement-rewrite-plan.md
 * §7.2. In short:
 *
 *  - the MTA engine's per-user state (PR 9: models, journeys and their
 *    meta, credits, export jobs, audit, and the outbox rows of the user's
 *    conversions) is deleted; the export jobs' CSV files are removed from
 *    disk once the delete has committed (the rows are the only record of
 *    their names, so the names are read inside the transaction first);
 *  - the identity graph (PR 2) is deleted: the account's hashing and linking
 *    keys, its visitors, signals and merges, and the per-click observations
 *    and visitor keys of the user's clicks — the links that say which
 *    clicks were one person;
 *  - app measurement is purged by AppDataPurge: registrations, SKAN
 *    encodings and Android installs deleted, postbacks released to
 *    unclaimed, the campaigns linked to the registrations unlinked;
 *  - the traffic-source notification outbox rows of the user's conversions
 *    are deleted (PR 5): a deleted account's queued postbacks never go out;
 *  - the goals engine (PR 4) is deleted: goals and their versions, campaign
 *    payouts, and every subject's events, progress and outcomes — the
 *    ledger rows the outcomes wrote stay, with the clicks;
 *  - the user's REST API keys are revoked. Sessions already refuse a deleted
 *    user (functions-auth.php reads user_deleted); the API authenticates by
 *    key alone, so before this a deleted user's key kept working — and could
 *    register the very apps whose postbacks the purge had just released;
 *  - clicks and conversion rows are KEPT, as they always were: they are the
 *    account's traffic record and other reports aggregate them.
 *
 * All of it runs in one transaction, and any statement that fails rolls the
 * whole delete back and throws: a user marked deleted with half their data
 * still linked is the outcome this refuses.
 */
final class UserDataPurge
{
    /**
     * The MTA engine's per-user state (AttributionTables, plan §6.3–6.4),
     * children before parents: credits and journeys are keyed by conversion
     * and model, not user, so they are reached through the models and the
     * journey meta that name the user, before those go. The outbox rows of
     * the user's conversions go too, and the worker refuses a deleted user's
     * conversion, or it would rebuild the journeys (and a default model) the
     * purge just removed. Every AttributionTables table has a statement
     * here; UserDeletionPurgeTest holds the two lists equal.
     */
    public const MTA_STATEMENTS = [
        'DELETE cr FROM 202_attribution_credits cr JOIN 202_attribution_models m ON m.model_id = cr.model_id WHERE m.user_id = ?',
        'DELETE j FROM 202_attribution_journeys j JOIN 202_attribution_journey_meta jm ON jm.conv_id = j.conv_id WHERE jm.user_id = ?',
        'DELETE FROM 202_attribution_journey_meta WHERE user_id = ?',
        'DELETE p FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id WHERE c.user_id = ?',
        'DELETE FROM 202_attribution_exports WHERE user_id = ?',
        'DELETE FROM 202_attribution_models WHERE user_id = ?',
        'DELETE FROM 202_attribution_audit WHERE user_id = ?',
        // The report rollup (PR 13): sums of the rows above, and the marks
        // and overrides it keeps beside them.
        'DELETE FROM 202_attribution_rollup WHERE user_id = ?',
        'DELETE FROM 202_attribution_rollup_dirty WHERE user_id = ?',
        'DELETE FROM 202_attribution_rollup_dirty_clicks WHERE user_id = ?',
        'DELETE FROM 202_attribution_rollup_overrides WHERE user_id = ?',
        'DELETE FROM 202_attribution_rollup_state WHERE user_id = ?',
    ];

    /** The identity graph's per-user rows; observations first, while the clicks still name the user. */
    private const IDENTITY_STATEMENTS = [
        'DELETE o FROM 202_identity_observations o JOIN 202_clicks c ON c.click_id = o.click_id WHERE c.user_id = ?',
        'DELETE FROM 202_clicks_visitor WHERE user_id = ?',
        'DELETE FROM 202_identity_merges WHERE user_id = ?',
        'DELETE FROM 202_identity_signals WHERE user_id = ?',
        'DELETE FROM 202_identity_visitors WHERE user_id = ?',
        'DELETE FROM 202_identity_keys WHERE user_id = ?',
    ];

    /**
     * The goals engine's per-user rows (GoalTables): per-subject state before
     * the definitions it was evaluated against. Every GoalTables table has a
     * statement here; UserDeletionPurgeTest holds the two lists equal.
     */
    public const GOAL_STATEMENTS = [
        'DELETE FROM 202_goal_outcomes WHERE user_id = ?',
        'DELETE FROM 202_goal_progress WHERE user_id = ?',
        'DELETE FROM 202_goal_events WHERE user_id = ?',
        'DELETE FROM 202_goal_subjects WHERE user_id = ?',
        'DELETE FROM 202_campaign_goals WHERE user_id = ?',
        'DELETE v FROM 202_goal_versions v JOIN 202_goals g ON g.goal_id = v.goal_id WHERE g.user_id = ?',
        'DELETE FROM 202_goals WHERE user_id = ?',
    ];

    /** Queued traffic-source postbacks: a deleted account's never go out. */
    private const NOTIFICATION_STATEMENTS = [
        'DELETE FROM 202_notification_pending WHERE user_id = ?',
    ];

    /** What lets the user act at all through the API; revoked with the rest. */
    private const ACCESS_STATEMENTS = [
        'DELETE FROM 202_api_keys WHERE user_id = ?',
    ];

    public function __construct(
        private readonly \mysqli $db,
        private readonly ?ExportFiles $exportFiles = null
    ) {
    }

    /**
     * What deleteUser() does, table by table, for a delete preview
     * (`DELETE /users/{id}?dry_run=1`). Read from the same statement lists
     * and AppDataPurge::TABLE_ACTIONS that the delete runs, so the preview
     * cannot promise a different cascade from the one that happens.
     *
     * @return list<array{resource: string, action: string, where: string}>
     */
    public static function cascade(int $userId): array
    {
        $cascade = [];
        foreach (array_merge(self::ACCESS_STATEMENTS, self::NOTIFICATION_STATEMENTS, self::MTA_STATEMENTS, self::IDENTITY_STATEMENTS, self::GOAL_STATEMENTS) as $sql) {
            if (preg_match('/^DELETE (?:\w+ )?FROM (\w+)/', $sql, $m) !== 1) {
                throw new \LogicException('Unreadable purge statement: ' . $sql);
            }
            $cascade[] = ['resource' => $m[1], 'action' => 'delete', 'where' => 'belongs to user ' . $userId];
        }
        $cascade[] = [
            'resource' => 'attribution export files',
            'action' => 'removed from disk once the delete commits',
            'where' => 'named by an export row of user ' . $userId,
        ];
        foreach (AppDataPurge::TABLE_ACTIONS as $table => $action) {
            $cascade[] = [
                'resource' => $table,
                'action' => $action === 'release'
                    ? 'release (user_id set to 0, registration_id to NULL; test-signal trust withdrawn)'
                    : $action,
                'where' => 'user_id = ' . $userId,
            ];
        }
        foreach (AppDataPurge::LINK_ACTIONS as $table => $action) {
            $cascade[] = ['resource' => $table, 'action' => $action, 'where' => 'names a registration of user ' . $userId];
        }
        $cascade[] = ['resource' => '202_users', 'action' => 'soft delete (user_deleted = 1)', 'where' => 'user_id = ' . $userId];

        return $cascade;
    }

    /**
     * Soft-delete the user and purge their data, atomically.
     *
     * @throws \RuntimeException when any statement fails; nothing is changed
     */
    public function deleteUser(int $userId): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A user id to delete is positive');
        }

        if (!$this->db->begin_transaction()) {
            throw new \RuntimeException('Could not start the user deletion transaction');
        }
        try {
            // Read inside the transaction, before the rows that hold them go.
            $exportFileNames = $this->exportFileNames($userId);
            foreach (array_merge(self::ACCESS_STATEMENTS, self::NOTIFICATION_STATEMENTS, self::MTA_STATEMENTS, self::IDENTITY_STATEMENTS, self::GOAL_STATEMENTS) as $sql) {
                $this->run($sql, $userId);
            }
            (new AppDataPurge($this->db))->purgeUser($userId);
            $this->run('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?', $userId);
            if (!$this->db->commit()) {
                throw new \RuntimeException('Could not commit the user deletion');
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw new \RuntimeException('User ' . $userId . ' was not deleted: ' . $e->getMessage(), 0, $e);
        }

        // The files go only once the rows that named them are gone for good:
        // removed first, a rolled-back delete would leave export rows whose
        // downloads find nothing. A file that cannot be removed does not undo
        // a committed delete; it is logged by name so it can be found.
        $files = $this->exportFiles ?? new ExportFiles();
        foreach ($exportFileNames as $name) {
            try {
                if (!$files->remove($name)) {
                    error_log('UserDataPurge: attribution export file ' . $name . ' of deleted user ' . $userId . ' could not be removed');
                }
            } catch (\Throwable $e) {
                error_log('UserDataPurge: an attribution export file of deleted user ' . $userId . ' was not removed: ' . $e->getMessage());
            }
        }
    }

    /**
     * The stored names of the user's export files.
     *
     * @return list<string>
     */
    private function exportFileNames(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT file_path FROM 202_attribution_exports WHERE user_id = ? AND file_path IS NOT NULL');
        if ($stmt === false) {
            throw new \RuntimeException('Prepare failed: export file names: ' . $this->db->error);
        }
        // @phpstan-ignore-next-line prosper202.directStmtCall — a checked one-shot bind; no Connection in scope
        if (!$stmt->bind_param('i', $userId)) {
            $stmt->close();
            throw new \RuntimeException('Bind failed: export file names');
        }
        // @phpstan-ignore-next-line prosper202.directStmtCall — checked, as in run()
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Reading the export file names failed: ' . $error);
        }
        // @phpstan-ignore-next-line prosper202.directStmtCall — false is checked: it is not "no files"
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('Reading the export file names failed: no result set');
        }
        $names = [];
        while (is_array($row = $result->fetch_assoc())) {
            $names[] = (string) $row['file_path'];
        }
        $stmt->close();

        return $names;
    }

    private function run(string $sql, int $userId): void
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Prepare failed: ' . $sql . ': ' . $this->db->error);
        }
        // @phpstan-ignore-next-line prosper202.directStmtCall — a checked one-shot bind; no Connection in scope
        if (!$stmt->bind_param('i', $userId)) {
            $stmt->close();
            throw new \RuntimeException('Bind failed: ' . $sql);
        }
        // @phpstan-ignore-next-line prosper202.directStmtCall — the checked execute this class exists to make
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Statement failed: ' . $sql . ': ' . $error);
        }
        $stmt->close();
    }
}

<?php

declare(strict_types=1);

namespace Prosper202\User;

use Api\V3\Apps\AppDataPurge;

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
 *  - the MTA engine's per-user state (models, snapshots and their
 *    touchpoints, settings, audit) is deleted, as it always was;
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
    /** The MTA engine's per-user state, children before parents. */
    private const MTA_STATEMENTS = [
        'DELETE FROM 202_attribution_touchpoints WHERE snapshot_id IN (SELECT snapshot_id FROM 202_attribution_snapshots WHERE user_id = ?)',
        'DELETE FROM 202_attribution_snapshots WHERE user_id = ?',
        'DELETE FROM 202_attribution_settings WHERE user_id = ?',
        'DELETE FROM 202_attribution_models WHERE user_id = ?',
        'DELETE FROM 202_attribution_audit WHERE user_id = ?',
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

    public function __construct(private readonly \mysqli $db)
    {
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
            foreach (array_merge(self::ACCESS_STATEMENTS, self::NOTIFICATION_STATEMENTS, self::MTA_STATEMENTS, self::IDENTITY_STATEMENTS, self::GOAL_STATEMENTS) as $sql) {
                $this->run($sql, $userId);
            }
            $unlinked = (new AppDataPurge($this->db))->purgeUser($userId);
            $this->run('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?', $userId);
            if (!$this->db->commit()) {
                throw new \RuntimeException('Could not commit the user deletion');
            }
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw new \RuntimeException('User ' . $userId . ' was not deleted: ' . $e->getMessage(), 0, $e);
        }

        // The campaigns the purge unlinked from the deleted registrations
        // go in the change feed, after the commit. The user IS deleted by
        // now, so a feed that cannot be written is logged by name rather
        // than thrown as "not deleted" (CLAUDE.md #13).
        foreach ($unlinked as $ownerId => $campaignIds) {
            try {
                (new \Api\V3\Controllers\CampaignsController($this->db, $ownerId))->recordLinkChanges($campaignIds);
            } catch (\Throwable $e) {
                error_log('p202 user delete: user ' . $userId . ' was deleted, but the change feed did not record the unlink of campaigns '
                    . implode(', ', $campaignIds) . ': ' . $e->getMessage());
            }
        }
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

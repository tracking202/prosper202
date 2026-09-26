<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Support\MysqliStatements;

/**
 * What deleting a user does to their app measurement data (plan §4.6).
 *
 * Every 202_app_* row the user owns is deleted, with one named exception:
 * their 202_app_postbacks rows are RELEASED — user_id set to 0 and
 * registration_id to NULL — because a postback is Apple's record that an
 * install happened, not the user's data. Released rows are unclaimed, so
 * the 30-day unclaimed retention window prunes them, and a new owner who
 * registers the same app within it claims them.
 *
 * Deleting the registrations is what frees their global (platform, app_key)
 * slots: before this purge existed, a deleted user's registration kept its
 * UNIQUE slot forever and nobody could register that app again.
 *
 * A released row keeps its trusted bit only where the verifier alone
 * decided it; a development-signed row the user's policy had made trusted
 * goes back to unvouched, because the policy that vouched for it is gone.
 *
 * Runs inside the caller's transaction (UserDataPurge); every statement
 * throws on failure so the caller rolls back rather than half-purging.
 */
final class AppDataPurge
{
    use MysqliStatements;

    /**
     * Every app table and what the purge does to it. A structural test holds
     * this list equal to the 202_app_* tables AppTables defines, so a table
     * added there without a decision here fails the build.
     */
    public const TABLE_ACTIONS = [
        '202_app_postbacks' => 'release',
        '202_app_skan_encodings' => 'delete',
        '202_app_skan_encoding_history' => 'delete',
        '202_app_installs' => 'delete',
        '202_app_integrity_credentials' => 'delete',
        '202_app_registrations' => 'delete',
    ];

    /**
     * Rows outside the app tables that point at a registration the purge
     * deletes, and what happens to them; the user delete preview lists
     * these beside TABLE_ACTIONS.
     */
    public const LINK_ACTIONS = [
        '202_aff_campaigns' => 'unlink (app_registration_id set to NULL; the campaign is kept)',
    ];

    public function __construct(private readonly \mysqli $db)
    {
    }

    /**
     * @return array<int, list<int>> the campaigns the purge unlinked, by
     *         owner, for the caller to put in the change feed once it has
     *         committed (CampaignsController::recordLinkChanges())
     */
    public function purgeUser(int $userId): array
    {
        if ($userId < 1) {
            // user_id 0 is "unclaimed": purging it would delete or release
            // every stranger's postback at once.
            throw new \InvalidArgumentException('A user id to purge is positive');
        }

        $development = Apple\SignatureState::DEVELOPMENT->value;
        $this->run(
            'UPDATE 202_app_postbacks SET trusted = NULL WHERE user_id = ? AND signature_state = ?',
            'is',
            $userId,
            $development
        );
        $this->run(
            'UPDATE 202_app_postbacks SET user_id = 0, registration_id = NULL WHERE user_id = ?',
            'i',
            $userId
        );
        $this->run('DELETE FROM 202_app_skan_encodings WHERE user_id = ?', 'i', $userId);
        $this->run('DELETE FROM 202_app_skan_encoding_history WHERE user_id = ?', 'i', $userId);
        // An install is the user's own record (their registration's SDK
        // reported it to them), not a platform's: deleted, not released.
        $this->run('DELETE FROM 202_app_installs WHERE user_id = ?', 'i', $userId);
        // The user's Google service accounts go with them: a credential
        // is the operator's secret, and nothing may decode with it after.
        $this->run('DELETE FROM 202_app_integrity_credentials WHERE user_id = ?', 'i', $userId);
        // The user's campaigns stay (like their clicks); their links to the
        // registrations deleted next go, as a registration delete unlinks
        // them (AppRegistrationsController::beforeDelete()). Matched by the
        // registration's owner, not the campaign's, so no campaign is left
        // naming a registration this purge removes.
        $stmt = $this->prepare(
            'SELECT aff_campaign_id, user_id FROM 202_aff_campaigns
             WHERE app_registration_id IN (SELECT registration_id FROM 202_app_registrations WHERE user_id = ?) FOR UPDATE'
        );
        $this->bind($stmt, 'i', $userId);
        $this->execute($stmt, 'Linked campaign lookup failed');
        $unlinked = [];
        foreach ($this->result($stmt)->fetch_all(MYSQLI_ASSOC) as $row) {
            $unlinked[(int) $row['user_id']][] = (int) $row['aff_campaign_id'];
        }
        $stmt->close();
        $this->run(
            'UPDATE 202_aff_campaigns SET app_registration_id = NULL
             WHERE app_registration_id IN (SELECT registration_id FROM 202_app_registrations WHERE user_id = ?)',
            'i',
            $userId
        );
        $this->run('DELETE FROM 202_app_registrations WHERE user_id = ?', 'i', $userId);

        return $unlinked;
    }

    private function run(string $sql, string $types, mixed ...$values): void
    {
        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$values);
        $this->execute($stmt, 'App data purge failed: ' . $sql);
        $stmt->close();
    }
}

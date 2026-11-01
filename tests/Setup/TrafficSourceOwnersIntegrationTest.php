<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TrafficSourceOwnersController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\Setup\TrafficSourceOwners;
use Prosper202\Setup\TrafficSourceRepairRefused;
use Prosper202\User\UserDataPurge;

/**
 * Traffic sources taken through Setup › Traffic Sources' rename before
 * 1.9.79: the report that finds them and the repair that moves one back
 * (TrafficSourceOwners, through TrafficSourceOwnersController as the API
 * runs it), on a real database.
 *
 * Each takeover is planted the old way: the page's own UPDATE, which set
 * user_id to the renamer's and named the source by id alone. OWNER's sources
 * go to TAKER (a Campaign manager) or ADMIN2 (an Admin); TAKER's own source
 * is the legitimate same-owner case; ADMIN's source with THIRD's account
 * saved after it is the shape the API made before 1.9.77. ADMIN (Admin role)
 * and SUPER (Super user role) read and repair.
 *
 * Skips unless P202_TEST_DB_* names a scratch database. The report and the
 * `all` repair are install-wide: they also list and move another class's
 * leftover rows, so point it at a scratch database, never one an instance
 * uses. This class removes its own rows at both ends.
 *
 * @group integration
 */
final class TrafficSourceOwnersIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ADMIN = 870101;
    private const OWNER = 870102;
    private const TAKER = 870103;
    private const ADMIN2 = 870104;
    private const THIRD = 870105;
    private const SUPER = 870106;
    /** user => role: Super user 1, Admin 2, Campaign manager 3 */
    private const ROLES = [
        self::ADMIN => 2,
        self::ADMIN2 => 2,
        self::SUPER => 1,
        self::OWNER => 3,
        self::TAKER => 3,
        self::THIRD => 3,
    ];

    /** OWNER's, taken by TAKER: two accounts (one removed), three clicks, two conversions that count. */
    private const TAKEN = 870201;
    /** TAKER's own, with TAKER's account. */
    private const OWN = 870202;
    /** OWNER's, taken by TAKER, who then put an account of their own on it. */
    private const TAKEN_THEN_USED = 870203;
    /** ADMIN's, with THIRD's account saved after it. */
    private const SHARED = 870204;
    /** OWNER's, taken by ADMIN2. */
    private const TAKEN_BY_ADMIN = 870205;
    /** OWNER's, taken by TAKER, one account. */
    private const TAKEN_TOO = 870206;
    /** source => the user it starts in */
    private const SOURCES = [
        self::TAKEN => self::OWNER,
        self::OWN => self::TAKER,
        self::TAKEN_THEN_USED => self::OWNER,
        self::SHARED => self::ADMIN,
        self::TAKEN_BY_ADMIN => self::OWNER,
        self::TAKEN_TOO => self::OWNER,
    ];
    /** [ppc_account_id, user, source, removed, saved at] */
    private const ACCOUNTS = [
        [870301, self::OWNER, self::TAKEN, 0, self::CREATED + 10],
        [870302, self::OWNER, self::TAKEN, 1, self::CREATED + 20],
        [870303, self::TAKER, self::OWN, 0, self::CREATED + 10],
        [870304, self::OWNER, self::TAKEN_THEN_USED, 0, self::CREATED + 10],
        [870305, self::THIRD, self::SHARED, 0, self::CREATED + 500],
        [870306, self::OWNER, self::TAKEN_BY_ADMIN, 0, self::CREATED + 10],
        [870307, self::OWNER, self::TAKEN_TOO, 0, self::CREATED + 10],
    ];

    /** ADMIN2's, made in the test that takes it into TAKER's account. */
    private const ADMINS_OWN = 870207;
    /** A user with no 202_users row. */
    private const GHOST = 870199;

    private const CREATED = 1_000_000_000;
    private const RENAMED = 1_100_000_000;

    /** Where the repairs' log lines go while a test runs. */
    private string $log = '';
    private string $logWas = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $seeder = new DataSeeder(self::$db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'tso-log-');
        $this->logWas = (string) ini_set('error_log', $this->log);
        self::cleanUp();
        foreach (self::ROLES as $user => $role) {
            self::user($user);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
            self::row('202_api_keys', ['user_id' => $user, 'api_key' => 'tso-key-' . $user, 'created_at' => 1]);
        }

        foreach (self::SOURCES as $id => $user) {
            self::row('202_ppc_networks', [
                'ppc_network_id' => $id,
                'user_id' => $user,
                'ppc_network_name' => 'Source ' . $id,
                'ppc_network_time' => self::CREATED,
            ]);
        }
        foreach (self::ACCOUNTS as [$id, $user, $source, $deleted, $time]) {
            self::account($id, $user, $source, $time, $deleted);
        }

        // Three clicks on TAKEN's accounts, the first visited twice.
        $visits = [[870401, 870301, 100], [870401, 870301, 200], [870402, 870301, 300], [870403, 870302, 400]];
        foreach ($visits as $click) {
            self::row('202_clicks', [
                'click_id' => $click[0],
                'user_id' => self::OWNER,
                'ppc_account_id' => $click[1],
                'click_cpc' => 0,
                'click_time' => self::CREATED + $click[2],
            ]);
        }
        self::row('202_click_latest_visits', ['click_id' => 870401, 'click_time' => self::CREATED + 200]);

        // Counted: 870501 and 870504. Not: the reversal of 870501, a removed one and a superseded one.
        self::conversion(870501, 870401);
        self::conversion(870502, 870401, ['reverses_conv_id' => 870501, 'click_payout' => -5]);
        self::conversion(870503, 870402, ['deleted' => 1]);
        self::conversion(870504, 870403);
        self::conversion(870505, 870403, ['superseded_reason' => 'replace', 'superseded_by' => 870504]);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->logWas);
            unlink($this->log);
            $this->log = '';
        }
    }

    private static function cleanUp(): void
    {
        $users = implode(',', array_keys(self::ROLES));
        $tables = ['202_user_role', '202_api_keys', '202_users', '202_clicks', '202_conversion_logs'];
        foreach ([...$tables, '202_dirty_hours'] as $t) {
            self::q("DELETE FROM $t WHERE user_id IN ($users)");
        }
        $sources = implode(',', array_keys(self::SOURCES));
        self::q("DELETE FROM 202_ppc_networks WHERE ppc_network_id IN ($sources, " . self::ADMINS_OWN . ')');
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id BETWEEN 870300 AND 870399');
        self::q('DELETE FROM 202_click_latest_visits WHERE click_id BETWEEN 870400 AND 870499');
    }

    private static function account(int $id, int $user, int $source, int $time, int $deleted = 0): void
    {
        self::row('202_ppc_accounts', [
            'ppc_account_id' => $id,
            'user_id' => $user,
            'ppc_network_id' => $source,
            'ppc_account_name' => 'Account ' . $id,
            'ppc_account_deleted' => $deleted,
            'ppc_account_time' => $time,
        ]);
    }

    /** @param array<string, int|string> $more */
    private static function conversion(int $id, int $click, array $more = []): void
    {
        self::row('202_conversion_logs', $more + [
            'conv_id' => $id,
            'click_id' => $click,
            'campaign_id' => 0,
            'click_payout' => 5,
            'user_id' => self::OWNER,
            'click_time' => self::CREATED,
            'conv_time' => self::CREATED + 1000,
            'time_difference' => '',
            'pixel_type' => 0,
            'user_agent' => '',
            'dedupe_key' => 'tso:' . $id,
        ]);
    }

    /** Setup › Traffic Sources' rename before 1.9.79, as the page built it. */
    private static function takeTheOldWay(int $renamer, int $sourceId, string $name): void
    {
        $mysql = [
            'user_id' => (string) $renamer,
            'ppc_network_name' => self::$db->real_escape_string($name),
            'ppc_network_time' => self::RENAMED,
            'ppc_network_id' => (string) $sourceId,
        ];
        $ppc_network_sql = " UPDATE 202_ppc_networks SET";
        $ppc_network_sql .= " `user_id`='" . $mysql['user_id'] . "',
                              `ppc_network_name`='" . $mysql['ppc_network_name'] . "',
                              `ppc_network_time`='" . $mysql['ppc_network_time'] . "'";
        $ppc_network_sql .= "WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "'";
        self::q($ppc_network_sql);
        self::assertSame(1, self::$db->affected_rows, 'the old rename moved the source');
    }

    /** TAKER's own account on a source TAKER took, saved after the takeover. */
    private static function takerUsesIt(int $sourceId): void
    {
        self::account(870308, self::TAKER, $sourceId, self::RENAMED + 100);
    }

    private static function controller(int $user): TrafficSourceOwnersController
    {
        $auth = Auth::fromRequest(['Authorization' => 'Bearer tso-key-' . $user], self::$db);

        return new TrafficSourceOwnersController(self::$db, $auth);
    }

    private static function ownerOf(int $sourceId): int
    {
        $found = self::$db->query('SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = ' . $sourceId);
        $row = $found->fetch_assoc();
        self::assertIsArray($row, "source $sourceId exists");

        return (int) $row['user_id'];
    }

    /** @return array<int, array<string, mixed>> this class's sources the report lists, by id */
    private static function listed(int $user = self::ADMIN): array
    {
        $listed = [];
        foreach (self::controller($user)->report()['data']['sources'] as $entry) {
            if (isset(self::SOURCES[$entry['ppc_network_id']]) || $entry['ppc_network_id'] === self::ADMINS_OWN) {
                $listed[$entry['ppc_network_id']] = $entry;
            }
        }

        return $listed;
    }

    private static function sees(int $user, int $sourceId): bool
    {
        try {
            (new PpcNetworksController(self::$db, $user))->get($sourceId);

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }

    /**
     * The field error a refused repair names.
     *
     * @param array<string, mixed> $payload
     */
    private static function refusal(array $payload, string $field): string
    {
        try {
            self::controller(self::ADMIN)->repair($payload);
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            self::assertArrayHasKey($field, $errors, (string) json_encode($payload));

            return $errors[$field];
        }
        self::fail('refused: ' . json_encode($payload));
    }

    public function testATakeoverPlantedTheOldWayIsListedForTheUserItWasTakenFrom(): void
    {
        self::assertTrue(self::sees(self::OWNER, self::TAKEN), 'the owner sees its source before');
        self::assertSame([self::SHARED], array_keys(self::listed()), 'before the takeover, only the shared source');

        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');

        self::assertFalse(self::sees(self::OWNER, self::TAKEN), "the owner's GET /ppc-networks/{id} answers 404");
        $listed = self::listed();
        self::assertSame([self::TAKEN, self::SHARED], array_keys($listed), 'not the source in its own account');
        $entry = $listed[self::TAKEN];
        self::assertSame('Taken', $entry['ppc_network_name']);
        self::assertFalse($entry['removed']);
        self::assertSame(self::RENAMED, $entry['ppc_network_time']);
        self::assertSame(self::OWNER, $entry['repair_to_user_id']);
        self::assertSame([], $entry['ambiguous']);
        self::assertSame([
            'user_id' => self::TAKER,
            'user_name' => 'rp' . self::TAKER,
            'user_deleted' => false,
            'user_active' => true,
            'ppc_accounts' => 0,
            'removed_ppc_accounts' => 0,
            'ppc_account_ids' => [],
            'last_ppc_account_time' => null,
            'clicks' => 0,
            'conversions' => 0,
        ], $entry['owner']);
        self::assertSame([[
            'user_id' => self::OWNER,
            'user_name' => 'rp' . self::OWNER,
            'user_deleted' => false,
            'user_active' => true,
            'ppc_accounts' => 1,
            'removed_ppc_accounts' => 1,
            'ppc_account_ids' => [870301, 870302],
            'last_ppc_account_time' => self::CREATED + 20,
            'clicks' => 3,
            'conversions' => 2,
        ]], $entry['named_by']);

        $report = self::controller(self::ADMIN)->report()['data'];
        self::assertGreaterThanOrEqual(1, $report['repairable']);
        self::assertSame(count($report['sources']), $report['repairable'] + $report['ambiguous']);
    }

    public function testTheRepairMovesTheSourceBackAndTheTakerLosesIt(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        self::assertTrue(self::sees(self::TAKER, self::TAKEN), 'the taker has it');

        $answer = self::controller(self::ADMIN)->repair(['ppc_network_id' => self::TAKEN]);

        self::assertSame(['repaired' => [[
            'ppc_network_id' => self::TAKEN,
            'ppc_network_name' => 'Taken',
            'removed' => false,
            'from_user_id' => self::TAKER,
            'to_user_id' => self::OWNER,
            'ppc_accounts' => 2,
            'queued_ppc_accounts' => 2,
        ]], 'skipped' => []], $answer['data']);
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
        $time = self::$db->query('SELECT ppc_network_time FROM 202_ppc_networks WHERE ppc_network_id = ' . self::TAKEN);
        self::assertSame((string) self::RENAMED, $time->fetch_assoc()['ppc_network_time'], 'only the user changes');
        self::assertTrue(self::sees(self::OWNER, self::TAKEN), "the owner's GET /ppc-networks/{id} answers again");
        self::assertFalse(self::sees(self::TAKER, self::TAKEN), 'the taker loses it');
        self::assertStringContainsString(
            'Prosper202 traffic source repair: traffic source ' . self::TAKEN . ' ("Taken") moved from user '
                . self::TAKER . ' to user ' . self::OWNER . ', whose 2 traffic source account(s) name it, at user '
                . self::ADMIN . "'s request",
            (string) file_get_contents($this->log)
        );

        $queued = self::$db->query('SELECT ppc_account_id, user_id, processed + 0 AS processed FROM 202_dirty_hours'
            . ' WHERE user_id = ' . self::OWNER . ' ORDER BY ppc_account_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([
            ['ppc_account_id' => '870301', 'user_id' => (string) self::OWNER, 'processed' => '0'],
            ['ppc_account_id' => '870302', 'user_id' => (string) self::OWNER, 'processed' => '0'],
        ], $queued, "the owner's accounts are queued for the report rollup");

        self::assertArrayNotHasKey(self::TAKEN, self::listed(), 'it is no longer listed');
        $again = self::refusal(['ppc_network_id' => self::TAKEN], 'ppc_network_id');
        self::assertStringContainsString('is not in the report', $again);
    }

    public function testAnAmbiguousSourceMovesOnlyToAUserNamedForIt(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN_THEN_USED, 'Used');
        self::takerUsesIt(self::TAKEN_THEN_USED);

        $entry = self::listed()[self::TAKEN_THEN_USED];
        self::assertSame([TrafficSourceOwners::OWNER_HAS_ACCOUNTS], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);

        $source = ['ppc_network_id' => self::TAKEN_THEN_USED];
        self::assertStringContainsString('ambiguous (owner_has_accounts)', self::refusal($source, 'to_user_id'));
        self::assertStringContainsString(
            'is in user ' . self::TAKER . "'s account already",
            self::refusal($source + ['to_user_id' => self::TAKER], 'to_user_id')
        );
        self::assertStringContainsString(
            'No traffic source account of user ' . self::THIRD,
            self::refusal($source + ['to_user_id' => self::THIRD], 'to_user_id')
        );
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN_THEN_USED), 'nothing was moved');

        $answer = self::controller(self::ADMIN)->repair($source + ['to_user_id' => self::OWNER]);
        self::assertSame(self::OWNER, $answer['data']['repaired'][0]['to_user_id']);
        self::assertSame(2, $answer['data']['repaired'][0]['queued_ppc_accounts'], "the taker's account is queued too");
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN_THEN_USED));
    }

    public function testASourceSharedBeforeTheApiCheckedOwnershipIsAmbiguous(): void
    {
        $entry = self::listed()[self::SHARED];
        self::assertSame(self::ADMIN, $entry['owner']['user_id']);
        self::assertSame([self::THIRD], array_column($entry['named_by'], 'user_id'));
        self::assertSame([TrafficSourceOwners::SAVED_AFTER_RENAME], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
    }

    public function testARepairNeedsACallerWhoMayActOnBothUsers(): void
    {
        self::takeTheOldWay(self::ADMIN2, self::TAKEN_BY_ADMIN, 'By admin');

        try {
            self::controller(self::ADMIN)->repair(['ppc_network_id' => self::TAKEN_BY_ADMIN]);
            self::fail('an Admin may not move a source out of another Admin\'s account');
        } catch (AuthException $e) {
            self::assertSame(403, $e->getCode());
            self::assertStringContainsString('add_edit_delete_admin', $e->getMessage());
        }
        self::assertSame(self::ADMIN2, self::ownerOf(self::TAKEN_BY_ADMIN), 'nothing was moved');

        $answer = self::controller(self::SUPER)->repair(['ppc_network_id' => self::TAKEN_BY_ADMIN]);
        self::assertSame(self::OWNER, $answer['data']['repaired'][0]['to_user_id']);
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN_BY_ADMIN));
    }

    public function testAllRepairsWhatTheReportNamesAUserForAndSkipsTheRest(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        self::takeTheOldWay(self::TAKER, self::TAKEN_TOO, 'Taken too');
        self::takeTheOldWay(self::TAKER, self::TAKEN_THEN_USED, 'Used');
        self::takerUsesIt(self::TAKEN_THEN_USED);
        self::takeTheOldWay(self::ADMIN2, self::TAKEN_BY_ADMIN, 'By admin');

        $answer = self::controller(self::ADMIN)->repair(['all' => true])['data'];

        $repaired = array_values(array_filter(
            array_column($answer['repaired'], 'ppc_network_id'),
            static fn (int $id): bool => isset(self::SOURCES[$id])
        ));
        self::assertSame([self::TAKEN, self::TAKEN_TOO], $repaired);
        $skipped = [];
        foreach ($answer['skipped'] as $s) {
            if (isset(self::SOURCES[$s['ppc_network_id']])) {
                $skipped[$s['ppc_network_id']] = $s['reason'];
            }
        }
        self::assertSame([
            self::TAKEN_THEN_USED => 'ambiguous',
            self::SHARED => 'ambiguous',
            self::TAKEN_BY_ADMIN => 'not_permitted',
        ], $skipped);

        $after = [
            self::TAKEN => self::OWNER,
            self::TAKEN_TOO => self::OWNER,
            self::TAKEN_THEN_USED => self::TAKER,
            self::SHARED => self::ADMIN,
            self::TAKEN_BY_ADMIN => self::ADMIN2,
            self::OWN => self::TAKER,
        ];
        foreach ($after as $id => $user) {
            self::assertSame($user, self::ownerOf($id), "source $id is in user $user's account");
        }
    }

    public function testAllReadsNoClicks(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        // With the clicks table out of reach, any read of it fails the call.
        self::q('RENAME TABLE 202_clicks TO 202_clicks_tso_hidden');
        try {
            $answer = self::controller(self::ADMIN)->repair(['all' => true])['data'];
        } finally {
            self::q('RENAME TABLE 202_clicks_tso_hidden TO 202_clicks');
        }

        self::assertContains(self::TAKEN, array_column($answer['repaired'], 'ppc_network_id'));
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
    }

    public function testARepairIntoAnAdminsAccountNeedsACallerWhoMayActOnIt(): void
    {
        self::row('202_ppc_networks', [
            'ppc_network_id' => self::ADMINS_OWN,
            'user_id' => self::ADMIN2,
            'ppc_network_name' => 'Admin source',
            'ppc_network_time' => self::CREATED,
        ]);
        self::account(870309, self::ADMIN2, self::ADMINS_OWN, self::CREATED + 10);
        self::takeTheOldWay(self::TAKER, self::ADMINS_OWN, 'From an admin');
        self::assertSame(self::ADMIN2, self::listed()[self::ADMINS_OWN]['repair_to_user_id']);

        try {
            self::controller(self::ADMIN)->repair(['ppc_network_id' => self::ADMINS_OWN]);
            self::fail('an Admin may not move a source into another Admin\'s account');
        } catch (AuthException $e) {
            self::assertSame(403, $e->getCode());
            self::assertStringContainsString('add_edit_delete_admin', $e->getMessage());
        }
        self::assertSame(self::TAKER, self::ownerOf(self::ADMINS_OWN), 'nothing was moved');
    }

    public function testASourceIsNotMovedIntoADeletedUsersAccount(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::OWNER);

        $entry = self::listed()[self::TAKEN];
        self::assertSame([TrafficSourceOwners::DELETED_USER], $entry['ambiguous']);
        self::assertNull($entry['repair_to_user_id']);
        self::assertTrue($entry['named_by'][0]['user_deleted']);

        $source = ['ppc_network_id' => self::TAKEN];
        self::assertStringContainsString('ambiguous (deleted_user)', self::refusal($source, 'to_user_id'));
        self::assertStringContainsString(
            'User ' . self::OWNER . ' is deleted',
            self::refusal($source + ['to_user_id' => self::OWNER], 'to_user_id')
        );
        $all = self::controller(self::ADMIN)->repair(['all' => true])['data'];
        self::assertNotContains(self::TAKEN, array_column($all['repaired'], 'ppc_network_id'));
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN), 'nothing was moved');
    }

    public function testASourceGoesBackToASwitchedOffUser(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::OWNER);

        $entry = self::listed()[self::TAKEN];
        self::assertSame([], $entry['ambiguous']);
        self::assertFalse($entry['named_by'][0]['user_active']);

        self::controller(self::ADMIN)->repair(['ppc_network_id' => self::TAKEN]);
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
    }

    public function testASourceIsNotMovedToAUserWithNoRecord(): void
    {
        self::account(870310, self::GHOST, self::SHARED, self::CREATED + 600);
        $entry = self::listed()[self::SHARED];
        self::assertContains(TrafficSourceOwners::NO_SUCH_USER, $entry['ambiguous']);
        self::assertSame([self::THIRD, self::GHOST], array_column($entry['named_by'], 'user_id'));

        $refused = self::refusal(['ppc_network_id' => self::SHARED, 'to_user_id' => self::GHOST], 'to_user_id');
        self::assertStringContainsString('User ' . self::GHOST . ' does not exist', $refused);
        self::assertSame(self::ADMIN, self::ownerOf(self::SHARED), 'nothing was moved');
    }

    public function testNothingIsMovedForWhatTheReportDoesNotList(): void
    {
        $none = self::refusal(['ppc_network_id' => 870299], 'ppc_network_id');
        self::assertStringContainsString('There is no traffic source 870299', $none);
        self::assertStringContainsString(
            'every traffic source account that names it is in user ' . self::TAKER . "'s account",
            self::refusal(['ppc_network_id' => self::OWN], 'ppc_network_id')
        );
        self::assertSame(self::TAKER, self::ownerOf(self::OWN));

        $bodies = [
            [['ppc_network_id' => 'abc'], 'ppc_network_id'],
            [['ppc_network_id' => '12abc'], 'ppc_network_id'],
            [['ppc_network_id' => 0], 'ppc_network_id'],
            [['ppc_network_id' => self::OWN, 'to_user_id' => '1.5'], 'to_user_id'],
            [[], 'ppc_network_id'],
            [['all' => true, 'ppc_network_id' => self::TAKEN], 'ppc_network_id'],
            [['all' => 'yes'], 'all'],
            [['ppc_network_id' => self::OWN, 'dry_run' => 1], 'dry_run'],
            [['ppc_network_id' => self::OWN, 'user_id' => 1], 'user_id'],
            [['ppc_network_id' => self::OWN, 'to_user_id' => self::OWNER, 'repair_to_user_id' => self::OWNER],
                'repair_to_user_id'],
            [['all' => true, 'repair_to_user_id' => self::OWNER], 'repair_to_user_id'],
            [['all' => true, 'owner_user_id' => self::TAKER], 'owner_user_id'],
            [['ppc_network_id' => self::OWN, 'owner_user_id' => 'x'], 'owner_user_id'],
            [['ppc_network_id' => self::OWN, 'repair_to_user_id' => 0], 'repair_to_user_id'],
            [['ppc_network_id' => self::OWN, 'repair_to_user_id' => null], 'repair_to_user_id'],
        ];
        foreach ($bodies as [$payload, $field]) {
            self::refusal($payload, $field);
        }
    }

    /**
     * A repair waits for a user that UserDataPurge::deleteUser() is deleting,
     * and reads them as deleted once the delete commits. The delete is held
     * here at its first statement (it locks the user's row before anything
     * else) and the repair runs in a child process; once the server shows the
     * child waiting, the delete marks the user deleted and commits.
     */
    public function testARepairWaitsForTheUserBeingDeletedAndMovesNothingIntoIt(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        $holder = self::connect();
        $conn = new Connection($holder);
        $child = null;
        try {
            $conn->transaction(function () use ($conn, &$child): void {
                $lock = $conn->prepareWrite(UserDataPurge::LOCK_USER);
                $conn->bind($lock, 'i', [self::OWNER]);
                $conn->fetchAll($lock);
                $child = self::waitingRepair(self::TAKEN);
                $delete = $conn->prepareWrite('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?');
                $conn->bind($delete, 'i', [self::OWNER]);
                $conn->executeUpdate($delete);
            });
        } finally {
            $holder->close();
        }
        [$proc, $pipes] = $child;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $out . $err);

        self::assertStringStartsWith('refused: ', $out, $err);
        self::assertStringContainsString('deleted_user', $out);
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN), 'nothing was moved into the deleted account');
    }

    private static function connect(): \mysqli
    {
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $db->query("SET SESSION sql_mode=''");

        return $db;
    }

    /** @return array{resource, array<int, resource>} the child, once it waits on a lock */
    private static function waitingRepair(int $sourceId): array
    {
        [$proc, $pipes, $thread] = self::repairInChild($sourceId);
        if (self::settle($proc, $thread) === 'waiting') {
            return [$proc, $pipes];
        }
        self::fail("The repair on connection $thread never waited for the user being deleted: "
            . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    }

    /** @return array{resource, array<int, resource>, string} the child, its pipes and its connection id */
    private static function repairInChild(int $sourceId): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/traffic-source-repair-runner.php',
                (string) $sourceId, (string) self::ADMIN,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');

        return [$proc, $pipes, $thread];
    }

    /** 'waiting' once the server shows the child waiting on a lock, 'exited' once it has ended. */
    private static function settle($proc, string $thread): string
    {
        $deadline = microtime(true) + 15;
        do {
            if (!proc_get_status($proc)['running']) {
                return 'exited';
            }
            $waiting = self::$db->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX'
                . " WHERE trx_mysql_thread_id = $thread AND trx_state = 'LOCK WAIT'");
            self::assertInstanceOf(\mysqli_result::class, $waiting, self::$db->error);
            if ((int) $waiting->fetch_row()[0] === 1) {
                return 'waiting';
            }
            // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
            usleep(250000);
        } while (microtime(true) < $deadline);
        self::fail("The repair on connection $thread neither ended nor waited on a lock");
    }

    /**
     * A click's report rollup (ClickRollupSql's INSERT … SELECT) share-locks
     * the click's traffic source account and then the account's source. A
     * repair that held the source and then locked the accounts naming it
     * deadlocked with one. The rollup's two reads are taken here by hand, on
     * either side of a repair in a child process.
     */
    public function testARepairDoesNotDeadlockWithARollupReadingTheSource(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        $rollup = self::connect();
        self::assertTrue($rollup->query('SET SESSION innodb_lock_wait_timeout = 15'), $rollup->error);
        try {
            self::assertTrue($rollup->begin_transaction(), $rollup->error);
            $account = $rollup->query('SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_id = 870301'
                . ' LOCK IN SHARE MODE');
            self::assertInstanceOf(\mysqli_result::class, $account, $rollup->error);
            self::assertSame([['870301']], $account->fetch_all());
            [$proc, $pipes, $thread] = self::repairInChild(self::TAKEN);
            self::settle($proc, $thread);
            try {
                $source = $rollup->query('SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_id = '
                    . self::TAKEN . ' LOCK IN SHARE MODE');
                $read = $source instanceof \mysqli_result ? 'read' : $rollup->error;
            } catch (\mysqli_sql_exception $e) {
                $read = $e->getMessage();
            }
            $rollup->query('COMMIT');
        } finally {
            $rollup->close();
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        self::assertSame('read', $read, "the rollup's read of the source");
        self::assertSame('ok', $out, $err);
        self::assertSame(self::OWNER, self::ownerOf(self::TAKEN));
    }

    /**
     * owner_user_id and repair_to_user_id are what the operator was shown
     * (`p202 system traffic-source-owners repair` sends them): a source that
     * has moved since, or would go to another user, is not moved.
     */
    public function testARepairOfWhatTheReportShowedIsRefusedOnceItSaysOtherwise(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        $shown = self::listed()[self::TAKEN];
        self::assertSame([self::TAKER, self::OWNER], [$shown['owner']['user_id'], $shown['repair_to_user_id']]);
        $body = [
            'ppc_network_id' => self::TAKEN,
            'owner_user_id' => self::TAKER,
            'repair_to_user_id' => self::OWNER,
        ];

        // In another account now, and still going to OWNER.
        self::takeTheOldWay(self::THIRD, self::TAKEN, 'Taken again');
        self::assertSame(self::OWNER, self::listed()[self::TAKEN]['repair_to_user_id']);
        self::assertStringContainsString(
            'is now in user ' . self::THIRD . "'s account, not in user " . self::TAKER . "'s",
            self::refusal($body, 'owner_user_id')
        );
        self::assertSame(self::THIRD, self::ownerOf(self::TAKEN), 'nothing was moved');

        // In TAKER's account again, and now going to another user: OWNER's
        // accounts went to another source and THIRD's named this one.
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        self::q('UPDATE 202_ppc_accounts SET ppc_network_id = ' . self::OWN . ' WHERE user_id = ' . self::OWNER
            . ' AND ppc_network_id = ' . self::TAKEN);
        self::account(870310, self::THIRD, self::TAKEN, self::CREATED + 10);
        self::assertSame(self::THIRD, self::listed()[self::TAKEN]['repair_to_user_id']);
        self::assertStringContainsString(
            'would now go to user ' . self::THIRD . ', not to user ' . self::OWNER,
            self::refusal($body, 'repair_to_user_id')
        );
        self::assertSame(self::TAKER, self::ownerOf(self::TAKEN), 'nothing was moved');

        $moved = self::controller(self::ADMIN)->repair(['repair_to_user_id' => self::THIRD] + $body)['data'];
        self::assertSame(self::THIRD, $moved['repaired'][0]['to_user_id'], 'what the report says now is moved');
        self::assertSame(self::THIRD, self::ownerOf(self::TAKEN));
    }

    public function testARepairOfRowsThatChangedAfterTheyWereReadMovesNothing(): void
    {
        self::takeTheOldWay(self::TAKER, self::TAKEN, 'Taken');
        $owners = new TrafficSourceOwners(new Connection(self::$db));
        $plan = $owners->plan(self::TAKEN, null);
        self::assertSame([self::TAKER, self::OWNER], [$plan['from'], $plan['to']]);

        self::takeTheOldWay(self::THIRD, self::TAKEN, 'Taken again');
        try {
            $owners->repair(self::TAKEN, $plan['from'], $plan['to'], null, self::ADMIN);
            self::fail('a repair planned on rows that changed is refused');
        } catch (TrafficSourceRepairRefused $e) {
            self::assertStringContainsString('changed after it was read', $e->getMessage());
        }
        self::assertSame(self::THIRD, self::ownerOf(self::TAKEN), 'nothing was moved');
        $queued = self::$db->query('SELECT COUNT(*) AS n FROM 202_dirty_hours WHERE user_id = ' . self::OWNER);
        self::assertSame('0', $queued->fetch_assoc()['n'], 'nothing was queued');
    }
}

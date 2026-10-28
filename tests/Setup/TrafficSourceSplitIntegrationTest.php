<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\ReportsController;
use Api\V3\Controllers\TrafficSourceOwnersController;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\Setup\TrafficSourceOwners;
use Prosper202\Setup\TrafficSourceRepairRefused;
use Prosper202\User\UserDataPurge;

/**
 * The split of a traffic source that accounts of more than one user name
 * (TrafficSourceOwners::planSplit() and split(), through
 * TrafficSourceOwnersController as POST /system/traffic-source-owners/split
 * runs it), on a real database. No move clears such a source: whichever
 * account it is in, another user's accounts still name it.
 *
 * MIXED is in TAKER's account, with TAKER's account and two of OWNER's (one
 * removed) on it, and three custom variables (one removed). SEVERAL is in
 * ADMIN's account, with no account of ADMIN's and one each of OWNER's and
 * THIRD's. OWNERS_OWN is OWNER's own, with OWNER's account. IN_ADMIN2 is in
 * ADMIN2's account (an Admin) with OWNER's account; NAMED_BY_ADMIN2 is in
 * TAKER's with TAKER's and ADMIN2's. ADMIN (Admin) and SUPER (Super user)
 * split.
 *
 * Skips unless P202_TEST_DB_* names a scratch database; the report is
 * install-wide, so never point it at one an instance uses. The test that runs
 * the cron job's re-roll also needs a 202-config.php. This class removes its
 * own rows at both ends.
 *
 * @group integration
 */
final class TrafficSourceSplitIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ADMIN = 871101;
    private const SUPER = 871102;
    private const ADMIN2 = 871103;
    private const TAKER = 871104;
    private const OWNER = 871105;
    private const THIRD = 871106;
    /** A user with no 202_users row. */
    private const GHOST = 871199;
    /** user => role: Super user 1, Admin 2, Campaign manager 3 */
    private const ROLES = [
        self::ADMIN => 2,
        self::SUPER => 1,
        self::ADMIN2 => 2,
        self::TAKER => 3,
        self::OWNER => 3,
        self::THIRD => 3,
    ];

    private const MIXED = 871201;
    private const SEVERAL = 871202;
    private const OWNERS_OWN = 871203;
    private const IN_ADMIN2 = 871204;
    private const NAMED_BY_ADMIN2 = 871205;
    /** source => [the user it is in, its name] */
    private const SOURCES = [
        self::MIXED => [self::TAKER, 'Mixed Source'],
        self::SEVERAL => [self::ADMIN, 'Several Source'],
        self::OWNERS_OWN => [self::OWNER, 'Owner Source'],
        self::IN_ADMIN2 => [self::ADMIN2, 'Admin2 Source'],
        self::NAMED_BY_ADMIN2 => [self::TAKER, 'Named By Admin2'],
    ];

    private const T1 = 871301;
    private const O1 = 871302;
    private const O2 = 871303;
    private const O3 = 871304;
    private const H1 = 871305;
    private const O4 = 871306;
    private const O5 = 871307;
    private const T2 = 871308;
    private const A2 = 871309;
    /** [ppc_account_id, user, source, removed] */
    private const ACCOUNTS = [
        [self::T1, self::TAKER, self::MIXED, 0],
        [self::O1, self::OWNER, self::MIXED, 0],
        [self::O2, self::OWNER, self::MIXED, 1],
        [self::O3, self::OWNER, self::SEVERAL, 0],
        [self::H1, self::THIRD, self::SEVERAL, 0],
        [self::O4, self::OWNER, self::OWNERS_OWN, 0],
        [self::O5, self::OWNER, self::IN_ADMIN2, 0],
        [self::T2, self::TAKER, self::NAMED_BY_ADMIN2, 0],
        [self::A2, self::ADMIN2, self::NAMED_BY_ADMIN2, 0],
    ];
    /** [ppc_variable_id, source, name, parameter, placeholder, removed] */
    private const VARIABLES = [
        [871501, self::MIXED, 'Keyword', 'kw', '{keyword}', 0],
        [871502, self::MIXED, 'Ad', 'ad', '{adid}', 0],
        [871503, self::MIXED, 'Old', 'old', '{old}', 1],
        [871504, self::SEVERAL, 'Sub', 'sub', '{sub}', 0],
        [871505, self::OWNERS_OWN, 'Own', 'own', '{own}', 0],
    ];
    /** [click_id, account]: TAKER's two on MIXED, OWNER's four (one on the removed account) */
    private const CLICKS = [
        [871401, self::T1], [871402, self::T1],
        [871403, self::O1], [871404, self::O1], [871405, self::O1], [871406, self::O2],
    ];
    /** user => the source their report filter names */
    private const FILTERS = [self::TAKER => self::MIXED, self::OWNER => self::MIXED, self::THIRD => self::SEVERAL];

    private const CREATED = 1_000_000_000;

    private static int $now = 0;
    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

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
        self::$now = time();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_split');
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'tss-log-');
        $this->logWas = (string) ini_set('error_log', $this->log);
        self::cleanUp();
        foreach (self::ROLES as $user => $role) {
            self::user($user);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
            self::row('202_api_keys', ['user_id' => $user, 'api_key' => 'tss-key-' . $user, 'created_at' => 1]);
            self::row('202_users_pref', [
                'user_id' => $user,
                'user_pref_ppc_network_id' => self::FILTERS[$user] ?? null,
            ]);
        }
        foreach (self::SOURCES as $id => [$user, $name]) {
            self::row('202_ppc_networks', [
                'ppc_network_id' => $id,
                'user_id' => $user,
                'ppc_network_name' => $name,
                // After the accounts: none reads as saved after a rename.
                'ppc_network_time' => self::CREATED + 100,
            ]);
        }
        foreach (self::ACCOUNTS as [$id, $user, $source, $removed]) {
            self::row('202_ppc_accounts', [
                'ppc_account_id' => $id,
                'user_id' => $user,
                'ppc_network_id' => $source,
                'ppc_account_name' => 'Account ' . $id,
                'ppc_account_deleted' => $removed,
                'ppc_account_time' => self::CREATED + 10,
            ]);
        }
        foreach (self::VARIABLES as [$id, $source, $name, $parameter, $placeholder, $removed]) {
            self::row('202_ppc_network_variables', [
                'ppc_variable_id' => $id,
                'ppc_network_id' => $source,
                'name' => $name,
                'parameter' => $parameter,
                'placeholder' => $placeholder,
                'deleted' => $removed,
            ]);
        }
        $users = array_column(self::ACCOUNTS, 1, 0);
        foreach (self::CLICKS as [$click, $account]) {
            self::row('202_clicks', [
                'click_id' => $click,
                'user_id' => $users[$account],
                'ppc_account_id' => $account,
                'landing_page_id' => 0,
                'click_cpc' => 0,
                'click_payout' => 0,
                'click_time' => self::$now - 2 * 86400,
            ]);
        }
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
        $users = implode(',', [...array_keys(self::ROLES), self::GHOST]);
        $tables = ['202_user_role', '202_api_keys', '202_users_pref', '202_users', '202_clicks', '202_dataengine',
            '202_dirty_hours'];
        foreach ($tables as $t) {
            self::q("DELETE FROM $t WHERE user_id IN ($users)");
        }
        // The copies are this class's users' sources; their variables go with them.
        self::q("DELETE v FROM 202_ppc_network_variables AS v INNER JOIN 202_ppc_networks AS n"
            . " ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id IN ($users)");
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id BETWEEN 871500 AND 871599');
        self::q("DELETE FROM 202_ppc_networks WHERE user_id IN ($users) OR ppc_network_id BETWEEN 871200 AND 871299");
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id BETWEEN 871300 AND 871399');
    }

    private static function controller(int $user): TrafficSourceOwnersController
    {
        $auth = Auth::fromRequest(['Authorization' => 'Bearer tss-key-' . $user], self::$db);

        return new TrafficSourceOwnersController(self::$db, $auth);
    }

    /** @return list<array<string, string|null>> */
    private static function rows(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private static function value(string $sql): ?string
    {
        $row = self::rows($sql)[0] ?? null;

        return $row === null ? null : array_values($row)[0];
    }

    /** @return array<int, int> account => the source it names */
    private static function accountSources(): array
    {
        $named = [];
        $rows = self::rows('SELECT ppc_account_id, ppc_network_id FROM 202_ppc_accounts'
            . ' WHERE ppc_account_id BETWEEN 871300 AND 871399 ORDER BY ppc_account_id');
        foreach ($rows as $row) {
            $named[(int) $row['ppc_account_id']] = (int) $row['ppc_network_id'];
        }

        return $named;
    }

    /** @return list<array<string, string|null>> this class's sources, copies included, as rows */
    private static function sources(): array
    {
        $users = implode(',', array_keys(self::ROLES));

        return self::rows('SELECT ppc_network_id, user_id, ppc_network_name, ppc_network_deleted, ppc_network_time'
            . " FROM 202_ppc_networks WHERE user_id IN ($users) OR ppc_network_id BETWEEN 871200 AND 871299"
            . ' ORDER BY ppc_network_id');
    }

    /** @return list<array<string, string|null>> a source's variables, without their ids */
    private static function variablesOf(int $sourceId): array
    {
        return self::rows('SELECT name, parameter, placeholder, deleted FROM 202_ppc_network_variables'
            . " WHERE ppc_network_id = $sourceId ORDER BY ppc_variable_id");
    }

    /** @return array<int, array<string, mixed>> this class's sources the report lists, by id */
    private static function listed(): array
    {
        $listed = [];
        foreach (self::controller(self::ADMIN)->report()['data']['sources'] as $entry) {
            if (isset(self::SOURCES[$entry['ppc_network_id']])) {
                $listed[$entry['ppc_network_id']] = $entry;
            }
        }

        return $listed;
    }

    /** @return list<array<string, string>> queued re-rolls of this class's users */
    private static function queued(): array
    {
        $users = implode(',', array_keys(self::ROLES));

        return self::rows('SELECT user_id, ppc_account_id, processed + 0 AS processed FROM 202_dirty_hours'
            . " WHERE user_id IN ($users) ORDER BY user_id, ppc_account_id");
    }

    /** @return array<int, ?string> user => the source their report filter names */
    private static function filters(): array
    {
        $users = implode(',', array_keys(self::ROLES));
        $filters = [];
        $rows = self::rows("SELECT user_id, user_pref_ppc_network_id FROM 202_users_pref WHERE user_id IN ($users)"
            . ' ORDER BY user_id');
        foreach ($rows as $row) {
            $filters[(int) $row['user_id']] = $row['user_pref_ppc_network_id'];
        }

        return $filters;
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
     * The field error a refused split names.
     *
     * @param array<string, mixed> $payload
     */
    private static function refusal(array $payload, string $field, int $as = self::ADMIN): string
    {
        try {
            self::controller($as)->split($payload);
        } catch (ValidationException $e) {
            $errors = $e->getFieldErrors();
            self::assertArrayHasKey($field, $errors, (string) json_encode($payload) . ': ' . json_encode($errors));

            return $errors[$field];
        }
        self::fail('refused: ' . json_encode($payload));
    }

    /** Everything a split may write, as it stands: a refused split leaves all of it so. */
    private static function state(): string
    {
        return (string) json_encode([
            self::sources(),
            self::accountSources(),
            self::rows('SELECT ppc_variable_id, ppc_network_id, name, deleted FROM 202_ppc_network_variables'
                . ' WHERE ppc_variable_id >= 871500 ORDER BY ppc_variable_id'),
            self::queued(),
            self::filters(),
        ]);
    }

    public function testASplitGivesEachOtherUserACopyAndTheReportNoLongerListsIt(): void
    {
        $entry = self::listed()[self::MIXED];
        self::assertSame([TrafficSourceOwners::OWNER_HAS_ACCOUNTS], $entry['ambiguous']);
        self::assertSame(2, $entry['custom_variables'], 'the live ones a split copies');
        self::assertSame([self::OWNER], array_column($entry['named_by'], 'user_id'));
        $before = self::sources();
        $ownersOwn = self::variablesOf(self::OWNERS_OWN);

        $answer = self::controller(self::ADMIN)->split([
            'ppc_network_id' => self::MIXED,
            'owner_user_id' => self::TAKER,
            'copy_to_user_ids' => [self::OWNER],
        ])['data'];

        $copy = $answer['copies'][0]['ppc_network_id'] ?? 0;
        self::assertGreaterThan(0, $copy);
        self::assertSame([
            'ppc_network_id' => self::MIXED,
            'ppc_network_name' => 'Mixed Source',
            'removed' => false,
            'owner_user_id' => self::TAKER,
            'copies' => [[
                'user_id' => self::OWNER,
                'ppc_network_id' => $copy,
                'ppc_account_ids' => [self::O1, self::O2],
                'custom_variables' => 2,
            ]],
            'queued_ppc_accounts' => 2,
        ], $answer);

        $after = self::sources();
        $made = array_values(array_filter($after, static fn (array $s): bool => (int) $s['ppc_network_id'] === $copy));
        self::assertCount(1, $made);
        self::assertSame([(string) self::OWNER, 'Mixed Source', '0'], [
            $made[0]['user_id'], $made[0]['ppc_network_name'], $made[0]['ppc_network_deleted'],
        ], 'the copy is in OWNER\'s account, with the source\'s name');
        self::assertGreaterThanOrEqual(self::$now, (int) $made[0]['ppc_network_time']);
        self::assertSame(
            $before,
            array_values(array_filter($after, static fn (array $s): bool => (int) $s['ppc_network_id'] !== $copy)),
            'no other source changed: MIXED stays in TAKER\'s account as it was'
        );
        self::assertSame([
            ['name' => 'Keyword', 'parameter' => 'kw', 'placeholder' => '{keyword}', 'deleted' => '0'],
            ['name' => 'Ad', 'parameter' => 'ad', 'placeholder' => '{adid}', 'deleted' => '0'],
        ], self::variablesOf($copy), 'the live custom variables are copied, in their order');
        self::assertCount(3, self::variablesOf(self::MIXED), 'the source keeps its own');
        self::assertSame($ownersOwn, self::variablesOf(self::OWNERS_OWN));

        self::assertSame([
            self::T1 => self::MIXED, self::O1 => $copy, self::O2 => $copy, self::O3 => self::SEVERAL,
            self::H1 => self::SEVERAL, self::O4 => self::OWNERS_OWN, self::O5 => self::IN_ADMIN2,
            self::T2 => self::NAMED_BY_ADMIN2, self::A2 => self::NAMED_BY_ADMIN2,
        ], self::accountSources(), "OWNER's accounts on MIXED, the removed one too, and nothing else moved");
        self::assertSame([
            self::ADMIN => null, self::SUPER => null, self::ADMIN2 => null,
            self::TAKER => (string) self::MIXED, self::OWNER => (string) $copy, self::THIRD => (string) self::SEVERAL,
        ], self::filters(), "OWNER's report filter names the copy; TAKER's still names MIXED");
        self::assertSame([
            ['user_id' => (string) self::OWNER, 'ppc_account_id' => (string) self::O1, 'processed' => '0'],
            ['user_id' => (string) self::OWNER, 'ppc_account_id' => (string) self::O2, 'processed' => '0'],
        ], self::queued(), "OWNER's moved accounts are queued for the report rollup, and only they");

        self::assertTrue(self::sees(self::OWNER, $copy), 'OWNER has a source of their own');
        self::assertFalse(self::sees(self::OWNER, self::MIXED));
        self::assertTrue(self::sees(self::TAKER, self::MIXED), 'TAKER keeps theirs');
        self::assertFalse(self::sees(self::TAKER, $copy));
        self::assertArrayNotHasKey(self::MIXED, self::listed(), 'it is no longer listed');
        self::assertStringContainsString(
            'Prosper202 traffic source split: traffic source ' . self::MIXED . ' ("Mixed Source") stays in user '
                . self::TAKER . "'s account; user " . self::OWNER . "'s 2 traffic source account(s) now name its copy,"
                . ' traffic source ' . $copy . "; at user " . self::ADMIN . "'s request",
            (string) file_get_contents($this->log)
        );

        $again = self::refusal(['ppc_network_id' => self::MIXED], 'ppc_network_id');
        self::assertStringContainsString('is not in the report', $again);
    }

    public function testEachUserWhoseAccountsNameItGetsACopyOfTheirOwn(): void
    {
        $entry = self::listed()[self::SEVERAL];
        self::assertSame([TrafficSourceOwners::SEVERAL_USERS], $entry['ambiguous']);
        self::q('UPDATE 202_ppc_networks SET ppc_network_deleted = 1 WHERE ppc_network_id = ' . self::SEVERAL);

        $answer = self::controller(self::ADMIN)->split(['ppc_network_id' => self::SEVERAL])['data'];

        self::assertSame(self::ADMIN, $answer['owner_user_id']);
        self::assertTrue($answer['removed']);
        self::assertSame([self::OWNER, self::THIRD], array_column($answer['copies'], 'user_id'));
        [$ownerCopy, $thirdCopy] = array_column($answer['copies'], 'ppc_network_id');
        self::assertNotSame($ownerCopy, $thirdCopy);
        $accounts = self::accountSources();
        self::assertSame([$ownerCopy, $thirdCopy], [$accounts[self::O3], $accounts[self::H1]]);
        $userOf = static fn (int $source): ?string => self::value(
            "SELECT user_id FROM 202_ppc_networks WHERE ppc_network_id = $source"
        );
        self::assertSame((string) self::OWNER, $userOf($ownerCopy));
        self::assertSame((string) self::THIRD, $userOf($thirdCopy));
        self::assertSame(
            (string) self::ADMIN,
            $userOf(self::SEVERAL),
            'the source stays in ADMIN\'s account, which no account names now'
        );
        foreach ([$ownerCopy, $thirdCopy] as $copy) {
            self::assertSame(
                [['name' => 'Sub', 'parameter' => 'sub', 'placeholder' => '{sub}', 'deleted' => '0']],
                self::variablesOf($copy)
            );
            self::assertSame(
                '1',
                self::value("SELECT ppc_network_deleted FROM 202_ppc_networks WHERE ppc_network_id = $copy"),
                'a copy of a removed source is removed too'
            );
        }
        self::assertSame((string) $thirdCopy, self::filters()[self::THIRD], "THIRD's report filter names THIRD's copy");
        self::assertArrayNotHasKey(self::SEVERAL, self::listed());
    }

    /**
     * The clicks already on the moved accounts are reported under the copy
     * once the cron job re-rolls them. Before the split the other user's
     * clicks have no source of their own (ClickRollupSql ties the source to
     * the click's account), so the breakdown, which joins the source, leaves
     * them out. The re-roll is the cron job's own pass, in a process that
     * boots the app.
     */
    public function testTheClicksAlreadyRecordedReportUnderEachUsersOwnSourceAfterTheCronJob(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before the cron pass ran.');
        }
        self::atThisVersion();
        self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.click_id BETWEEN 871400 AND 871499'));
        self::assertSame([], self::bySource(self::OWNER), "OWNER's clicks are under no source of OWNER's");
        self::assertSame([[self::MIXED, 'Mixed Source', 2]], self::bySource(self::TAKER));

        $split = self::controller(self::ADMIN)->split(['ppc_network_id' => self::MIXED])['data'];
        $copy = $split['copies'][0]['ppc_network_id'];
        self::assertSame([], self::bySource(self::OWNER), 'the report rows wait for the cron job');
        self::cronPass();

        self::assertSame([[$copy, 'Mixed Source', 4]], self::bySource(self::OWNER), "OWNER's four, under OWNER's copy");
        self::assertSame([[self::MIXED, 'Mixed Source', 2]], self::bySource(self::TAKER), "TAKER's two, as they were");
    }

    /** A split reads no clicks: it is planned from the accounts, as repair-all is. */
    public function testASplitReadsNoClicks(): void
    {
        // With the clicks table out of reach, any read of it fails the call.
        self::q('RENAME TABLE 202_clicks TO 202_clicks_tss_hidden');
        try {
            $answer = self::controller(self::ADMIN)->split(['ppc_network_id' => self::MIXED])['data'];
        } finally {
            self::q('RENAME TABLE 202_clicks_tss_hidden TO 202_clicks');
        }

        self::assertSame([self::OWNER], array_column($answer['copies'], 'user_id'));
    }

    public function testASplitOfWhatTheReportShowedIsRefusedOnceItSaysOtherwise(): void
    {
        $shown = ['ppc_network_id' => self::MIXED, 'owner_user_id' => self::TAKER, 'copy_to_user_ids' => [self::OWNER]];
        $state = self::state();

        // In THIRD's account now.
        self::q('UPDATE 202_ppc_networks SET user_id = ' . self::THIRD . ' WHERE ppc_network_id = ' . self::MIXED);
        self::assertStringContainsString(
            'is now in user ' . self::THIRD . "'s account, not in user " . self::TAKER
                . "'s as the report you read said",
            self::refusal($shown, 'owner_user_id')
        );
        self::q('UPDATE 202_ppc_networks SET user_id = ' . self::TAKER . ' WHERE ppc_network_id = ' . self::MIXED);

        // THIRD's account names it too now: THIRD would get a copy nobody was shown.
        self::q('UPDATE 202_ppc_accounts SET ppc_network_id = ' . self::MIXED . ' WHERE ppc_account_id = ' . self::H1);
        self::assertStringContainsString(
            'would now give a copy to users ' . self::OWNER . ', ' . self::THIRD . ', not to users ' . self::OWNER,
            self::refusal($shown, 'copy_to_user_ids')
        );
        self::q('UPDATE 202_ppc_accounts SET ppc_network_id = ' . self::SEVERAL
            . ' WHERE ppc_account_id = ' . self::H1);
        self::assertSame($state, self::state(), 'nothing was written');

        // A user whose accounts do not name it gets no copy by being named.
        self::assertStringContainsString(
            'not to users ' . self::OWNER . ', ' . self::THIRD,
            self::refusal(['copy_to_user_ids' => [self::THIRD, self::OWNER]] + $shown, 'copy_to_user_ids')
        );
        self::assertSame($state, self::state(), 'nothing was written');

        $answer = self::controller(self::ADMIN)->split($shown)['data'];
        self::assertSame([self::OWNER], array_column($answer['copies'], 'user_id'), 'what was shown is split');
    }

    public function testASplitOfRowsThatChangedAfterTheyWereReadCopiesNothing(): void
    {
        $owners = new TrafficSourceOwners(new Connection(self::$db));
        $plan = $owners->planSplit(self::MIXED);
        self::assertSame(self::TAKER, $plan['owner']);
        self::assertSame([self::OWNER => [self::O1, self::O2]], $plan['copies']);

        // OWNER moved the removed account to their own source meanwhile.
        self::q('UPDATE 202_ppc_accounts SET ppc_network_id = ' . self::OWNERS_OWN
            . ' WHERE ppc_account_id = ' . self::O2);
        $state = self::state();
        try {
            $owners->split(self::MIXED, $plan['owner'], $plan['copies'], self::ADMIN);
            self::fail('a split planned on rows that changed is refused');
        } catch (TrafficSourceRepairRefused $e) {
            self::assertStringContainsString('changed after it was read', $e->getMessage());
            self::assertStringContainsString('for their traffic source accounts ' . self::O1 . '.', $e->getMessage());
        }
        self::assertSame($state, self::state(), 'nothing was copied, moved or queued');
    }

    /**
     * The split's writes are one transaction: the second user's accounts are
     * refused here by a trigger after the first user's copy, variables,
     * accounts and report filter were written, and none of it stands.
     */
    public function testAFailureMidSplitLeavesNothingHalfDone(): void
    {
        $state = self::state();
        self::q("CREATE TRIGGER p202_test_refuse_split BEFORE UPDATE ON 202_ppc_accounts FOR EACH ROW
            IF NEW.ppc_account_id = " . self::H1 . " THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account refused by the test';
            END IF");
        try {
            self::controller(self::ADMIN)->split(['ppc_network_id' => self::SEVERAL]);
            self::fail('the split went through the refused write');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('account refused by the test', (string) $e->getPrevious()?->getMessage());
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_split');
        }

        self::assertSame($state, self::state(), "no copy, no moved account, no variable, filter or queued re-roll");
        self::assertArrayHasKey(self::SEVERAL, self::listed(), 'it is still listed');
        self::assertStringNotContainsString('traffic source split', (string) file_get_contents($this->log));
    }

    public function testASplitNeedsACallerWhoMayActOnEveryUserItNames(): void
    {
        $state = self::state();
        $refused = [
            self::IN_ADMIN2 => 'the source is in an Admin\'s account',
            self::NAMED_BY_ADMIN2 => 'an Admin gets a copy',
        ];
        foreach ($refused as $source => $why) {
            try {
                self::controller(self::ADMIN)->split(['ppc_network_id' => $source]);
                self::fail("an Admin may not split when $why");
            } catch (AuthException $e) {
                self::assertSame(403, $e->getCode(), $why);
                self::assertStringContainsString('add_edit_delete_admin', $e->getMessage(), $why);
            }
        }
        self::assertSame($state, self::state(), 'nothing was written');

        foreach ([self::IN_ADMIN2 => [self::OWNER], self::NAMED_BY_ADMIN2 => [self::ADMIN2]] as $source => $copyTo) {
            $answer = self::controller(self::SUPER)->split(['ppc_network_id' => $source])['data'];
            self::assertSame($copyTo, array_column($answer['copies'], 'user_id'));
        }
    }

    public function testNothingIsCopiedForADeletedUserOrOneWithNoRecord(): void
    {
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::THIRD);
        $state = self::state();
        self::assertStringContainsString(
            'User ' . self::THIRD . ', whose traffic source accounts name traffic source ' . self::SEVERAL
                . ', is deleted',
            self::refusal(['ppc_network_id' => self::SEVERAL], 'ppc_network_id')
        );
        self::q('UPDATE 202_users SET user_deleted = 0 WHERE user_id = ' . self::THIRD);

        self::row('202_ppc_accounts', [
            'ppc_account_id' => 871310, 'user_id' => self::GHOST, 'ppc_network_id' => self::MIXED,
            'ppc_account_name' => 'Ghost', 'ppc_account_time' => self::CREATED,
        ]);
        self::assertStringContainsString(
            'User ' . self::GHOST . ', whose traffic source accounts name traffic source ' . self::MIXED
                . ', does not exist on this install',
            self::refusal(['ppc_network_id' => self::MIXED], 'ppc_network_id')
        );
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = 871310');
        self::assertSame($state, self::state(), 'nothing was written');
    }

    public function testABodyIsReadStrictly(): void
    {
        $state = self::state();
        self::assertStringContainsString(
            'There is no traffic source 871299',
            self::refusal(['ppc_network_id' => 871299], 'ppc_network_id')
        );
        self::assertStringContainsString(
            'every traffic source account that names it is in user ' . self::OWNER . "'s account",
            self::refusal(['ppc_network_id' => self::OWNERS_OWN], 'ppc_network_id')
        );
        $bodies = [
            [[], 'ppc_network_id'],
            [['ppc_network_id' => 'abc'], 'ppc_network_id'],
            [['ppc_network_id' => '12abc'], 'ppc_network_id'],
            [['ppc_network_id' => 0], 'ppc_network_id'],
            [['ppc_network_id' => self::MIXED, 'to_user_id' => self::OWNER], 'to_user_id'],
            [['ppc_network_id' => self::MIXED, 'all' => true], 'all'],
            [['ppc_network_id' => self::MIXED, 'dry_run' => 1], 'dry_run'],
            [['ppc_network_id' => self::MIXED, 'copy_to' => [self::OWNER]], 'copy_to'],
            [['ppc_network_id' => self::MIXED, 'owner_user_id' => 'x'], 'owner_user_id'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => null], 'copy_to_user_ids'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => (string) self::OWNER], 'copy_to_user_ids'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => []], 'copy_to_user_ids'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => [self::OWNER, self::OWNER]], 'copy_to_user_ids'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => ['owner' => self::OWNER]], 'copy_to_user_ids'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => [0]], 'copy_to_user_ids.0'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => ['1.5']], 'copy_to_user_ids.0'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => [self::OWNER, '871105x']], 'copy_to_user_ids.1'],
            [['ppc_network_id' => self::MIXED, 'copy_to_user_ids' => [16777216]], 'copy_to_user_ids.0'],
        ];
        foreach ($bodies as [$payload, $field]) {
            self::refusal($payload, $field);
        }
        self::assertSame($state, self::state(), 'nothing was written');

        $answer = self::controller(self::ADMIN)->split([
            'ppc_network_id' => (string) self::MIXED,
            'owner_user_id' => (string) self::TAKER,
            'copy_to_user_ids' => [(string) self::OWNER],
        ])['data'];
        self::assertSame([self::OWNER], array_column($answer['copies'], 'user_id'), 'ids as a form posts them');
    }

    /**
     * 202_ppc_network_variables.ppc_network_id is a signed mediumint, and a
     * source's id is not: a server without strict mode clips a copy's id
     * past 8388607 to 8388607 in the variables it writes, which is another
     * source's (here a source of THIRD's). Such a split is refused whole.
     */
    public function testACopyWhoseIdTheVariablesCannotHoldIsRefusedWhole(): void
    {
        $state = self::state();
        self::row('202_ppc_networks', [
            'ppc_network_id' => 8388607, 'user_id' => self::THIRD, 'ppc_network_name' => 'Highest variable id',
            'ppc_network_time' => self::CREATED,
        ]);
        self::q("SET SESSION sql_mode=''");
        try {
            self::controller(self::ADMIN)->split(['ppc_network_id' => self::MIXED]);
            self::fail('a copy whose variables would be clipped onto another source was made');
        } catch (DatabaseException $e) {
            self::assertStringContainsString(
                'was given id 8388608, where its custom variables cannot be kept',
                (string) $e->getPrevious()?->getMessage()
            );
        } finally {
            self::q(self::$strict);
            $onto = self::variablesOf(8388607);
            self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_network_id >= 8388607');
            self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id >= 8388607');
            self::q('ALTER TABLE 202_ppc_networks AUTO_INCREMENT = 1');
        }
        self::assertSame([], $onto, "nothing was written onto THIRD's source");
        self::assertSame($state, self::state(), 'nothing was written');
    }

    /**
     * A split waits for a user that UserDataPurge::deleteUser() is deleting
     * (it locks the users first, as the repair does), and reads them as
     * deleted once the delete commits.
     */
    public function testASplitWaitsForAUserBeingDeletedAndCopiesNothingIntoIt(): void
    {
        $state = self::state();
        $holder = self::connect();
        $conn = new Connection($holder);
        $child = null;
        try {
            $conn->transaction(function () use ($conn, &$child): void {
                $lock = $conn->prepareWrite(UserDataPurge::LOCK_USER);
                $conn->bind($lock, 'i', [self::OWNER]);
                $conn->fetchAll($lock);
                [$proc, $pipes, $thread] = self::splitInChild(self::MIXED);
                self::assertSame('waiting', self::settle($proc, $thread), 'the split waits for the user');
                $child = [$proc, $pipes];
                $delete = $conn->prepareWrite('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ?');
                $conn->bind($delete, 'i', [self::OWNER]);
                $conn->executeUpdate($delete);
            });
        } finally {
            $holder->close();
        }
        [$out, $err] = self::finish($child);

        self::assertStringStartsWith('refused: ', $out, $err);
        self::assertStringContainsString('User ' . self::OWNER . ', whose traffic source accounts name', $out);
        self::q('UPDATE 202_users SET user_deleted = 0 WHERE user_id = ' . self::OWNER);
        self::assertSame($state, self::state(), 'nothing was copied into the deleted account');
    }

    /**
     * A click's report rollup (ClickRollupSql's INSERT … SELECT)
     * share-locks the click's traffic source account and then the account's
     * source. A split writes both, so it takes them in that order: the
     * rollup's two reads are taken here by hand, on either side of a split in
     * a child process, and the split waits for the account with the source
     * still free.
     */
    public function testASplitDoesNotDeadlockWithARollupReadingTheSource(): void
    {
        $rollup = self::connect();
        self::assertTrue($rollup->query('SET SESSION innodb_lock_wait_timeout = 15'), $rollup->error);
        try {
            self::assertTrue($rollup->begin_transaction(), $rollup->error);
            $account = $rollup->query('SELECT ppc_account_id FROM 202_ppc_accounts WHERE ppc_account_id = '
                . self::O1 . ' LOCK IN SHARE MODE');
            self::assertInstanceOf(\mysqli_result::class, $account, $rollup->error);
            [$proc, $pipes, $thread] = self::splitInChild(self::MIXED);
            self::assertSame('waiting', self::settle($proc, $thread), 'the split waits for the account');
            try {
                $source = $rollup->query('SELECT ppc_network_id FROM 202_ppc_networks WHERE ppc_network_id = '
                    . self::MIXED . ' LOCK IN SHARE MODE');
                $read = $source instanceof \mysqli_result ? 'read' : $rollup->error;
            } catch (\mysqli_sql_exception $e) {
                $read = $e->getMessage();
            }
            $rollup->query('COMMIT');
        } finally {
            $rollup->close();
        }
        [$out, $err] = self::finish([$proc, $pipes]);

        self::assertSame('read', $read, "the rollup's read of the source");
        self::assertSame('ok', $out, $err);
        self::assertNotSame(self::MIXED, self::accountSources()[self::O1], 'the split went through');
    }

    /** The cron job's report rollup reads the app at the schema's version. */
    private static function atThisVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/version.php';
        if (self::$versions === null) {
            self::$versions = array_column(self::rows('SELECT version FROM 202_version'), 'version');
        }
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
    }

    /** The cron job's dirty-hours pass (DataEngine::processDirtyHours()), in a process that boots the app. */
    private static function cronPass(): void
    {
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::OWNER,
            'P202_TEST_FROM' => (string) (self::$now - 3600),
            'P202_TEST_TO' => (string) (self::$now + 3600),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Report/fixtures/account-scope-runner.php',
                'dirty-hours'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertSame('RETURNED {"left":0}', trim($out), 'the pass processed and cleared the queue: ' . $out . $err);
    }

    /**
     * GET /reports/breakdown?breakdown=ppc_network for $user over the clicks'
     * days, as [id, name, clicks] rows: the report that groups by the
     * source each report row holds.
     *
     * @return list<array{0: ?int, 1: ?string, 2: int}>
     */
    private static function bySource(int $user): array
    {
        $rows = (new ReportsController(self::$db, $user))->breakdown([
            'breakdown' => 'ppc_network',
            'time_from' => (string) (self::$now - 3 * 86400),
            'time_to' => (string) self::$now,
        ])['data'];

        return array_map(static fn (array $row): array => [
            $row['id'] === null ? null : (int) $row['id'],
            $row['name'],
            (int) $row['total_clicks'],
        ], $rows);
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

    /** @return array{resource, array<int, resource>, string} the child, its pipes and its connection id */
    private static function splitInChild(int $sourceId): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/traffic-source-repair-runner.php',
                (string) $sourceId, (string) self::ADMIN, 'split',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');

        return [$proc, $pipes, $thread];
    }

    /**
     * @param array{resource, array<int, resource>}|null $child
     * @return array{string, string} what it printed after its connection id, and its stderr
     */
    private static function finish(?array $child): array
    {
        self::assertNotNull($child, 'the child ran');
        [$proc, $pipes] = $child;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [$out, $err];
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
        self::fail("The split on connection $thread neither ended nor waited on a lock");
    }
}

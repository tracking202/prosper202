<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickPathReads;
use Prosper202\Click\TrackerVariables;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\Setup\TrafficSourceOwners;

/**
 * The cron job's automatic split (TrafficSourceOwners::splitAutomatically()),
 * on a real server: a traffic source every other user's accounts were put on
 * after it was last saved is split without being asked, and every other
 * listed source is left exactly as it is, still listed.
 *
 * KEEPER holds every source. NEWCOMER's accounts name them:
 * - PUT_ON: NEWCOMER's account was saved after the source (and KEEPER has an
 *   account on it too): split automatically;
 * - EARLIER: NEWCOMER's account was saved before the source was: left;
 * - BOTH: one of NEWCOMER's accounts after, one before: left;
 * - TWO_USERS: NEWCOMER's after, THIRD's before: left, for both;
 * - NAMESAKE: after, but NEWCOMER has a source of that name already: left;
 * - OF_DELETED: after, by a deleted user: left.
 *
 * Skips unless P202_TEST_DB_* names a scratch database; the report is
 * install-wide, so never point it at one an instance uses.
 *
 * @group integration
 */
final class TrafficSourceAutomaticSplitIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ADMIN = 872101;
    private const KEEPER = 872102;
    private const NEWCOMER = 872103;
    private const THIRD = 872104;
    private const GONE = 872105;
    /** user => role: Admin 2, Campaign manager 3 */
    private const ROLES = [self::ADMIN => 2, self::KEEPER => 3, self::NEWCOMER => 3, self::THIRD => 3, self::GONE => 3];

    private const PUT_ON = 872201;
    private const EARLIER = 872202;
    private const BOTH = 872203;
    private const TWO_USERS = 872204;
    private const NAMESAKE = 872205;
    private const OF_DELETED = 872206;
    private const NEWCOMERS_OWN = 872207;
    /** source => [the user it is in, its name] */
    private const SOURCES = [
        self::PUT_ON => [self::KEEPER, 'Search Ads'],
        self::EARLIER => [self::KEEPER, 'Display Ads'],
        self::BOTH => [self::KEEPER, 'Video Ads'],
        self::TWO_USERS => [self::KEEPER, 'Email List'],
        self::NAMESAKE => [self::KEEPER, 'Newsletter'],
        self::OF_DELETED => [self::KEEPER, 'Partner Sites'],
        self::NEWCOMERS_OWN => [self::NEWCOMER, 'NEWSLETTER'],
    ];

    private const SAVED = 1_000_000_000;
    private const BEFORE = self::SAVED - 50;
    private const AFTER = self::SAVED + 50;

    private const K1 = 872301;
    private const N1 = 872302;
    private const N2 = 872303;
    private const N3 = 872304;
    private const N4 = 872305;
    private const N5 = 872306;
    private const H1 = 872307;
    private const N6 = 872308;
    private const G1 = 872309;
    /** [ppc_account_id, user, source, saved] */
    private const ACCOUNTS = [
        [self::K1, self::KEEPER, self::PUT_ON, self::BEFORE],
        [self::N1, self::NEWCOMER, self::PUT_ON, self::AFTER],
        [self::N2, self::NEWCOMER, self::EARLIER, self::BEFORE],
        [self::N3, self::NEWCOMER, self::BOTH, self::AFTER],
        [self::N4, self::NEWCOMER, self::BOTH, self::BEFORE],
        [self::N5, self::NEWCOMER, self::TWO_USERS, self::AFTER],
        [self::H1, self::THIRD, self::TWO_USERS, self::BEFORE],
        [self::N6, self::NEWCOMER, self::NAMESAKE, self::SAVED],
        [self::G1, self::GONE, self::OF_DELETED, self::AFTER],
    ];
    /** [ppc_variable_id, source, name, parameter, removed] */
    private const VARIABLES = [
        [872501, self::PUT_ON, 'Placement', 'placement', 0],
        [872502, self::PUT_ON, 'Ad group', 'adgroup', 0],
        [872503, self::PUT_ON, 'Retired', 'retired', 1],
        [872504, self::EARLIER, 'Placement', 'placement', 0],
    ];
    private const TRACKER_PUBLIC = 872601;

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
        $this->log = (string) tempnam(sys_get_temp_dir(), 'tsa-log-');
        $this->logWas = (string) ini_set('error_log', $this->log);
        self::cleanUp();
        foreach (self::ROLES as $user => $role) {
            self::user($user);
            self::row('202_user_role', ['user_id' => $user, 'role_id' => $role]);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::GONE);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::ADMIN);
        foreach (self::SOURCES as $id => [$user, $name]) {
            self::row('202_ppc_networks', ['ppc_network_id' => $id, 'user_id' => $user,
                'ppc_network_name' => $name, 'ppc_network_time' => self::SAVED]);
        }
        foreach (self::ACCOUNTS as [$id, $user, $source, $saved]) {
            self::row('202_ppc_accounts', ['ppc_account_id' => $id, 'user_id' => $user, 'ppc_network_id' => $source,
                'ppc_account_name' => 'Account ' . $id, 'ppc_account_time' => $saved]);
        }
        foreach (self::VARIABLES as [$id, $source, $name, $parameter, $removed]) {
            self::row('202_ppc_network_variables', ['ppc_variable_id' => $id, 'ppc_network_id' => $source,
                'name' => $name, 'parameter' => $parameter, 'placeholder' => '{' . $parameter . '}',
                'deleted' => $removed]);
        }
        self::row('202_trackers', ['user_id' => self::NEWCOMER, 'tracker_id_public' => self::TRACKER_PUBLIC,
            'aff_campaign_id' => 0, 'text_ad_id' => 0, 'ppc_account_id' => self::N1, 'landing_page_id' => 0,
            'rotator_id' => 0, 'click_cloaking' => 0, 'tracker_time' => self::SAVED]);
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
        self::q("DELETE FROM 202_change_log WHERE entity = 'traffic_source_split'");
        self::q("DELETE v FROM 202_ppc_network_variables AS v JOIN 202_ppc_networks AS n"
            . " ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id IN ($users)");
        foreach (
            ['202_user_role', '202_users_pref', '202_users', '202_dirty_hours', '202_trackers',
            '202_ppc_accounts', '202_ppc_networks'] as $table
        ) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function owners(): TrafficSourceOwners
    {
        return new TrafficSourceOwners(new Connection(self::$db));
    }

    /** @return list<array<string, string|null>> */
    private static function rows(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** Everything a split could write, but the split's own record: sources, accounts, variables, filters. */
    private static function state(): array
    {
        $users = implode(',', array_keys(self::ROLES));

        return [
            self::rows("SELECT * FROM 202_ppc_networks WHERE user_id IN ($users) ORDER BY ppc_network_id"),
            self::rows("SELECT * FROM 202_ppc_accounts WHERE user_id IN ($users) ORDER BY ppc_account_id"),
            self::rows('SELECT v.* FROM 202_ppc_network_variables AS v JOIN 202_ppc_networks AS n'
                . " ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id IN ($users) ORDER BY v.ppc_variable_id"),
            self::rows("SELECT user_id, user_pref_ppc_network_id FROM 202_users_pref WHERE user_id IN ($users)"
                . ' ORDER BY user_id'),
        ];
    }

    /** @return array<int, array<string, mixed>> the report's entries by source */
    private static function listed(): array
    {
        return array_column(self::owners()->report(false), null, 'ppc_network_id');
    }

    /** The variables a click on NEWCOMER's tracker reads, as dl.php reads them. */
    private static function clickReads(): array
    {
        $row = ClickPathReads::uncached(new Connection(self::$db))->tracker(self::TRACKER_PUBLIC);
        self::assertIsArray($row);

        return array_column(TrackerVariables::pairs($row), 0);
    }

    public function testTheReportSaysWhichSourcesTheCronJobSplitsAndWhyItLeavesTheRest(): void
    {
        $listed = self::listed();
        self::assertSame(
            [self::PUT_ON, self::EARLIER, self::BOTH, self::TWO_USERS, self::NAMESAKE, self::OF_DELETED],
            array_keys($listed)
        );
        self::assertSame([
            self::PUT_ON => [],
            self::EARLIER => ['may_be_theirs'],
            self::BOTH => ['may_be_theirs'],
            self::TWO_USERS => ['may_be_theirs'],
            // Saved in the source's own second: not before it. The name is compared as the column does.
            self::NAMESAKE => ['same_name'],
            self::OF_DELETED => ['deleted_user'],
        ], array_column($listed, 'auto_split_blocked_by', 'ppc_network_id'));
        foreach ($listed as $id => $entry) {
            self::assertSame($id === self::PUT_ON, $entry['auto_split'], "source $id");
        }
        // The move the report offers is a separate question: only EARLIER has one user to go back to.
        self::assertSame(
            [self::EARLIER => self::NEWCOMER],
            array_filter(array_column($listed, 'repair_to_user_id', 'ppc_network_id'))
        );
    }

    public function testASourceTheAccountsWerePutOnIsSplitAndEveryOtherIsLeftAsItIs(): void
    {
        self::assertSame([], self::clickReads(), 'before: a click on the account reads none of its variables');
        $before = self::state();

        $done = self::owners()->splitAutomatically();

        self::assertCount(1, $done);
        self::assertSame(self::PUT_ON, $done[0]['ppc_network_id']);
        self::assertSame(self::KEEPER, $done[0]['owner_user_id']);
        self::assertCount(1, $done[0]['copies']);
        $copy = $done[0]['copies'][0];
        self::assertSame(self::NEWCOMER, $copy['user_id']);
        self::assertSame([self::N1], $copy['ppc_account_ids']);
        self::assertSame(2, $copy['custom_variables']);
        $copyId = (int) $copy['ppc_network_id'];

        // What a click on the account records now: the copy's live variables.
        self::assertSame(['placement', 'adgroup'], self::clickReads());
        $reads = ClickPathReads::uncached(new Connection(self::$db));
        self::assertNull($reads->sourceOfAnotherUser(self::NEWCOMER, self::N1)['source_user_id']);

        // The copy, and nothing else: every row that was there is as it was,
        // but the one account, which names the copy.
        $after = self::state();
        $was = $before;
        foreach ($was[1] as &$account) {
            if ((int) $account['ppc_account_id'] === self::N1) {
                $account['ppc_network_id'] = (string) $copyId;
            }
        }
        unset($account);
        $new = [
            array_values(array_filter($after[0], static fn (array $r): bool => (int) $r['ppc_network_id'] === $copyId)),
            array_values(array_filter($after[2], static fn (array $r): bool => (int) $r['ppc_network_id'] === $copyId)),
        ];
        $notCopy = static fn (array $r): bool => (int) $r['ppc_network_id'] !== $copyId;
        $after[0] = array_values(array_filter($after[0], $notCopy));
        $after[2] = array_values(array_filter($after[2], $notCopy));
        self::assertSame($was, $after, 'the source, its user\'s account and every other source are untouched');
        self::assertCount(1, $new[0]);
        self::assertSame(
            [(string) self::NEWCOMER, 'Search Ads', '0'],
            [$new[0][0]['user_id'], $new[0][0]['ppc_network_name'], $new[0][0]['ppc_network_deleted']]
        );
        self::assertSame(
            [['Placement', 'placement', '0', '872501'], ['Ad group', 'adgroup', '0', '872502']],
            array_map(static fn (array $v): array => [$v['name'], $v['parameter'], $v['deleted'],
                $v['copied_from_ppc_variable_id']], $new[1]),
            'each of the copy\'s variables names the one it was copied from'
        );

        // The fixed point: no longer listed, and the rest are, as they were.
        self::assertSame(
            [self::EARLIER, self::BOTH, self::TWO_USERS, self::NAMESAKE, self::OF_DELETED],
            array_keys(self::listed())
        );
        // The account is queued for the report rollup, as a user's split queues it.
        self::assertSame(
            [['user_id' => (string) self::NEWCOMER, 'ppc_account_id' => (string) self::N1, 'processed' => '0']],
            self::queued()
        );

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('traffic source ' . self::PUT_ON . ' ("Search Ads") stays in user '
            . self::KEEPER . '\'s account; user ' . self::NEWCOMER
            . '\'s 1 traffic source account(s) now name its copy,'
            . ' traffic source ' . $copyId . '; made by the cron job without being asked', $log);

        // Run again: nothing more.
        $settled = self::state();
        self::assertSame([], self::owners()->splitAutomatically());
        self::assertSame($settled, self::state());
        self::assertCount(1, self::rows("SELECT 1 FROM 202_change_log WHERE entity = 'traffic_source_split'"));
    }

    /** @return list<array<string, string|null>> the re-rolls queued for the report rollup */
    private static function queued(): array
    {
        return self::rows('SELECT user_id, ppc_account_id, processed + 0 AS processed FROM 202_dirty_hours'
            . ' WHERE user_id IN (' . implode(',', array_keys(self::ROLES)) . ') ORDER BY ppc_account_id');
    }

    public function testTheSplitIsListedWithWhoWasGivenWhatAndWhen(): void
    {
        $from = time();
        $done = self::owners()->splitAutomatically();
        $copyId = (int) $done[0]['copies'][0]['ppc_network_id'];

        $splits = self::owners()->recentSplits($from - 5);
        self::assertCount(1, $splits);
        $at = $splits[0]['split_at'];
        self::assertGreaterThanOrEqual($from, $at);
        self::assertSame([
            'ppc_network_id' => self::PUT_ON,
            'ppc_network_name' => 'Search Ads',
            'removed' => false,
            'owner_user_id' => self::KEEPER,
            'automatic' => true,
            'actor_user_id' => 0,
            'split_at' => $at,
            'copies' => [[
                'user_id' => self::NEWCOMER, 'ppc_network_id' => $copyId, 'ppc_account_ids' => [self::N1],
                'custom_variables' => 2, 'user_name' => 'rp' . self::NEWCOMER,
            ]],
            'owner_user_name' => 'rp' . self::KEEPER,
        ], $splits[0]);
        self::assertSame([], self::owners()->recentSplits($at + 1), 'older than the window: not listed');

        // A user's split is listed too, as theirs.
        $plan = self::owners()->planSplit(self::TWO_USERS);
        self::owners()->split(self::TWO_USERS, $plan['owner'], $plan['copies'], self::ADMIN);
        $splits = self::owners()->recentSplits($from - 5);
        self::assertSame([[self::TWO_USERS, false, self::ADMIN], [self::PUT_ON, true, 0]], array_map(
            static fn (array $s): array => [$s['ppc_network_id'], $s['automatic'], $s['actor_user_id']],
            $splits
        ));

        // The API's answer and the page carry both.
        require_once dirname(__DIR__, 2) . '/202-config/functions-traffic-source-owners-ui.php';
        $data = \p202_ts_owners_controller(self::$db, self::ADMIN)->report(false)['data'];
        self::assertSame($splits, $data['splits']);
        self::assertSame(TrafficSourceOwners::SPLITS_NOTE, $data['splits_note']);
        self::assertSame(0, $data['auto_split'], 'nothing is left for the cron job');
        $notice = \p202_ts_owners_notice($data, 'administration.php');
        self::assertStringContainsString(
            '2 traffic sources were split in the last 30 days, 1 of them automatically.',
            $notice
        );
        $table = \p202_ts_owners_splits($data);
        self::assertStringContainsString('<tr data-split-of="' . self::PUT_ON . '">', $table);
        self::assertStringContainsString(
            'copy #' . $copyId . ' · 1 traffic source account (' . self::N1 . ') · 2 custom variables',
            $table
        );
        self::assertStringContainsString('<span class="p202-pill p202-pill--good">automatically</span>', $table);
        self::assertStringContainsString('by user ' . self::ADMIN, $table);
        $note = htmlspecialchars(TrafficSourceOwners::SPLITS_NOTE, ENT_QUOTES, 'UTF-8');
        self::assertStringContainsString($note, $table);
    }

    public function testASourceThatStoppedBeingOneToSplitIsRefusedUnderTheLock(): void
    {
        $plan = self::owners()->planSplit(self::PUT_ON);
        // After the plan was read: the user makes a source of that name.
        self::row('202_ppc_networks', ['user_id' => self::NEWCOMER, 'ppc_network_name' => 'search ads',
            'ppc_network_time' => self::SAVED]);
        $before = self::state();
        try {
            self::owners()->split(self::PUT_ON, $plan['owner'], $plan['copies'], 0, true);
            self::fail('the split is refused');
        } catch (\Prosper202\Setup\TrafficSourceRepairRefused $e) {
            self::assertStringContainsString('is not one to split without being asked (same_name)', $e->getMessage());
        }
        self::assertSame($before, self::state());
        self::assertSame([], self::owners()->splitAutomatically(), 'and the run leaves it, with nothing logged');
        self::assertSame('', (string) file_get_contents($this->log));
        self::assertSame($before, self::state());

        $this->expectException(\LogicException::class);
        self::owners()->split(self::PUT_ON, $plan['owner'], $plan['copies'], self::ADMIN, true);
    }

    /** Two cron runs at once: one copy, no failure. */
    public function testTwoRunsAtOnceMakeOneCopy(): void
    {
        $runner = __DIR__ . '/fixtures/traffic-source-repair-runner.php';
        $procs = [];
        $out = [];
        foreach ([0, 1, 2] as $n) {
            $out[$n] = (string) tempnam(sys_get_temp_dir(), 'tsa-run-');
            $procs[$n] = proc_open(
                [PHP_BINARY, $runner, '0', '0', 'auto'],
                [1 => ['file', $out[$n], 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
        }
        $answers = [];
        foreach ($procs as $n => $proc) {
            proc_close($proc);
            $lines = file($out[$n], FILE_IGNORE_NEW_LINES);
            unlink($out[$n]);
            $answers[] = $lines[1] ?? 'no answer';
        }
        sort($answers);
        self::assertSame(['ok 0', 'ok 0', 'ok 1'], $answers);
        self::assertCount(1, self::rows('SELECT 1 FROM 202_ppc_networks WHERE user_id = ' . self::NEWCOMER
            . " AND ppc_network_name = 'Search Ads'"));
        self::assertCount(1, self::rows("SELECT 1 FROM 202_change_log WHERE entity = 'traffic_source_split'"));
    }
}

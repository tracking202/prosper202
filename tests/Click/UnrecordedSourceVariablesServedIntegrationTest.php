<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickPathReads;
use Prosper202\Click\UnrecordedSourceVariables;
use Prosper202\Database\Connection;
use Tests\Support\RecordingRowCache;

/**
 * A click on a traffic source account that names another user's traffic
 * source stores none of that source's custom variable values, and the server
 * log says so, through the real click scripts on a real database (served by
 * `php -S` over the 202-config.php the integration job writes).
 *
 * The line is written only when the account's source is another user's AND
 * the click came with a value of one of its live variables. Each case that
 * expects no line differs from the one that expects it in one thing.
 *
 * @group integration
 */
final class UnrecordedSourceVariablesServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 4601;
    private const OTHER = 4602;
    private const OWN_SOURCE = 4611;
    private const OTHER_SOURCE = 4612;
    private const BARE_SOURCE = 4613;
    /** The other user's too, with an account of the owner's put on it after it was saved. */
    private const PUT_ON_SOURCE = 4614;
    private const OWN_ACCOUNT = 4621;
    private const STRAY_ACCOUNT = 4622;
    private const BARE_ACCOUNT = 4623;
    private const PUT_ON_ACCOUNT = 4624;
    /** A second account of the owner's on the other user's source, saved before it as the first. */
    private const SECOND_STRAY_ACCOUNT = 4625;
    private const CAMPAIGN = 4631;
    private const ROTATOR = 4641;
    private const SIMPLE_PAGE = 4651;
    private const ADVANCED_PAGE = 4652;
    /** Trackers: direct links, then redirector links, by the account each names. */
    private const DIRECT = ['own' => 4661, 'stray' => 4662, 'bare' => 4663, 'put_on' => 4664, 'stray2' => 4665];
    private const REDIRECTOR = ['own' => 4671, 'stray' => 4672];
    private const SECRET = 'sidebar-secret-value';
    private const SAID = 'p202 click: a click on traffic source account';

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        self::seed();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $users = self::OWNER . ', ' . self::OTHER;
        self::q("DELETE FROM 202_change_log WHERE entity IN ('traffic_source_split', 'unrecorded_variables_said')");
        self::q("DELETE FROM 202_dirty_hours WHERE user_id IN ($users)");
        // A split's copy has an id of its own.
        self::q('DELETE v FROM 202_ppc_network_variables AS v JOIN 202_ppc_networks AS n'
            . " ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id IN ($users)");
        self::q("DELETE FROM 202_ppc_networks WHERE user_id IN ($users)");
        foreach (
            ['202_trackers' => 'tracker_id', '202_rotators' => 'id', '202_landing_pages' => 'landing_page_id',
            '202_aff_campaigns' => 'aff_campaign_id', '202_ppc_accounts' => 'ppc_account_id',
            '202_ppc_networks' => 'ppc_network_id', '202_ppc_network_variables' => 'ppc_network_id'] as $table => $id
        ) {
            self::q("DELETE FROM $table WHERE $id BETWEEN 4600 AND 4699");
        }
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
    }

    private static function seed(): void
    {
        self::cleanUp();
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_pref_privacy = 'disabled'");
        }
        // The account's own source and the other user's define the same two
        // live variables; the other's also has a removed one. The bare source
        // is the account's own and has none.
        $now = time();
        foreach (
            [self::OWN_SOURCE => self::OWNER, self::OTHER_SOURCE => self::OTHER,
            self::BARE_SOURCE => self::OWNER, self::PUT_ON_SOURCE => self::OTHER] as $source => $user
        ) {
            self::row('202_ppc_networks', ['ppc_network_id' => $source, 'user_id' => $user,
                'ppc_network_name' => "source $source", 'ppc_network_time' => $now]);
        }
        foreach ([self::OWN_SOURCE, self::OTHER_SOURCE, self::PUT_ON_SOURCE] as $source) {
            foreach (['placement', 'adgroup'] as $parameter) {
                self::row('202_ppc_network_variables', ['ppc_network_id' => $source, 'name' => $parameter,
                    'parameter' => $parameter, 'placeholder' => '{' . $parameter . '}']);
            }
        }
        self::row('202_ppc_network_variables', ['ppc_network_id' => self::OTHER_SOURCE, 'name' => 'retired',
            'parameter' => 'retired', 'placeholder' => '{retired}', 'deleted' => 1]);
        // The stray account was saved before its source was: the source may
        // have been the owner's, so the cron job leaves it (may_be_theirs).
        $sourceOf = [self::OWN_ACCOUNT => self::OWN_SOURCE, self::STRAY_ACCOUNT => self::OTHER_SOURCE,
            self::BARE_ACCOUNT => self::BARE_SOURCE, self::PUT_ON_ACCOUNT => self::PUT_ON_SOURCE,
            self::SECOND_STRAY_ACCOUNT => self::OTHER_SOURCE];
        foreach ($sourceOf as $account => $source) {
            self::row('202_ppc_accounts', ['ppc_account_id' => $account, 'user_id' => self::OWNER,
                'ppc_network_id' => $source, 'ppc_account_name' => "account $account",
                'ppc_account_time' => $source === self::OTHER_SOURCE ? $now - 100 : $now]);
        }
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + 100000,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'Spring sale',
            'aff_campaign_url' => 'https://shop.example/spring', 'aff_campaign_payout' => 1,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(), 'identity_signals' => 0,
        ]);
        self::row('202_rotators', ['id' => self::ROTATOR, 'public_id' => self::ROTATOR + 100000,
            'user_id' => self::OWNER, 'name' => 'rotator', 'default_url' => null,
            'default_campaign' => self::CAMPAIGN, 'default_lp' => null]);
        foreach ([self::SIMPLE_PAGE => 0, self::ADVANCED_PAGE => 1] as $page => $type) {
            self::row('202_landing_pages', ['landing_page_id' => $page, 'user_id' => self::OWNER,
                'landing_page_id_public' => $page + 100000, 'aff_campaign_id' => $type === 0 ? self::CAMPAIGN : 0,
                'landing_page_nickname' => "page $page", 'landing_page_url' => 'https://shop.example/lp',
                'landing_page_type' => $type, 'landing_page_time' => time()]);
        }
        $accounts = ['own' => self::OWN_ACCOUNT, 'stray' => self::STRAY_ACCOUNT, 'bare' => self::BARE_ACCOUNT,
            'put_on' => self::PUT_ON_ACCOUNT, 'stray2' => self::SECOND_STRAY_ACCOUNT];
        foreach ([[self::DIRECT, 0], [self::REDIRECTOR, self::ROTATOR]] as [$trackers, $rotator]) {
            foreach ($trackers as $which => $tracker) {
                self::row('202_trackers', [
                    'tracker_id' => $tracker, 'user_id' => self::OWNER, 'tracker_id_public' => $tracker + 100000,
                    'aff_campaign_id' => $rotator === 0 ? self::CAMPAIGN : 0, 'text_ad_id' => 0,
                    'ppc_account_id' => $accounts[$which], 'landing_page_id' => 0, 'rotator_id' => $rotator,
                    'click_cloaking' => 0, 'tracker_time' => time(),
                ]);
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
        // Each test starts with nothing said for any account this hour.
        self::q("DELETE FROM 202_change_log WHERE entity = 'unrecorded_variables_said'");
    }

    /**
     * One request; what the server logged for it, and the values the click
     * it recorded (if it recorded one) stored.
     *
     * @return array{log: string, stored: list<string>}
     */
    private function click(string $path): array
    {
        $db = self::requireScratch();
        $before = (int) $db->query('SELECT IFNULL(MAX(click_id), 0) FROM 202_clicks WHERE user_id = ' . self::OWNER)
            ->fetch_row()[0];
        $logged = strlen((string) file_get_contents(self::$log));

        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        clearstatcache();
        $log = substr((string) file_get_contents(self::$log), $logged);
        self::assertContains($status, [200, 302], $path . "\n" . $out . "\nserver log:\n" . $log);

        $click = (int) $db->query('SELECT IFNULL(MAX(click_id), 0) FROM 202_clicks WHERE user_id = ' . self::OWNER)
            ->fetch_row()[0];
        self::assertGreaterThan($before, $click, 'a click is recorded for ' . $path . "\nserver log:\n" . $log);
        $stored = $db->query('SELECT v.variable FROM 202_clicks_variable AS cv'
            . ' JOIN 202_variable_sets AS vs ON vs.variable_set_id = cv.variable_set_id'
            . ' JOIN 202_custom_variables AS v ON FIND_IN_SET(v.custom_variable_id, vs.variables)'
            . ' WHERE cv.click_id = ' . $click . ' ORDER BY v.variable')->fetch_all(MYSQLI_NUM);

        return ['log' => $log, 'stored' => array_column($stored, 0)];
    }

    /** @return iterable<string, array{string}> the request of each script that stores a source's variables */
    public static function strayClicks(): iterable
    {
        yield 'a direct link' => ['/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['stray'] + 100000)];
        yield 'a redirector link' => ['/tracking202/redirect/rtr.php?t202id=' . (self::REDIRECTOR['stray'] + 100000)];
        yield 'a simple landing page' => ['/tracking202/static/record.php?lpip=' . (self::SIMPLE_PAGE + 100000)
            . '&t202id=' . (self::DIRECT['stray'] + 100000)];
        yield 'an advanced landing page' => ['/tracking202/static/record.php?lpip=' . (self::ADVANCED_PAGE + 100000)
            . '&t202id=' . (self::DIRECT['stray'] + 100000)];
    }

    /**
     * @dataProvider strayClicks
     */
    public function testAClickOnAnotherUsersSourceStoresNoValueAndTheLogSaysSo(string $path): void
    {
        $click = $this->click($path . '&placement=' . self::SECRET . '&adgroup=winter&retired=old&c1=kept');

        self::assertSame([], $click['stored'], 'the click stores none of the other user\'s source\'s values');
        self::assertSame(1, substr_count($click['log'], self::SAID), $click['log']);
        self::assertStringContainsString(
            self::SAID . ' ' . self::STRAY_ACCOUNT . ' of user ' . self::OWNER
            . ' came with 2 custom variable values and is stored without them: the account names traffic source '
            . self::OTHER_SOURCE . ', which is in user ' . self::OTHER . '\'s account',
            $click['log']
        );
        self::assertStringContainsString('Account › Settings', $click['log'], 'the line says where the repair is');
        foreach ([self::SECRET, 'winter', 'kept', '127.0.0.1'] as $private) {
            self::assertStringNotContainsString($private, $click['log'], 'the line holds no value and no address');
        }
    }

    /**
     * @dataProvider strayClicks
     */
    public function testAClickThatCameWithNoValueSaysNothing(string $path): void
    {
        // A removed variable's value, and c1, were never stored as variables.
        $click = $this->click($path . '&retired=old&c1=kept&placement=');

        self::assertSame([], $click['stored']);
        self::assertStringNotContainsString(self::SAID, $click['log']);
    }

    public function testAClickOnTheAccountsOwnSourceStoresItsValuesAndSaysNothing(): void
    {
        foreach (
            ['/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['own'] + 100000),
            '/tracking202/redirect/rtr.php?t202id=' . (self::REDIRECTOR['own'] + 100000),
            '/tracking202/static/record.php?lpip=' . (self::SIMPLE_PAGE + 100000)
                . '&t202id=' . (self::DIRECT['own'] + 100000),
            '/tracking202/static/record.php?lpip=' . (self::ADVANCED_PAGE + 100000)
                . '&t202id=' . (self::DIRECT['own'] + 100000),
            ] as $path
        ) {
            $click = $this->click($path . '&placement=' . self::SECRET . '&adgroup=winter');

            self::assertSame([self::SECRET, 'winter'], $click['stored'], $path . "\n" . $click['log']);
            self::assertStringNotContainsString(self::SAID, $click['log'], $path);
        }
    }

    public function testAClickOnAnOwnSourceWithNoVariablesSaysNothing(): void
    {
        $click = $this->click('/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['bare'] + 100000)
            . '&placement=' . self::SECRET . '&adgroup=winter');

        self::assertSame([], $click['stored']);
        self::assertStringNotContainsString(self::SAID, $click['log']);
    }

    /**
     * The cron job's split, then a real click: an account put on another
     * user's source records its values again with nobody asked, the account
     * the cron job leaves to the operator still records none and still says
     * so, and the other user's sources are as they were.
     */
    public function testAfterTheCronJobsSplitAClickRecordsItsValuesAgain(): void
    {
        $db = self::requireScratch();
        $putOn = '/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['put_on'] + 100000)
            . '&placement=' . self::SECRET . '&adgroup=winter';
        $stray = '/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['stray'] + 100000)
            . '&placement=' . self::SECRET . '&adgroup=winter';
        $before = $this->click($putOn);
        self::assertSame([], $before['stored']);
        self::assertSame(1, substr_count($before['log'], self::SAID . ' ' . self::PUT_ON_ACCOUNT), $before['log']);
        $theirs = 'SELECT * FROM 202_ppc_networks WHERE user_id = ' . self::OTHER . ' ORDER BY ppc_network_id';
        $theirsWas = $db->query($theirs)->fetch_all(MYSQLI_ASSOC);

        $owners = new \Prosper202\Setup\TrafficSourceOwners(new Connection($db));
        $done = $owners->splitAutomatically();
        self::assertSame([self::PUT_ON_SOURCE], array_column($done, 'ppc_network_id'), 'only the one put on a source');

        $after = $this->click($putOn);
        self::assertSame([self::SECRET, 'winter'], $after['stored'], $after['log']);
        self::assertStringNotContainsString(self::SAID, $after['log']);
        self::assertSame($theirsWas, $db->query($theirs)->fetch_all(MYSQLI_ASSOC), 'the other user\'s are untouched');

        $left = $this->click($stray);
        self::assertSame([], $left['stored']);
        self::assertSame(1, substr_count($left['log'], self::SAID . ' ' . self::STRAY_ACCOUNT), $left['log']);
        self::assertSame([self::OTHER_SOURCE], array_column($owners->report(false), 'ppc_network_id'));
        self::assertSame([], $owners->splitAutomatically(), 'a second run finds nothing to do');
    }

    /**
     * The bound, with no cache at all (the served tree has no memcache
     * here): however many clicks, one line an hour for each traffic source
     * account, and a line again once the hour is over.
     */
    public function testWithoutACacheManyClicksInTheHourWriteOneLine(): void
    {
        $db = self::requireScratch();
        $paths = array_column(iterator_to_array(self::strayClicks(), false), 0);
        $log = '';
        for ($n = 0; $n < 12; $n++) {
            $log .= $this->click($paths[$n % 4] . '&placement=' . self::SECRET . '&adgroup=winter')['log'];
        }
        self::assertSame(1, substr_count($log, self::SAID), $log);
        self::assertStringContainsString('Said once an hour for each traffic source account', $log);
        self::assertSame(
            [[(string) self::STRAY_ACCOUNT, (string) self::OWNER]],
            $db->query("SELECT entity_id, actor_user_id FROM 202_change_log WHERE entity = 'unrecorded_variables_said'")
                ->fetch_all(MYSQLI_NUM)
        );

        // Another account is another line; the hour over, the first is said again.
        $other = $this->click('/tracking202/redirect/dl.php?t202id=' . (self::DIRECT['stray2'] + 100000)
            . '&placement=' . self::SECRET);
        self::assertSame(1, substr_count($other['log'], self::SAID . ' ' . self::SECOND_STRAY_ACCOUNT), $other['log']);
        self::q("UPDATE 202_change_log SET changed_at = changed_at - 3601 WHERE entity = 'unrecorded_variables_said'");
        $again = $this->click($paths[0] . '&placement=' . self::SECRET)['log']
            . $this->click($paths[1] . '&placement=' . self::SECRET)['log'];
        self::assertSame(1, substr_count($again, self::SAID . ' ' . self::STRAY_ACCOUNT), $again);
    }

    /**
     * In one process: memcache, where there is one, spares the database's
     * read; an account on its own source is never asked; and when what was
     * said can be neither read nor kept, the line is still one an hour for
     * the process, never one for each click.
     */
    public function testTheCacheSparesTheReadAndAFailureIsStillBounded(): void
    {
        $db = self::requireScratch();
        $conn = new Connection($db);
        $reads = ClickPathReads::uncached($conn);
        $said = new RecordingRowCache();
        $note = new UnrecordedSourceVariables($reads, $conn, $said, 'test');
        $request = ['placement' => self::SECRET];
        $rows = static fn (): int => (int) $db->query(
            "SELECT COUNT(*) FROM 202_change_log WHERE entity = 'unrecorded_variables_said'"
        )->fetch_row()[0];
        $log = (string) tempnam(sys_get_temp_dir(), 'p202note');
        $previous = ini_set('error_log', $log);
        UnrecordedSourceVariables::forget();
        try {
            for ($n = 0; $n < 5; $n++) {
                $note->note(self::OWNER, self::STRAY_ACCOUNT, $request);
            }
            $first = (string) file_get_contents($log);
            $asked = count($said->calls);
            // An account on its own source, and one that came with no value: nothing said, nothing kept
            // in the database. The first is kept in the cache, since no click on it has anything to say;
            // the second is not, since the next click may come with a value.
            $note->note(self::OWNER, self::OWN_ACCOUNT, $request);
            $ownCalls = array_slice($said->calls, $asked);
            $note->note(self::OWNER, self::OWN_ACCOUNT, $request);
            $ownAgain = array_slice($said->calls, $asked + count($ownCalls));
            $beforeNoValue = count($said->calls);
            $note->note(self::OWNER, self::SECOND_STRAY_ACCOUNT, ['retired' => 'old']);
            $noValueCalls = array_slice($said->calls, $beforeNoValue);
            $kept = $rows();
            // Told the source is the account's own, nothing is asked: no line, no row.
            \Prosper202\Repository\LookupRepositoryFactory::noteUnrecordedVariables(
                $db,
                self::OWNER,
                self::SECOND_STRAY_ACCOUNT,
                true,
                $request
            );
            $all = (string) file_get_contents($log);
            $keptAfter = $rows();

            // What was said can be neither read nor kept.
            self::q('RENAME TABLE 202_change_log TO 202_change_log_away');
            try {
                $bare = new UnrecordedSourceVariables($reads, $conn);
                for ($n = 0; $n < 5; $n++) {
                    $bare->note(self::OWNER, self::STRAY_ACCOUNT, $request);
                }
            } finally {
                self::q('RENAME TABLE 202_change_log_away TO 202_change_log');
            }
            $failed = substr((string) file_get_contents($log), strlen($all));
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
            UnrecordedSourceVariables::forget();
        }

        self::assertSame(1, substr_count($first, self::SAID), $first);
        self::assertStringContainsString('came with 1 custom variable value and is stored without it:', $first);
        self::assertSame($first, $all, 'nothing more is said');
        self::assertSame(1, $kept, 'one row for the account, written once');
        self::assertSame(1, $keptAfter, 'an account said to be on its own source is not looked at');
        self::assertSame(6, $asked, 'five reads of the cache and one write: the database is read once');
        self::assertSame(['get', 'set'], array_column($ownCalls, 0));
        self::assertSame(['said' => '0'], $ownCalls[1][2]);
        self::assertSame([['get', $ownCalls[0][1]]], $ownAgain, 'the account\'s next click reads only the cache');
        self::assertSame(['get'], array_column($noValueCalls, 0), 'a click with no value keeps nothing');
        self::assertSame(1, substr_count($failed, 'whether that was already said this hour could not be read or kept'));
        self::assertSame(1, substr_count($failed, "\n"), $failed);
        self::assertStringNotContainsString(self::SECRET, $all . $failed);
        self::assertSame(3600, UnrecordedSourceVariables::SAID_FOR_SECONDS);
    }
}

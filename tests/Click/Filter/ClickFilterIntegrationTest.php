<?php

declare(strict_types=1);

namespace Tests\Click\Filter;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\Filter\AddressRange;
use Prosper202\Click\Filter\ClickFilterEntry;
use Prosper202\Click\Filter\ClickFilterListRefusal;
use Prosper202\Click\Filter\ClickFilterRules;
use Prosper202\Click\Filter\ClickFilterSettings;
use Prosper202\Click\Filter\ClickFilterTester;
use Prosper202\Click\Filter\MysqlClickFilterStore;
use Prosper202\Click\Filter\RepeatVisitCleanup;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * The click filter against a real server: FILTER::verdict() as the click
 * path calls it (the class extracted from connect2.php, which a test cannot
 * include), the account's settings and list as the store keeps them, the
 * reason a filtered click is recorded with, the daily cleanup, and the
 * address test, which records nothing.
 *
 * The defaults are held to the filter as it was before it had settings: the
 * same clicks filtered, and the same rows left in 202_last_ips, for a run of
 * clicks through every check.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST, ...); it
 * installs the schema there and writes users 5811-5813, their filter rows,
 * 202_last_ips rows and clicks 99581101-99581199.
 *
 * @group integration
 */
final class ClickFilterIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 5811;
    private const OTHER = 5812;
    /** Signs in from SIGN_IN; nobody's clicks are recorded under it. */
    private const SIGNER = 5813;
    private const SIGN_IN = '192.0.2.21';
    private const FIRST_CLICK = 99581101;

    /** @var array<string, int> address => its 202_ips row */
    private static array $ids = [];

    /** @var array<string, mixed> */
    private array $server = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        if (!class_exists('FILTER', false)) {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/202-config/connect2.php');
            $start = strpos($src, "\nclass FILTER\n");
            $end = strpos($src, "\nfunction rotateTrackerUrl(");
            self::assertIsInt($start, 'class FILTER not found in connect2.php');
            self::assertIsInt($end);
            eval(substr($src, $start, $end - $start));
        }
        self::cleanUp();
        $locations = new MysqlLocationRepository(new Connection(self::$db));
        foreach ([self::SIGN_IN, '198.51.100.7', '198.51.100.0', '203.0.113.9', '2001:db8:5::9', self::crawler()] as $address) {
            self::$ids[$address] = $locations->findOrCreateIp($address);
        }
        foreach ([self::OWNER, self::OTHER, self::SIGNER] as $user) {
            self::user($user);
        }
        self::q('UPDATE 202_users SET user_last_login_ip_id = ' . self::$ids[self::SIGN_IN] . ' WHERE user_id = ' . self::SIGNER);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->server = $_SERVER;
        foreach (['202_last_ips', '202_click_filter_entries', '202_click_filter_settings'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::OWNER . ', ' . self::OTHER . ')');
        }
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (1, ' . self::OWNER . ', ' . self::OTHER . ')');
    }

    /** The account's privacy setting, which decides the address form its clicks record. */
    private static function privacy(string $value): void
    {
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER . ", user_pref_privacy = '$value'"
            . " ON DUPLICATE KEY UPDATE user_pref_privacy = '$value'");
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private static function cleanUp(): void
    {
        $users = self::OWNER . ', ' . self::OTHER . ', ' . self::SIGNER;
        foreach (['202_last_ips', '202_click_filter_entries', '202_click_filter_settings', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 98);
        foreach (
            [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_record', '202_clicks_site', '202_clicks_filter_reason',
            ] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    /** The first address of the built-in crawler ranges' first block, computed, never written out. */
    private static function crawler(): string
    {
        return long2ip(1208926208);
    }

    private static function store(): MysqlClickFilterStore
    {
        return new MysqlClickFilterStore(new Connection(self::$db));
    }

    /** A click arriving from $address, for $user: its flag and reason, as the click path takes them. */
    private static function click(string $address, int $user = self::OWNER): \Prosper202\Click\Filter\ClickFilterVerdict
    {
        $_SERVER = ['REMOTE_ADDR' => $address, 'REQUEST_METHOD' => 'GET'];

        return \FILTER::verdict(self::$db, self::$ids[$address], $user);
    }

    /** The filter before it had settings: its three checks, in order, as startFilter() asked them. */
    private static function clickBefore(string $address, int $user): int
    {
        $_SERVER = ['REMOTE_ADDR' => $address, 'REQUEST_METHOD' => 'GET'];
        $filter = \FILTER::checkUserIP(self::$db, $address);
        if ($filter == false) {
            $filter = \FILTER::checkNetrange(0, $address);
            if ($filter == false) {
                $filter = \FILTER::checkLastIps(self::$db, $user, self::$ids[$address]);
            }
        }

        return $filter == true ? 1 : 0;
    }

    /** @return list<string> the account's 202_last_ips rows, as ip_id (time left out) */
    private static function lastIps(int $user): array
    {
        $rows = [];
        $result = self::$db->query("SELECT ip_id FROM 202_last_ips WHERE user_id = $user ORDER BY ip_id");
        self::assertInstanceOf(\mysqli_result::class, $result);
        while ($row = $result->fetch_assoc()) {
            $rows[] = (string) $row['ip_id'];
        }

        return $rows;
    }

    /** @return list<string> */
    private static function clicks(): array
    {
        return [self::SIGN_IN, self::crawler(), '198.51.100.7', '198.51.100.7', '2001:db8:5::9', '2001:db8:5::9', self::SIGN_IN, '203.0.113.9'];
    }

    public function testTheDefaultsFilterTheClicksTheFilterAlwaysDidAndRecordTheSameAddresses(): void
    {
        $before = [];
        foreach (self::clicks() as $address) {
            $before[] = self::clickBefore($address, self::OTHER);
        }
        $now = [];
        $reasons = [];
        foreach (self::clicks() as $address) {
            $verdict = self::click($address);
            $now[] = $verdict->flag();
            $reasons[] = $verdict->reason;
        }

        self::assertSame([1, 1, 0, 1, 0, 1, 1, 0], $before, 'the scenario exercises every check');
        self::assertSame($before, $now);
        self::assertSame(self::lastIps(self::OTHER), self::lastIps(self::OWNER), 'the same addresses are remembered');
        self::assertSame(
            ['sign_in_address', 'crawler_range', null, 'repeat_visit', null, 'repeat_visit', 'sign_in_address', null],
            $reasons
        );
    }

    public function testASwitchedOffCheckCountsWhatItFiltered(): void
    {
        self::store()->saveSettings(self::OWNER, new ClickFilterSettings(false, false, false));

        foreach (self::clicks() as $address) {
            self::assertFalse(self::click($address)->filtered, $address);
        }
        self::assertSame([], self::lastIps(self::OWNER), 'a repeat check switched off remembers nothing');
    }

    public function testTheRepeatWindowBoundsWhatCountsAsARepeat(): void
    {
        $ip = self::$ids['198.51.100.7'];
        self::q('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (' . self::OWNER . ", $ip, " . (time() - 7200) . ')');

        self::store()->saveSettings(self::OWNER, new ClickFilterSettings(true, true, true, '1h'));
        self::assertFalse(self::click('198.51.100.7')->filtered, 'two hours ago is outside an hour');
        self::assertCount(2, self::lastIps(self::OWNER), 'and the click starts a new window');
        self::assertSame('repeat_visit', self::click('198.51.100.7')->reason, 'inside it now');

        self::q('DELETE FROM 202_last_ips WHERE user_id = ' . self::OWNER);
        self::q('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (' . self::OWNER . ", $ip, " . (time() - 3 * 86400) . ')');
        self::store()->saveSettings(self::OWNER, new ClickFilterSettings(true, true, true, '7d'));
        self::assertTrue(self::click('198.51.100.7')->filtered, 'three days ago is inside a week');
        self::store()->saveSettings(self::OWNER, new ClickFilterSettings(true, true, true, '24h'));
        self::assertFalse(self::click('198.51.100.7')->filtered, 'shortening to 24h forgets what a week kept, not waiting for the cleanup');
        self::assertCount(1, self::lastIps(self::OWNER), 'only the click just made is left');
        self::assertSame('repeat_visit', self::click('198.51.100.7')->reason, 'and that one is a repeat inside the day');
    }

    public function testTheListBeatsTheChecksAlwaysCountFirst(): void
    {
        $store = self::store();
        $count = $store->addEntry(self::OWNER, AddressRange::parse(self::SIGN_IN), ClickFilterEntry::COUNT, 'QA');
        $verdict = self::click(self::SIGN_IN);
        self::assertFalse($verdict->filtered, 'always count beats a sign-in address');
        self::assertSame($count->id, $verdict->entryId);
        self::assertFalse(self::click(self::SIGN_IN)->filtered, 'and a repeat');
        self::assertSame([], self::lastIps(self::OWNER), 'and nothing is remembered for it');

        $farm = $store->addEntry(self::OWNER, AddressRange::parse('2001:db8::/32'), ClickFilterEntry::FILTER, 'farm');
        $verdict = self::click('2001:db8:5::9');
        self::assertSame('filter_list', $verdict->reason, 'a first click from the block is filtered');
        self::assertSame($farm->id, $verdict->entryId);

        $store->addEntry(self::OWNER, AddressRange::parse('2001:db8:5::/48'), ClickFilterEntry::COUNT, '');
        self::assertFalse(self::click('2001:db8:5::9')->filtered, 'always count beats always filter, whichever is wider');

        self::assertSame('sign_in_address', self::click(self::SIGN_IN, self::OTHER)->reason, 'another account\'s list is not this one\'s');
    }

    public function testAnUnreadableFilterFallsBackToTheDefaultsAndSaysSo(): void
    {
        self::q('INSERT INTO 202_click_filter_settings (user_id, sign_in_addresses, crawler_ranges, repeat_visits, repeat_window, updated_at)'
            . ' VALUES (' . self::OWNER . ', 0, 0, 0, 999, 0)');
        $log = tempnam(sys_get_temp_dir(), 'p202-filter-log');
        $previous = ini_set('error_log', (string) $log);
        try {
            self::assertSame('sign_in_address', self::click(self::SIGN_IN)->reason, 'the defaults, not the switches stored with it');
            self::q('DELETE FROM 202_click_filter_settings WHERE user_id = ' . self::OWNER);
            self::q('RENAME TABLE 202_click_filter_entries TO 202_click_filter_entries_gone');
            try {
                self::assertSame('crawler_range', self::click(self::crawler())->reason, 'a missing table is the defaults too');
            } finally {
                self::q('RENAME TABLE 202_click_filter_entries_gone TO 202_click_filter_entries');
            }
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $logged = (string) file_get_contents((string) $log);
        unlink((string) $log);
        self::assertStringContainsString('could not be read', $logged);
        self::assertSame(1, substr_count($logged, 'Prosper202 click filter'), 'logged once per request');
    }

    public function testTheListRefusesADuplicateAndKeepsToItsSize(): void
    {
        $store = self::store();
        $first = $store->addEntry(self::OWNER, AddressRange::parse('203.0.113.0/24'), ClickFilterEntry::FILTER, '');
        try {
            $store->addEntry(self::OWNER, AddressRange::parse('203.0.113.0/24'), ClickFilterEntry::COUNT, '');
            self::fail('the same block twice');
        } catch (ClickFilterListRefusal $e) {
            self::assertSame($first->id, $e->existingEntryId);
        }
        $store->addEntry(self::OTHER, AddressRange::parse('203.0.113.0/24'), ClickFilterEntry::COUNT, '');

        $second = $store->addEntry(self::OWNER, AddressRange::parse('203.0.113.9'), ClickFilterEntry::COUNT, '');
        try {
            $store->updateEntry(self::OWNER, new ClickFilterEntry($second->id, $first->range, 'count', '', 1));
            self::fail('an update onto another entry\'s block');
        } catch (ClickFilterListRefusal $e) {
            self::assertSame($first->id, $e->existingEntryId);
        }
        self::assertFalse($store->removeEntry(self::OTHER, $first->id), 'another account\'s entry is not removed');
        self::assertTrue($store->removeEntry(self::OWNER, $first->id));

        $values = [];
        for ($i = 0; $i < ClickFilterRules::MAX_ENTRIES - 1; $i++) {
            $values[] = '(' . self::OWNER . ", 6, UNHEX('" . sprintf('20010db8%024x', $i + 1) . "'), UNHEX('"
                . sprintf('20010db8%024x', $i + 1) . "'), 128, 'filter', '', 0)";
        }
        self::q('INSERT INTO 202_click_filter_entries (user_id, family, range_start, range_end, prefix, action, note, created_at) VALUES '
            . implode(', ', $values));
        self::assertCount(ClickFilterRules::MAX_ENTRIES, $store->entries(self::OWNER));
        $this->expectException(ClickFilterListRefusal::class);
        $store->addEntry(self::OWNER, AddressRange::parse('198.51.100.0/24'), ClickFilterEntry::FILTER, '');
    }

    public function testAFilteredClickIsRecordedWithWhyAndACountedOneWithout(): void
    {
        $repo = new MysqlClickRepository(new Connection(self::$db));
        $click = static fn (int $id, int $filtered, string $reason, int $entry = 0): ClickRecord => new ClickRecord(
            clickId: $id,
            userId: self::OWNER,
            clickTime: time(),
            clickFiltered: $filtered,
            ipId: self::$ids['198.51.100.7'],
            clickFilterReason: $reason,
            clickFilterEntryId: $entry,
        );
        $repo->recordClick($click(self::FIRST_CLICK, 1, 'repeat_visit'));
        $repo->recordClick($click(self::FIRST_CLICK + 1, 1, 'filter_list', 42));
        $repo->recordClick($click(self::FIRST_CLICK + 2, 0, ''));
        $repo->recordClick($click(self::FIRST_CLICK + 3, 1, ''));

        $rows = self::$db->query('SELECT click_id, filter_reason, entry_id FROM 202_clicks_filter_reason'
            . ' WHERE click_id BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 3) . ' ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $rows);
        self::assertSame([
            ['click_id' => (string) self::FIRST_CLICK, 'filter_reason' => 'repeat_visit', 'entry_id' => '0'],
            ['click_id' => (string) (self::FIRST_CLICK + 1), 'filter_reason' => 'filter_list', 'entry_id' => '42'],
        ], array_map(static fn (array $r): array => array_map('strval', $r), $rows->fetch_all(MYSQLI_ASSOC)));

        self::q('RENAME TABLE 202_clicks_filter_reason TO 202_clicks_filter_reason_gone');
        $previous = ini_set('error_log', '/dev/null');
        try {
            $repo->recordClick($click(self::FIRST_CLICK + 4, 1, 'crawler_range'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            self::q('RENAME TABLE 202_clicks_filter_reason_gone TO 202_clicks_filter_reason');
        }
        $kept = self::$db->query('SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id = ' . (self::FIRST_CLICK + 4));
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame('1', (string) $kept->fetch_assoc()['n'], 'a reason that cannot be written never costs the click');
    }

    public function testTheCleanupKeepsAnAddressForALongerWindowOnly(): void
    {
        $now = time();
        $ip = self::$ids['198.51.100.7'];
        foreach ([self::OWNER, self::OTHER] as $user) {
            foreach ([3600, 2 * 86400, 8 * 86400] as $age) {
                self::q("INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES ($user, $ip, " . ($now - $age) . ')');
            }
        }
        self::store()->saveSettings(self::OWNER, new ClickFilterSettings(true, true, true, '7d'));

        self::q(RepeatVisitCleanup::sql($now));

        $ages = static function (int $user) use ($now): array {
            $result = self::$db->query("SELECT time FROM 202_last_ips WHERE user_id = $user ORDER BY time DESC");
            self::assertInstanceOf(\mysqli_result::class, $result);

            return array_map(static fn (array $r): int => intdiv($now - (int) $r['time'], 3600), $result->fetch_all(MYSQLI_ASSOC));
        };
        self::assertSame([1, 48], $ages(self::OWNER), 'a week keeps two days, not eight');
        self::assertSame([1], $ages(self::OTHER), 'the defaults keep a day, as before');
    }

    public function testTheAddressTestAnswersEveryRuleAndRecordsNothing(): void
    {
        $count = static function (string $table): string {
            $result = self::$db->query("SELECT COUNT(*) AS n FROM $table");
            self::assertInstanceOf(\mysqli_result::class, $result);

            return (string) $result->fetch_assoc()['n'];
        };
        $before = [$count('202_last_ips'), $count('202_ips'), $count('202_ips_v6')];
        $tester = new ClickFilterTester(new Connection(self::$db));

        $fresh = $tester->test(self::OWNER, (string) inet_pton('198.51.100.66'));
        self::assertFalse($fresh['filtered']);
        self::assertStringContainsString('next click', $fresh['explanation']);

        $other = $tester->test(self::OWNER, (string) inet_pton(self::SIGN_IN));
        self::assertNull($other['reason'], 'another user\'s sign-in address is not told to this account');
        self::assertFalse($other['checks']['sign_in_address']['matches']);
        self::assertSame('sign_in_address', $tester->test(self::SIGNER, (string) inet_pton(self::SIGN_IN))['reason']);
        self::assertSame('crawler_range', $tester->test(self::OWNER, (string) inet_pton(self::crawler()))['reason']);

        self::click('198.51.100.7');
        $before[0] = $count('202_last_ips');
        $repeat = $tester->test(self::OWNER, (string) inet_pton('198.51.100.7'));
        self::assertSame('repeat_visit', $repeat['reason']);
        self::assertTrue($repeat['checks']['repeat_visit']['matches']);

        $entry = self::store()->addEntry(self::OWNER, AddressRange::parse('198.51.100.0/24'), ClickFilterEntry::COUNT, 'QA');
        $counted = $tester->test(self::OWNER, (string) inet_pton('198.51.100.7'));
        self::assertFalse($counted['filtered']);
        self::assertSame($entry->id, $counted['entry']['entry_id']);
        self::assertTrue($counted['checks']['repeat_visit']['matches'], 'each check still says its own answer');

        self::assertSame($before, [$count('202_last_ips'), $count('202_ips'), $count('202_ips_v6')]);
    }

    public function testUnderPrivacyTheTestSaysANeighbourClickedAsTheRepeatCheckDoes(): void
    {
        self::privacy('all');
        // A click stored masked, as privacy stores it: its block's row.
        self::q('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (' . self::OWNER . ', ' . self::$ids['198.51.100.0'] . ', ' . time() . ')');

        $answer = (new ClickFilterTester(new Connection(self::$db)))->test(self::OWNER, (string) inet_pton('198.51.100.77'));

        self::assertSame('repeat_visit', $answer['reason']);
        self::assertStringContainsString('an address in its /24 clicked already', $answer['explanation']);
    }

    public function testTheTestAsksOnlyTheFormTheNextClickWouldStore(): void
    {
        $tester = new ClickFilterTester(new Connection(self::$db));
        $address = (string) inet_pton('198.51.100.77');

        // Tracked in full: a masked row left from an earlier privacy mode is not what the next click is checked against.
        self::privacy('disabled');
        self::q('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (' . self::OWNER . ', ' . self::$ids['198.51.100.0'] . ', ' . time() . ')');
        $full = $tester->test(self::OWNER, $address);
        self::assertFalse($full['filtered'], 'a leftover masked row does not make a full-address click a repeat');
        self::assertFalse($full['checks']['repeat_visit']['matches']);

        // Under privacy: a full-address row left from before is not either.
        self::q('DELETE FROM 202_last_ips WHERE user_id = ' . self::OWNER);
        self::privacy('all');
        self::q('INSERT INTO 202_last_ips (user_id, ip_id, time) VALUES (' . self::OWNER . ', ' . self::$ids['198.51.100.7'] . ', ' . time() . ')');
        $masked = $tester->test(self::OWNER, (string) inet_pton('198.51.100.7'));
        self::assertFalse($masked['filtered'], 'a leftover full-address row does not make a masked click a repeat');
        self::assertFalse($masked['checks']['repeat_visit']['matches']);
    }
}

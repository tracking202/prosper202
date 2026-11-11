<?php

declare(strict_types=1);

namespace Tests\Fraud;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\Fraud\IpqsClient;
use Prosper202\Fraud\IpqsScoring;
use Prosper202\User\UserDataPurge;

/**
 * IpqsScoring on a real database, through the real IpqsClient, against a
 * stand-in IPQualityScore on loopback (IpqsStub): which clicks are sent,
 * which are filtered and how the reports read them, the cache, the backoff,
 * and the purge.
 *
 * @group integration
 */
final class IpqsScoringIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 9301;
    private const OTHER = 9302;
    private const NO_KEY = 9303;
    private const DELETED = 9304;
    private const USERS = [self::OWNER, self::OTHER, self::NO_KEY, self::DELETED];
    private const NOW = 1_900_000_000;

    private static ?IpqsStub $stub = null;

    /** @var array<string, int> country code => country_id */
    private static array $countries = [];

    /** @var array<string, int> address => ip_id */
    private static array $ips = [];

    private int $now = self::NOW;

    private int $nextClick = 930001;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::$stub = new IpqsStub();
        foreach (['US', 'BR', 'DE', 'GB', 'CH', 'NO', 'RE', 'non'] as $code) {
            self::$countries[$code] = self::insert('INSERT INTO 202_locations_country SET country_code = '
                . self::str($code) . ', country_name = ' . self::str('ipqs ' . $code));
        }
        self::$ips['198.51.100.0'] = self::insert("INSERT INTO 202_ips SET ip_address = '198.51.100.0'");
        for ($i = 50; $i < 70; $i++) {
            self::$ips['203.0.113.' . $i] = self::insert("INSERT INTO 202_ips SET ip_address = '203.0.113.$i'");
        }
        self::$ips['10.0.0.9'] = self::insert("INSERT INTO 202_ips SET ip_address = '10.0.0.9'");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::clean();
        self::q('DELETE FROM 202_locations_country WHERE country_id IN (' . implode(',', self::$countries) . ')');
        self::q('DELETE FROM 202_ips WHERE ip_id IN (' . implode(',', self::$ips) . ')');
        self::$stub?->stop();
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::clean();
        self::$stub->forget();
        foreach (self::USERS as $user) {
            self::user($user);
        }
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::DELETED);
        $keys = [self::OWNER => 'good-key', self::OTHER => 'second-key', self::DELETED => 'deleted-key'];
        foreach ($keys as $user => $key) {
            self::q("INSERT INTO 202_users_pref SET user_id = $user, ipqs_api_key = " . self::str($key));
        }
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::NO_KEY);
    }

    private static function clean(): void
    {
        $users = implode(',', self::USERS);
        $clicks = 'SELECT click_id FROM 202_clicks WHERE user_id IN (' . $users . ')';
        foreach (['202_clicks_advance', '202_clicks_filter_reason', '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN (SELECT click_id FROM ($clicks) AS ours)");
        }
        $tables = ['202_clicks', '202_clicks_spy', '202_dataengine', '202_ipqs_scores', '202_ipqs_progress',
            '202_click_filter_entries', '202_click_filter_settings',
            '202_users_pref', '202_users', '202_dirty_hours'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    /** A click of $user, $ago seconds old, from $ip placed in $country (null: not placed). */
    private function click(int $user, string $ip, ?string $country, int $ago = 300, int $lead = 0): int
    {
        $id = $this->nextClick++;
        $time = $this->now - $ago;
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $id, user_id = $user, aff_campaign_id = 0, landing_page_id = 0,"
                . " ppc_account_id = 0, click_cpc = 0, click_payout = 0, click_lead = $lead, click_filtered = 0,"
                . " click_bot = 0, click_alp = 0, click_time = $time");
        }
        $countryId = $country === null ? 0 : self::$countries[$country];
        self::q("INSERT INTO 202_clicks_advance SET click_id = $id, text_ad_id = 0, keyword_id = 0, ip_id = "
            . self::$ips[$ip] . ", country_id = $countryId, region_id = 0, city_id = 0, platform_id = 0,"
            . ' browser_id = 0, device_id = 0, isp_id = 0');
        ClickRollupSql::rollUpClick(new Connection(self::$db), $id);

        return $id;
    }

    /** @return array{accounts: int, clicks: int, lookups: int, filtered: int, held: int, failed: int, pruned: int} */
    private function score(): array
    {
        $client = new IpqsClient(self::$stub->origin);
        $scoring = new IpqsScoring(new Connection(self::$db), $client, fn (): int => $this->now);

        return $scoring->run($this->now + 30);
    }

    private static function one(string $sql): ?string
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);
        $row = $result->fetch_row();

        return $row === null || $row[0] === null ? null : (string) $row[0];
    }

    /** @return array{clicks: string, spy: string, report: ?string, reason: ?string} */
    private static function filtered(int $click): array
    {
        return [
            'clicks' => (string) self::one("SELECT GROUP_CONCAT(DISTINCT click_filtered) FROM 202_clicks"
                . " WHERE click_id = $click"),
            'spy' => (string) self::one("SELECT GROUP_CONCAT(DISTINCT click_filtered) FROM 202_clicks_spy"
                . " WHERE click_id = $click"),
            'report' => self::one("SELECT click_filtered FROM 202_dataengine WHERE click_id = $click"),
            'reason' => self::one("SELECT filter_reason FROM 202_clicks_filter_reason WHERE click_id = $click"),
        ];
    }

    private const COUNTED = ['clicks' => '0', 'spy' => '0', 'report' => '0', 'reason' => null];
    private const FRAUD = ['clicks' => '1', 'spy' => '1', 'report' => '1', 'reason' => 'fraud_score'];

    public function testAHighScoreFiltersTheClickWhereEveryReportReadsIt(): void
    {
        self::$stub->scores(['203.0.113.50' => 95, '203.0.113.51' => 89, '203.0.113.60' => 99]);
        $fraud = $this->click(self::OWNER, '203.0.113.50', 'US', 400);
        $fine = $this->click(self::OWNER, '203.0.113.51', 'BR', 350);
        $again = $this->click(self::OWNER, '203.0.113.50', 'US', 300);
        $lead = $this->click(self::OWNER, '203.0.113.50', 'US', 250, 1);
        $private = $this->click(self::OWNER, '10.0.0.9', 'US', 200);
        self::assertSame(self::COUNTED, self::filtered($fraud), 'counted before it is scored');

        $report = $this->score();

        self::assertSame(
            ['203.0.113.50', '203.0.113.51'],
            self::$stub->addresses(),
            'each address once; no private one'
        );
        self::assertSame(self::FRAUD, self::filtered($fraud));
        self::assertSame(self::FRAUD, self::filtered($again), 'the cached score filters the repeat visitor');
        self::assertSame(self::COUNTED, self::filtered($fine), '89 is under the threshold');
        self::assertSame(['clicks' => '0', 'spy' => '0', 'report' => '0', 'reason' => null], self::filtered($private));
        $leadFiltered = self::one("SELECT click_filtered FROM 202_clicks WHERE click_id = $lead");
        self::assertSame('0', $leadFiltered, 'a lead is never filtered');
        self::assertSame(2, $report['lookups']);
        self::assertSame(2, $report['filtered']);
        self::assertSame(
            ['203.0.113.50' => '95', '203.0.113.51' => '89'],
            self::cached(self::OWNER),
            'only the score is kept, per address'
        );
    }

    /**
     * "Not fraud" (FraudVerdictUndo): the click and the account's other
     * clicks from the address that were filtered for their score count
     * again where every report reads them, the address goes on the click
     * filter list as always count, and scoring leaves it alone after that.
     */
    public function testNotFraudCountsTheAddressesClicksAgainAndExemptsIt(): void
    {
        self::$stub->scores(['203.0.113.50' => 95, '203.0.113.60' => 99]);
        $first = $this->click(self::OWNER, '203.0.113.50', 'US', 400);
        $second = $this->click(self::OWNER, '203.0.113.50', 'US', 300);
        $elsewhere = $this->click(self::OWNER, '203.0.113.60', 'US', 280);
        $theirs = $this->click(self::OTHER, '203.0.113.50', 'US', 260);
        $ruled = $this->click(self::OWNER, '203.0.113.50', 'US', 240);
        $this->score();
        // A click one of the account's own rules filtered, from the same address.
        self::q("UPDATE 202_clicks SET click_filtered = 1 WHERE click_id = $ruled");
        self::q("REPLACE INTO 202_clicks_filter_reason SET click_id = $ruled, filter_reason = 'repeat_visit', entry_id = 0");
        self::assertSame(self::FRAUD, self::filtered($first));
        self::assertSame(self::FRAUD, self::filtered($theirs));
        $conn = new Connection(self::$db);
        self::assertSame(['scored' => 2, 'filtered' => 3], \Prosper202\Fraud\FraudVerdictUndo::lastDay($conn, self::OWNER, $this->now));

        $undo = new \Prosper202\Fraud\FraudVerdictUndo($conn);
        self::assertNull($undo->undo(self::OWNER, $theirs), 'another account\'s click is not undone');
        self::assertNull($undo->undo(self::OWNER, $ruled), 'a click a rule filtered is not undone here');
        self::assertSame(self::FRAUD, self::filtered($theirs));
        // The writer itself holds both lines, whoever calls it.
        $writer = new \Prosper202\Click\MysqlClickRepository($conn);
        self::assertFalse($writer->unfilterFraud(self::OWNER, $ruled), 'the writer leaves a click a rule filtered');
        self::assertFalse($writer->unfilterFraud(self::OWNER, $theirs), 'and another account\'s click');
        self::assertSame('1', self::one("SELECT click_filtered FROM 202_clicks WHERE click_id = $ruled"));
        self::assertSame(self::FRAUD, self::filtered($theirs));

        $answer = $undo->undo(self::OWNER, $second);
        self::assertSame(['address' => '203.0.113.50', 'clicks' => 2, 'more' => false, 'exempted' => true, 'exempt_problem' => null], $answer);
        self::assertSame(self::COUNTED, self::filtered($first), 'the address\'s other scored click counts again too');
        self::assertSame(self::COUNTED, self::filtered($second));
        self::assertSame(self::FRAUD, self::filtered($elsewhere), 'another address keeps its verdict');
        self::assertSame(self::FRAUD, self::filtered($theirs), 'another account keeps its own');
        self::assertSame('repeat_visit', self::filtered($ruled)['reason']);
        self::assertSame('1', self::one("SELECT click_filtered FROM 202_clicks WHERE click_id = $ruled"));
        self::assertSame(
            'count',
            self::one('SELECT action FROM 202_click_filter_entries WHERE user_id = ' . self::OWNER),
            'the address is always counted from now on'
        );
        self::assertSame(['scored' => 2, 'filtered' => 1], \Prosper202\Fraud\FraudVerdictUndo::lastDay($conn, self::OWNER, $this->now));
        self::assertNull($undo->undo(self::OWNER, $second), 'a click that counts has no verdict to undo');

        // A new click from the address is neither looked up nor filtered, though its cached score is 95.
        self::$stub->forget();
        self::$stub->scores(['203.0.113.50' => 95]);
        $later = $this->click(self::OWNER, '203.0.113.50', 'US', 120);
        $report = $this->score();
        self::assertSame(self::COUNTED, self::filtered($later));
        self::assertSame([], self::$stub->addresses(), 'a listed address is not sent');
        self::assertSame(0, $report['filtered']);
        self::assertSame(1, (int) self::one('SELECT COUNT(*) FROM 202_click_filter_entries WHERE user_id = ' . self::OWNER));
    }

    /** POST /clicks/{id}/not-fraud: the same undo, as the API answers it. */
    public function testTheApiCountsAClickAgainAndRefusesWhatIsNotAVerdict(): void
    {
        self::$stub->scores(['203.0.113.50' => 95]);
        $first = $this->click(self::OWNER, '203.0.113.50', 'US', 400);
        $second = $this->click(self::OWNER, '203.0.113.50', 'US', 300);
        $counted = $this->click(self::OWNER, '203.0.113.51', 'US', 280);
        $theirs = $this->click(self::OTHER, '203.0.113.50', 'US', 260);
        $this->score();
        $api = new \Api\V3\Controllers\ClicksController(self::$db, self::OWNER);

        foreach ([$counted, $theirs, 999999999] as $click) {
            try {
                $api->notFraud($click, []);
                self::fail("click $click has no verdict of the caller's to undo");
            } catch (\Api\V3\Exception\NotFoundException $e) {
                self::assertStringContainsString('fraud_score', $e->getMessage());
            }
        }
        try {
            $api->notFraud($first, ['address' => '203.0.113.60']);
            self::fail('took a body it does not read');
        } catch (\Api\V3\Exception\ValidationException) {
        }
        self::assertSame(self::FRAUD, self::filtered($first), 'a refused request changes nothing');
        self::assertSame(self::FRAUD, self::filtered($theirs));

        $answer = $api->notFraud($first, [])['data'];
        self::assertSame(
            [$first, '203.0.113.50', 2, false, true],
            [$answer['click_id'], $answer['ip_address'], $answer['clicks_counted'], $answer['more_clicks_filtered'], $answer['address_always_counted']]
        );
        self::assertStringContainsString('always count', $answer['note']);
        self::assertSame(self::COUNTED, self::filtered($first));
        self::assertSame(self::COUNTED, self::filtered($second));
        self::assertSame(self::FRAUD, self::filtered($theirs));
        // The entry it made is the click filter's own: listed there, and removable there.
        $entries = (new \Prosper202\Click\Filter\MysqlClickFilterStore(new Connection(self::$db)))->entries(self::OWNER);
        self::assertSame([['203.0.113.50', 'count']], array_map(static fn ($e): array => [$e->range->text(), $e->action], $entries));
    }

    /** With the list full the clicks still count again, and the answer says the address is not exempt. */
    public function testNotFraudWithAFullListStillCountsTheClickAndSaysSo(): void
    {
        self::$stub->scores(['203.0.113.50' => 95]);
        $click = $this->click(self::OWNER, '203.0.113.50', 'US', 400);
        $this->score();
        $rows = [];
        for ($i = 0; $i < \Prosper202\Click\Filter\ClickFilterRules::MAX_ENTRIES; $i++) {
            $packed = bin2hex((string) inet_pton('198.51.' . intdiv($i, 250) . '.' . ($i % 250 + 1)));
            $rows[] = '(' . self::OWNER . ", 4, 0x$packed, 0x$packed, 32, 'filter', '', 0)";
        }
        self::q('INSERT INTO 202_click_filter_entries (user_id, family, range_start, range_end, prefix, action, note, created_at) VALUES ' . implode(',', $rows));

        $answer = (new \Prosper202\Fraud\FraudVerdictUndo(new Connection(self::$db)))->undo(self::OWNER, $click);
        self::assertSame([1, false], [$answer['clicks'] ?? null, $answer['exempted'] ?? null]);
        self::assertStringContainsString('the list is full', (string) $answer['exempt_problem']);
        self::assertSame(self::COUNTED, self::filtered($click));
    }

    public function testEuropeanAndUnplacedVisitorsAreNeverSent(): void
    {
        self::$stub->scores(['203.0.113.52' => 99, '203.0.113.53' => 99]);
        $held = [];
        foreach (['DE', 'GB', 'CH', 'NO', 'non', null] as $i => $country) {
            $held[] = $this->click(self::OWNER, '203.0.113.5' . ($i + 4), $country);
        }
        $outside = $this->click(self::OWNER, '203.0.113.53', 'US');

        $report = $this->score();

        self::assertSame(['203.0.113.53'], self::$stub->addresses(), 'only the visitor placed outside Europe');
        foreach ($held as $click) {
            self::assertSame(self::COUNTED, self::filtered($click), "click $click is held, not scored");
        }
        self::assertSame(self::FRAUD, self::filtered($outside));
        self::assertSame(6, $report['held']);
        self::assertSame(['203.0.113.53' => '99'], self::cached(self::OWNER), 'nothing is recorded of a held click');
    }

    public function testAVisitorFromAnEuOutermostRegionIsHeld(): void
    {
        self::$stub->scores(['203.0.113.67' => 99]);
        $reunion = $this->click(self::OWNER, '203.0.113.67', 'RE');

        $report = $this->score();

        self::assertSame([], self::$stub->addresses(), 'Réunion is the EU: never sent');
        self::assertSame(self::COUNTED, self::filtered($reunion));
        self::assertSame(1, $report['held']);
        self::assertSame([], self::cached(self::OWNER));
    }

    public function testAnAccountHoldingEveryVisitorBackIsNotScored(): void
    {
        self::$stub->scores(['203.0.113.55' => 99]);
        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'all' WHERE user_id = " . self::OWNER);
        $held = $this->click(self::OWNER, '203.0.113.55', 'US');

        $report = $this->score();

        self::assertSame([], self::$stub->addresses(), "privacy 'all': nothing is sent");
        self::assertSame(self::COUNTED, self::filtered($held));
        self::assertSame(0, $report['lookups']);

        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'disabled' WHERE user_id = " . self::OWNER);
        $this->score();

        self::assertSame(['203.0.113.55'], self::$stub->addresses(), "the same click is scored under 'disabled'");
        self::assertSame(self::FRAUD, self::filtered($held));
    }

    public function testAMaskedAddressIsNeverSent(): void
    {
        self::$stub->scores(['198.51.100.0' => 99, '203.0.113.56' => 99]);
        $masked = $this->click(self::OWNER, '198.51.100.0', 'US', 400);
        $whole = $this->click(self::OWNER, '203.0.113.56', 'US', 300);

        $report = $this->score();

        self::assertSame(['203.0.113.56'], self::$stub->addresses(), 'a /24 stands for no one visitor');
        self::assertSame(self::COUNTED, self::filtered($masked));
        self::assertSame(self::FRAUD, self::filtered($whole));
        self::assertSame(1, $report['held']);
    }

    public function testASwitchedOffAccountIsNotScored(): void
    {
        self::$stub->scores(['203.0.113.57' => 99]);
        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::OWNER);
        $click = $this->click(self::OWNER, '203.0.113.57', 'US');

        $this->score();

        self::assertSame([], self::$stub->addresses());
        self::assertSame(self::COUNTED, self::filtered($click));
    }

    public function testAnErrorOnAClickIsRecordedAndScoringGoesOnAfterIt(): void
    {
        self::$stub->scores(['203.0.113.58' => 99, '203.0.113.59' => 99]);
        $broken = $this->click(self::OWNER, '203.0.113.58', 'US', 400);
        $next = $this->click(self::OWNER, '203.0.113.59', 'US', 300);
        self::q('RENAME TABLE 202_clicks_filter_reason TO 202_clicks_filter_reason_away');
        try {
            $report = $this->score();
        } finally {
            self::q('RENAME TABLE 202_clicks_filter_reason_away TO 202_clicks_filter_reason');
        }

        self::assertSame(1, $report['failed']);
        self::assertSame(['203.0.113.58'], self::$stub->addresses(), 'filtering it failed, after the lookup');
        self::assertSame(self::COUNTED, self::filtered($broken), 'the failed filter wrote nothing');
        self::assertSame('1', self::one('SELECT failures FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER));
        self::assertSame(
            (string) (self::NOW + IpqsScoring::RETRY_FIRST),
            self::one('SELECT retry_at FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER),
            'waited out like a failed lookup'
        );
        self::assertNotNull(IpqsScoring::status(new Connection(self::$db), self::OWNER)['last_error'] ?? null);

        $this->now += IpqsScoring::RETRY_FIRST;
        $this->score();

        self::assertSame(
            ['203.0.113.58', '203.0.113.59'],
            self::$stub->addresses(),
            'the next click, not the one that failed again'
        );
        self::assertSame(self::COUNTED, self::filtered($broken));
        self::assertSame(self::FRAUD, self::filtered($next));
        self::assertSame('0', self::one('SELECT failures FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER));
    }

    public function testEachAccountUsesItsOwnKeyAndCache(): void
    {
        self::$stub->scores(['203.0.113.50' => 95]);
        $owner = $this->click(self::OWNER, '203.0.113.50', 'US');
        $other = $this->click(self::OTHER, '203.0.113.50', 'US');
        $noKey = $this->click(self::NO_KEY, '203.0.113.50', 'US');
        $deleted = $this->click(self::DELETED, '203.0.113.50', 'US');

        $this->score();

        $keys = array_map(static fn (array $r): string => explode('/', $r['path'])[4], self::$stub->requests());
        sort($keys);
        self::assertSame(['good-key', 'second-key'], $keys, 'one lookup per account, each with its key');
        self::assertSame(self::FRAUD, self::filtered($owner));
        self::assertSame(self::FRAUD, self::filtered($other));
        self::assertSame(self::COUNTED, self::filtered($noKey), 'an account without a key is not scored');
        self::assertSame(self::COUNTED, self::filtered($deleted), 'nor a deleted one');
    }

    public function testTheWalkTakesEachClickOnceAndWaitsForANewOne(): void
    {
        $first = $this->click(self::OWNER, '203.0.113.61', 'US');
        $young = $this->click(self::OWNER, '203.0.113.62', 'US', 10);

        $this->score();
        self::assertSame(['203.0.113.61'], self::$stub->addresses(), 'a click younger than a minute waits');
        $this->score();
        self::assertSame(['203.0.113.61'], self::$stub->addresses(), 'nothing is taken twice');

        $this->now += 120;
        $this->score();
        self::assertSame(['203.0.113.61', '203.0.113.62'], self::$stub->addresses());
        self::assertSame(self::COUNTED, self::filtered($first));
        self::assertSame(self::COUNTED, self::filtered($young));
    }

    public function testAFailedLookupWaitsAndResumesAtTheSameClick(): void
    {
        self::q("UPDATE 202_users_pref SET ipqs_api_key = 'refused-k3y' WHERE user_id = " . self::OWNER);
        self::$stub->scores(['203.0.113.63' => 97]);
        $click = $this->click(self::OWNER, '203.0.113.63', 'US');
        $after = $this->click(self::OWNER, '203.0.113.64', 'US', 200);

        $report = $this->score();

        self::assertSame(1, $report['failed']);
        self::assertSame(['203.0.113.63'], self::$stub->addresses(), 'the run stops at the failure');
        $progress = self::$db->query('SELECT failures, retry_at, last_error FROM 202_ipqs_progress'
            . ' WHERE user_id = ' . self::OWNER);
        self::assertInstanceOf(\mysqli_result::class, $progress);
        $row = $progress->fetch_assoc();
        self::assertSame('1', (string) $row['failures']);
        self::assertSame((string) (self::NOW + IpqsScoring::RETRY_FIRST), (string) $row['retry_at']);
        self::assertStringContainsString('refused the lookup', (string) $row['last_error']);
        self::assertStringNotContainsString('refused-k3y', (string) $row['last_error'], 'the key is never recorded');

        $this->now += 60;
        $this->score();
        self::assertCount(1, self::$stub->requests(), 'the account waits out its backoff');

        self::q("UPDATE 202_users_pref SET ipqs_api_key = 'good-key' WHERE user_id = " . self::OWNER);
        $this->now += IpqsScoring::RETRY_FIRST;
        $this->score();
        self::assertSame(
            ['203.0.113.63', '203.0.113.63', '203.0.113.64'],
            self::$stub->addresses(),
            'resumed at the click'
        );
        self::assertSame(self::FRAUD, self::filtered($click));
        self::assertSame(self::COUNTED, self::filtered($after));
        self::assertSame('0', self::one('SELECT failures FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER));
    }

    public function testCachedScoresAreDeletedAfterADay(): void
    {
        $this->click(self::OWNER, '203.0.113.65', 'US');
        $this->score();
        self::assertSame(['203.0.113.65' => '10'], self::cached(self::OWNER));

        $this->now += IpqsScoring::CACHE_SECONDS + 1;
        $report = $this->score();

        self::assertSame(1, $report['pruned']);
        self::assertSame([], self::cached(self::OWNER), 'deleted, not only ignored');
        $this->click(self::OWNER, '203.0.113.65', 'US');
        $this->score();
        self::assertSame(['203.0.113.65', '203.0.113.65'], self::$stub->addresses(), 'looked up again a day later');
    }

    public function testDeletingTheUserPurgesItsScores(): void
    {
        $this->click(self::OWNER, '203.0.113.66', 'US');
        $this->click(self::OTHER, '203.0.113.66', 'US');
        $this->score();
        self::assertSame('1', self::one('SELECT COUNT(*) FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER));

        (new UserDataPurge(self::$db))->deleteUser(self::OWNER);

        self::assertSame([], self::cached(self::OWNER));
        self::assertSame('0', self::one('SELECT COUNT(*) FROM 202_ipqs_progress WHERE user_id = ' . self::OWNER));
        self::assertSame(['203.0.113.66' => '10'], self::cached(self::OTHER), 'another account keeps its own');
    }

    /** @return array<string, string> address => score */
    private static function cached(int $user): array
    {
        $result = self::$db->query('SELECT i.ip_address, s.fraud_score FROM 202_ipqs_scores s'
            . ' JOIN 202_ips i ON i.ip_id = s.ip_id WHERE s.user_id = ' . $user . ' ORDER BY i.ip_address');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $out = [];
        foreach ($result->fetch_all(MYSQLI_NUM) as [$ip, $score]) {
            $out[(string) $ip] = (string) $score;
        }

        return $out;
    }
}

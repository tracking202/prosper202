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

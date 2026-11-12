<?php

declare(strict_types=1);

namespace Tests\Spend;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Spend\SpendLedger;

/**
 * The spend ledger in the reports, on a real server (CLAUDE.md #9): a
 * click carrying an ad id the ledger has for its hour is costed at its
 * share of that hour's spend; every other click keeps its CPC; spend no
 * click carries is reported as unallocated, in no row; and a ledger with
 * nothing in the window leaves every report exactly as it was.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6301 and 6302's rows.
 *
 * @group integration
 */
final class LedgerCostIntegrationTest extends TestCase
{
    private const USER = 6301;
    private const OTHER = 6302;
    private const NETWORK = 6301;
    private const ZONE = 'America/New_York';

    /** A UTC hour two days back: 2 clicks of ad A, one of B, one with no ad. */
    private static int $hour = 0;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
        $now = time();
        self::$hour = $now - $now % 3600 - 2 * 86400;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        self::seed();
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::q("DELETE FROM 202_spend_ledger WHERE user_id = $u");
            self::q("DELETE FROM 202_users_pref WHERE user_id = $u");
            self::q("DELETE FROM 202_users WHERE user_id = $u");
            self::q("DELETE FROM 202_ppc_networks WHERE user_id = $u");
        }
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_network_id IN (' . self::NETWORK . ', ' . (self::NETWORK + 1) . ')');
        self::q('DELETE FROM 202_custom_variables WHERE custom_variable_id BETWEEN 630100 AND 630199');
        self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id BETWEEN 630100 AND 630199');
    }

    private static function seed(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'lc$u', user_email = 'lc$u@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = '" . self::ZONE . "',"
                . " user_time_register = 0, install_hash = '', user_hash = ''");
            self::q("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD'");
        }
        // The account's traffic source with an ad_id variable, and the other
        // account's with one too: its clicks share an ad id with ours.
        foreach ([self::USER => self::NETWORK, self::OTHER => self::NETWORK + 1] as $u => $network) {
            self::q("INSERT INTO 202_ppc_networks SET ppc_network_id = $network, user_id = $u,"
                . " ppc_network_name = 'ChatGPT Ads', ppc_network_time = 0");
            self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = $network, ppc_network_id = $network,"
                . " name = 'ad_id', parameter = 'ad_id', placeholder = '{ad_id}'");
            self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = " . ($network + 10) . ", ppc_network_id = $network,"
                . " name = 'campaign_id', parameter = 'campaign_id', placeholder = '{campaign_id}'");
        }
        // Variable sets: 630101 ad A, 630102 ad B, 630103 ad A and ad B on
        // two ad_id values (ambiguous), 630104 the other account's ad A,
        // 630105 a campaign id only.
        $values = [630101 => [self::NETWORK, 'ad_A'], 630102 => [self::NETWORK, 'ad_B'],
            630103 => [self::NETWORK, 'ad_C'], 630104 => [self::NETWORK + 1, 'ad_A'], 630105 => [self::NETWORK + 10, 'ad_A']];
        foreach ($values as $id => [$variable, $value]) {
            self::q("INSERT INTO 202_custom_variables SET custom_variable_id = $id, ppc_variable_id = $variable, variable = '$value'");
        }
        $sets = [630101 => [630101], 630102 => [630102], 630103 => [630101, 630103], 630104 => [630104], 630105 => [630105]];
        foreach ($sets as $set => $variables) {
            foreach ($variables as $v) {
                self::q("INSERT INTO 202_variable_sets2 SET variable_set_id = $set, variables = '$v'");
            }
        }

        $h = self::$hour;
        // click id => [user, network, time, variable set, cost]
        $clicks = [
            630001 => [self::USER, self::NETWORK, $h + 100, '630101', '0.10000'],
            630002 => [self::USER, self::NETWORK, $h + 2000, '630101', '0.10000'],
            630003 => [self::USER, self::NETWORK, $h + 3700, '630102', '0.50000'],
            630004 => [self::USER, self::NETWORK, $h + 200, '0', '0.20000'],
            630005 => [self::USER, self::NETWORK, $h + 300, '630103', '0.30000'],
            630006 => [self::USER, self::NETWORK, $h + 400, '630105', '0.40000'],
            630007 => [self::OTHER, self::NETWORK + 1, $h + 500, '630104', '0.70000'],
            // This account's click naming the other account's traffic source
            // (CLAUDE.md #27): its variables are not this account's, so it
            // carries no ad and keeps its CPC.
            630008 => [self::USER, self::NETWORK + 1, $h + 600, '630104', '0.60000'],
        ];
        foreach ($clicks as $id => [$user, $network, $time, $set, $cost]) {
            self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = $id, click_time = $time,"
                . " ppc_network_id = $network, ppc_account_id = 1, aff_campaign_id = 1, landing_page_id = 0,"
                . " keyword_id = 0, variable_set_id = '$set', clicks = 1, click_out = 1, leads = 0,"
                . " click_lead = 0, click_filtered = 0, click_bot = 0, payout = 0, income = 0, cost = $cost");
        }
    }

    /** @param array<string, mixed> $row */
    private static function ledgerRow(string $ad, int $hourStart, string $spend, string $currency = 'USD'): array
    {
        return ['platform' => 'openai_ads', 'account_ref' => 'adacct_demo', 'campaign_ref' => 'cmp_1',
            'ad_group_ref' => null, 'ad_ref' => $ad, 'hour_start' => $hourStart, 'impressions' => 1000,
            'clicks' => 12, 'spend' => $spend, 'currency' => $currency];
    }

    private static function write(int $user, array $rows): array
    {
        $ledger = new SpendLedger(new Connection(self::$db));
        [$parsed, $errors] = $ledger->parse($user, $rows);
        self::assertSame([], $errors);

        return $ledger->write($user, $parsed);
    }

    /** Every report this change touches, as JSON. @return array<string, string> */
    private static function reports(ReportsController $reports, array $window): array
    {
        $out = [
            'summary' => $reports->summary($window),
            'breakdown' => $reports->breakdown($window + ['breakdown' => 'ppc_network']),
            'timeseries_day' => $reports->timeseries($window + ['interval' => 'day']),
            'timeseries_hour' => $reports->timeseries($window + ['interval' => 'hour']),
            'daypart' => $reports->daypart($window),
            'weekpart' => $reports->weekpart($window),
            'groups' => $reports->groups($window + ['by' => 'ppc_network,campaign']),
        ];

        return array_map(static fn (array $r): string => (string) json_encode($r, JSON_PRESERVE_ZERO_FRACTION), $out);
    }

    private static function window(int $from, int $to): array
    {
        return ['time_from' => (string) $from, 'time_to' => (string) $to];
    }

    public function testALedgerWithNothingInTheWindowLeavesEveryReportAsItWas(): void
    {
        $window = self::window(self::$hour, self::$hour + 7199);
        $without = new ReportsController(self::$db, self::USER, static fn (?int $f, ?int $t) => null);
        $before = self::reports($without, $window);

        // The other account's ledger, and this account's outside the window.
        self::write(self::OTHER, [self::ledgerRow('ad_A', self::$hour, '9.00')]);
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour - 86400, '9.00'), self::ledgerRow('ad_A', self::$hour + 7200, '9.00')]);

        self::assertSame($before, self::reports(new ReportsController(self::$db, self::USER), $window));
        self::assertStringNotContainsString('spend_ledger', implode('', $before));
        // And the figures are the clicks' CPCs: 0.1 + 0.1 + 0.5 + 0.2 + 0.3 + 0.4 + 0.6.
        self::assertEqualsWithDelta(2.2, json_decode($before['summary'], true)['data']['total_cost'], 1e-9);
    }

    public function testAMatchedClickIsCostedAtItsShareOfTheHoursSpend(): void
    {
        self::write(self::USER, [
            // Ad A's hour: its two clicks cost 1.50 each, not 0.10.
            self::ledgerRow('ad_A', self::$hour, '3.00'),
            // Ad Z: no click carries it, so unallocated.
            self::ledgerRow('ad_Z', self::$hour, '2.25'),
        ]);
        // The other account's click on ad A takes no share of it.
        self::write(self::OTHER, [self::ledgerRow('ad_A', self::$hour, '7.00')]);
        // A row in another currency (the account's currency changed since)
        // is not counted, and is said to be there.
        self::q('INSERT INTO 202_spend_ledger SET user_id = ' . self::USER . ", platform = 'openai_ads',"
            . " account_ref = 'adacct_eur', ad_ref = 'ad_A', hour_start = " . self::$hour . ", spend = 50,"
            . " currency = 'EUR', created_at = 0, updated_at = 0");

        $reports = new ReportsController(self::$db, self::USER);
        $summary = $reports->summary(self::window(self::$hour, self::$hour + 7199));
        // 1.5 + 1.5 + ad B's 0.5 (no ledger hour) + 0.2 (no ad) + 0.3
        // (two ad ids: ambiguous) + 0.4 (ad_A on a campaign_id variable)
        // + 0.6 (another account's traffic source).
        self::assertEqualsWithDelta(5.0, $summary['data']['total_cost'], 1e-9);
        self::assertSame(1, $summary['spend_ledger']['other_currency_rows']);
        self::assertSame(self::$hour + 3600, $summary['spend_ledger']['reaches']);
        self::assertEqualsWithDelta(2.25, $summary['spend_ledger']['unallocated']['total_cost'], 1e-9);
        self::assertStringContainsString('Spend data reaches', $summary['spend_ledger']['note']);
        self::assertStringContainsString(self::ZONE, $summary['spend_ledger']['note']);

        // A window that starts mid-hour keeps the share each click has of
        // the whole hour: only the second ad A click is in it.
        $late = $reports->summary(self::window(self::$hour + 1000, self::$hour + 7199));
        self::assertEqualsWithDelta(1.5 + 0.5, $late['data']['total_cost'], 1e-9);

        // Every report agrees with the summary's total.
        $window = self::window(self::$hour, self::$hour + 7199);
        $all = array_map(static fn (string $j): array => json_decode($j, true), self::reports($reports, $window));
        // The breakdown has no row for the click naming another account's
        // traffic source (as before the ledger): 5.0 less its 0.6.
        self::assertEqualsWithDelta(4.4, array_sum(array_column($all['breakdown']['data'], 'total_cost')), 1e-9);
        self::assertArrayHasKey('spend_ledger', $all['breakdown']);
        foreach (['timeseries_day', 'timeseries_hour', 'daypart', 'weekpart'] as $name) {
            $sum = array_sum(array_column($all[$name]['data'], 'total_cost'));
            self::assertEqualsWithDelta(5.0, $sum, 1e-9, $name);
            self::assertArrayHasKey('spend_ledger', $all[$name], $name);
        }
        self::assertEqualsWithDelta(5.0, $all['groups']['totals']['total_cost'], 1e-9);
        self::assertEqualsWithDelta(5.0, array_sum(array_column($all['groups']['data'], 'total_cost')), 1e-9);
    }

    public function testARePullReplacesTheHourAndASecondWriteChangesNothing(): void
    {
        self::assertSame(['inserted' => 1, 'replaced' => 0, 'unchanged' => 0], self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '3.00')]));
        self::assertSame(['inserted' => 0, 'replaced' => 0, 'unchanged' => 1], self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '3')]));
        self::assertSame(['inserted' => 0, 'replaced' => 1, 'unchanged' => 0], self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '4.20')]));
        $row = self::$db->query('SELECT COUNT(*) AS n, SUM(spend) AS s FROM 202_spend_ledger WHERE user_id = ' . self::USER)->fetch_assoc();
        self::assertSame(['n' => '1', 's' => '4.200000'], $row);

        $ledger = new SpendLedger(new Connection(self::$db));
        [$parsed] = $ledger->parse(self::USER, [self::ledgerRow('ad_A', self::$hour, '5'), self::ledgerRow('ad_B', self::$hour, '1')]);
        self::assertSame(['inserted' => 1, 'replaced' => 1, 'unchanged' => 0], $ledger->preview(self::USER, $parsed));
        $after = self::$db->query('SELECT COUNT(*) AS n, SUM(spend) AS s FROM 202_spend_ledger WHERE user_id = ' . self::USER)->fetch_assoc();
        self::assertSame($row, $after, 'a preview writes nothing');
    }

    public function testAnotherCurrencyAndARepeatedHourAreRefusedByName(): void
    {
        $ledger = new SpendLedger(new Connection(self::$db));
        [$parsed, $errors] = $ledger->parse(self::USER, [
            self::ledgerRow('ad_A', self::$hour, '3.00', 'EUR'),
            self::ledgerRow('ad_B', self::$hour, '1.00'),
            self::ledgerRow('ad_B', self::$hour, '2.00'),
        ]);
        self::assertSame([], $parsed);
        self::assertStringContainsString('is EUR, and this account records money in USD', $errors['rows.0.currency']);
        self::assertStringContainsString('same platform, ad account, ad and hour as rows.1', $errors['rows.2']);
    }

    public function testTheLedgerReadsDaysInTheAccountsZoneWithTheClicksThatCarryEachAd(): void
    {
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '3.00'), self::ledgerRow('ad_Z', self::$hour, '2.25')]);
        $read = (new SpendLedger(new Connection(self::$db)))->read(self::USER, null, null, 'day', self::ZONE, 'openai_ads', 50);
        $byAd = array_column($read['rows'], null, 'ad_ref');
        $day = (new \DateTimeImmutable('@' . self::$hour))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d');
        self::assertSame($day, $byAd['ad_A']['period']);
        self::assertSame(2, $byAd['ad_A']['matched_clicks']);
        self::assertSame(0.0, $byAd['ad_A']['unallocated_spend']);
        self::assertSame(0, $byAd['ad_Z']['matched_clicks']);
        self::assertSame(2.25, $byAd['ad_Z']['unallocated_spend']);
        self::assertNull($byAd['ad_A']['ad_group_ref']);
        self::assertFalse($read['truncated']);
    }

    public function testOneAdIdNamesOneAdAcrossPlatformsAndAdAccounts(): void
    {
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '1.00')]);
        $ledger = new SpendLedger(new Connection(self::$db));
        $other = ['platform' => 'other_ads'] + self::ledgerRow('ad_A', self::$hour, '5.00');
        [$parsed, $errors] = $ledger->parse(self::USER, [$other]);
        self::assertSame([], $parsed);
        self::assertStringContainsString('ad ad_A is already recorded for openai_ads ad account adacct_demo', $errors['rows.0.ad_ref']);
        $account = ['account_ref' => 'adacct_two'] + self::ledgerRow('ad_B', self::$hour, '1.00');
        [, $errors] = $ledger->parse(self::USER, [self::ledgerRow('ad_B', self::$hour - 3600, '1.00'), $account]);
        self::assertStringContainsString('under another platform or ad account than rows.0', $errors['rows.1.ad_ref']);
        // Another account's ad of that id is its own.
        self::assertSame(['inserted' => 1, 'replaced' => 0, 'unchanged' => 0], self::write(self::OTHER, [$other]));
    }

    public function testAnAdIdOnAVariableNamedOtherwiseIsNotTheAd(): void
    {
        // AD_ID is another URL parameter than ad_id.
        self::q('UPDATE 202_ppc_network_variables SET parameter = \'AD_ID\' WHERE ppc_variable_id = ' . self::NETWORK);
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '9.00')]);
        $summary = (new ReportsController(self::$db, self::USER))->summary(self::window(self::$hour, self::$hour + 7199));
        self::assertEqualsWithDelta(2.2, $summary['data']['total_cost'], 1e-9, 'every click at its CPC');
        self::assertEqualsWithDelta(9.0, $summary['spend_ledger']['unallocated']['total_cost'], 1e-9);
    }

    public function testAnHourShowsExactlyWhatItBilledAcrossItsClicks(): void
    {
        // A third click of ad A in its hour: 1.00 over three clicks.
        self::q('INSERT INTO 202_dataengine SET user_id = ' . self::USER . ', click_id = 630009, click_time = ' . (self::$hour + 900)
            . ', ppc_network_id = ' . self::NETWORK . ", ppc_account_id = 1, aff_campaign_id = 1, landing_page_id = 0,"
            . " keyword_id = 0, variable_set_id = '630101', clicks = 1, click_out = 1, leads = 0,"
            . ' click_lead = 0, click_filtered = 0, click_bot = 0, payout = 0, income = 0, cost = 0.10000');
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '1.00')]);
        $summary = (new ReportsController(self::$db, self::USER))->summary(self::window(self::$hour, self::$hour + 7199));
        // 1.00 exactly, plus the other clicks' CPCs: 0.5 + 0.2 + 0.3 + 0.4 + 0.6.
        self::assertSame(3.0, $summary['data']['total_cost']);
    }

    public function testAChangedRowIsReplacedWhenTheClockHasMoved(): void
    {
        $t = self::$hour + 7200;
        $ledger = new SpendLedger(new Connection(self::$db), static function () use (&$t): int {
            return $t;
        });
        [$rows] = $ledger->parse(self::USER, [self::ledgerRow('ad_A', self::$hour, '3.00')]);
        self::assertSame(['inserted' => 1, 'replaced' => 0, 'unchanged' => 0], $ledger->write(self::USER, $rows));
        $t += 3600;
        self::assertSame(['inserted' => 0, 'replaced' => 0, 'unchanged' => 1], $ledger->write(self::USER, $rows), 'a later identical pull changes nothing');
        $stamp = self::$db->query('SELECT updated_at FROM 202_spend_ledger WHERE user_id = ' . self::USER)->fetch_assoc()['updated_at'];
        self::assertSame((string) (self::$hour + 7200), $stamp);
        $t += 3600;
        [$rows] = $ledger->parse(self::USER, [self::ledgerRow('ad_A', self::$hour, '3.50')]);
        self::assertSame(['inserted' => 0, 'replaced' => 1, 'unchanged' => 0], $ledger->write(self::USER, $rows));
    }

    public function testAWriteTakesAtMostTwoThousandRows(): void
    {
        $rows = [];
        for ($i = 0; $i <= 2000; $i++) {
            $rows[] = self::ledgerRow('ad_' . $i, self::$hour, '1');
        }
        [$parsed, $errors] = (new SpendLedger(new Connection(self::$db)))->parse(self::USER, $rows);
        self::assertSame([], $parsed);
        self::assertSame('holds 2001 rows; send at most 2000 per request', $errors['rows']);
        [$parsed] = (new SpendLedger(new Connection(self::$db)))->parse(self::USER, array_slice($rows, 0, 2000));
        self::assertCount(2000, $parsed);
    }

    public function testTheNoteNamesThePlatformThatReachesLeast(): void
    {
        self::write(self::USER, [self::ledgerRow('ad_A', self::$hour, '1.00'), ['platform' => 'other_ads'] + self::ledgerRow('ad_Z', self::$hour + 3600, '1.00')]);
        $meta = (new ReportsController(self::$db, self::USER))->summary(self::window(self::$hour, self::$hour + 7199))['spend_ledger'];
        self::assertSame(['openai_ads' => self::$hour + 3600, 'other_ads' => self::$hour + 7200], $meta['reaches_by_platform']);
        self::assertSame(self::$hour + 3600, $meta['reaches']);
        self::assertStringContainsString('for every platform', $meta['note']);
    }
}

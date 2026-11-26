<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Report\ScratchReportDatabase;

/**
 * A report filter with several values (`aff_campaign_id=1,4`) and the
 * click_id filter, run as SQL on a real server: each list answers what the
 * union of its single-value calls answers, in the summary, a breakdown, the
 * group tree and the timeseries (CLAUDE.md #9: the IN lists, the ORs with a
 * "none", and the IPv6 subquery are the code under test).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); it writes
 * users 8136 and 8137's rows and removes them at both ends.
 *
 * @group integration
 */
final class ReportFilterListsIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 8136;
    private const OTHER = 8137;
    private const PUBLISHER_ONE = 8138;
    private const PUBLISHER_TWO = 8139;
    private const FIRST_CLICK = 81360001;

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $now = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::seed();
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
        self::requireScratch();
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
        }
        self::$db->query('DELETE FROM 202_clicks_publisher WHERE click_id BETWEEN ' . self::FIRST_CLICK
            . ' AND ' . (self::FIRST_CLICK + 99));
        foreach (['ipA', 'ipB', 'ip6'] as $k) {
            if (isset(self::$ids[$k])) {
                self::$db->query('DELETE FROM 202_ips WHERE ip_id = ' . self::$ids[$k]);
            }
        }
        if (isset(self::$ids['v6'])) {
            self::$db->query('DELETE FROM 202_ips_v6 WHERE ip_id = ' . self::$ids['v6']);
        }
        foreach (['dmDesk', 'dmPhone', 'dmTab'] as $k) {
            if (isset(self::$ids[$k])) {
                self::$db->query('DELETE FROM 202_device_models WHERE device_id = ' . self::$ids[$k]);
            }
        }
        self::$ids = [];
    }

    private static function seed(): void
    {
        self::q("SET SESSION sql_mode=''");
        self::q('INSERT IGNORE INTO 202_device_types (type_id, type_name)'
            . " VALUES (1, 'Desktop'), (2, 'Mobile'), (3, 'Tablet'), (4, 'Bot')");
        $i = &self::$ids;
        $campaign = static fn (int $user, string $name): int => self::insert(
            "INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1, aff_campaign_name = "
            . self::str($name) . ", aff_campaign_url = 'https://o.example', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0'
        );
        $i['campA'] = $campaign(self::USER, 'fl A');
        $i['campB'] = $campaign(self::USER, 'fl B');
        $i['campC'] = $campaign(self::USER, 'fl C');

        $i['ipA'] = self::insert("INSERT INTO 202_ips SET ip_address = '198.51.100.36'");
        $i['ipB'] = self::insert("INSERT INTO 202_ips SET ip_address = '198.51.100.37'");
        $packed = self::str((string) inet_pton('2001:db8::8136'));
        $i['v6'] = self::insert('INSERT INTO 202_ips_v6 SET ip_address = ' . $packed);
        $i['ip6'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str((string) $i['v6']));

        $i['dmDesk'] = self::insert("INSERT INTO 202_device_models SET device_name = 'fl Desktop', device_type = 1");
        $i['dmPhone'] = self::insert("INSERT INTO 202_device_models SET device_name = 'fl Phone', device_type = 2");
        $i['dmTab'] = self::insert("INSERT INTO 202_device_models SET device_name = 'fl Tablet', device_type = 3");

        self::$now = time() - 7200;
        $n = 0;
        $row = static function (array $c) use (&$n): void {
            $n++;
            $c += [
                'user_id' => self::USER, 'click_id' => self::FIRST_CLICK + $n, 'click_time' => self::$now - 3600 * $n,
                'aff_campaign_id' => 0, 'ppc_account_id' => 1, 'ppc_network_id' => 0, 'landing_page_id' => 0,
                'keyword_id' => 0, 'ip_id' => 0, 'click_referer_site_url_id' => 0, 'device_id' => 0,
                'click_filtered' => 0, 'click_bot' => 0, 'click_lead' => 0,
                'clicks' => 1, 'click_out' => 1, 'payout' => 0, 'income' => 0, 'cost' => 0,
            ];
            $c['leads'] = $c['click_lead'];
            self::q('INSERT INTO 202_dataengine SET ' . implode(', ', array_map(
                static fn (string $k, mixed $v): string => "$k = "
                    . ($v === null ? 'NULL' : (is_string($v) ? self::str($v) : $v)),
                array_keys($c),
                $c
            )));
        };
        $row(['aff_campaign_id' => $i['campA'], 'ppc_network_id' => 10,
            'device_id' => $i['dmDesk'], 'ip_id' => $i['ipA'],
            'click_lead' => 1, 'income' => 10, 'cost' => 1]);
        $row(['aff_campaign_id' => $i['campA'], 'ppc_network_id' => 11,
            'device_id' => $i['dmPhone'], 'ip_id' => $i['ipB'],
            'landing_page_id' => 5, 'click_filtered' => 1, 'cost' => 2]);
        $row(['aff_campaign_id' => $i['campB'], 'ppc_network_id' => null,
            'device_id' => $i['dmPhone'], 'ip_id' => $i['ip6'],
            'landing_page_id' => 5, 'click_lead' => 1, 'income' => 20, 'cost' => 3]);
        $row(['aff_campaign_id' => $i['campB'], 'ppc_network_id' => 0,
            'device_id' => $i['dmDesk'], 'ip_id' => $i['ipA'],
            'click_filtered' => 1, 'click_bot' => 1]);
        $row(['aff_campaign_id' => $i['campC'], 'ppc_network_id' => 10,
            'device_id' => $i['dmTab'], 'ip_id' => $i['ipB'],
            'landing_page_id' => 5, 'click_lead' => 1, 'income' => 5]);
        $row(['aff_campaign_id' => $i['campC'], 'ppc_network_id' => 11,
            'device_id' => $i['dmDesk'], 'cost' => 4]);
        // The first click again, a later visit: a click id names both rows.
        $row(['click_id' => self::FIRST_CLICK + 1, 'click_time' => self::$now, 'aff_campaign_id' => $i['campA'],
            'ppc_network_id' => 10, 'device_id' => $i['dmDesk'], 'ip_id' => $i['ipA'], 'cost' => 6]);
        // Another account's click on the same values: in no figure here.
        $row(['user_id' => self::OTHER, 'aff_campaign_id' => $i['campA'], 'ppc_network_id' => 10,
            'device_id' => $i['dmDesk'], 'ip_id' => $i['ipA'], 'click_lead' => 1, 'income' => 99]);

        foreach ([1 => self::PUBLISHER_ONE, 3 => self::PUBLISHER_TWO, 5 => self::PUBLISHER_ONE] as $k => $publisher) {
            self::q('INSERT INTO 202_clicks_publisher SET click_id = ' . (self::FIRST_CLICK + $k)
                . ", publisher_user_id = $publisher, tracker_id_public = 0");
        }
        self::q(self::$strict);
    }

    private function reports(): ReportsController
    {
        return new ReportsController(self::$db, self::USER);
    }

    /** @return array<string, string> */
    private function window(): array
    {
        return ['time_from' => (string) (self::$now - 86400 * 2), 'time_to' => (string) (self::$now + 60)];
    }

    /**
     * @param array<string, mixed> $d
     * @return array{int, int, float, float} clicks, leads, income, cost
     */
    private static function totals(array $d): array
    {
        return [
            (int) $d['total_clicks'],
            (int) $d['total_leads'],
            (float) $d['total_income'],
            (float) $d['total_cost'],
        ];
    }

    /** @return array{int, int, float, float} clicks, leads, income, cost */
    private function summary(array $params): array
    {
        $d = $this->reports()->summary($params + $this->window())['data'];

        return self::totals($d);
    }

    /** @return array<string, array{int, int, float, float}> campaign name => figures */
    private function byCampaign(array $params): array
    {
        $out = [];
        $query = ['breakdown' => 'campaign', 'limit' => '500'] + $params + $this->window();
        $answer = $this->reports()->breakdown($query);
        foreach ($answer['data'] as $r) {
            $out[(string) $r['name']] = self::totals($r);
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, array{int, float}> campaign name => clicks, income of each group */
    private function groups(array $params): array
    {
        $out = [];
        foreach ($this->reports()->groups(['by' => 'campaign'] + $params + $this->window())['data'] as $g) {
            $out[(string) ($g['name'] ?? 'none')] = [(int) $g['total_clicks'], (float) $g['total_income']];
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, array{int, float}> bucket => clicks, cost */
    private function series(array $params): array
    {
        $out = [];
        foreach ($this->reports()->timeseries(['interval' => 'hour'] + $params + $this->window())['data'] as $r) {
            $bucket = (string) $r['period'];
            $out[$bucket] = [(int) $r['total_clicks'], (float) $r['total_cost']];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<string, array<int|float>> $sum
     * @param array<string, array<int|float>> $add
     * @return array<string, array<int|float>>
     */
    private static function merged(array $sum, array $add): array
    {
        foreach ($add as $key => $figures) {
            $sum[$key] = isset($sum[$key])
                ? array_map(static fn (int|float $a, int|float $b): int|float => $a + $b, $sum[$key], $figures)
                : $figures;
        }
        ksort($sum);

        return $sum;
    }

    /** @return iterable<string, array{string, list<string|int>}> */
    public static function lists(): iterable
    {
        yield 'campaigns' => ['aff_campaign_id', ['campA', 'campB']];
        yield 'traffic sources' => ['ppc_network_id', [10, 11]];
        yield 'none and a source' => ['ppc_network_id', ['none', 10]];
        yield "the pages' none and a source" => ['ppc_network_id', ['16777215', 11]];
        yield 'device types' => ['device_type', [1, 3]];
        yield 'an IPv4 and an IPv6 address' => ['ip', ['198.51.100.36', '2001:DB8:0:0::8136']];
        yield 'two IPv4 addresses' => ['ip', ['198.51.100.36', '198.51.100.37']];
        yield 'publishers' => ['publisher_id', [self::PUBLISHER_ONE, self::PUBLISHER_TWO]];
        yield 'no publisher and a publisher' => ['publisher_id', ['none', self::PUBLISHER_TWO]];
        yield 'clicks' => ['click_id', [self::FIRST_CLICK + 1, self::FIRST_CLICK + 3]];
    }

    /**
     * @dataProvider lists
     * @param list<string|int> $values each selecting clicks none of the others does
     */
    public function testAListAnswersTheUnionOfItsSingleValueCalls(string $param, array $values): void
    {
        $values = array_map(static fn (string|int $v): string => (string) (self::$ids[$v] ?? $v), $values);
        $list = [$param => implode(',', $values)];

        $summary = [0, 0, 0.0, 0.0];
        $campaigns = [];
        $groups = [];
        $series = [];
        foreach ($values as $value) {
            $one = $this->summary([$param => $value]);
            self::assertGreaterThan(0, $one[0], "$param=$value selects a click, so the union is a real test");
            $summary = array_map(static fn (int|float $a, int|float $b): int|float => $a + $b, $summary, $one);
            $campaigns = self::merged($campaigns, $this->byCampaign([$param => $value]));
            $groups = self::merged($groups, $this->groups([$param => $value]));
            $series = self::merged($series, $this->series([$param => $value]));
        }
        self::assertLessThan(
            $this->summary([])[0],
            $summary[0],
            'the list narrows: some of the account\'s clicks are outside it'
        );

        self::assertSame($summary, $this->summary($list), 'summary');
        self::assertSame($campaigns, $this->byCampaign($list), 'breakdown');
        self::assertSame($groups, $this->groups($list), 'groups');
        self::assertSame($series, $this->series($list), 'timeseries');
        $twice = [$param => implode(',', [...$values, $values[0]])];
        self::assertSame($this->summary($list), $this->summary($twice), 'a value named twice counts once');
    }

    /** show's values overlap (a filtered click can be a lead): the list is every click any of them selects. */
    public function testShowValuesSelectEveryClickAnyOfThemSelects(): void
    {
        // filtered: rows 2 and 4; leads: rows 1, 3 and 5.
        self::assertSame([5, 3, 35.0, 6.0], $this->summary(['show' => 'filtered,leads']));
        // filtered_bot (row 4) lies inside filtered.
        self::assertSame($this->summary(['show' => 'filtered']), $this->summary(['show' => 'filtered,filtered_bot']));
        self::assertSame($this->summary([]), $this->summary(['method_of_promotion' => 'directlink,landingpage']));
    }

    /** A re-clicked click is every one of its rows, as the unfiltered report counts it. */
    public function testAClickIdNamesEveryVisitOfTheClick(): void
    {
        self::assertSame([2, 1, 10.0, 7.0], $this->summary(['click_id' => (string) (self::FIRST_CLICK + 1)]));
        self::assertSame(
            [0, 0, 0.0, 0.0],
            $this->summary(['click_id' => (string) (self::FIRST_CLICK + 8)]),
            "another account's click is not this account's to report"
        );
    }

    public function testAMalformedListIsRefusedByName(): void
    {
        $malformed = [
            ['aff_campaign_id', '1,,2'], ['aff_campaign_id', '1,'], ['aff_campaign_id', '0,4'],
            ['click_id', '0'], ['click_id', '12abc'], ['show', 'all,real'], ['ip', '198.51.100.36,'],
            ['device_type', 'none,1'],
        ];
        foreach ($malformed as [$param, $value]) {
            try {
                $this->reports()->summary([$param => $value]);
                self::fail("$param=$value was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($param, $e->getFieldErrors(), "$param=$value");
            }
        }
    }
}

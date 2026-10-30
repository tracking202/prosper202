<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tracking202\Report\ReportPrefsStore;

/** @group integration */
final class SeenFilterListsIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 991701;
    private const OTHER = 991702;
    private const BASE = 7100000;
    private const CLICK = 9917010000;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::row('202_locations_country', [
            'country_id' => self::BASE, 'country_code' => 'us', 'country_name' => 'United States',
        ]);
        self::row('202_locations_country', [
            'country_id' => self::BASE + 1, 'country_code' => '', 'country_name' => 'No code',
        ]);
        foreach (P202_OVERVIEW_SEEN_LISTS as $name => $definition) {
            $column = $definition[0];
            [$table, $labelColumn] = self::lookup($name);
            $values = [];
            for ($i = 0; $i < 4096; $i++) {
                $id = self::BASE + $i;
                $label = match ($i) {
                    0 => 'Zulu', 1, 2 => 'Alpha', 3 => 'Éclair', 4 => 'Selected',
                    default => 'Unused ' . $i,
                };
                $country = self::BASE + ($i === 1 ? 1 : ($i === 2 ? 9999 : 0));
                $values[] = '(' . $id . ', ' . self::str($label)
                    . ($name === 'region_id' ? ', ' . $country : '') . ')';
            }
            self::q("INSERT INTO $table ($column, $labelColumn"
                . ($name === 'region_id' ? ', main_country_id' : '') . ') VALUES ' . implode(',', $values));
        }
        $rows = [];
        for ($i = 0; $i < 16384; $i++) {
            $id = self::BASE + $i % 4;
            $rows[] = self::clickValues(self::CLICK + $i, self::USER, 1500, $id, 1);
        }
        $rows[] = self::clickValues(self::CLICK + 16384, self::USER, 1000, self::BASE + 1, 10);
        $rows[] = self::clickValues(self::CLICK + 16385, self::USER, 2000, self::BASE + 2, 10);
        $rows[] = self::clickValues(self::CLICK + 16386, self::USER, 999, self::BASE + 4, 99999);
        $rows[] = self::clickValues(self::CLICK + 16387, self::USER, 2001, self::BASE + 4, 99999);
        $rows[] = self::clickValues(self::CLICK + 16388, self::OTHER, 1500, self::BASE + 3, 9999);
        $rows[] = self::clickValues(self::CLICK + 16389, 0, 1500, self::BASE, 999999);
        $rows[] = self::clickValues(self::CLICK + 16390, self::USER, 1500, self::BASE + 9999, 999999);
        $rows[] = self::clickValues(self::CLICK + 16391, self::USER, 1500, null, 999999);
        self::q('INSERT INTO 202_dataengine '
            . '(click_id, user_id, click_time, region_id, isp_id, browser_id, platform_id, clicks,'
            . ' ppc_account_id, landing_page_id, payout) VALUES ' . implode(',', $rows));
        self::q('ANALYZE TABLE 202_dataengine, 202_locations_region, 202_locations_country,'
            . ' 202_locations_isp, 202_browsers, 202_platforms');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $last = self::BASE + 4095;
        self::q('DELETE FROM 202_dataengine WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 16391));
        foreach (P202_OVERVIEW_SEEN_LISTS as $name => $definition) {
            [$table] = self::lookup($name);
            self::q("DELETE FROM $table WHERE {$definition[0]} BETWEEN " . self::BASE . " AND $last");
        }
        self::q('DELETE FROM 202_locations_country WHERE country_id IN (' . self::BASE . ', ' . (self::BASE + 1) . ')');
    }

    /** @return array{string, string} */
    private static function lookup(string $name): array
    {
        return match ($name) {
            'region_id' => ['202_locations_region', 'region_name'],
            'isp_id' => ['202_locations_isp', 'isp_name'],
            'browser_id' => ['202_browsers', 'browser_name'],
            'platform_id' => ['202_platforms', 'platform_name'],
        };
    }

    private static function clickValues(int $click, int $user, int $time, ?int $id, int $weight): string
    {
        $value = $id === null ? 'NULL' : (string) $id;

        return "($click, $user, $time, $value, $value, $value, $value, $weight, 0, 0, 0)";
    }

    public function testNamedSuggestionsKeepLegacyNameGroupingScopeWindowAndLimit(): void
    {
        self::requireScratch();
        $store = new ReportPrefsStore(self::$db);
        foreach (['region', 'isp'] as $kind) {
            $select = $kind === 'region' ? 'r.region_name, c.country_code' : 'i.isp_name';
            $join = $kind === 'region'
                ? 'JOIN 202_locations_region r ON r.region_id=2st.region_id'
                    . ' LEFT JOIN 202_locations_country c ON c.country_id=r.main_country_id'
                : 'JOIN 202_locations_isp i ON i.isp_id=2st.isp_id';
            foreach ([self::USER, null] as $user) {
                $scope = $user === null ? '2st.user_id != 0' : "2st.user_id=$user";
                foreach ([[1000, 2000], [3000, 4000]] as [$from, $to]) {
                    foreach ([1, 2, 300] as $limit) {
                        $rows = self::$db->query("SELECT $select FROM 202_dataengine 2st $join WHERE $scope"
                            . " AND click_time >= $from AND click_time <= $to GROUP BY $select"
                            . " ORDER BY SUM(clicks) DESC, $select LIMIT $limit")->fetch_all(MYSQLI_ASSOC);
                        $expected = array_map(static function (array $row) use ($kind): string {
                            if ($kind === 'isp') {
                                return $row['isp_name'];
                            }
                            $code = strtoupper(trim((string) ($row['country_code'] ?? '')));
                            return $row['region_name'] . ($code === '' ? '' : " ($code)");
                        }, $rows);
                        self::assertSame(
                            array_values(array_unique($expected)),
                            $store->suggestions($kind, $user, $from, $to, $limit)
                        );
                    }
                }
            }
        }
        self::assertSame((string) (self::BASE + 1), $store->resolveName('isp', 'Alpha', self::USER, 1000, 1500)['id']);
        self::assertSame((string) (self::BASE + 2), $store->resolveName('isp', 'Alpha', self::USER, 1500, 2000)['id']);
    }

    /** @return array<string, array{string, ?int, int, int, int}> */
    public static function windows(): array
    {
        $cases = [];
        foreach (['region_id', 'isp_id', 'browser_id', 'platform_id'] as $name) {
            foreach ([self::USER, null] as $user) {
                foreach ([1, 2, 300] as $limit) {
                    foreach ([[1000, 2000], [3000, 4000]] as [$from, $to]) {
                        $cases[$name . ':' . ($user ?? 'all') . ":$limit:$from"] = [$name, $user, $from, $to, $limit];
                    }
                }
            }
        }

        return $cases;
    }

    /** @dataProvider windows */
    public function testDropdownKeepsTheLegacyAnswer(string $name, ?int $user, int $from, int $to, int $limit): void
    {
        $conn = new Connection(self::requireScratch());
        [$column, $lookup, $id, $label] = P202_OVERVIEW_SEEN_LISTS[$name];
        $scope = $user === null ? 'd.user_id != 0' : 'd.user_id = ?';
        $params = $user === null ? [$from, $to] : [$user, $from, $to];
        $legacy = "SELECT d.$column AS id, MIN($label) AS label FROM 202_dataengine AS d"
            . " JOIN $lookup ON ($id = d.$column) WHERE $scope AND d.click_time >= ? AND d.click_time <= ?"
            . " GROUP BY d.$column ORDER BY SUM(d.clicks) DESC, d.$column LIMIT $limit";

        self::assertSame(
            self::runSql($conn, $legacy, $params),
            self::runSql($conn, p202_overview_seen_list_sql($name, $user === null, $limit), $params)
        );
    }

    public function testSelectedValuesSurviveEmptyWindowsAndTheLimit(): void
    {
        $conn = new Connection(self::requireScratch());
        foreach (array_keys(P202_OVERVIEW_SEEN_LISTS) as $name) {
            $current = (string) (self::BASE + 4);
            $label = $name === 'region_id' ? 'Selected (US)' : 'Selected';
            $empty = p202_overview_seen_list($conn, $name, self::USER, 3000, 4000, $current, 1);
            self::assertSame([self::BASE + 4 => $label], $empty);
            $limited = p202_overview_seen_list($conn, $name, self::USER, 1000, 2000, $current, 1);
            self::assertSame([self::BASE + 1 => 'Alpha', self::BASE + 4 => $label], $limited);
        }
    }

    public function testRegionLabelLookupsAreBoundedByGroupsRatherThanClicks(): void
    {
        $db = self::requireScratch();
        if (!str_contains($db->server_info, 'MariaDB')) {
            self::markTestSkipped('Actual lookup counts use MariaDB ANALYZE FORMAT=JSON.');
        }
        $conn = new Connection($db);
        $rows = self::runSql(
            $conn,
            'ANALYZE FORMAT=JSON ' . p202_overview_seen_list_sql('region_id', false),
            [self::USER, 1000, 2000]
        );
        $plan = json_decode((string) array_values($rows[0])[0], true, 512, JSON_THROW_ON_ERROR);
        $lookups = [];
        $walk = static function (array $node) use (&$walk, &$lookups): void {
            if (isset($node['table_name']) && in_array($node['table_name'], ['l', 'c'], true)) {
                self::assertArrayHasKey('r_loops', $node);
                $lookups[$node['table_name']] = $node['r_loops'];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($plan);
        self::assertCount(2, $lookups);
        foreach ($lookups as $table => $loops) {
            self::assertLessThanOrEqual(8, $loops, "$table must be looked up per distinct region, not per click");
        }
    }

    /**
     * @param list<int> $params
     * @return list<array<string, mixed>>
     */
    private static function runSql(Connection $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepareRead($sql);
        $conn->bind($stmt, str_repeat('i', count($params)), $params);

        return $conn->fetchAll($stmt);
    }
}

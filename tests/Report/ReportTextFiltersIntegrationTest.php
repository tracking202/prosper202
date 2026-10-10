<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Support\ReportFilter;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\TextFilterSql;
use Prosper202\DataEngine\UserPrefFilters;

/**
 * The report pages' keyword, referer and IP filters, run as SQL on a real
 * server against rows built to tell the right answer from the wrong ones:
 *
 *  - keywords and referers holding LIKE's % and _, and neighbours those
 *    characters would match as wildcards (`50% off` / `500 off`);
 *  - more matching referring URLs than a GROUP_CONCAT list holds at
 *    group_concat_max_len = 1024, MySQL 8's default;
 *  - one address stored in two 202_ips rows (no unique key stops it), an
 *    address another contains (10.20.30.4 in 10.20.30.45), and an IPv6
 *    address written two ways.
 *
 * Each filter is checked twice: against the clicks it must select, and
 * against what the API's ReportFilter selects with its own prepared binds,
 * so the pages (DataEngine's UserPrefFilters, Visitors and the Group
 * Overview, all through TextFilterSql) and GET /reports/* agree by
 * execution, not by reading.
 *
 * @group integration
 */
final class ReportTextFiltersIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 5721;

    /** Matching referring URLs: well over 1024 bytes of ids. */
    private const MANY = 300;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<string, int> label => click id */
    private static array $clicks = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::seed();
        self::q('SET SESSION group_concat_max_len = 1024');
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
        self::$db->query('DELETE FROM 202_keywords WHERE keyword LIKE ' . self::str('tf5721 %'));
        self::$db->query('DELETE FROM 202_site_urls WHERE site_url_address LIKE ' . self::str('https://tf5721.example/%'));
        self::$db->query("DELETE FROM 202_ips WHERE ip_address IN ('10.20.30.4', '10.20.30.45')");
        if (isset(self::$ids['v6ref'], self::$ids['v6row'])) {
            self::$db->query('DELETE FROM 202_ips WHERE ip_id = ' . self::$ids['v6ref']);
            self::$db->query('DELETE FROM 202_ips_v6 WHERE ip_id = ' . self::$ids['v6row']);
        }
        self::$db->query('DELETE FROM 202_dataengine WHERE user_id = ' . self::USER);
        self::$ids = [];
        self::$clicks = [];
    }

    private static function seed(): void
    {
        $i = &self::$ids;
        foreach (['pct' => '50% off', 'near' => '500 off', 'under' => 'a_b', 'x' => 'axb', 'bang' => 'deal!now'] as $k => $kw) {
            $i["kw$k"] = self::insert('INSERT INTO 202_keywords SET keyword = ' . self::str("tf5721 $kw"));
        }
        foreach (['under' => 'deals_2026', 'x' => 'dealsX2026'] as $k => $path) {
            $i["url$k"] = self::insert('INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = '
                . self::str("https://tf5721.example/$path"));
        }
        $i['ip4'] = self::insert("INSERT INTO 202_ips SET ip_address = '10.20.30.4'");
        $i['ip4dup'] = self::insert("INSERT INTO 202_ips SET ip_address = '10.20.30.4'");
        $i['ip45'] = self::insert("INSERT INTO 202_ips SET ip_address = '10.20.30.45'");
        $i['v6row'] = self::insert('INSERT INTO 202_ips_v6 SET ip_address = ' . self::str((string) inet_pton('2001:db8::5721')));
        $i['v6ref'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str((string) $i['v6row']));

        $n = 0;
        $click = static function (string $label, array $c) use (&$n): void {
            $n++;
            $id = 572100000 + $n;
            self::row('202_dataengine', $c + [
                'user_id' => self::USER, 'click_id' => $id, 'click_time' => 1767225600, 'ppc_account_id' => 0,
                'landing_page_id' => 0, 'keyword_id' => 0, 'ip_id' => 0, 'click_referer_site_url_id' => 0,
                'clicks' => 1, 'payout' => 0,
            ]);
            self::$clicks[$label] = $id;
        };
        $click('kw 50% off', ['keyword_id' => $i['kwpct']]);
        $click('kw 500 off', ['keyword_id' => $i['kwnear']]);
        $click('kw a_b', ['keyword_id' => $i['kwunder']]);
        $click('kw axb', ['keyword_id' => $i['kwx']]);
        $click('kw deal!now', ['keyword_id' => $i['kwbang']]);
        $click('ref deals_2026', ['click_referer_site_url_id' => $i['urlunder']]);
        $click('ref dealsX2026', ['click_referer_site_url_id' => $i['urlx']]);
        $click('ip 10.20.30.4 first row', ['ip_id' => $i['ip4']]);
        $click('ip 10.20.30.4 second row', ['ip_id' => $i['ip4dup']]);
        $click('ip 10.20.30.45', ['ip_id' => $i['ip45']]);
        $click('ip 2001:db8::5721', ['ip_id' => $i['v6ref']]);
        for ($m = 0; $m < self::MANY; $m++) {
            $url = self::insert('INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = '
                . self::str("https://tf5721.example/many/$m"));
            $click("many $m", ['click_referer_site_url_id' => $url]);
        }
    }

    /**
     * The labels of the clicks a WHERE fragment over `2st` selects.
     *
     * @return list<string>
     */
    private function selected(string $where): array
    {
        $result = self::$db->query('SELECT 2st.click_id FROM 202_dataengine 2st WHERE 2st.user_id = ' . self::USER . $where);
        self::assertNotFalse($result, self::$db->error);
        $byId = array_flip(self::$clicks);
        $labels = [];
        foreach ($result->fetch_all() as [$id]) {
            $labels[] = $byId[(int) $id];
        }
        sort($labels);

        return $labels;
    }

    /** What a page selects: the filter as UserPrefFilters builds it from a preferences row. */
    private function page(string $column, string $value): array
    {
        $db = self::$db;
        $filters = UserPrefFilters::build(
            [$column => $value, 'user_pref_show' => 'all'],
            0,
            true,
            static fn (string $text): string => $db->real_escape_string($text)
        );
        self::assertSame('', $filters['join']);

        return $this->selected($filters['filter']);
    }

    /** What the API selects: ReportFilter's terms with its binds, prepared. */
    private function api(string $param, string $value): array
    {
        [$where, $binds, $types] = ReportFilter::where([$param => $value], static fn (): string => 'UTC', [
            'keyword_id' => '2st.keyword_id', 'ip_id' => '2st.ip_id',
            'click_referer_site_url_id' => '2st.click_referer_site_url_id',
        ]);
        $stmt = self::$db->prepare('SELECT 2st.click_id FROM 202_dataengine 2st WHERE 2st.user_id = ' . self::USER
            . ' AND ' . implode(' AND ', $where));
        self::assertNotFalse($stmt, self::$db->error);
        $stmt->bind_param($types, ...$binds);
        self::assertTrue($stmt->execute(), $stmt->error);
        $byId = array_flip(self::$clicks);
        $labels = [];
        foreach ($stmt->get_result()->fetch_all() as [$id]) {
            $labels[] = $byId[(int) $id];
        }
        $stmt->close();
        sort($labels);

        return $labels;
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function filters(): iterable
    {
        yield 'a % is a percent sign' => ['user_pref_keyword', 'keyword', 'tf5721 50%', ['kw 50% off']];
        yield 'an _ is an underscore' => ['user_pref_keyword', 'keyword', 'tf5721 a_b', ['kw a_b']];
        yield 'the escape character is itself' => ['user_pref_keyword', 'keyword', 'deal!now', ['kw deal!now']];
        yield 'a keyword is case-insensitive' => ['user_pref_keyword', 'keyword', 'TF5721 AXB', ['kw axb']];
        yield 'a referer _ is an underscore' => ['user_pref_referer', 'referer', 'deals_2026', ['ref deals_2026']];
        yield 'an address is every row it is stored in' => ['user_pref_ip', 'ip', '10.20.30.4', ['ip 10.20.30.4 first row', 'ip 10.20.30.4 second row']];
        yield 'an address is not a substring' => ['user_pref_ip', 'ip', '10.20.30.45', ['ip 10.20.30.45']];
        yield 'an IPv6 address however it is written' => ['user_pref_ip', 'ip', '2001:0DB8:0:0::5721', ['ip 2001:db8::5721']];
    }

    /**
     * @dataProvider filters
     * @param list<string> $expected
     */
    public function testEachFilterSelectsWhatItSaysAndWhatTheApiSelects(string $column, string $param, string $value, array $expected): void
    {
        self::assertSame($expected, $this->page($column, $value), 'the page');
        self::assertSame($expected, $this->api($param, $value), 'the API');
    }

    public function testARefererMatchingMoreUrlsThanAListHoldsKeepsEveryClick(): void
    {
        // The proof that this server would have cut the list: the old
        // resolution, run here, comes back shorter than its ids.
        $old = self::$db->query('SELECT GROUP_CONCAT(DISTINCT su.site_url_id) AS ids FROM 202_site_urls su'
            . ' WHERE su.site_url_address LIKE ' . self::str('%tf5721.example/many/%'));
        self::assertNotFalse($old, self::$db->error);
        $list = (string) $old->fetch_assoc()['ids'];
        self::assertLessThan(self::MANY, count(explode(',', $list)), 'group_concat_max_len cuts the old id list here');

        $many = $this->page('user_pref_referer', 'tf5721.example/many/');
        self::assertCount(self::MANY, $many, 'every click whose referer matches');
        self::assertSame($many, $this->api('referer', 'tf5721.example/many/'));
    }

    public function testAStoredAddressThatIsNotOneMatchesNothing(): void
    {
        self::assertSame([], $this->page('user_pref_ip', '10.20.30'));
        self::assertSame(' AND 0=1', TextFilterSql::where(['user_pref_ip' => '10.20.30'], '2st', static fn (string $t): string => $t));
    }
}

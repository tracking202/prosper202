<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * Overview › Group Overview's own query (ReportSummaryForm::getQuery), run on
 * a real server and drawn into its tree as the page draws it, over rows
 * built to expose three of its defects:
 *
 *  - c1-c4 grouped through the id fold (NULL or '0' read as none), so every
 *    click with c1=0 sat under "[No c1]" with the clicks that had none;
 *  - its referer filter joined 202_clicks_site on the referer instead of
 *    the click, so each click counted once per click sharing its referer,
 *    and its IP filter inner-joined 202_ips_v6, which no IPv4 address has;
 *  - its Payout was one campaign's payout selected outside the GROUP BY.
 *
 * Runs with the empty sql_mode connect.php gives the pages.
 *
 * @group integration
 */
final class GroupOverviewIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 5731;

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/ReportSummaryForm.class.php';
        if (!class_exists('DB', false)) {
            eval('class DB { public static $conn; public static function getInstance() { return new self(); }'
                . ' public function getConnection() { return self::$conn; } }');
        }
        self::cleanUp();
        self::seed();
        self::q("SET SESSION sql_mode = ''");
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
        \DB::$conn = self::$db;
        $_SESSION['publisher'] = true;
        $_SESSION['user_own_id'] = self::USER;
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_dataengine WHERE user_id = ' . self::USER);
        self::$db->query('DELETE FROM 202_aff_campaigns WHERE user_id = ' . self::USER);
        self::$db->query("DELETE FROM 202_keywords WHERE keyword LIKE 'go5731%'");
        self::$db->query("DELETE FROM 202_site_urls WHERE site_url_address LIKE 'https://go5731.example/%'");
        self::$db->query("DELETE FROM 202_ips WHERE ip_address = '10.57.31.1'");
        foreach (['c1', 'c2', 'c3', 'c4'] as $c) {
            foreach (['zero', 'empty', 'word'] as $k) {
                if (isset(self::$ids["$c$k"])) {
                    self::$db->query("DELETE FROM 202_tracking_$c WHERE {$c}_id = " . self::$ids["$c$k"]);
                }
            }
        }
        self::$ids = [];
    }

    private static function seed(): void
    {
        $i = &self::$ids;
        foreach (['c1', 'c2', 'c3', 'c4'] as $c) {
            // As INDEXES::get_c1_id() leaves them: one row per value, "" included.
            $i["{$c}zero"] = self::insert("INSERT INTO 202_tracking_$c SET $c = '0'");
            $i["{$c}empty"] = self::insert("INSERT INTO 202_tracking_$c SET $c = ''");
            $i["{$c}word"] = self::insert("INSERT INTO 202_tracking_$c SET $c = 'go5731'");
        }
        $i['campTen'] = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER
            . ", aff_campaign_name = 'go5731 ten', aff_campaign_url = 'https://go5731.example/o', aff_campaign_payout = 10,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0, aff_network_id = 0');
        $i['campFour'] = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER
            . ", aff_campaign_name = 'go5731 four', aff_campaign_url = 'https://go5731.example/o', aff_campaign_payout = 4,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0, aff_network_id = 0');
        $i['kw'] = self::insert("INSERT INTO 202_keywords SET keyword = 'go5731 kw'");
        $i['url'] = self::insert("INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = 'https://go5731.example/ref'");
        $i['ip'] = self::insert("INSERT INTO 202_ips SET ip_address = '10.57.31.1'");

        $n = 0;
        $click = static function (array $c) use (&$n): void {
            $n++;
            self::row('202_dataengine', $c + [
                'user_id' => self::USER, 'click_id' => 573100 + $n, 'click_time' => 1767225600, 'ppc_account_id' => 0,
                'landing_page_id' => 0, 'aff_campaign_id' => self::$ids['campTen'], 'keyword_id' => self::$ids['kw'],
                'c1_id' => 0, 'c2_id' => 0, 'c3_id' => 0, 'c4_id' => 0,
                'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
            ]);
        };
        $values = static fn (string $k): array => [
            'c1_id' => self::$ids["c1$k"], 'c2_id' => self::$ids["c2$k"], 'c3_id' => self::$ids["c3$k"], 'c4_id' => self::$ids["c4$k"],
        ];
        // Three clicks with c1..c4 = "0", two with "", one with a word, one
        // with no tracking row at all (ids 0). Three share a referer and an
        // address; two leads, one through each campaign.
        $click($values('zero') + ['click_referer_site_url_id' => self::$ids['url'], 'ip_id' => self::$ids['ip'],
            'leads' => 1, 'click_lead' => 1, 'income' => 10]);
        $click($values('zero') + ['click_referer_site_url_id' => self::$ids['url'], 'ip_id' => self::$ids['ip'],
            'aff_campaign_id' => self::$ids['campFour'], 'leads' => 1, 'click_lead' => 1, 'income' => 4]);
        $click($values('zero') + ['click_referer_site_url_id' => self::$ids['url'], 'ip_id' => self::$ids['ip']]);
        $click($values('empty'));
        $click($values('empty'));
        $click($values('word'));
        $click([]);
    }

    /**
     * The groups of a one-level report: title => [clicks, payout].
     *
     * @param array<string, string> $filters user_pref_* => value
     * @return array<string, array{int, float}>
     */
    private function groups(int $level, array $filters = []): array
    {
        $prefs = [];
        foreach (['aff_campaign_id', 'aff_network_id', 'browser_id', 'country_id', 'device_id', 'ip', 'isp_id', 'keyword',
            'landing_page_id', 'platform_id', 'ppc_account_id', 'ppc_network_id', 'referer', 'region_id', 'show', 'subid',
            'text_ad_id'] as $k) {
            $prefs['user_pref_' . $k] = '';
        }
        $prefs = $filters + $prefs;
        $form = new \ReportSummaryForm();
        $form->setDetails([$level]);
        $form->setDetailsSort([\ReportBasicForm::SORT_NAME]);
        $form->setDisplayType([\ReportBasicForm::DISPLAY_TYPE_TABLE]);
        $form->setStartTime(1767225600 - 60);
        $form->setEndTime(1767225600 + 60);
        $result = self::$db->query($form->getQuery((string) self::USER, $prefs));
        self::assertNotFalse($result, self::$db->error);
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $form->addReportData($row);
        }
        $out = [];
        foreach ($form->getReportData()->getChildArrayBySort() as $child) {
            $out[(string) $child->getTitle()] = [(int) $child->getClicks(), round((float) $child->getPayout(), 2)];
        }
        ksort($out);

        return $out;
    }

    /** @return iterable<string, array{int, string}> */
    public static function customVariables(): iterable
    {
        // A provider runs before setUpBeforeClass().
        require_once dirname(__DIR__, 2) . '/202-config/ReportSummaryForm.class.php';
        yield 'c1' => [\ReportBasicForm::DETAIL_LEVEL_C1, '[No c1]'];
        yield 'c2' => [\ReportBasicForm::DETAIL_LEVEL_C2, '[No c2]'];
        yield 'c3' => [\ReportBasicForm::DETAIL_LEVEL_C3, '[No c3]'];
        yield 'c4' => [\ReportBasicForm::DETAIL_LEVEL_C4, '[No c4]'];
    }

    /** @dataProvider customVariables */
    public function testAValueOfZeroIsItsOwnGroup(int $level, string $none): void
    {
        $groups = array_map(static fn (array $g): int => $g[0], $this->groups($level));
        self::assertSame(['0' => 3, $none => 3, 'go5731' => 1], $groups, 'three "0", two "" and one with no tracking row, one word');
    }

    public function testASharedRefererCountsEachClickOnce(): void
    {
        self::assertSame(['go5731 kw' => 3], array_map(
            static fn (array $g): int => $g[0],
            $this->groups(\ReportBasicForm::DETAIL_LEVEL_KEYWORD, ['user_pref_referer' => 'go5731.example/ref'])
        ));
    }

    public function testAnIpv4FilterFindsItsClicks(): void
    {
        self::assertSame(['go5731 kw' => 3], array_map(
            static fn (array $g): int => $g[0],
            $this->groups(\ReportBasicForm::DETAIL_LEVEL_KEYWORD, ['user_pref_ip' => '10.57.31.1'])
        ));
    }

    public function testPayoutIsIncomePerLead(): void
    {
        // Two leads, $10 through one campaign and $4 through the other: $7
        // per lead, not whichever campaign's payout the server read first.
        self::assertSame(['go5731 kw' => [7, 7.0]], $this->groups(\ReportBasicForm::DETAIL_LEVEL_KEYWORD));
    }
}

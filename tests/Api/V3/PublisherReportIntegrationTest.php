<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ClicksController;
use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\LatestVisit;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * Reports by publisher, on a real server: the clicks a publisher link
 * credited (202_clicks_publisher) as a breakdown, a group level and a
 * filter, over rows the click writer and the rollup wrote.
 *
 * The store's account has two publishers. Dana's links took three clicks
 * (two on Running Shoes, one a sale; one on Rain Jackets, clicked twice),
 * the second publisher's one, and one click came on no publisher link.
 * Another account credited a click of its own to Dana.
 *
 * @group integration
 */
final class PublisherReportIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990911;
    private const OTHER = 990912;
    private const DANA = 990913;
    private const UNNAMED = 990914;
    private const FIRST_CLICK = 99091101;

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $now = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::$db->query("SET SESSION sql_mode=''");
        self::seed();
        self::$db->query(self::$strict);
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
    }

    private static function cleanUp(): void
    {
        $users = implode(',', [self::OWNER, self::OTHER, self::DANA, self::UNNAMED]);
        foreach (['202_users', '202_aff_campaigns', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 9);
        $clickTables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_clicks_publisher', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks',
            '202_google', '202_clicks_variable',
        ];
        foreach ($clickTables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    private static function seed(): void
    {
        foreach ([self::OWNER, self::OTHER, self::DANA, self::UNNAMED] as $user) {
            self::user($user);
        }
        self::q("UPDATE 202_users SET user_fname = 'Dana', user_lname = 'Reyes' WHERE user_id = " . self::DANA);
        self::q("UPDATE 202_users SET user_fname = '', user_lname = '' WHERE user_id = " . self::UNNAMED);
        $campaign = static fn (int $user, string $name): int => self::insert(
            "INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 0, aff_campaign_name = "
            . self::str($name) . ", aff_campaign_url = 'https://shop.example/', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0'
        );
        self::$ids = [
            'shoes' => $campaign(self::OWNER, 'Running Shoes'),
            'jackets' => $campaign(self::OWNER, 'Rain Jackets'),
            'theirs' => $campaign(self::OTHER, 'Their Store'),
        ];
        self::$now = time() - 7200;

        $i = self::$ids;
        // click => [account, campaign, publisher or 0, lead, payout]
        $clicks = [
            [self::OWNER, $i['shoes'], self::DANA, 1, '12.50'],
            [self::OWNER, $i['shoes'], self::DANA, 0, '0'],
            [self::OWNER, $i['jackets'], self::DANA, 0, '0'],
            [self::OWNER, $i['shoes'], self::UNNAMED, 0, '0'],
            [self::OWNER, $i['jackets'], 0, 0, '0'],
            [self::OTHER, $i['theirs'], self::DANA, 0, '0'],
        ];
        $conn = new Connection(self::$db);
        $repo = new MysqlClickRepository($conn);
        foreach ($clicks as $n => [$user, $campaignId, $publisher, $lead, $payout]) {
            $clickId = self::FIRST_CLICK + $n;
            $repo->recordClick(ClickRecordBuilder::fromLegacyArray([
                'user_id' => (string) $user,
                'aff_campaign_id' => (string) $campaignId,
                'click_time' => (string) (self::$now + $n),
                'click_cpc' => '0.25',
                'publisher_user_id' => (string) $publisher,
            ], $clickId));
            if ($lead === 1) {
                self::q("UPDATE 202_clicks SET click_lead = 1, click_payout = $payout WHERE click_id = $clickId");
            }
        }
        // The third click again, ten minutes on: a second visit's row, as a re-click writes one.
        $again = self::FIRST_CLICK + 2;
        self::q('INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id,'
            . ' click_cpc, click_payout, click_lead, click_filtered, click_bot, click_alp, click_time)'
            . ' SELECT click_id, user_id, aff_campaign_id, landing_page_id, ppc_account_id, click_cpc, click_payout,'
            . " click_lead, click_filtered, click_bot, click_alp, click_time + 600 FROM 202_clicks WHERE click_id = $again");
        LatestVisit::refresh($conn, $again);
        foreach (array_keys($clicks) as $n) {
            ClickRollupSql::rollUpClick($conn, self::FIRST_CLICK + $n);
        }
    }

    private static function reports(int $user = self::OWNER): ReportsController
    {
        return new ReportsController(self::requireScratch(), $user);
    }

    /** @return array<string, mixed> */
    private static function window(): array
    {
        return ['time_from' => (string) (self::$now - 60), 'time_to' => (string) (self::$now + 3600)];
    }

    public function testABreakdownByPublisherCountsEachCreditedClickOnceUnderItsPublisher(): void
    {
        $r = self::reports()->breakdown(['breakdown' => 'publisher'] + self::window());

        self::assertSame(
            [[self::DANA, 'Dana Reyes', 3, 1, 12.5, 0.75], [self::UNNAMED, 'rp' . self::UNNAMED, 1, 0, 0.0, 0.25]],
            array_map(static fn (array $row): array => [
                (int) $row['id'], $row['name'], $row['total_clicks'], $row['total_leads'],
                $row['total_income'], $row['total_cost'],
            ], $r['data']),
            'the click clicked twice is one click; the click on no publisher link, and another account\'s, are in no row'
        );
        self::assertContains('publisher', $r['available_breakdowns']);
    }

    public function testAnotherAccountSeesOnlyItsOwnCreditedClicks(): void
    {
        $r = self::reports(self::OTHER)->breakdown(['breakdown' => 'publisher'] + self::window());

        self::assertSame([[self::DANA, 1]], array_map(
            static fn (array $row): array => [(int) $row['id'], $row['total_clicks']],
            $r['data']
        ));
    }

    public function testGroupsNestCampaignsUnderEachPublisherAndKeepTheUncreditedClicks(): void
    {
        $r = self::reports()->groups(['by' => 'publisher,campaign'] + self::window());

        $shape = [];
        foreach ($r['data'] as $publisher) {
            $shape[$publisher['name'] ?? 'none'] = [
                $publisher['total_clicks'],
                array_column($publisher['children'], 'total_clicks', 'name'),
            ];
        }
        self::assertSame([
            'Dana Reyes' => [3, ['Rain Jackets' => 1, 'Running Shoes' => 2]],
            'rp' . self::UNNAMED => [1, ['Running Shoes' => 1]],
            'none' => [1, ['Rain Jackets' => 1]],
        ], $shape);
        self::assertSame(5, $r['totals']['total_clicks']);
        self::assertSame(12.5, $r['totals']['total_income']);
    }

    public function testThePublisherFilterNarrowsEveryReport(): void
    {
        $dana = ['publisher_id' => (string) self::DANA] + self::window();
        self::assertSame(3, self::reports()->summary($dana)['data']['total_clicks']);
        self::assertSame(
            ['Running Shoes' => 2, 'Rain Jackets' => 1],
            array_column(self::reports()->breakdown(['breakdown' => 'campaign'] + $dana)['data'], 'total_clicks', 'name')
        );
        self::assertSame(
            1,
            self::reports()->summary(['publisher_id' => 'none'] + self::window())['data']['total_clicks'],
            'none: the clicks credited to no publisher'
        );
        self::assertSame(5, self::reports()->summary(['publisher_id' => '0'] + self::window())['data']['total_clicks']);
        self::assertSame(
            0,
            self::reports(self::OTHER)->summary(['publisher_id' => (string) self::UNNAMED] + self::window())['data']['total_clicks']
        );
    }

    public function testTheClickListTakesThePublisherFilter(): void
    {
        $clicks = new ClicksController(self::requireScratch(), self::OWNER);
        $ids = static fn (array $params): array => array_map(
            static fn (array $row): int => (int) $row['click_id'],
            $clicks->list($params + self::window())['data']
        );

        // The list has a row per visit, so the click clicked twice is there twice.
        $dana = array_values(array_unique($ids(['publisher_id' => (string) self::DANA])));
        sort($dana);
        self::assertSame([self::FIRST_CLICK, self::FIRST_CLICK + 1, self::FIRST_CLICK + 2], $dana);
        self::assertSame([self::FIRST_CLICK + 4], $ids(['publisher_id' => 'none']));
    }

    public function testAPublisherIdThatIsNotAnIdIsRefusedByName(): void
    {
        try {
            self::reports()->summary(['publisher_id' => '12abc']);
            self::fail('12abc is not an id');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('publisher_id', $e->getFieldErrors());
        }
    }
}

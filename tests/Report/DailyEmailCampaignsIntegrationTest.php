<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Report\DailyEmailCampaigns;

/**
 * The daily email's figures are the addressee's own clicks, and name a
 * campaign only when it is the click's own account's (CLAUDE.md #27).
 *
 * The cron (202-cronjobs/daily-email.php) read every account's clicks and
 * mailed them to user 1, each under its campaign's name -- another
 * account's campaigns, and this account's clicks under another account's
 * campaign when a tracker named one (nothing stopped that before 229df10).
 *
 * Account A (USER) has, today, two clicks on its own campaign, one naming
 * OTHER's campaign and one naming none; yesterday, one on its own campaign
 * and one naming OTHER's. OTHER has three clicks of its own today.
 *
 * @group integration
 */
final class DailyEmailCampaignsIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990091;
    private const OTHER = 990092;
    private const DAY = 86400;

    private static int $today = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::$today = (int) (floor(time() / self::DAY) * self::DAY) + 12 * 3600;
        $t = self::$today;
        foreach ([self::USER => 'My Offer', self::OTHER => 'Their Offer'] as $user => $name) {
            self::row('202_aff_campaigns', ['aff_campaign_id' => $user, 'user_id' => $user, 'aff_network_id' => 0, 'aff_campaign_name' => $name,
                'aff_campaign_url' => 'https://offer.example/' . $user, 'aff_campaign_payout' => 5, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t]);
        }
        $n = 0;
        $click = static function (int $user, int $campaign, int $time) use (&$n): void {
            $n++;
            $id = 99009100 + $n;
            self::row('202_clicks', ['click_id' => $id, 'user_id' => $user, 'aff_campaign_id' => $campaign, 'ppc_account_id' => 0,
                'landing_page_id' => 0, 'click_cpc' => 0.1, 'click_payout' => 5, 'click_lead' => 0, 'click_time' => $time]);
            self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => $id, 'click_out' => 1]);
        };
        $click(self::USER, self::USER, $t);
        $click(self::USER, self::USER, $t);
        $click(self::USER, self::OTHER, $t);
        $click(self::USER, 0, $t);
        $click(self::USER, self::USER, $t - self::DAY);
        $click(self::USER, self::OTHER, $t - self::DAY);
        foreach ([1, 2, 3] as $_) {
            $click(self::OTHER, self::OTHER, $t);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_clicks_record WHERE click_id BETWEEN 99009101 AND 99009199');
        foreach ([self::USER, self::OTHER] as $user) {
            self::q("DELETE FROM 202_clicks WHERE user_id = $user");
            self::q("DELETE FROM 202_aff_campaigns WHERE user_id = $user");
        }
    }

    /** @param list<array<string, mixed>> $rows @return array<int, array{string|null, int}> campaign => [name, clicks] */
    private static function byCampaign(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['aff_campaign_id']] = [$row['aff_campaign_name'], (int) $row['clicks']];
        }
        ksort($out);

        return $out;
    }

    public function testTodayIsTheAccountsOwnClicksUnderItsOwnCampaigns(): void
    {
        $conn = new Connection(self::requireScratch());
        $day = [self::$today - 12 * 3600, self::$today + 12 * 3600 - 1];
        self::assertSame([
            0 => [null, 2],
            self::USER => ['My Offer', 2],
        ], self::byCampaign(DailyEmailCampaigns::top($conn, self::USER, $day[0], $day[1], 5)),
            'another account\'s clicks are not counted, and A\'s click naming its campaign is counted with the ones naming none, unnamed');
    }

    public function testYesterdayReadsTheSameCampaignsTheSameWay(): void
    {
        $conn = new Connection(self::requireScratch());
        $day = [self::$today - self::DAY - 12 * 3600, self::$today - 12 * 3600 - 1];
        self::assertSame([
            0 => [null, 1],
            self::USER => ['My Offer', 1],
        ], self::byCampaign(DailyEmailCampaigns::forCampaigns($conn, self::USER, [self::USER, 0], $day[0], $day[1])));
        self::assertSame([], DailyEmailCampaigns::forCampaigns($conn, self::USER, [], $day[0], $day[1]), 'no campaigns, no query');
    }
}

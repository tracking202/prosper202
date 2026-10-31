<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionRollup;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * An attribution report names a campaign, traffic source or landing page
 * only from the click's own account. A click names them by id, and nothing
 * stopped a tracker naming another account's before the API checked linked
 * ids (229df10): such a click is still credited and counted, under its
 * stored id, with no name — on the full computation, on the rollup's name
 * lookup, and in the journey reads.
 *
 * @group integration
 */
final class ForeignNamesIntegrationTest extends TestCase
{
    use AttributionDatabase {
        setUp as private databaseSetUp;
    }

    private const NAMES = ['campaign' => 'Their Offer', 'traffic_source' => 'Their Source', 'landing_page' => 'Their LP'];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_landing_pages'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
    }

    /** Account 1's click 5 names account 2's campaign 9, source 8 and landing page 7; click 6 is all account 1's. */
    private function scenario(int $now): array
    {
        $this->campaign(1);
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=9, user_id=2, aff_network_id=1, aff_campaign_name='Their Offer',
            aff_campaign_url='http://theirs', aff_campaign_payout=1, aff_campaign_time=1, aff_campaign_foreign_payout=1, payout_mode='replace'");
        self::fixture("INSERT INTO 202_ppc_accounts SET ppc_account_id=8, user_id=2, ppc_network_id=1, ppc_account_name='Their Source', ppc_account_time=1");
        self::fixture("INSERT INTO 202_ppc_accounts SET ppc_account_id=3, user_id=1, ppc_network_id=1, ppc_account_name='My Source', ppc_account_time=1");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=7, user_id=2, aff_campaign_id=9, landing_page_url='http://their-lp',
            landing_page_nickname='Their LP', landing_page_time=1");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=4, user_id=1, aff_campaign_id=1, landing_page_url='http://my-lp',
            landing_page_nickname='My LP', landing_page_time=1");
        $this->click(5, 9, $now - 3 * 86400, '0.40', 8);
        $this->click(6, 1, $now - 3 * 86400 + 600, '0.60', 3);
        self::fixture('UPDATE 202_clicks SET landing_page_id = 7 WHERE click_id = 5');
        self::fixture('UPDATE 202_clicks SET landing_page_id = 4 WHERE click_id = 6');
        $theirs = $this->convert(5, '4.00', 'T', $now - 3 * 86400 + 60);
        $mine = $this->convert(6, '6.00', 'M', $now - 3 * 86400 + 660);
        $this->work();

        return ['theirs' => $theirs, 'mine' => $mine];
    }

    /** @return array<string, array<string, mixed>> key => row */
    private static function byKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['key']] = $r;
        }

        return $out;
    }

    public function testABreakdownNamesNoOtherAccountsRecordAndStillCountsTheClick(): void
    {
        $now = time();
        $this->scenario($now);
        $keys = ['campaign' => ['9', '1', 'Campaign 1'], 'traffic_source' => ['8', '3', 'My Source'], 'landing_page' => ['7', '4', 'My LP']];

        // The full computation, then the same through the rollup.
        $this->assertBreakdowns(new AttributionReports($this->conn, false), $keys, $now, 'full');
        $rollup = new AttributionRollup($this->conn, static fn (): int => $now);
        for ($i = 0; $i < 50 && $rollup->run(3600)->hoursBuilt > 0; $i++) {
            // until every hour is summed
        }
        $rolled = new AttributionReports($this->conn, true);
        $this->assertBreakdowns($rolled, $keys, $now, 'rollup');
        self::assertGreaterThan(0, $rolled->lastServedHours(), 'the rollup served the hours, so its name lookup was what named them');
    }

    /** @param array<string, array{0: string, 1: string, 2: string}> $keys */
    private function assertBreakdowns(AttributionReports $reports, array $keys, int $now, string $path): void
    {
        foreach ($keys as $dimension => [$theirKey, $myKey, $myName]) {
            $out = $reports->breakdownAll(1, null, null, $this->defaultModelId(), $dimension, $now - 10 * 86400, $now);
            $rows = self::byKey($out['rows']);
            self::assertArrayHasKey($theirKey, $rows, "$path $dimension: the click is still counted, under its stored id");
            self::assertNull($rows[$theirKey]['name'], "$path $dimension: another account's record is not named");
            self::assertSame('4.00000', (string) $rows[$theirKey]['attributed_revenue'], "$path $dimension: and still credited");
            self::assertSame($myName, $rows[$myKey]['name'], "$path $dimension: the account's own record is named");
            self::assertStringNotContainsString(self::NAMES[$dimension], (string) json_encode($out), "$path $dimension");
            self::assertSame('10.00000', (string) $out['totals']['attributed_revenue'], "$path $dimension: the totals are unchanged");
        }
    }

    public function testTheJourneyReadsNameNoOtherAccountsRecord(): void
    {
        $now = time();
        $ids = $this->scenario($now);
        $reports = new AttributionReports($this->conn);

        $recent = array_column($reports->recentJourneys(1, $now - 10 * 86400, $now, 25), null, 'conv_id');
        self::assertArrayHasKey($ids['theirs'], $recent, 'the conversion is listed');
        self::assertNull($recent[$ids['theirs']]['campaign_name']);
        self::assertSame('Campaign 1', $recent[$ids['mine']]['campaign_name']);

        $journey = $reports->journey(1, $ids['theirs']);
        self::assertNotNull($journey);
        self::assertStringNotContainsString('Their', (string) json_encode($journey), "the journey names nothing of another account's");
        $own = $reports->journey(1, $ids['mine']);
        self::assertStringContainsString('My Source', (string) json_encode($own));
    }
}

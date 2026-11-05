<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\PurLinkLegacySubid;
use Prosper202\Click\PurLinkSubid;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Database\Connection;

/**
 * A paid 1.9.56 database with PurLink clicks still pending in
 * 202_clicks_purlink, upgraded by the 1.9.80 step (fixtures/ladder-runner.php):
 * each click's latest pending click-out is carried into the PurLink tables,
 * so the offer's postback with the paid `<click>-<acip>` SubID converts, on
 * pb.php's check and gpb.php's. A row that names another account's page or
 * offer, a converted click, an older click-out of the same click or a row
 * past paid's 90 days is skipped and counted. The old table is kept.
 *
 * @group integration
 */
final class PurLinkCarryOverUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990561;
    private const OTHER = 990562;
    private const FIRST = 99056101;
    private const PAGE = 95601;
    private const OTHERS_PAGE = 95602;
    private const OFFER = 95611;
    private const SECOND_OFFER = 95612;
    private const OTHERS_OFFER = 95613;

    /** @var list<string> */
    private static array $versions = [];

    /** @var array<int, int> public id => campaign id */
    private static array $campaign = [];

    private static int $page = 0;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {}'
                . ' public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        if (!$found instanceof \mysqli_result) {
            throw new \RuntimeException('could not read 202_version: ' . self::$db->error);
        }
        self::$versions = array_column($found->fetch_all(MYSQLI_ASSOC), 'version');
        self::cleanUp();
        $owners = [self::OFFER => self::OWNER, self::SECOND_OFFER => self::OWNER, self::OTHERS_OFFER => self::OTHER];
        foreach ($owners as $public => $user) {
            self::$campaign[$public] = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $user,"
                . " aff_campaign_id_public = $public, aff_network_id = 1, aff_campaign_name = 'offer $public',"
                . " aff_campaign_url = 'https://offer.example/?s=[[subid]]', aff_campaign_payout = 6.5,"
                . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 1');
        }
        self::$page = self::page(self::OWNER, self::PAGE);
        self::page(self::OTHER, self::OTHERS_PAGE);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::clicksCleanUp();
    }

    private static function cleanUp(): void
    {
        self::clicksCleanUp();
        $users = self::OWNER . ',' . self::OTHER;
        foreach (['202_landing_pages', '202_aff_campaigns', '202_revenue_events', '202_dirty_hours'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function clicksCleanUp(): void
    {
        self::q('DROP TABLE IF EXISTS 202_clicks_purlink');
        self::q('DROP TRIGGER IF EXISTS 202_purlink_carry_blocker');
        $range = 'BETWEEN ' . self::FIRST . ' AND ' . (self::FIRST + 99);
        self::q('DELETE p FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id'
            . " WHERE c.click_id $range");
        $tables = [
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
            '202_clicks_variable', '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
            '202_click_latest_visits', '202_attribution_rollup_dirty_clicks', '202_purlink_exit_order',
            '202_dataengine',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    private static function page(int $user, int $public): int
    {
        return self::insert("INSERT INTO 202_landing_pages SET user_id = $user, landing_page_id_public = $public,"
            . " aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'advanced',"
            . " landing_page_url = 'https://shop.example/', landing_page_time = 1");
    }

    /** An advanced landing page's click before its click-out: no campaign yet. */
    private static function click(int $clickId): void
    {
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(new ClickRecord(
            clickId: $clickId,
            userId: self::OWNER,
            clickTime: time() - 7200,
            landingPageId: self::$page,
            clickIdPublic: (string) $clickId,
            clickAlp: 1,
        ));
    }

    /** 202_clicks_purlink as paid 1.9.56's upgrade step defines it, with a charset clause the server accepts. */
    private static function paidTable(): void
    {
        self::q('CREATE TABLE 202_clicks_purlink (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `click_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `acip` int(10) unsigned NOT NULL DEFAULT 0,
            `lpip` int(10) unsigned NOT NULL DEFAULT 0,
            `click_outbound_site_url_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `click_lead` tinyint(1) unsigned NOT NULL DEFAULT 0,
            `click_time` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `click_lead` (`click_lead`),
            KEY `click_id` (`click_id`,`acip`,`lpip`,`click_outbound_site_url_id`,`click_lead`,`click_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /** A click-out as purl.php wrote it. */
    private static function pending(
        int $clickId,
        int $offer,
        int $page = self::PAGE,
        int $age = 3600,
        int $lead = 0,
    ): void {
        self::q("INSERT INTO 202_clicks_purlink SET click_id = $clickId, acip = $offer, lpip = $page,"
            . " click_outbound_site_url_id = 7, click_lead = $lead, click_time = " . (time() - $age));
    }

    /** @return array{string, string} what the runner printed, and what was logged */
    private static function upgrade(string $from = '1.9.79'): array
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $from]);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }

    private static function value(string $sql): ?string
    {
        $result = self::requireScratch()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);
        $row = $result->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    private static function version(): string
    {
        return (string) self::value('SELECT version FROM 202_version');
    }

    private static function campaignOf(int $clickId): string
    {
        return (string) self::value("SELECT aff_campaign_id FROM 202_clicks WHERE click_id = $clickId");
    }

    /** pb.php?acip=<offer>&subid=<click>-<offer>, as pb.php reads and records it. */
    private static function postback(int $clickId, int $offer): array
    {
        $legacy = PurLinkSubid::legacy($clickId . '-' . $offer);
        self::assertNotNull($legacy);

        return p202RecordLegacyConversion(self::requireScratch(), $legacy['click_id'], 2, MatchMethod::CLICK_ID, [
            'campaign_id' => self::$campaign[$offer], 'transaction_id' => "paid-$clickId", 'source' => 'legacy_pixel',
        ]);
    }

    public function testPendingPaidClickOutsConvertAfterTheUpgradeAndTheRestAreCounted(): void
    {
        [$carried, $switched, $foreignPage, $foreignOffer, $converted, $old, $gone]
            = range(self::FIRST, self::FIRST + 6);
        foreach ([$carried, $switched, $foreignPage, $foreignOffer, $converted, $old] as $clickId) {
            self::click($clickId);
        }
        self::q("UPDATE 202_clicks SET click_lead = 1 WHERE click_id = $converted");
        self::paidTable();
        self::pending($carried, self::OFFER);
        self::pending($switched, self::OFFER, age: 3000);
        self::pending($switched, self::SECOND_OFFER, age: 2000);
        self::pending($foreignPage, self::OFFER, self::OTHERS_PAGE);
        self::pending($foreignOffer, self::OTHERS_OFFER);
        self::pending($converted, self::OFFER);
        self::pending($old, self::OFFER, age: 100 * 86400);
        self::pending($gone, self::OFFER);
        // Converted in paid already: not pending.
        self::pending($foreignPage, self::SECOND_OFFER, age: 5000, lead: 1);
        $resolver = new PurLinkLegacySubid(new Connection(self::requireScratch()));
        self::assertNull($resolver->resolve($carried . '-' . self::OFFER), 'paid left the click off its offer');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', self::version());
        self::assertStringContainsString(
            'Prosper202 PurLink carry-over: 2 pending click(s) of 202_clicks_purlink carried; skipped 6 row(s):'
                . ' expired 1, superseded 1, other_page 1, no_offer 1, converted 1, no_click 1.'
                . ' The table is left in place.',
            $err
        );
        self::assertSame('9', self::value('SELECT COUNT(*) FROM 202_clicks_purlink'), 'the old table is kept');

        self::assertSame((string) self::$campaign[self::OFFER], self::campaignOf($carried));
        self::assertSame(
            (string) self::$campaign[self::SECOND_OFFER],
            self::campaignOf($switched),
            'the latest click-out'
        );
        foreach ([$foreignPage, $foreignOffer, $old] as $clickId) {
            self::assertSame('0', self::campaignOf($clickId), "click $clickId is not routed");
        }
        self::assertSame('0', self::campaignOf($converted));
        self::assertSame(
            ['1', '0', (string) self::$campaign[self::OFFER], (string) self::$page],
            self::requireScratch()->query("SELECT r.click_out, r.click_cloaking, o.campaign_id, o.landing_page_id"
                . " FROM 202_clicks_record r JOIN 202_purlink_exit_order o USING (click_id) WHERE click_id = $carried")
                ->fetch_row()
        );
        self::assertSame('0', self::value("SELECT sequence FROM 202_purlink_exit_order WHERE click_id = $carried"));
        self::assertSame('2', self::value('SELECT COUNT(*) FROM 202_purlink_exit_order WHERE click_id BETWEEN '
            . self::FIRST . ' AND ' . (self::FIRST + 99)));

        // A second run (a retry after a later failure) writes nothing again.
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringContainsString(
            'carry-over: 0 pending click(s) of 202_clicks_purlink carried; skipped 8 row(s): expired 1,'
                . ' already_carried 2, superseded 1, other_page 1, no_offer 1, converted 1, no_click 1.',
            $err
        );
        self::assertSame((string) self::$campaign[self::SECOND_OFFER], self::campaignOf($switched));
        self::assertSame('2', self::value('SELECT COUNT(*) FROM 202_purlink_exit_order WHERE click_id BETWEEN '
            . self::FIRST . ' AND ' . (self::FIRST + 99)));

        // gpb.php's lookup and pb.php's check.
        self::assertSame(
            ['click_id' => $carried, 'user_id' => self::OWNER, 'campaign_id' => self::$campaign[self::OFFER]],
            $resolver->resolve($carried . '-' . self::OFFER)
        );
        $outcome = self::postback($carried, self::OFFER);
        self::assertTrue($outcome['recorded'], (string) json_encode($outcome));
        self::assertSame((string) self::$campaign[self::OFFER], self::value(
            "SELECT campaign_id FROM 202_conversion_logs WHERE click_id = $carried"
        ));
        self::assertTrue(self::postback($switched, self::SECOND_OFFER)['recorded']);
        self::assertSame('campaign_mismatch', self::postback($foreignOffer, self::OFFER)['reason']);
    }

    public function testNoPaidTableIsNothingToDo(): void
    {
        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', self::version());
        self::assertStringNotContainsString('PurLink carry-over', $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
    }

    public function testATableOfAnotherShapeIsLeftAndDoesNotHoldTheUpgrade(): void
    {
        self::q('CREATE TABLE 202_clicks_purlink (`id` int unsigned NOT NULL AUTO_INCREMENT, `click_id` bigint unsigned'
            . ' NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', self::version());
        self::assertStringContainsString(
            '202_clicks_purlink does not have paid 1.9.56\'s columns; nothing carried',
            $err
        );
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
    }

    public function testAFailedCarryLeavesTheVersionAndTheClickAndTheNextRunCarriesIt(): void
    {
        $clickId = self::FIRST + 10;
        self::click($clickId);
        self::paidTable();
        self::pending($clickId, self::OFFER);
        self::q("CREATE TRIGGER 202_purlink_carry_blocker BEFORE INSERT ON 202_purlink_exit_order FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked by the test'");

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED false', $out, $err);
        self::assertSame('1.9.79', self::version(), 'a failed carry must not mark the step complete');
        self::assertStringContainsString('Prosper202 upgrade: the pending PurLink clicks of paid 1.9.56', $err);
        self::assertSame('0', self::campaignOf($clickId), 'the route rolled back with the exit order');

        self::q('DROP TRIGGER 202_purlink_carry_blocker');
        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', self::version());
        self::assertSame((string) self::$campaign[self::OFFER], self::campaignOf($clickId));
    }
}

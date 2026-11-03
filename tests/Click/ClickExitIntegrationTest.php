<?php

declare(strict_types=1);

namespace Tests\Click;

use LogicException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickExit;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\OfferRoute;
use Prosper202\Click\PurLinkExit;
use Prosper202\Click\PurLinkLegacySubid;
use Prosper202\Click\PurLinkPixels;
use Prosper202\Click\PurLinkProof;
use Prosper202\Click\PurLinkSubid;
use Prosper202\Click\RecordedClicks;
use Prosper202\Click\RotatorRoute;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\PurLinkCampaignChanged;
use Prosper202\Database\Connection;

/**
 * A click leaving its landing page (off.php, offrtr.php, lp.php, pci.php),
 * written by the one writer on a real server in strict mode: everything the
 * exit sets in one transaction, and nothing at all when the click, the offer
 * or the rotator named with it is not the exit's account's. The scripts wrote
 * these rows by hand, and offrtr.php and lp.php wrote whatever click the
 * visitor's cookie named.
 *
 * @group integration
 */
final class ClickExitIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990401;
    private const OTHER = 990402;
    private const FIRST_CLICK = 99040101;

    private static int $campaign = 0;
    private static int $othersCampaign = 0;
    private static int $rotator = 0;
    private static int $rule = 0;
    private static int $redirect = 0;
    private static int $othersRotator = 0;
    private static int $othersRule = 0;
    private static int $othersRedirect = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::$campaign = self::campaign(self::OWNER);
        self::$othersCampaign = self::campaign(self::OTHER);
        [self::$rotator, self::$rule, self::$redirect] = self::rotator(self::OWNER);
        [self::$othersRotator, self::$othersRule, self::$othersRedirect] = self::rotator(self::OTHER);
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
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        self::q('DELETE p FROM 202_attribution_pending p JOIN 202_conversion_logs c ON c.conv_id = p.conv_id'
            . " WHERE c.click_id $range");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id $range");
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
            '202_attribution_rollup_dirty_clicks', '202_purlink_exit_order', '202_purlink_pixel_fires',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::OWNER . ', ' . self::OTHER;
        self::q("DELETE rr FROM 202_rotator_rules_redirects rr JOIN 202_rotator_rules ru ON ru.id = rr.rule_id"
            . " JOIN 202_rotators r ON r.id = ru.rotator_id WHERE r.user_id IN ($users)");
        self::q("DELETE ru FROM 202_rotator_rules ru JOIN 202_rotators r ON r.id = ru.rotator_id"
            . " WHERE r.user_id IN ($users)");
        self::q("DELETE FROM 202_rotators WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_landing_pages WHERE user_id IN ($users)");
        self::q("DELETE x FROM 202_ppc_account_pixels x JOIN 202_ppc_accounts a USING (ppc_account_id) WHERE a.user_id IN ($users)");
        self::q("DELETE FROM 202_ppc_accounts WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
    }

    private static function campaign(int $userId): int
    {
        return self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . $userId . ', aff_network_id = 1,'
            . " aff_campaign_name = 'exit', aff_campaign_url = 'https://offer.example/', aff_campaign_payout = 7.25,"
            . ' aff_campaign_time = 1, aff_campaign_foreign_payout = 0');
    }

    /** @return array{int, int, int} the rotator, a rule of it and a redirect of the rule */
    private static function rotator(int $userId): array
    {
        $rotator = self::insert("INSERT INTO 202_rotators SET public_id = 1, user_id = $userId");
        $rule = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = $rotator, status = 1");
        $redirect = self::insert("INSERT INTO 202_rotator_rules_redirects SET rule_id = $rule, name = 'r'");

        return [$rotator, $rule, $redirect];
    }

    private static function repo(): MysqlClickRepository
    {
        return new MysqlClickRepository(new Connection(self::requireScratch()));
    }

    /** A landing page click as record_simple.php records one. */
    private static function click(int $clickId, int $userId = self::OWNER, int $age = 60): void
    {
        self::repo()->recordClick(new ClickRecord(
            clickId: $clickId,
            userId: $userId,
            clickTime: time() - $age,
            affCampaignId: 4,
            landingPageId: 6,
            clickPayout: '2.5',
            clickIdPublic: '512345',
            clickCloaking: -1,
            clickRefererSiteUrlId: 40,
            clickLandingSiteUrlId: 41,
            clickOutboundSiteUrlId: 42,
            clickCloakingSiteUrlId: 43,
            clickRedirectSiteUrlId: 44,
        ));
    }

    /** @return array<string, string|null> */
    private static function one(string $sql): array
    {
        $result = self::requireScratch()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, 'no row for ' . $sql);

        return $row;
    }

    /** @return array<string, string|null> what an exit may change, from every table it writes */
    private static function state(int $clickId): array
    {
        return self::one('SELECT c.aff_campaign_id, c.click_payout, c.rotator_id, c.rule_id,'
            . ' s.aff_campaign_id AS spy_campaign, s.click_payout AS spy_payout,'
            . ' r.click_out, r.click_cloaking, st.click_outbound_site_url_id, st.click_redirect_site_url_id,'
            . ' st.click_landing_site_url_id, st.click_cloaking_site_url_id,'
            . ' (SELECT COUNT(*) FROM 202_clicks_rotator WHERE click_id = c.click_id) AS routes,'
            . ' (SELECT COUNT(*) FROM 202_attribution_rollup_dirty_clicks WHERE click_id = c.click_id) AS marks'
            . ' FROM 202_clicks c JOIN 202_clicks_spy s USING (click_id) JOIN 202_clicks_record r USING (click_id)'
            . " JOIN 202_clicks_site st USING (click_id) WHERE c.click_id = $clickId");
    }

    private const UNTOUCHED = [
        'aff_campaign_id' => '4', 'click_payout' => '2.50000', 'rotator_id' => '0', 'rule_id' => '0',
        'spy_campaign' => '4', 'spy_payout' => '2.50', 'click_out' => '0', 'click_cloaking' => '-1',
        'click_outbound_site_url_id' => '42', 'click_redirect_site_url_id' => '44',
        'click_landing_site_url_id' => '41', 'click_cloaking_site_url_id' => '43', 'routes' => '0', 'marks' => '0',
    ];

    public function testPageScopedExitOnlyMarksItsOwnActiveLandingPage(): void
    {
        $clickId = self::FIRST_CLICK + 90;
        self::click($clickId);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = " . self::$campaign . ", landing_page_nickname = 'proof',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        $otherPage = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OTHER
            . ", aff_campaign_id = " . self::$othersCampaign . ", landing_page_nickname = 'other',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        $nextPage = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = " . self::$campaign . ", landing_page_nickname = 'next',"
            . " landing_page_url = 'https://example.test/next', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        $otherOffer = self::campaign(self::OWNER);

        self::assertFalse(self::repo()->recordExit(new ClickExit(self::OWNER, $clickId, landingPageId: $otherPage)));
        self::assertFalse(self::repo()->recordExit(new ClickExit(
            self::OWNER,
            $clickId,
            offer: new OfferRoute($otherOffer, '7.25'),
            landingPageId: $page,
        )));
        self::assertSame('0', self::state($clickId)['click_out']);
        self::q("UPDATE 202_landing_pages SET landing_page_deleted = 1 WHERE landing_page_id = $page");
        self::assertFalse(self::repo()->recordExit(new ClickExit(self::OWNER, $clickId, landingPageId: $page)));
        self::assertSame('0', self::state($clickId)['click_out']);
        self::q("UPDATE 202_landing_pages SET landing_page_deleted = 0 WHERE landing_page_id = $page");
        self::q("UPDATE 202_clicks SET landing_page_id = $nextPage WHERE click_id = $clickId");
        self::assertFalse(self::repo()->recordExit(new ClickExit(self::OWNER, $clickId, landingPageId: $page)));
        self::assertSame('0', self::state($clickId)['click_out']);
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        self::assertTrue(self::repo()->recordExit(new ClickExit(self::OWNER, $clickId, landingPageId: $page)));
        self::assertSame('1', self::state($clickId)['click_out']);
    }

    public function testPurLinkRoutesOnlyAValidProofToTheSimplePagesOwnCampaign(): void
    {
        $clickId = self::FIRST_CLICK + 91;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11102 WHERE aff_campaign_id = ' . self::$othersCampaign);
        $anotherOffer = self::campaign(self::OWNER);
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11103 WHERE aff_campaign_id = $anotherOffer");
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = " . self::$campaign . ", landing_page_nickname = 'simple',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));

        self::assertFalse($exit->record($proof . 'x', null, $now, $key));
        self::assertFalse($exit->record($proof, null, $now + 60, $key));
        self::assertFalse($exit->record($proof, '11102', $now, $key));
        self::assertFalse($exit->record($proof, '11103', $now, $key));
        self::assertFalse($exit->record($proof, '11101e0', $now, $key));
        self::assertNull($exit->subid($proof, '11102', $now, $key));
        self::assertNull($exit->subid($proof, '11103', $now, $key));
        self::assertSame('0', self::state($clickId)['click_out']);
        self::assertTrue($exit->record($proof, '11101', $now, $key));
        self::assertSame('1', self::state($clickId)['click_out']);
        self::assertSame((string) self::$campaign, self::state($clickId)['aff_campaign_id']);
    }

    public function testPurLinkAdvancedPageCanSelectOnlyItsAccountsActiveOffer(): void
    {
        $clickId = self::FIRST_CLICK + 92;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11102 WHERE aff_campaign_id = ' . self::$othersCampaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'advanced',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));

        self::assertFalse($exit->record($proof, null, $now, $key));
        self::assertFalse($exit->record($proof, '11102', $now, $key));
        self::assertNull($exit->subid($proof, '11102', $now, $key));
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_deleted = 1 WHERE aff_campaign_id = ' . self::$campaign);
        self::assertFalse($exit->record($proof, '11101', $now, $key));
        self::assertNull($exit->subid($proof, '11101', $now, $key));
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_deleted = 0 WHERE aff_campaign_id = ' . self::$campaign);
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        self::assertSame([
            'click_id' => $clickId,
            'landing_page_id' => $page,
            'user_id' => self::OWNER,
            'campaign_public_id' => 11101,
        ], $exit->claims($subid, $key));
        self::assertMatchesRegularExpression('/\A' . $clickId . '-11101-[0-9a-f]{12}\z/', $subid);
        self::assertNull($exit->claims($subid, str_repeat('y', 32)));
        self::assertNull($exit->claims(str_replace('-11101-', '-11102-', $subid), $key));
        self::assertNull($exit->claims($clickId . '-11101', $key));
        self::assertSame('0', self::state($clickId)['click_out']);
        self::assertTrue($exit->record($proof, '11101', $now, $key));
        self::assertSame('1', self::state($clickId)['click_out']);
        self::assertSame((string) self::$campaign, self::state($clickId)['aff_campaign_id']);
    }

    public function testPurLinkExitDoesNotRewriteAConvertedClicksValue(): void
    {
        $clickId = self::FIRST_CLICK + 93;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'converted',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page, click_lead = 1 WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);

        $exit = new PurLinkExit(new Connection(self::requireScratch()));
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        $seen = $exit->convert(
            $subid,
            $key,
            static fn (int $user, int $click, int $campaign, string $payout): array => [$user, $click, $campaign, $payout]
        );
        self::assertSame([self::OWNER, $clickId, self::$campaign, '7.25'], $seen);
        self::assertTrue($exit->record($proof, '11101', $now, $key));
        self::assertSame('1', self::state($clickId)['click_out']);
        self::assertSame('4', self::state($clickId)['aff_campaign_id']);
        self::assertSame('2.50000', self::state($clickId)['click_payout']);
    }

    public function testPurLinkConversionCanArriveBeforeTheExitReport(): void
    {
        $clickId = self::FIRST_CLICK + 94;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'early',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $conn = new Connection(self::requireScratch());
        $exit = new PurLinkExit($conn);
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        self::q("UPDATE 202_clicks SET landing_page_id = 0 WHERE click_id = $clickId");
        $called = false;
        self::assertNull($exit->convert($subid, $key, static function () use (&$called): void {
            $called = true;
        }));
        self::assertFalse($called);
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        $writer = new MysqlConversionRepository($conn);

        $result = $exit->convert($subid, $key, static function (
            int $user,
            int $click,
            int $campaign,
            string $payout
        ) use ($writer): array {
            return $writer->recordInTransaction(
                $user,
                ['click_id' => $click, 'transaction_id' => 'purlink-early', 'campaign_id' => $campaign, 'payout' => $payout],
                MatchMethod::CLICK_ID,
            );
        });

        self::assertIsArray($result);
        self::assertGreaterThan(0, $result['convId']);
        self::assertSame((string) self::$campaign, self::state($clickId)['aff_campaign_id']);
        self::assertSame('7.25000', self::state($clickId)['click_payout']);
        $conversion = self::one('SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE conv_id = '
            . (int) $result['convId']);
        self::assertSame(['campaign_id' => (string) self::$campaign, 'click_payout' => '7.25000'], $conversion);
    }

    public function testPurLinkConversionFailureRollsBackTheOfferAndExit(): void
    {
        $clickId = self::FIRST_CLICK + 95;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'rollback',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        try {
            $exit->convert($subid, $key, static function (): never {
                throw new \RuntimeException('conversion failed');
            });
            self::fail('The failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('conversion failed', $e->getMessage());
        }
        self::assertSame('0', self::state($clickId)['click_out']);
        self::assertSame('4', self::state($clickId)['aff_campaign_id']);
        self::assertSame('2.50000', self::state($clickId)['click_payout']);
    }

    /** @dataProvider offerPayoutModes */
    public function testTwoAdvancedOffersRecordTheirOwnCampaignAndPayout(
        string $mode,
        int $offset,
        string $expectedTotal,
        ?string $firstSuperseded
    ): void
    {
        $clickId = self::FIRST_CLICK + $offset;
        self::click($clickId);
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101, payout_mode = '$mode'"
            . ' WHERE aff_campaign_id = ' . self::$campaign);
        $second = self::campaign(self::OWNER);
        $secondPublic = 11104 + $offset;
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_id_public = $secondPublic, aff_campaign_payout = 12.50"
            . " WHERE aff_campaign_id = $second");
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'two offers',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $conn = new Connection(self::requireScratch());
        $exit = new PurLinkExit($conn);
        $firstToken = $exit->subid($proof, '11101', $now, $key);
        $secondToken = $exit->subid($proof, (string) $secondPublic, $now, $key);
        self::assertIsString($firstToken);
        self::assertIsString($secondToken);
        $first = $exit->recordConversion($firstToken, $key, [
            'transaction_id' => 'offer-first', 'click_id' => 1, 'campaign_id' => self::$othersCampaign,
            'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        $last = $exit->recordConversion($secondToken, $key, [
            'transaction_id' => 'offer-second', 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertGreaterThan(0, $first['convId']);
        self::assertGreaterThan(0, $last['convId']);
        self::assertSame(
            ['campaign_id' => (string) self::$campaign, 'click_payout' => '7.25000'],
            self::one('SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE conv_id = ' . (int) $first['convId'])
        );
        self::assertSame(
            ['campaign_id' => (string) $second, 'click_payout' => '12.50000'],
            self::one('SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE conv_id = ' . (int) $last['convId'])
        );
        self::assertSame($expectedTotal, self::state($clickId)['click_payout']);
        self::assertSame(
            ['superseded_reason' => $firstSuperseded],
            self::one('SELECT superseded_reason FROM 202_conversion_logs WHERE conv_id = ' . (int) $first['convId'])
        );
        $retry = $exit->recordConversion($secondToken, $key, [
            'transaction_id' => 'offer-second', 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($retry);
        self::assertTrue($retry['duplicate']);
        self::assertSame($expectedTotal, self::state($clickId)['click_payout']);
        // A later campaign payout change must not change the amount reversed.
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_payout = 99 WHERE aff_campaign_id = $second");
        $reversal = $exit->recordConversion($secondToken, $key, [
            'transaction_id' => 'offer-second', 'reversal' => true, 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($reversal);
        self::assertSame(
            ['click_payout' => '-12.50000'],
            self::one('SELECT click_payout FROM 202_conversion_logs WHERE conv_id = ' . (int) $reversal['convId'])
        );
        self::assertSame($mode === 'replace' ? '0.00000' : '7.25000', self::state($clickId)['click_payout']);
        $explicit = $exit->recordConversion($secondToken, $key, [
            'transaction_id' => 'offer-explicit', 'payout' => '3.125', 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($explicit);
        self::assertSame(
            ['campaign_id' => (string) $second, 'click_payout' => '3.12500'],
            self::one('SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE conv_id = ' . (int) $explicit['convId'])
        );
        self::assertSame($mode === 'replace' ? '3.12500' : '10.37500', self::state($clickId)['click_payout']);
        self::q("UPDATE 202_aff_campaigns SET payout_mode = 'replace' WHERE aff_campaign_id = " . self::$campaign);
    }

    public function testDeferredPixelsFireEachServerConversionOnceForItsOwnPageview(): void
    {
        $clickId = self::FIRST_CLICK + 70;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'deferred',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        $account = self::insert('INSERT INTO 202_ppc_accounts SET user_id = ' . self::OWNER
            . ", ppc_network_id = 1, ppc_account_name = 'source', ppc_account_time = 1");
        self::q("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = $account, pixel_type_id = 1,"
            . " pixel_code = 'https://source.example/px?v=[[payout]]&t=[[transactionid]]&s=[[subid]]'");
        self::q("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = $account, pixel_type_id = 4,"
            . " pixel_code = 'https://source.example/server?t=[[transactionid]]'");
        self::q("UPDATE 202_clicks SET landing_page_id = $page, ppc_account_id = $account WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $exitProof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 3600, $key);
        $pixelProof = PurLinkProof::issue(PurLinkProof::PIXELS, $clickId, $page, self::OWNER, $now, 3600, $key);
        $conn = new Connection(self::requireScratch());
        $exit = new PurLinkExit($conn);
        $pixels = new PurLinkPixels($conn);

        self::assertSame('waiting', $pixels->poll($pixelProof, $now, $key)['status'], 'no conversion yet');
        self::assertSame('refused', $pixels->poll($exitProof, $now, $key)['status'], 'an exit proof is not a pixel proof');
        self::assertSame('refused', $pixels->poll($pixelProof, $now + 3600, $key)['status'], 'expired');
        self::assertSame('refused', $pixels->poll($pixelProof, $now, str_repeat('y', 32))['status'], 'another key');

        $subid = $exit->subid($exitProof, '11101', $now, $key);
        self::assertIsString($subid);
        $sale = $exit->recordConversion($subid, $key, [
            'transaction_id' => 'deferred-sale', 'pixel_type' => 2, 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($sale);
        $fired = $pixels->poll($pixelProof, $now, $key);
        self::assertSame('fired', $fired['status']);
        self::assertSame(1, $fired['conversions']);
        self::assertStringContainsString('https://source.example/px?v=7.25000&amp;t=deferred-sale&amp;s=' . $clickId, $fired['markup']);
        self::assertStringNotContainsString('/server', $fired['markup'], 'server postbacks are never resent');
        self::assertSame('waiting', $pixels->poll($pixelProof, $now, $key)['status'], 'each conversion fires once');
        self::assertSame(
            ['expires_at' => (string) ($now + 3600)],
            self::one('SELECT expires_at FROM 202_purlink_pixel_fires WHERE conv_id = ' . (int) $sale['convId'])
        );

        // A browser pixel conversion rendered its own markup; a reversed sale
        // is not announced; a replaced one no longer counts.
        $browser = $exit->recordConversion($subid, $key, [
            'transaction_id' => 'browser-sale', 'pixel_type' => 1, 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($browser);
        $taken = $exit->recordConversion($subid, $key, [
            'transaction_id' => 'taken-back', 'pixel_type' => 2, 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($taken);
        $reversal = $exit->recordConversion($subid, $key, [
            'transaction_id' => 'taken-back', 'pixel_type' => 2, 'reversal' => true, 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($reversal);
        self::assertSame('waiting', $pixels->poll($pixelProof, $now, $key)['status']);

        // A pageview's proof cannot read the click once it is on another page,
        // and another account's click is never this proof's.
        $moved = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'moved',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $moved WHERE click_id = $clickId");
        self::assertSame('refused', $pixels->poll($pixelProof, $now, $key)['status']);
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        $foreign = self::FIRST_CLICK + 71;
        self::click($foreign, self::OTHER);
        RecordedClicks::note($foreign);
        $forged = PurLinkProof::issue(PurLinkProof::PIXELS, $foreign, $page, self::OWNER, $now, 3600, $key);
        self::assertSame('refused', $pixels->poll($forged, $now, $key)['status']);

        // Fired rows go once no proof can poll for them.
        (new \Prosper202\Click\PurLinkExitRetention($conn))->prune($now + 3601);
        self::assertSame(
            ['n' => '0'],
            self::one("SELECT COUNT(*) AS n FROM 202_purlink_pixel_fires WHERE click_id = $clickId")
        );
    }

    public function testExpiredExitFencesArePrunedInBoundedBatches(): void
    {
        $base = 7888000000;
        $now = time();
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = '(' . ($base + $i) . ',' . self::OWNER . ',1,1,1,' . ($now - 1) . ')';
        }
        $rows[] = '(' . ($base + 1001) . ',' . self::OWNER . ',1,1,1,' . ($now + 1) . ')';
        self::q('INSERT INTO 202_purlink_exit_order VALUES ' . implode(',', $rows));
        try {
            $retention = new \Prosper202\Click\PurLinkExitRetention(new Connection(self::requireScratch()));
            self::assertSame(1000, $retention->prune($now));
            self::assertSame(1, $retention->prune($now));
            self::assertSame(0, $retention->prune($now));
            self::assertSame(['n' => '1'], self::one('SELECT COUNT(*) AS n FROM 202_purlink_exit_order WHERE click_id BETWEEN '
                . $base . ' AND ' . ($base + 1001)));
            self::assertSame(1, $retention->prune($now + 1));
        } finally {
            self::q('DELETE FROM 202_purlink_exit_order WHERE click_id BETWEEN ' . $base . ' AND ' . ($base + 1001));
        }
    }

    public function testOrderedPurLinkExitsIgnoreRetriesAndOutOfOrderReports(): void
    {
        $clickId = self::FIRST_CLICK + 86;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public=11101 WHERE aff_campaign_id=' . self::$campaign);
        $other = self::campaign(self::OWNER);
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_id_public=129979, aff_campaign_payout=12.5 WHERE aff_campaign_id=$other");
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id=' . self::OWNER
            . ", aff_campaign_id=0, landing_page_type=1, landing_page_nickname='ordered',"
            . " landing_page_url='https://example.test/', landing_page_time=1");
        self::q("UPDATE 202_clicks SET landing_page_id=$page WHERE click_id=$clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));
        self::assertSame('recorded', $exit->recordOrdered($proof, '129979', '2', $now, $key));
        self::assertSame('stale', $exit->recordOrdered($proof, '11101', '1', $now, $key));
        self::assertSame('duplicate', $exit->recordOrdered($proof, '129979', '2', $now, $key));
        self::assertSame('conflict', $exit->recordOrdered($proof, '11101', '2', $now, $key));
        self::assertSame((string) $other, self::state($clickId)['aff_campaign_id']);
        self::assertSame('refused', $exit->recordOrdered($proof, '129979', '2.5', $now, $key));
        self::assertSame('refused', $exit->recordOrdered($proof, '129979', '2147483648', $now, $key));
        self::assertSame('refused', $exit->recordOrdered($proof, '129979', '3', $now + 60, $key));
        self::assertSame('recorded', $exit->recordOrdered($proof, '11101', '3', $now, $key));
        self::assertSame((string) self::$campaign, self::state($clickId)['aff_campaign_id']);
        self::assertSame(['sequence' => '3', 'campaign_id' => (string) self::$campaign],
            self::one("SELECT sequence,campaign_id FROM 202_purlink_exit_order WHERE click_id=$clickId"));

        // Once any visit converts, another exit records its ordering without
        // rewriting older visits or the paid click's current campaign.
        self::q("UPDATE 202_clicks SET click_lead=1 WHERE click_id=$clickId");
        self::assertSame('recorded', $exit->recordOrdered($proof, '129979', '4', $now, $key));
        self::assertSame((string) self::$campaign, self::state($clickId)['aff_campaign_id']);
        self::assertSame('4', self::one("SELECT sequence FROM 202_purlink_exit_order WHERE click_id=$clickId")['sequence']);
    }

    public function testPurLinkConversionClickUpdateRollsBackWithItsRouteAndLedger(): void
    {
        $clickId = self::FIRST_CLICK + 87;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'atomic update',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        try {
            $exit->recordConversion($subid, $key, ['transaction_id' => 'callback-rollback', 'skip_bridge' => true],
                MatchMethod::CLICK_ID, static function (): void { throw new \RuntimeException('failed click update'); });
            self::fail('a failed click update must roll back the conversion');
        } catch (\RuntimeException $error) {
            self::assertSame('failed click update', $error->getMessage());
        }
        self::assertSame(['campaign_id' => '4'], self::one("SELECT aff_campaign_id AS campaign_id FROM 202_clicks WHERE click_id = $clickId"));
        self::assertSame(['n' => '0'], self::one("SELECT COUNT(*) AS n FROM 202_conversion_logs WHERE click_id = $clickId"));
        $called = 0;
        $result = $exit->recordConversion($subid, $key, ['transaction_id' => 'callback-rollback', 'skip_bridge' => true],
            MatchMethod::CLICK_ID, static function (int $updatedClick, float $payout) use (&$called, $clickId): void {
                self::assertSame($clickId, $updatedClick);
                self::assertSame(7.25, $payout);
                ++$called;
            });
        self::assertIsArray($result);
        self::assertSame(1, $called);
        self::assertSame(['campaign_id' => (string) self::$campaign],
            self::one('SELECT campaign_id FROM 202_conversion_logs WHERE conv_id = ' . (int) $result['convId']));
    }

    public function testConvertedOldMultiVisitClickDoesNotHaveAnUnconvertedVisitRerouted(): void
    {
        $clickId = self::FIRST_CLICK + 89;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        $page = self::insert('INSERT INTO 202_landing_pages SET user_id = ' . self::OWNER
            . ", aff_campaign_id = 0, landing_page_type = 1, landing_page_nickname = 'old visits',"
            . " landing_page_url = 'https://example.test/', landing_page_time = 1");
        self::q("UPDATE 202_clicks SET landing_page_id = $page WHERE click_id = $clickId");
        self::q("INSERT INTO 202_clicks SELECT * FROM 202_clicks WHERE click_id = $clickId");
        self::q("UPDATE 202_clicks SET click_lead = 1 WHERE click_id = $clickId LIMIT 1");
        RecordedClicks::note($clickId);
        $key = str_repeat('x', 32);
        $now = time();
        $proof = PurLinkProof::issue(PurLinkProof::EXIT, $clickId, $page, self::OWNER, $now, 60, $key);
        $exit = new PurLinkExit(new Connection(self::requireScratch()));
        $subid = $exit->subid($proof, '11101', $now, $key);
        self::assertIsString($subid);
        $result = $exit->recordConversion($subid, $key, [
            'transaction_id' => 'old-multiple-visits', 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertIsArray($result);
        self::assertSame(
            ['visits' => '2'],
            self::one("SELECT COUNT(*) AS visits FROM 202_clicks WHERE click_id = $clickId AND aff_campaign_id = 4")
        );
        self::assertSame(
            ['campaign_id' => (string) self::$campaign, 'click_payout' => '7.25000'],
            self::one('SELECT campaign_id, click_payout FROM 202_conversion_logs WHERE conv_id = ' . (int) $result['convId'])
        );
    }

    /** @return iterable<string, array{string, int, string, string|null}> */
    public static function offerPayoutModes(): iterable
    {
        yield 'replace' => ['replace', 98, '12.50000', 'replace'];
        yield 'accumulate' => ['accumulate', 99, '19.75000', null];
    }

    public function testLegacyPurLinkSubidOnlyResolvesTheClicksCurrentOwnedCampaign(): void
    {
        $clickId = self::FIRST_CLICK + 96;
        self::click($clickId);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11101 WHERE aff_campaign_id = ' . self::$campaign);
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = 11102 WHERE aff_campaign_id = ' . self::$othersCampaign);
        $resolver = new PurLinkLegacySubid(new Connection(self::requireScratch()));

        self::assertNull($resolver->resolve($clickId . '-11101'));
        self::q('UPDATE 202_clicks SET aff_campaign_id = ' . self::$campaign . " WHERE click_id = $clickId");
        self::assertSame([
            'click_id' => $clickId, 'user_id' => self::OWNER, 'campaign_id' => self::$campaign,
        ], $resolver->resolve($clickId . '-11101'));
        self::assertNull($resolver->resolve($clickId . '-11102'));
        self::assertNull($resolver->resolve($clickId . '-11101x'));
        self::q('UPDATE 202_clicks SET aff_campaign_id = ' . self::$othersCampaign . " WHERE click_id = $clickId");
        self::assertNull($resolver->resolve($clickId . '-11102'));
        self::assertNull($resolver->resolve($clickId . '-11101'));
    }

    public function testLegacyCampaignCheckRunsUnderTheConversionLock(): void
    {
        $clickId = self::FIRST_CLICK + 88;
        self::click($clickId);
        $writer = new MysqlConversionRepository(new Connection(self::requireScratch()));
        try {
            $writer->record(self::OWNER, [
                'click_id' => $clickId, 'expected_campaign_id' => self::$campaign,
                'transaction_id' => 'legacy-guard', 'skip_bridge' => true,
            ], MatchMethod::CLICK_ID);
            self::fail('A changed campaign was accepted');
        } catch (PurLinkCampaignChanged) {
            self::assertSame('0', self::one("SELECT COUNT(*) AS n FROM 202_conversion_logs WHERE click_id = $clickId")['n']);
            self::assertSame('0', self::state($clickId)['click_out']);
        }
        self::q('UPDATE 202_clicks SET aff_campaign_id = ' . self::$campaign . " WHERE click_id = $clickId");
        self::q('UPDATE 202_clicks_spy SET aff_campaign_id = ' . self::$campaign . " WHERE click_id = $clickId");
        $result = $writer->record(self::OWNER, [
            'click_id' => $clickId, 'expected_campaign_id' => self::$campaign,
            'transaction_id' => 'legacy-guard', 'skip_bridge' => true,
        ], MatchMethod::CLICK_ID);
        self::assertGreaterThan(0, $result['convId']);
        self::assertSame((string) self::$campaign, self::one('SELECT campaign_id FROM 202_conversion_logs WHERE conv_id = '
            . (int) $result['convId'])['campaign_id']);
    }

    public function testExitInTransactionRefusesToRunWithoutACallerTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outside one');
        self::repo()->recordExitInTransaction(new ClickExit(self::OWNER, self::FIRST_CLICK + 97));
    }

    /**
     * off.php: the offer's campaign and payout, the click-out mark and its
     * cloaking, and the URLs, the redirect resolved from the rows already
     * rewritten (its [[payout]] is the offer's).
     */
    public function testAClickLeavingForAnOfferTakesItAll(): void
    {
        $clickId = self::FIRST_CLICK;
        self::click($clickId);
        $seenInside = null;

        $recorded = self::repo()->recordExit(
            new ClickExit(
                self::OWNER,
                $clickId,
                offer: new OfferRoute(self::$campaign, '7.25'),
                cloaking: 1,
                outboundSiteUrlId: 61,
            ),
            function () use ($clickId, &$seenInside): int {
                $seenInside = self::one("SELECT click_payout FROM 202_clicks WHERE click_id = $clickId");

                return 62;
            }
        );

        self::assertTrue($recorded);
        self::assertSame(['click_payout' => '7.25000'], $seenInside);
        self::assertSame(array_replace(self::UNTOUCHED, [
            'aff_campaign_id' => (string) self::$campaign, 'click_payout' => '7.25000',
            'spy_campaign' => (string) self::$campaign, 'spy_payout' => '7.25', 'click_out' => '1',
            'click_cloaking' => '1', 'click_outbound_site_url_id' => '61', 'click_redirect_site_url_id' => '62',
        ]), self::state($clickId));
    }

    /**
     * The click is the visitor's to name: another account's leaves as it
     * was, its redirect never resolved.
     */
    public function testAnotherAccountsClickIsNotWritten(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        self::click($clickId, self::OTHER);
        $resolved = false;

        $recorded = self::repo()->recordExit(
            new ClickExit(
                self::OWNER,
                $clickId,
                offer: new OfferRoute(self::$campaign, '7.25'),
                rotator: new RotatorRoute(self::$rotator, self::$rule, self::$redirect),
                cloaking: 1,
                outboundSiteUrlId: 61,
            ),
            function () use (&$resolved): int {
                $resolved = true;

                return 62;
            }
        );

        self::assertFalse($recorded);
        self::assertFalse($resolved);
        self::assertSame(self::UNTOUCHED, self::state($clickId));
    }

    public function testAClickWithNoRowsIsNotWritten(): void
    {
        self::assertFalse(self::repo()->recordExit(new ClickExit(self::OWNER, self::FIRST_CLICK + 2)));
    }

    /** The question offrtr.php asks before it turns a rotation: the same one recordExit() asks. */
    public function testIsAccountsClickAnswersForTheAccountsOwnClicksOnly(): void
    {
        $own = self::FIRST_CLICK + 16;
        $others = self::FIRST_CLICK + 17;
        self::click($own);
        self::click($others, self::OTHER);
        // A re-click's second row of another account's makes the click no longer wholly this one's.
        $mixed = self::FIRST_CLICK + 18;
        self::click($mixed);
        self::q('INSERT INTO 202_clicks SET click_id = ' . $mixed . ', user_id = ' . self::OTHER
            . ', ppc_account_id = 0, click_cpc = 0, click_time = ' . time());

        self::assertTrue(self::repo()->isAccountsClick(self::OWNER, $own));
        self::assertFalse(self::repo()->isAccountsClick(self::OWNER, $others));
        self::assertFalse(self::repo()->isAccountsClick(self::OWNER, self::FIRST_CLICK + 19), 'no rows');
        self::assertFalse(self::repo()->isAccountsClick(self::OWNER, $mixed));
    }

    public function testAnotherAccountsOfferIsNotWritten(): void
    {
        $clickId = self::FIRST_CLICK + 3;
        self::click($clickId);

        self::assertFalse(self::repo()->recordExit(
            new ClickExit(self::OWNER, $clickId, offer: new OfferRoute(self::$othersCampaign, '7.25'), cloaking: 1)
        ));
        self::assertSame(self::UNTOUCHED, self::state($clickId));
    }

    /**
     * Each refused by one check alone: the others hold, so a check that went
     * would let it through.
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignRoutes(): iterable
    {
        yield "another account's rotator, with its own rule and redirect" => ['rotator'];
        yield "another rotator's rule, with that rule's redirect" => ['rule'];
        yield "another rule's redirect" => ['redirect'];
    }

    /** @dataProvider foreignRoutes */
    public function testARouteNotWhollyTheAccountsIsNotWritten(string $foreign): void
    {
        $clickId = self::FIRST_CLICK + 4 + array_search($foreign, ['rotator', 'rule', 'redirect'], true);
        self::click($clickId);
        $route = match ($foreign) {
            'rotator' => new RotatorRoute(self::$othersRotator, self::$othersRule, self::$othersRedirect),
            'rule' => new RotatorRoute(self::$rotator, self::$othersRule, self::$othersRedirect),
            'redirect' => new RotatorRoute(self::$rotator, self::$rule, self::$othersRedirect),
        };

        $exit = new ClickExit(self::OWNER, $clickId, rotator: $route, clickedOut: false);

        self::assertFalse(self::repo()->recordExit($exit));
        self::assertSame(self::UNTOUCHED, self::state($clickId));
    }

    /**
     * offrtr.php: the route on every row of the click (a re-click gives it a
     * second 202_clicks row), 202_clicks.rule_id holding the redirect as
     * rtr.php writes it; a rule sending to a URL marks no click-out.
     */
    public function testARotatorRouteIsWrittenWhereRtrPhpWritesIt(): void
    {
        $clickId = self::FIRST_CLICK + 7;
        self::click($clickId);
        self::q('INSERT INTO 202_clicks SET click_id = ' . $clickId . ', user_id = ' . self::OWNER
            . ', ppc_account_id = 0, click_cpc = 0, click_time = ' . time());

        self::assertTrue(self::repo()->recordExit(new ClickExit(
            self::OWNER,
            $clickId,
            rotator: new RotatorRoute(self::$rotator, self::$rule, self::$redirect),
            clickedOut: false,
        )));

        self::assertSame(
            ['rotator_id' => (string) self::$rotator, 'rule_id' => (string) self::$rule,
                'rule_redirect_id' => (string) self::$redirect],
            self::one("SELECT rotator_id, rule_id, rule_redirect_id FROM 202_clicks_rotator WHERE click_id = $clickId")
        );
        self::assertSame(
            ['routed' => '2'],
            self::one('SELECT COUNT(*) AS routed FROM 202_clicks WHERE click_id = ' . $clickId
                . ' AND rotator_id = ' . self::$rotator . ' AND rule_id = ' . self::$redirect)
        );
        self::assertSame('0', self::state($clickId)['click_out']);
    }

    /** The rotator's default: rule and redirect 0, which name no row and are not looked for. */
    public function testTheRotatorsDefaultRouteIsWritten(): void
    {
        $clickId = self::FIRST_CLICK + 8;
        self::click($clickId);

        self::assertTrue(self::repo()->recordExit(new ClickExit(
            self::OWNER,
            $clickId,
            offer: new OfferRoute(self::$campaign, '7.25'),
            rotator: new RotatorRoute(self::$rotator, 0, 0),
            cloaking: 0,
        )));

        self::assertSame(
            ['rotator_id' => (string) self::$rotator, 'rule_id' => '0', 'rule_redirect_id' => '0'],
            self::one("SELECT rotator_id, rule_id, rule_redirect_id FROM 202_clicks_rotator WHERE click_id = $clickId")
        );
        self::assertSame('1', self::state($clickId)['click_out']);
    }

    /** A converted click's value belongs to its ledger: its campaign and payout stay; it still leaves. */
    public function testAConvertedClickKeepsItsCampaignAndPayout(): void
    {
        $clickId = self::FIRST_CLICK + 9;
        self::click($clickId);
        self::q("UPDATE 202_clicks SET click_lead = 1 WHERE click_id = $clickId");

        self::assertTrue(self::repo()->recordExit(
            new ClickExit(self::OWNER, $clickId, offer: new OfferRoute(self::$campaign, '7.25'), cloaking: 0)
        ));

        self::assertSame(
            array_replace(self::UNTOUCHED, ['click_out' => '1', 'click_cloaking' => '0']),
            self::state($clickId)
        );
    }

    /** pci.php: the click-out mark alone, its cloaking kept. */
    public function testAnExitWithNoCloakingKeepsTheClicks(): void
    {
        $clickId = self::FIRST_CLICK + 10;
        self::click($clickId);

        self::assertTrue(self::repo()->recordExit(new ClickExit(self::OWNER, $clickId)));

        self::assertSame(array_replace(self::UNTOUCHED, ['click_out' => '1']), self::state($clickId));
    }

    /** A click old enough to be summed is marked for the rollup in the rewrite's transaction; a fresh one is not. */
    public function testOnlyARewriteOfASummedClickIsMarked(): void
    {
        $old = self::FIRST_CLICK + 11;
        $fresh = self::FIRST_CLICK + 12;
        self::click($old, age: 7200);
        self::click($fresh);

        foreach ([$old, $fresh] as $clickId) {
            $offer = new OfferRoute(self::$campaign, '7.25');
            self::repo()->recordExit(new ClickExit(self::OWNER, $clickId, offer: $offer));
        }

        self::assertSame('1', self::state($old)['marks']);
        self::assertSame('0', self::state($fresh)['marks']);
    }

    public function testAFailureWhileResolvingTheRedirectWritesNothing(): void
    {
        $clickId = self::FIRST_CLICK + 13;
        self::click($clickId, age: 7200);

        try {
            self::repo()->recordExit(
                new ClickExit(
                    self::OWNER,
                    $clickId,
                    offer: new OfferRoute(self::$campaign, '7.25'),
                    rotator: new RotatorRoute(self::$rotator, self::$rule, self::$redirect),
                    cloaking: 1,
                    outboundSiteUrlId: 61,
                ),
                static function (): int {
                    throw new \RuntimeException('the redirect could not be resolved');
                }
            );
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the redirect could not be resolved', $e->getMessage());
        }

        self::assertSame(self::UNTOUCHED, self::state($clickId));
    }

    /** @return iterable<string, array{ClickExit, ?\Closure, string}> */
    public static function exitsThatCannotBeWritten(): iterable
    {
        $id = self::FIRST_CLICK + 14;
        yield 'an outbound URL without its redirect' => [new ClickExit(self::OWNER, $id, outboundSiteUrlId: 61), null,
            'A click exit records its outbound and redirect URLs together, or neither'];
        yield 'a redirect without its outbound URL' => [new ClickExit(self::OWNER, $id), static fn (): int => 62,
            'A click exit records its outbound and redirect URLs together, or neither'];
        yield 'nothing' => [
            new ClickExit(self::OWNER, $id, clickedOut: false),
            null,
            'A click exit that writes nothing',
        ];
    }

    /** @dataProvider exitsThatCannotBeWritten */
    public function testAnExitThatCannotBeWrittenIsRefusedUnread(ClickExit $exit, ?\Closure $resolve, string $why): void
    {
        self::requireScratch();
        try {
            self::repo()->recordExit($exit, $resolve);
            self::fail('refused');
        } catch (LogicException $e) {
            self::assertSame($why, $e->getMessage());
        }
    }

    /** rtr.php re-clicks a click it found among its account's; any other is a mistake, and nothing is written. */
    public function testAReClickOfAnotherAccountsClickIsRefused(): void
    {
        $clickId = self::FIRST_CLICK + 15;
        self::click($clickId, self::OTHER);

        try {
            self::repo()->recordReClick(new ClickRecord(
                clickId: $clickId,
                userId: self::OWNER,
                clickTime: time(),
                recordsCampaignRows: false,
                clickOut: 1,
                rotator: new RotatorRoute(7, 8, 9),
            ), static fn (): int => 52);
            self::fail('refused');
        } catch (LogicException $e) {
            self::assertStringContainsString('not account ' . self::OWNER . "'s", $e->getMessage());
        }

        self::assertSame(self::UNTOUCHED, self::state($clickId));
        self::assertSame(['n' => '1'], self::one("SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id = $clickId"));
    }
}

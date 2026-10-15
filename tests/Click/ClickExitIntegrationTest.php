<?php

declare(strict_types=1);

namespace Tests\Click;

use LogicException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickExit;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\OfferRoute;
use Prosper202\Click\RotatorRoute;
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
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
            '202_attribution_rollup_dirty_clicks',
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

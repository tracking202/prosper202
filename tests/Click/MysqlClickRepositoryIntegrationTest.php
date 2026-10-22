<?php

declare(strict_types=1);

namespace Tests\Click;

use LogicException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Every path's click rows, written by the one writer on a real server in the
 * strict mode a MySQL 8 server runs with: a new click's rows commit together
 * or not at all, the redirector's redirect URL is resolved inside the
 * transaction from the rows already written, and a re-click (rtr.php?lpr=)
 * rewrites what the redirector sets and keeps every other column the click
 * has — the REPLACE it replaced reset its customer, its reviewed flag, its
 * public id and its landing and cloaking URLs.
 *
 * @group integration
 */
final class MysqlClickRepositoryIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990301;
    private const FIRST_CLICK = 99030101;

    /** 202_clicks_rotator as a redirectorClick() writes it */
    private const ROUTE = ['rotator_id' => '7', 'rule_id' => '8', 'rule_redirect_id' => '9'];

    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
        '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
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
        foreach ([...self::TABLES, '202_attribution_rollup_dirty_clicks'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    /** A landing page click as record_simple.php builds one. */
    private static function landingPageClick(int $clickId): ClickRecord
    {
        return new ClickRecord(
            clickId: $clickId,
            userId: self::USER,
            clickTime: time() - 600,
            affCampaignId: 4,
            landingPageId: 6,
            ppcAccountId: 3,
            clickCpc: '0.12500',
            clickPayout: '2.5',
            keywordId: 5,
            ipId: 11,
            countryId: 12,
            c1Id: 21,
            c4Id: 24,
            variableSetId: 8,
            gclid: "Cj0'quote\\back",
            utmSourceId: 31,
            utmContentId: 35,
            clickIdPublic: '512345',
            clickCloaking: 1,
            clickRefererSiteUrlId: 40,
            clickLandingSiteUrlId: 41,
            clickOutboundSiteUrlId: 42,
            clickCloakingSiteUrlId: 43,
            clickRedirectSiteUrlId: 44,
        );
    }

    /** A redirector click as rtr.php builds one. */
    private static function redirectorClick(
        int $clickId,
        int $keywordId = 5,
        string $publicId = '0',
        int $cloakingSiteUrlId = 0,
    ): ClickRecord {
        return new ClickRecord(
            clickId: $clickId,
            userId: self::USER,
            clickTime: time(),
            ppcAccountId: 3,
            clickCpc: '0.25',
            clickFiltered: 1,
            keywordId: $keywordId,
            ipId: 13,
            countryId: 12,
            c1Id: 91,
            recordsCampaignRows: false,
            clickIdPublic: $publicId,
            clickCloaking: $publicId === '0' ? 0 : 1,
            clickOut: 1,
            clickRefererSiteUrlId: 50,
            clickOutboundSiteUrlId: 51,
            clickCloakingSiteUrlId: $cloakingSiteUrlId,
            rotator: new RotatorRoute(7, 8, 9),
        );
    }

    /** @return array<string, int> table => rows the click has there */
    private static function rows(int $clickId): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) self::one("SELECT COUNT(*) AS n FROM $table WHERE click_id = $clickId")['n'];
        }

        return $counts;
    }

    /** @return array<string, int> */
    private static function rowsOf(array $tables, int $count = 1): array
    {
        return array_merge(array_fill_keys(self::TABLES, 0), array_fill_keys($tables, $count));
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

    private static function marks(int $clickId): int
    {
        $sql = "SELECT COUNT(*) AS n FROM 202_attribution_rollup_dirty_clicks WHERE click_id = $clickId";

        return (int) self::one($sql)['n'];
    }

    /** @param array<string, string|null> $expected column => the value the server answers */
    private static function assertColumns(string $table, int $clickId, array $expected, string $where = ''): void
    {
        $columns = implode(', ', array_keys($expected));
        self::assertSame($expected, self::one("SELECT $columns FROM $table WHERE click_id = $clickId $where"), $table);
    }

    private static function repo(): MysqlClickRepository
    {
        return new MysqlClickRepository(new Connection(self::requireScratch()));
    }

    public function testALandingPageClickWritesEveryRowButTheRotators(): void
    {
        $clickId = self::FIRST_CLICK;
        self::repo()->recordClick(self::landingPageClick($clickId));

        self::assertSame(self::rowsOf(array_diff(self::TABLES, ['202_clicks_rotator'])), self::rows($clickId));
        $core = [
            'user_id' => (string) self::USER, 'aff_campaign_id' => '4', 'landing_page_id' => '6',
            'ppc_account_id' => '3', 'click_filtered' => '0',
        ];
        self::assertColumns('202_clicks', $clickId, $core + [
            'click_cpc' => '0.12500', 'click_payout' => '2.50000', 'rotator_id' => '0', 'rule_id' => '0',
        ]);
        self::assertColumns('202_clicks_spy', $clickId, $core + ['click_cpc' => '0.13', 'click_payout' => '2.50']);
        self::assertColumns('202_clicks_advance', $clickId, [
            'keyword_id' => '5', 'ip_id' => '11', 'country_id' => '12',
        ]);
        self::assertColumns('202_clicks_tracking', $clickId, ['c1_id' => '21', 'c2_id' => '0', 'c4_id' => '24']);
        self::assertColumns('202_clicks_variable', $clickId, ['variable_set_id' => '8']);
        // Bound as given: the quote and the backslash are the gclid's own.
        self::assertColumns('202_google', $clickId, [
            'gclid' => "Cj0'quote\\back", 'utm_source_id' => '31', 'utm_medium_id' => '0', 'utm_content_id' => '35',
        ]);
        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '512345', 'click_cloaking' => '1', 'click_in' => '1', 'click_out' => '0',
        ]);
        self::assertColumns('202_clicks_site', $clickId, [
            'click_referer_site_url_id' => '40', 'click_landing_site_url_id' => '41',
            'click_outbound_site_url_id' => '42', 'click_cloaking_site_url_id' => '43',
            'click_redirect_site_url_id' => '44',
        ]);
        self::assertSame(0, self::marks($clickId), 'a new click is not summed yet, so it is not marked');
    }

    /**
     * dl.php's uncloaked click names its public id '': the BIGINT UNSIGNED
     * column refused it under a strict sql_mode, and the click was lost.
     */
    public function testADirectLinkClickWithNoPublicIdIsStored(): void
    {
        $clickId = self::FIRST_CLICK + 10;
        self::repo()->recordClick(ClickRecordBuilder::fromLegacyArray([
            'user_id' => (string) self::USER, 'click_time' => time(), 'click_id_public' => '',
            'click_payout' => '', 'click_cpc' => '0.10000', 'click_cloaking' => 0, 'click_in' => 1, 'click_out' => 1,
        ], $clickId));

        self::assertColumns('202_clicks_record', $clickId, ['click_id_public' => '0', 'click_cloaking' => '0']);
        self::assertColumns('202_clicks', $clickId, ['click_payout' => '0.00000']);
    }

    public function testARedirectorClickHasItsRouteAndItsRedirectResolvedFromItsRows(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        $seenInside = null;
        $resolve = function () use ($clickId, &$seenInside): int {
            $seenInside = self::rows($clickId);

            return 52;
        };
        self::repo()->recordClick(self::redirectorClick($clickId), $resolve);

        $written = ['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
            '202_clicks_rotator', '202_clicks_record', '202_clicks_site'];
        self::assertSame(self::rowsOf($written), self::rows($clickId), 'no gclid, UTM or variable rows');
        self::assertSame(
            self::rowsOf(array_diff($written, ['202_clicks_site'])),
            $seenInside,
            'the resolver runs after every row but the site row, which takes its answer'
        );
        self::assertColumns('202_clicks', $clickId, [
            'ppc_account_id' => '3', 'click_cpc' => '0.25000', 'click_payout' => '0.00000', 'click_filtered' => '1',
            'rotator_id' => '7', 'rule_id' => '9',
        ]);
        self::assertColumns('202_clicks_rotator', $clickId, self::ROUTE);
        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '0', 'click_cloaking' => '0', 'click_in' => '1', 'click_out' => '1',
        ]);
        self::assertColumns('202_clicks_site', $clickId, [
            'click_referer_site_url_id' => '50', 'click_landing_site_url_id' => '0',
            'click_outbound_site_url_id' => '51', 'click_cloaking_site_url_id' => '0',
            'click_redirect_site_url_id' => '52',
        ]);
    }

    /**
     * The public id is a digit, the click id and a digit: on a 32-bit build
     * it passes PHP_INT_MAX at tens of millions of clicks, and (int) clamped
     * it, so cl.php?pci= found no click. Past PHP_INT_MAX here is the same
     * clamp on a 64-bit build.
     */
    public function testACloakedClickKeepsItsPublicIdWhole(): void
    {
        $clickId = self::FIRST_CLICK + 2;
        $click = self::redirectorClick($clickId, publicId: '18446744073709551615');
        self::repo()->recordClick($click, static fn (): int => 52);

        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '18446744073709551615', 'click_cloaking' => '1',
        ]);
    }

    public function testAFailureWhileResolvingTheRedirectWritesNoRow(): void
    {
        $clickId = self::FIRST_CLICK + 3;

        try {
            self::repo()->recordClick(self::redirectorClick($clickId), static function (): int {
                throw new \RuntimeException('the redirect could not be resolved');
            });
            self::fail('the failure reaches the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the redirect could not be resolved', $e->getMessage());
        }

        self::assertSame(self::rowsOf([]), self::rows($clickId));
    }

    public function testAFailedLastStatementTakesTheOthersWithIt(): void
    {
        $clickId = self::FIRST_CLICK + 4;

        try {
            // Out of range for the unsigned column: the server refuses the site row.
            self::repo()->recordClick(self::redirectorClick($clickId), static fn (): int => -1);
            self::fail('the refused statement reaches the caller');
        } catch (QueryException $e) {
            self::assertStringContainsString('click_redirect_site_url_id', $e->getMessage());
        }

        self::assertSame(self::rowsOf([]), self::rows($clickId));
    }

    public function testAClickThatCarriesItsRedirectIdIsNotResolvedAgain(): void
    {
        $clickId = self::FIRST_CLICK + 5;

        try {
            self::repo()->recordClick(self::landingPageClick($clickId), static fn (): int => 52);
            self::fail('a click with its redirect id and a resolver is refused');
        } catch (LogicException $e) {
            self::assertStringContainsString('not both', $e->getMessage());
        }

        self::assertSame(self::rowsOf([]), self::rows($clickId));
    }

    /**
     * A re-click through another rule, from another address and device, with
     * other c1-c4: every column it rewrites differs from the click's.
     */
    private static function reClickOf(int $clickId, int $clickTime): ClickRecord
    {
        return new ClickRecord(
            clickId: $clickId,
            userId: self::USER,
            clickTime: $clickTime,
            affCampaignId: 14,
            landingPageId: 16,
            ppcAccountId: 13,
            clickCpc: '0.75',
            clickPayout: '1.25',
            clickFiltered: 1,
            clickBot: 1,
            textAdId: 0,
            keywordId: 6,
            ipId: 23,
            countryId: 22,
            regionId: 24,
            ispId: 29,
            cityId: 25,
            platformId: 26,
            browserId: 27,
            deviceId: 28,
            c1Id: 91,
            c2Id: 92,
            c3Id: 93,
            c4Id: 94,
            recordsCampaignRows: false,
            clickOut: 1,
            clickRefererSiteUrlId: 50,
            clickOutboundSiteUrlId: 51,
            rotator: new RotatorRoute(17, 18, 19),
        );
    }

    /**
     * A re-click of a landing page click: a second 202_clicks row at the new
     * time, the redirector's columns rewritten, and the customer a
     * conversion set, the reviewed flag, the public id its pci.php links
     * carry, its landing and cloaking URLs and its text ad (the redirector
     * records none) kept. The redirect is resolved
     * from the rewritten rows, before the site row takes its answer.
     */
    public function testAReClickRewritesWhatTheRedirectorSetsAndKeepsTheRest(): void
    {
        $clickId = self::FIRST_CLICK + 6;
        self::repo()->recordClick(self::landingPageClick($clickId));
        self::q("UPDATE 202_clicks_tracking SET customer_id = 4242 WHERE click_id = $clickId");
        self::q("UPDATE 202_clicks_record SET click_reviewed = 1 WHERE click_id = $clickId");
        self::q("UPDATE 202_clicks_advance SET text_ad_id = 33 WHERE click_id = $clickId");
        $at = time() + 60;
        $seenInside = null;
        $resolve = function () use ($clickId, &$seenInside): int {
            $seenInside = [
                self::one("SELECT keyword_id, c2_id FROM 202_clicks_advance JOIN 202_clicks_tracking"
                    . " USING (click_id) WHERE click_id = $clickId"),
                self::one("SELECT click_redirect_site_url_id FROM 202_clicks_site WHERE click_id = $clickId"),
            ];

            return 52;
        };

        self::repo()->recordReClick(self::reClickOf($clickId, $at), $resolve);

        self::assertSame(
            array_merge(self::rowsOf(self::TABLES), ['202_clicks' => 2, '202_clicks_spy' => 2]),
            self::rows($clickId),
            '202_clicks and 202_clicks_spy have no key on click_id: a second row; the rest one each'
        );
        self::assertSame(
            [['keyword_id' => '6', 'c2_id' => '92'], ['click_redirect_site_url_id' => '44']],
            $seenInside,
            'the resolver reads the rewritten rows, before the site row takes its answer'
        );
        $clickRow = [
            'user_id' => (string) self::USER, 'aff_campaign_id' => '14', 'landing_page_id' => '16',
            'ppc_account_id' => '13', 'click_filtered' => '1', 'click_bot' => '1', 'click_alp' => '0',
        ];
        self::assertColumns('202_clicks', $clickId, $clickRow + [
            'click_cpc' => '0.75000', 'click_payout' => '1.25000', 'rotator_id' => '17', 'rule_id' => '19',
        ], "AND click_time = $at");
        self::assertColumns('202_clicks_spy', $clickId, $clickRow + [
            'click_cpc' => '0.75', 'click_payout' => '1.25',
        ], "AND click_time = $at");
        self::assertColumns('202_clicks_advance', $clickId, [
            'text_ad_id' => '33', 'keyword_id' => '6', 'ip_id' => '23', 'country_id' => '22', 'region_id' => '24',
            'isp_id' => '29', 'city_id' => '25', 'platform_id' => '26', 'browser_id' => '27', 'device_id' => '28',
        ]);
        self::assertColumns('202_clicks_tracking', $clickId, [
            'c1_id' => '91', 'c2_id' => '92', 'c3_id' => '93', 'c4_id' => '94', 'customer_id' => '4242',
        ]);
        self::assertColumns('202_clicks_rotator', $clickId, [
            'rotator_id' => '17', 'rule_id' => '18', 'rule_redirect_id' => '19',
        ]);
        self::assertColumns('202_clicks_record', $clickId, [
            'click_id_public' => '512345', 'click_cloaking' => '0', 'click_in' => '1', 'click_out' => '1',
            'click_reviewed' => '1',
        ]);
        self::assertColumns('202_clicks_site', $clickId, [
            'click_referer_site_url_id' => '50', 'click_landing_site_url_id' => '41',
            'click_outbound_site_url_id' => '51', 'click_cloaking_site_url_id' => '43',
            'click_redirect_site_url_id' => '52',
        ]);
        self::assertColumns('202_clicks_variable', $clickId, ['variable_set_id' => '8']);
        self::assertColumns('202_google', $clickId, ['gclid' => "Cj0'quote\\back", 'utm_source_id' => '31']);
        self::assertSame(1, self::marks($clickId));
    }

    /** A redirector click re-clicked through another rule takes the new route in both places it is kept. */
    public function testAReClickThroughAnotherRuleTakesItsRoute(): void
    {
        $clickId = self::FIRST_CLICK + 14;
        self::repo()->recordClick(self::redirectorClick($clickId), static fn (): int => 52);
        $at = time() + 60;

        self::repo()->recordReClick(self::reClickOf($clickId, $at), static fn (): int => 53);

        self::assertColumns('202_clicks_rotator', $clickId, [
            'rotator_id' => '17', 'rule_id' => '18', 'rule_redirect_id' => '19',
        ]);
        self::assertColumns('202_clicks', $clickId, ['rotator_id' => '17', 'rule_id' => '19'], "AND click_time = $at");
    }

    /** The redirector sets click_in: a click that was not in is, once re-clicked. */
    public function testAReClickRewritesTheInFlag(): void
    {
        $clickId = self::FIRST_CLICK + 12;
        self::repo()->recordClick(
            new ClickRecord(clickId: $clickId, userId: self::USER, clickTime: time(), clickIn: 0)
        );

        self::repo()->recordReClick(self::reClickOf($clickId, time()), static fn (): int => 52);

        self::assertColumns('202_clicks_record', $clickId, ['click_in' => '1', 'click_out' => '1']);
    }

    /** A cloaked re-click of a click with no public id stores the one it brings, and its cl.php URL. */
    public function testACloakedReClickStoresThePublicIdItBrings(): void
    {
        $clickId = self::FIRST_CLICK + 7;
        self::repo()->recordClick(self::redirectorClick($clickId), static fn (): int => 52);

        $reClick = self::redirectorClick($clickId, publicId: '799030108', cloakingSiteUrlId: 60);
        self::repo()->recordReClick($reClick, static fn (): int => 53);

        self::assertColumns('202_clicks_record', $clickId, ['click_id_public' => '799030108', 'click_cloaking' => '1']);
        self::assertColumns('202_clicks_site', $clickId, [
            'click_cloaking_site_url_id' => '60', 'click_redirect_site_url_id' => '53',
        ]);
    }

    /** rtr.php finds the click by address; one with no keyed rows gets them. */
    public function testAReClickOfAClickWithNoKeyedRowsWritesThem(): void
    {
        $clickId = self::FIRST_CLICK + 8;
        self::q('INSERT INTO 202_clicks SET click_id = ' . $clickId . ', user_id = ' . self::USER
            . ', ppc_account_id = 0, click_cpc = 0, click_time = ' . (time() - 60));

        self::repo()->recordReClick(self::redirectorClick($clickId), static fn (): int => 52);

        $keyed = ['202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
            '202_clicks_record', '202_clicks_site'];
        self::assertSame(array_merge(self::rowsOf($keyed), ['202_clicks' => 2]), self::rows($clickId));
        self::assertColumns('202_clicks_tracking', $clickId, ['c1_id' => '91', 'customer_id' => null]);
    }

    public function testAFailedReClickLeavesTheClickAsItWasAndUnmarked(): void
    {
        $clickId = self::FIRST_CLICK + 9;
        self::repo()->recordClick(self::redirectorClick($clickId, 5), static fn (): int => 52);

        try {
            self::repo()->recordReClick(self::redirectorClick($clickId, 6), static fn (): int => -1);
            self::fail('the refused statement reaches the caller');
        } catch (QueryException $e) {
            self::assertStringContainsString('click_redirect_site_url_id', $e->getMessage());
        }

        self::assertSame(
            self::rowsOf(['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
                '202_clicks_rotator', '202_clicks_record', '202_clicks_site']),
            self::rows($clickId)
        );
        self::assertColumns('202_clicks_advance', $clickId, ['keyword_id' => '5']);
        self::assertSame(0, self::marks($clickId));
    }

    public function testOnlyTheRedirectorReClicks(): void
    {
        $clickId = self::FIRST_CLICK + 11;

        try {
            self::repo()->recordReClick(
                new ClickRecord(clickId: $clickId, userId: self::USER, clickTime: time()),
                static fn (): int => 52
            );
            self::fail('a re-click off the redirector is refused');
        } catch (LogicException $e) {
            self::assertSame('Only the redirector re-clicks a click', $e->getMessage());
        }

        self::assertSame(self::rowsOf([]), self::rows($clickId));
        self::assertSame(0, self::marks($clickId));
    }

    /** A record with a route that would write campaign rows is no redirector click either. */
    public function testARoutedClickWithCampaignRowsIsNoReClick(): void
    {
        $clickId = self::FIRST_CLICK + 13;

        try {
            self::repo()->recordReClick(
                new ClickRecord(
                    clickId: $clickId,
                    userId: self::USER,
                    clickTime: time(),
                    rotator: new RotatorRoute(7, 8, 9),
                ),
                static fn (): int => 52
            );
            self::fail('a re-click that would write campaign rows is refused');
        } catch (LogicException $e) {
            self::assertSame('Only the redirector re-clicks a click', $e->getMessage());
        }

        self::assertSame(self::rowsOf([]), self::rows($clickId));
    }

    /** Every allocated id is noted for the redirect to sign (RecordedClicks), and each is new. */
    public function testAnAllocatedIdIsNotedAndNew(): void
    {
        \Prosper202\Click\RecordedClicks::reset();
        $first = self::repo()->allocateClickId();
        $second = self::repo()->allocateClickId();

        self::assertGreaterThan($first, $second);
        self::assertTrue(\Prosper202\Click\RecordedClicks::has($first));
        self::assertTrue(\Prosper202\Click\RecordedClicks::has($second));
        self::assertFalse(\Prosper202\Click\RecordedClicks::has($first - 1), 'only the ids allocated');
        \Prosper202\Click\RecordedClicks::reset();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Click;

use LogicException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\LastClickFromAddress;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * Every path's click rows, written by the one writer on a real server in the
 * strict mode a MySQL 8 server runs with: a new click's rows commit together
 * or not at all, the redirector's redirect URL is resolved inside the
 * transaction from the rows already written, and a re-click (rtr.php?lpr=)
 * rewrites what the redirector sets and keeps every other column the click
 * has — the REPLACE it replaced reset its customer, its reviewed flag, its
 * public id and its landing and cloaking URLs. A re-click's row takes the
 * first visit's traffic source, cost and filtered and bot flags, and the
 * click keeps its keyword and the c1-c4 the re-click does not bring.
 *
 * @group integration
 */
final class MysqlClickRepositoryIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990301;
    /** The account of the click found by address, alone in its window. */
    private const BY_ADDRESS_USER = 990303;
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
     * time with the first visit's traffic source, cost and flags, the redirector's
     * columns rewritten, the c1-c4 it brings stored, and the customer a
     * conversion set, the reviewed flag, the public id its pci.php links
     * carry, its landing and cloaking URLs, its keyword and its text ad (the
     * redirector records none) kept. The redirect is resolved from the
     * rewritten rows, before the site row takes its answer.
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
            [['keyword_id' => '5', 'c2_id' => '92'], ['click_redirect_site_url_id' => '44']],
            $seenInside,
            'the resolver reads the rewritten rows, before the site row takes its answer'
        );
        // The link's source is 13 at 0.75, and the visit filtered as a bot:
        // the first visit's, 3 at 0.125 and neither, are the click's.
        $clickRow = [
            'user_id' => (string) self::USER, 'aff_campaign_id' => '14', 'landing_page_id' => '16',
            'ppc_account_id' => '3', 'click_filtered' => '0', 'click_bot' => '0', 'click_alp' => '0',
            'click_lead' => '0',
        ];
        self::assertColumns('202_clicks', $clickId, $clickRow + [
            'click_cpc' => '0.12500', 'click_payout' => '1.25000', 'rotator_id' => '17', 'rule_id' => '19',
        ], "AND click_time = $at");
        self::assertColumns('202_clicks_spy', $clickId, $clickRow + [
            'click_cpc' => '0.13', 'click_payout' => '1.25',
        ], "AND click_time = $at");
        self::assertColumns('202_clicks_advance', $clickId, [
            'text_ad_id' => '33', 'keyword_id' => '5', 'ip_id' => '23', 'country_id' => '22', 'region_id' => '24',
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

    /**
     * rtr.php finds the click by address; one with no keyed rows gets them,
     * and its row the first visit's source, cost (none) and flags.
     */
    public function testAReClickOfAClickWithNoKeyedRowsWritesThem(): void
    {
        $clickId = self::FIRST_CLICK + 8;
        $first = time() - 60;
        self::q('INSERT INTO 202_clicks SET click_id = ' . $clickId . ', user_id = ' . self::USER
            . ', ppc_account_id = 0, click_cpc = 0, click_time = ' . $first);

        self::repo()->recordReClick(self::redirectorClick($clickId), static fn (): int => 52);

        $keyed = ['202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
            '202_clicks_record', '202_clicks_site'];
        self::assertSame(array_merge(self::rowsOf($keyed), ['202_clicks' => 2]), self::rows($clickId));
        self::assertColumns('202_clicks', $clickId, [
            'ppc_account_id' => '0', 'click_cpc' => '0.00000', 'click_filtered' => '0',
        ], "AND click_time > $first");
        self::assertColumns('202_clicks_advance', $clickId, ['keyword_id' => '5', 'ip_id' => '13']);
        self::assertColumns('202_clicks_tracking', $clickId, [
            'c1_id' => '91', 'c2_id' => '0', 'customer_id' => null,
        ]);
    }

    /** A redirector click from source 3 at 0.25, with c1-c4 81-84, ten minutes ago. */
    private static function trackedClick(int $clickId, int $userId = self::USER, int $ago = 600): ClickRecord
    {
        return new ClickRecord(
            clickId: $clickId,
            userId: $userId,
            clickTime: time() - $ago,
            ppcAccountId: 3,
            clickCpc: '0.25',
            keywordId: 5,
            ipId: 13,
            c1Id: 81,
            c2Id: 82,
            c3Id: 83,
            c4Id: 84,
            recordsCampaignRows: false,
            rotator: new RotatorRoute(7, 8, 9),
        );
    }

    /**
     * A re-click through the link's source 13 at 0.75, bringing the c1-c4 ids given (0: none).
     *
     * @param array{int, int, int, int} $c
     */
    private static function reClickBringing(
        int $clickId,
        int $clickTime,
        array $c = [0, 0, 0, 0],
        int $userId = self::USER,
    ): ClickRecord {
        return new ClickRecord(
            clickId: $clickId,
            userId: $userId,
            clickTime: $clickTime,
            affCampaignId: 14,
            ppcAccountId: 13,
            clickCpc: '0.75',
            clickPayout: '1.25',
            keywordId: 6,
            ipId: 23,
            c1Id: $c[0],
            c2Id: $c[1],
            c3Id: $c[2],
            c4Id: $c[3],
            recordsCampaignRows: false,
            clickOut: 1,
            rotator: new RotatorRoute(17, 18, 19),
        );
    }

    /**
     * A visit's rows as a re-click wrote them before it kept the first
     * visit's: the link's source and cost, and no lead unless one is given.
     */
    private static function linksVisit(
        int $clickId,
        int $time,
        int $userId = self::USER,
        int $lead = 0,
        string $payout = '0',
    ): void {
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $clickId, user_id = $userId, aff_campaign_id = 14,"
                . " ppc_account_id = 13, click_cpc = 0.75, click_lead = $lead, click_payout = $payout,"
                . " click_time = $time");
        }
    }

    /** @return array<string, string> c1_id..c4_id as the click holds them */
    private static function cIds(int $clickId): array
    {
        return self::one("SELECT c1_id, c2_id, c3_id, c4_id FROM 202_clicks_tracking WHERE click_id = $clickId");
    }

    public function testAReClickBringingNoC1ToC4KeepsTheClicks(): void
    {
        $clickId = self::FIRST_CLICK + 20;
        self::repo()->recordClick(self::trackedClick($clickId), static fn (): int => 52);

        self::repo()->recordReClick(self::reClickBringing($clickId, time()), static fn (): int => 53);

        self::assertSame(['c1_id' => '81', 'c2_id' => '82', 'c3_id' => '83', 'c4_id' => '84'], self::cIds($clickId));
    }

    public function testAReClickBringingItsOwnC2ReplacesOnlyC2(): void
    {
        $clickId = self::FIRST_CLICK + 21;
        self::repo()->recordClick(self::trackedClick($clickId), static fn (): int => 52);

        self::repo()->recordReClick(self::reClickBringing($clickId, time(), [0, 92, 0, 0]), static fn (): int => 53);

        self::assertSame(['c1_id' => '81', 'c2_id' => '92', 'c3_id' => '83', 'c4_id' => '84'], self::cIds($clickId));
    }

    /**
     * The click's latest row is an earlier re-click's, written with the
     * link's source and cost: the next re-click takes the first visit's.
     */
    public function testAReClickTakesTheFirstVisitsSourceOverAnEarlierReClicksRow(): void
    {
        $clickId = self::FIRST_CLICK + 22;
        self::repo()->recordClick(self::trackedClick($clickId), static fn (): int => 52);
        self::linksVisit($clickId, time() - 300);
        $at = time() + 60;

        self::repo()->recordReClick(self::reClickBringing($clickId, $at), static fn (): int => 53);

        $source = ['ppc_account_id' => '3', 'aff_campaign_id' => '14'];
        self::assertColumns('202_clicks', $clickId, $source + ['click_cpc' => '0.25000'], "AND click_time = $at");
        self::assertColumns('202_clicks_spy', $clickId, $source + ['click_cpc' => '0.25'], "AND click_time = $at");
    }

    /**
     * @return iterable<string, array{array{int, string}, array{int, string}, string, string}> the first
     *     visit's and a later row's [lead, payout], and the payout the re-click's row is given in
     *     202_clicks and in 202_clicks_spy
     */
    public static function leads(): iterable
    {
        yield 'a row written since the click was last recomputed is no lead'
            => [[1, '4.50'], [0, '0'], '4.50000', '4.50'];
        yield 'of two leads before the ledger, the latest' => [[1, '4.50'], [1, '5.00'], '5.00000', '5.00'];
    }

    /**
     * A click that is a lead holds its lead and value on every row the
     * ledger last recomputed (MysqlConversionLedger::recompute()): a
     * re-click's row is given them, from the latest visit that is a lead, as
     * the ledger carries a pre-ledger lead's in (ensureManaged()).
     *
     * @dataProvider leads
     * @param array{int, string} $first
     * @param array{int, string} $later
     */
    public function testAReClickOfALeadIsALeadWithItsValue(
        array $first,
        array $later,
        string $payout,
        string $spy
    ): void {
        $clickId = self::FIRST_CLICK + 25;
        foreach ([...self::TABLES, '202_attribution_rollup_dirty_clicks'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = $clickId");
        }
        self::repo()->recordClick(self::trackedClick($clickId), static fn (): int => 52);
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("UPDATE $table SET click_lead = {$first[0]}, click_payout = {$first[1]} WHERE click_id = $clickId");
        }
        self::linksVisit($clickId, time() - 300, lead: $later[0], payout: $later[1]);
        $at = time() + 60;

        self::repo()->recordReClick(self::reClickBringing($clickId, $at), static fn (): int => 53);

        $row = "AND click_time = $at";
        self::assertColumns('202_clicks', $clickId, ['click_lead' => '1', 'click_payout' => $payout], $row);
        self::assertColumns('202_clicks_spy', $clickId, ['click_lead' => '1', 'click_payout' => $spy], $row);
    }

    /** @return iterable<string, array{array{int, int}, array{int, int}}> first visit's and re-click's [filtered, bot] */
    public static function arrivalFlags(): iterable
    {
        yield 'a re-click the filter calls a duplicate and a bot, of a clean click' => [[0, 0], [1, 1]];
        yield 'a clean re-click of a filtered click' => [[1, 0], [0, 1]];
        yield 'a filtered re-click of a click recorded as a bot, not filtered' => [[0, 1], [1, 0]];
    }

    /**
     * Whether a click is a duplicate or a bot is its arrival's: rtr.php's
     * duplicate filter calls every re-click from its click's address a
     * second arrival, and the visit's own verdicts are not stored.
     *
     * @dataProvider arrivalFlags
     * @param array{int, int} $first
     * @param array{int, int} $visit
     */
    public function testAReClickKeepsTheFirstVisitsFilteredAndBotFlags(array $first, array $visit): void
    {
        $clickId = self::FIRST_CLICK + 24;
        foreach ([...self::TABLES, '202_attribution_rollup_dirty_clicks'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = $clickId");
        }
        $time = time() - 600;
        self::q("INSERT INTO 202_clicks SET click_id = $clickId, user_id = " . self::USER . ', ppc_account_id = 3,'
            . " click_cpc = 0.25, click_filtered = {$first[0]}, click_bot = {$first[1]}, click_time = $time");
        $at = time();

        self::repo()->recordReClick(
            new ClickRecord(
                clickId: $clickId,
                userId: self::USER,
                clickTime: $at,
                ppcAccountId: 13,
                clickCpc: '0.75',
                clickFiltered: $visit[0],
                clickBot: $visit[1],
                recordsCampaignRows: false,
                rotator: new RotatorRoute(17, 18, 19),
            ),
            static fn (): int => 53
        );

        $flags = ['click_filtered' => (string) $first[0], 'click_bot' => (string) $first[1]];
        self::assertColumns('202_clicks', $clickId, $flags, "AND click_time = $at");
        self::assertColumns('202_clicks_spy', $clickId, $flags, "AND click_time = $at");
    }

    /**
     * rtr.php finds the click by a visit inside LastClickFromAddress's 30
     * days, and the click's first visit, 40 days back, is still the one its
     * source and cost are taken from: by the lookup, and by the re-click.
     */
    public function testAClickFirstSeenBeforeTheWindowIsReClickedWithItsFirstVisitsSource(): void
    {
        $clickId = self::FIRST_CLICK + 23;
        $conn = new Connection(self::requireScratch());
        $address = '198.51.100.123';
        $first = new ClickRecord(
            clickId: $clickId,
            userId: self::BY_ADDRESS_USER,
            clickTime: time() - 40 * 86400,
            ppcAccountId: 3,
            clickCpc: '0.25',
            keywordId: 5,
            ipId: (new MysqlLocationRepository($conn))->findOrCreateIp($address),
            recordsCampaignRows: false,
            rotator: new RotatorRoute(7, 8, 9),
        );
        self::repo()->recordClick($first, static fn (): int => 52);
        self::linksVisit($clickId, time() - 10 * 86400, self::BY_ADDRESS_USER);

        $found = LastClickFromAddress::find($conn, $address, self::BY_ADDRESS_USER, time() - 2592000, false);
        self::assertSame(
            ['click_id' => $clickId, 'ppc_account_id' => 3, 'click_id_public' => '0', 'keyword_id' => 5],
            $found,
            'found by the visit 10 days back, with the first visit\'s source'
        );
        $at = time() + 60;
        self::repo()->recordReClick(
            self::reClickBringing($clickId, $at, userId: self::BY_ADDRESS_USER),
            static fn (): int => 53
        );

        self::assertColumns('202_clicks', $clickId, [
            'ppc_account_id' => '3', 'click_cpc' => '0.25000',
        ], "AND click_time = $at");
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

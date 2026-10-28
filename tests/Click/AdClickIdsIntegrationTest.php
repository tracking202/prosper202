<?php

declare(strict_types=1);

namespace Tests\Click;

use Api\V3\Controllers\ClicksController;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdColumns;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\ClickRequest;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * A click's ad click ids (gclid, gbraid, wbraid, ttclid, msclkid, fbclid) on
 * a real server in the strict mode a MySQL 8 server runs with: read from the
 * request as the click scripts read them, stored as they came (cut to their
 * columns, in characters), and read back by the API, the traffic source
 * pixels' tokens and the token read the click path makes, with one "none":
 * the API's null, a token's ''. A ttclid is measured at 337 characters and
 * an fbclid at 200, more than the gclid's 150, and each is kept whole.
 *
 * @group integration
 */
final class AdClickIdsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990401;
    private const FIRST_CLICK = 99040101;

    private const GCLID = 'CjwKCAjwlbu2BhA3EiwA3yXyu3uy-Qsj7kZJBAglX5PQ8RfvBvZye9QVCmsV5CjLklkDgoHgcivQpBoCfzgQAvD_BwE';
    private const GBRAID = '0AAAAApp6U2mDWVO0HLPFIpTPe4XNESLc_';
    private const WBRAID = 'Cj8KCQjw8rW2BhD9ARIuAFWUJq8MotJsyHASXlAJUfdRtOmCfEOXuYOy4Ss-8nEi054Pk4tiN4hrohxJcRoCOfs';
    private const MSCLKID = '9a3f6c1d2b7e4f08a1c5d9e3b6f2a7c4';

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
        foreach ([...self::TABLES, '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q('DROP TRIGGER IF EXISTS p202_test_google_refused');
    }

    /** A real ttclid's shape and length, 337 characters. */
    private static function ttclid(): string
    {
        return 'E.C.P.' . str_repeat('Cs0BrH-fsgVGkAOH', 20) . 'wv7TIPFQW9W';
    }

    /** A real fbclid's shape and length, 200 characters, its case mixed. */
    private static function fbclid(): string
    {
        return 'IwY2xjaw' . str_repeat('Fbc1d-Shape_aBcD', 11) . '_aem_0IiQMcYBgFH';
    }

    /**
     * A landing page click with the request's ids, as record_simple.php
     * reads and builds it.
     *
     * @param array<string, mixed> $get
     */
    private static function record(int $clickId, array $get): void
    {
        $mysql = ['user_id' => (string) self::USER, 'click_time' => (string) time(), 'click_cpc' => '0.10000'];
        foreach (ClickRequest::adClickIds($get) as $name => $id) {
            $mysql[$name] = $id;
        }
        (new MysqlClickRepository(new Connection(self::requireScratch())))
            ->recordClick(ClickRecordBuilder::fromLegacyArray($mysql, $clickId));
    }

    /** @return array<string, string|null> */
    private static function stored(int $clickId): array
    {
        $result = self::requireScratch()->query(
            "SELECT gclid, gbraid, wbraid, ttclid, msclkid, fbclid FROM 202_google WHERE click_id = $clickId"
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, "click $clickId has one 202_google row");

        return $rows[0];
    }

    public function testAClickKeepsItsIdsAsTheyCame(): void
    {
        $clickId = self::FIRST_CLICK;
        $ids = self::allIds();
        self::record($clickId, $ids + ['c1' => 'other']);

        self::assertSame($ids, self::stored($clickId));
        self::assertSame(
            array_diff_key($ids, ['gclid' => true]),
            AdClickIdColumns::of(new Connection(self::$db), $clickId),
            'the read the click path makes of the ids 1.9.79 added'
        );
        self::assertSame(
            ['gbraid' => '', 'wbraid' => '', 'ttclid' => '', 'msclkid' => '', 'fbclid' => ''],
            AdClickIdColumns::of(new Connection(self::$db), self::FIRST_CLICK + 98),
            'a click with no row has none'
        );
    }

    /** @return array<string, string> */
    private static function allIds(): array
    {
        return [
            'gclid' => self::GCLID, 'gbraid' => self::GBRAID, 'wbraid' => self::WBRAID, 'ttclid' => self::ttclid(),
            'msclkid' => self::MSCLKID, 'fbclid' => self::fbclid(),
        ];
    }

    /**
     * What the click path refuses nothing for, being unable to refuse: an id
     * past its column is stored cut to it, in characters; a list (gbraid[]=)
     * and an absent one are none; a byte that is no UTF-8 is '?', as for the
     * gclid. Strict mode would have refused the uncut value and lost the click.
     */
    public function testAnOverLongOrMalformedIdIsStoredAsTheGclidIs(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        self::record($clickId, [
            'gclid' => str_repeat('g', 151),
            'gbraid' => ['0AAAA'],
            'wbraid' => "Cj8\xFFbad",
            'ttclid' => str_repeat('é', 1000) . 'tail',
            'msclkid' => ['9a3f'],
            'fbclid' => str_repeat('é', 999) . "\xFFtail",
        ]);

        self::assertSame([
            'gclid' => str_repeat('g', 150),
            'gbraid' => '',
            'wbraid' => 'Cj8?bad',
            'ttclid' => str_repeat('é', 1000),
            'msclkid' => '',
            'fbclid' => str_repeat('é', 999) . '?',
        ], self::stored($clickId));

        $clickId = self::FIRST_CLICK + 8;
        self::record($clickId, ['msclkid' => str_repeat('m', 151), 'fbclid' => ['IwAR']]);
        self::assertSame(
            [str_repeat('m', 150), ''],
            [self::stored($clickId)['msclkid'], self::stored($clickId)['fbclid']]
        );
    }

    /**
     * Only the server's 1054 for one of the added columns is the window
     * before the upgrade (AdClickIdColumns): any other refusal of the row
     * fails the click, every row of it, rather than storing it without its
     * ids.
     */
    public function testAnyOtherRefusalOfTheRowFailsTheClick(): void
    {
        $clickId = self::FIRST_CLICK + 7;
        self::q('DROP TRIGGER IF EXISTS p202_test_google_refused');
        self::q("CREATE TRIGGER p202_test_google_refused BEFORE INSERT ON 202_google FOR EACH ROW"
            . " IF NEW.ttclid <> '' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'; END IF");
        try {
            self::record($clickId, ['ttclid' => self::ttclid()]);
            self::fail('a refused 202_google row failed nothing');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refused by the test', $e->getMessage());
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_google_refused');
        }

        foreach (self::TABLES as $table) {
            $found = self::$db->query("SELECT COUNT(*) AS n FROM $table WHERE click_id = $clickId");
            self::assertInstanceOf(\mysqli_result::class, $found, self::$db->error);
            self::assertSame('0', (string) $found->fetch_assoc()['n'], "$table kept none of the click");
        }
    }

    /** A click id's case is part of it: the columns compare byte for byte. */
    public function testTheColumnsCompareByteForByte(): void
    {
        $result = self::requireScratch()->query(
            "SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '202_google' AND COLUMN_NAME IN ('gbraid', 'wbraid', 'ttclid', 'msclkid', 'fbclid')"
            . ' ORDER BY COLUMN_NAME'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        self::assertSame(
            [
                'fbclid' => 'utf8mb4_bin', 'gbraid' => 'utf8mb4_bin', 'msclkid' => 'utf8mb4_bin',
                'ttclid' => 'utf8mb4_bin', 'wbraid' => 'utf8mb4_bin',
            ],
            array_column($result->fetch_all(MYSQLI_ASSOC), 'COLLATION_NAME', 'COLUMN_NAME')
        );

        $clickId = self::FIRST_CLICK + 2;
        self::record($clickId, ['gbraid' => 'AbC']);
        $found = self::$db->query("SELECT COUNT(*) AS n FROM 202_google WHERE click_id = $clickId AND gbraid = 'abc'");
        self::assertInstanceOf(\mysqli_result::class, $found, self::$db->error);
        self::assertSame('0', (string) $found->fetch_assoc()['n'], "'abc' is not 'AbC'");
        // Meta says its click id is case sensitive.
        $clickId = self::FIRST_CLICK + 9;
        self::record($clickId, ['fbclid' => 'IwAR2F4-dbP0']);
        $found = self::$db->query(
            "SELECT COUNT(*) AS n FROM 202_google WHERE click_id = $clickId AND fbclid = 'iwar2f4-dbp0'"
        );
        self::assertInstanceOf(\mysqli_result::class, $found, self::$db->error);
        self::assertSame('0', (string) $found->fetch_assoc()['n'], "'iwar2f4-dbp0' is not 'IwAR2F4-dbP0'");
    }

    /**
     * GET /clicks and /clicks/{id} serve each id, and null for none: a click
     * stored without one ('') and a redirector's click, which has no
     * 202_google row, alike.
     */
    public function testTheApiServesEachIdAndNullForNone(): void
    {
        $with = self::FIRST_CLICK + 3;
        $gclidOnly = self::FIRST_CLICK + 4;
        $redirector = self::FIRST_CLICK + 5;
        self::record($with, self::allIds());
        self::record($gclidOnly, ['gclid' => self::GCLID]);
        (new MysqlClickRepository(new Connection(self::$db)))->recordClick(
            new ClickRecord(
                clickId: $redirector,
                userId: self::USER,
                clickTime: time(),
                recordsCampaignRows: false,
                rotator: new RotatorRoute(7, 8, 9),
            ),
            static fn (): int => 0
        );

        $clicks = new ClicksController(self::$db, self::USER);
        $pick = static fn (array $row): array => array_intersect_key($row, self::allIds());
        $expected = [
            $with => self::allIds(),
            $gclidOnly => ['gclid' => self::GCLID] + array_fill_keys(AdClickIdColumns::ADDED, null),
            $redirector => array_fill_keys(array_keys(ClickRecord::AD_CLICK_IDS), null),
        ];
        $listed = array_column($clicks->list(['limit' => 500])['data'], null, 'click_id');
        foreach ($expected as $clickId => $ids) {
            self::assertSame($ids, $pick($clicks->get($clickId)['data']), "GET /clicks/$clickId");
            self::assertArrayHasKey($clickId, $listed);
            self::assertSame($ids, $pick($listed[$clickId]), "GET /clicks, click $clickId");
        }
    }

    /**
     * 202_google's key is (click_id, gclid), so a click can have two rows.
     * GET /clicks serves it once, as many rows as it counts, page by page;
     * it, GET /clicks/{id} and the token read name the same ids, each one
     * any row has.
     */
    public function testAClickWithTwoGoogleRowsIsServedOnceWithEachId(): void
    {
        $twice = self::FIRST_CLICK + 20;
        self::record($twice, ['gclid' => self::GCLID, 'wbraid' => self::WBRAID]);
        self::record(self::FIRST_CLICK + 21, ['ttclid' => self::ttclid()]);
        self::q("INSERT INTO 202_google SET click_id = $twice, gclid = '', gbraid = '" . self::GBRAID . "',"
            . " wbraid = '', ttclid = '', msclkid = '" . self::MSCLKID . "', fbclid = '', utm_source_id = 0,"
            . ' utm_medium_id = 0, utm_campaign_id = 0, utm_term_id = 0, utm_content_id = 0');

        $clicks = new ClicksController(self::$db, self::USER);
        $list = $clicks->list(['limit' => '500']);
        $total = $list['pagination']['total'];
        $listed = array_map('intval', array_column($list['data'], 'click_id'));
        self::assertCount($total, $listed, 'the list serves as many rows as it counts');
        self::assertSame($listed, array_values(array_unique($listed)), 'each click once');
        $ids = [
            'gclid' => self::GCLID, 'gbraid' => self::GBRAID, 'wbraid' => self::WBRAID, 'ttclid' => null,
            'msclkid' => self::MSCLKID, 'fbclid' => null,
        ];
        self::assertSame($ids, array_intersect_key($clicks->get($twice)['data'], $ids), 'GET /clicks/{id}');
        $row = array_column($list['data'], null, 'click_id')[$twice];
        self::assertSame($ids, array_intersect_key($row, $ids), 'GET /clicks names the ids GET /clicks/{id} does');

        $paged = [];
        for ($offset = 0; $offset < $total; $offset++) {
            $page = $clicks->list(['limit' => '1', 'offset' => (string) $offset])['data'];
            self::assertCount(1, $page, "page $offset");
            $paged[] = (int) $page[0]['click_id'];
        }
        self::assertSame($listed, $paged, 'a page at a time is the list');

        self::assertSame(
            ['gbraid' => self::GBRAID, 'wbraid' => self::WBRAID, 'ttclid' => '', 'msclkid' => self::MSCLKID, 'fbclid' => ''],
            AdClickIdColumns::of(new Connection(self::$db), $twice),
            'the token read names the same ids'
        );
    }

    /**
     * The tokens a traffic source's pixels are sent with: each id, '' for
     * none, encoded once into the URL.
     */
    public function testATrafficSourcesPixelTokensCarryEachId(): void
    {
        $clickId = self::FIRST_CLICK + 6;
        self::record($clickId, [
            'gbraid' => self::GBRAID, 'ttclid' => self::ttclid(),
            'msclkid' => self::MSCLKID, 'fbclid' => self::fbclid(),
        ]);

        $click = TrafficSourcePixels::clickTokens(new Connection(self::$db), self::USER, $clickId);
        self::assertNotNull($click);
        self::assertSame(
            [
                'gclid' => '', 'gbraid' => self::GBRAID, 'wbraid' => '', 'ttclid' => self::ttclid(),
                'msclkid' => self::MSCLKID, 'fbclid' => self::fbclid(),
            ],
            array_intersect_key($click['tokens'], self::allIds())
        );
        self::assertSame(
            'https://ads.example/s2s?gb=' . self::GBRAID . '&wb=&tt=' . self::ttclid() . '&ms=' . self::MSCLKID
                . '&fb=' . self::fbclid(),
            TrafficSourcePixels::replaceTokens(
                'https://ads.example/s2s?gb=[[gbraid]]&wb=[[wbraid]]&tt=[[TTCLID]]&ms=[[msclkid]]&fb=[[fbclid]]',
                $click['tokens']
            )
        );
    }
}

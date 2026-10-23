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
 * A click's ad click ids (gclid, gbraid, wbraid, ttclid) on a real server in
 * the strict mode a MySQL 8 server runs with: read from the request as the
 * click scripts read them, stored as they came (cut to their columns, in
 * characters), and read back by the API, the traffic source pixels' tokens
 * and the token read the click path makes, with one "none": the API's null,
 * a token's ''. A ttclid is measured at 337 characters, more than the
 * gclid's 150, and is kept whole.
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
            "SELECT gclid, gbraid, wbraid, ttclid FROM 202_google WHERE click_id = $clickId"
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
            ['gbraid' => '', 'wbraid' => '', 'ttclid' => ''],
            AdClickIdColumns::of(new Connection(self::$db), self::FIRST_CLICK + 98),
            'a click with no row has none'
        );
    }

    /** @return array<string, string> */
    private static function allIds(): array
    {
        return ['gclid' => self::GCLID, 'gbraid' => self::GBRAID, 'wbraid' => self::WBRAID, 'ttclid' => self::ttclid()];
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
        ]);

        self::assertSame([
            'gclid' => str_repeat('g', 150),
            'gbraid' => '',
            'wbraid' => 'Cj8?bad',
            'ttclid' => str_repeat('é', 1000),
        ], self::stored($clickId));
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
            . " AND TABLE_NAME = '202_google' AND COLUMN_NAME IN ('gbraid', 'wbraid', 'ttclid') ORDER BY COLUMN_NAME"
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        self::assertSame(
            ['gbraid' => 'utf8mb4_bin', 'ttclid' => 'utf8mb4_bin', 'wbraid' => 'utf8mb4_bin'],
            array_column($result->fetch_all(MYSQLI_ASSOC), 'COLLATION_NAME', 'COLUMN_NAME')
        );

        $clickId = self::FIRST_CLICK + 2;
        self::record($clickId, ['gbraid' => 'AbC']);
        $found = self::$db->query("SELECT COUNT(*) AS n FROM 202_google WHERE click_id = $clickId AND gbraid = 'abc'");
        self::assertInstanceOf(\mysqli_result::class, $found, self::$db->error);
        self::assertSame('0', (string) $found->fetch_assoc()['n'], "'abc' is not 'AbC'");
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
            $gclidOnly => ['gclid' => self::GCLID, 'gbraid' => null, 'wbraid' => null, 'ttclid' => null],
            $redirector => ['gclid' => null, 'gbraid' => null, 'wbraid' => null, 'ttclid' => null],
        ];
        $listed = array_column($clicks->list(['limit' => 500])['data'], null, 'click_id');
        foreach ($expected as $clickId => $ids) {
            self::assertSame($ids, $pick($clicks->get($clickId)['data']), "GET /clicks/$clickId");
            self::assertArrayHasKey($clickId, $listed);
            self::assertSame($ids, $pick($listed[$clickId]), "GET /clicks, click $clickId");
        }
    }

    /**
     * The tokens a traffic source's pixels are sent with: each id, '' for
     * none, encoded once into the URL.
     */
    public function testATrafficSourcesPixelTokensCarryEachId(): void
    {
        $clickId = self::FIRST_CLICK + 6;
        self::record($clickId, ['gbraid' => self::GBRAID, 'ttclid' => self::ttclid()]);

        $click = TrafficSourcePixels::clickTokens(new Connection(self::$db), self::USER, $clickId);
        self::assertNotNull($click);
        self::assertSame(
            ['gclid' => '', 'gbraid' => self::GBRAID, 'wbraid' => '', 'ttclid' => self::ttclid()],
            array_intersect_key($click['tokens'], self::allIds())
        );
        self::assertSame(
            'https://ads.example/s2s?gb=' . self::GBRAID . '&wb=&tt=' . self::ttclid(),
            TrafficSourcePixels::replaceTokens(
                'https://ads.example/s2s?gb=[[gbraid]]&wb=[[wbraid]]&tt=[[TTCLID]]',
                $click['tokens']
            )
        );
    }
}

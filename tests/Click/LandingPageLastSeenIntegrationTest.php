<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\LandingPageLastSeen;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;

/**
 * When a landing page last had a click (202_landing_page_last_seen), written
 * by the click writer on a real server: a new click and a re-click note
 * their page, the time moves at most once a WINDOW and never back, a page
 * of another account is never noted for the click's account, and a note
 * that cannot be written costs the note, logged, and never the click.
 *
 * @group integration
 */
final class LandingPageLastSeenIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990701;
    private const OTHER = 990702;
    private const FIRST_CLICK = 99070101;

    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
        '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
        '202_attribution_rollup_dirty_clicks', '202_click_latest_visits', '202_click_consent_refusals',
    ];

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
        }
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
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::USER . ', ' . self::OTHER;
        self::q("DELETE FROM 202_landing_page_last_seen WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_landing_pages WHERE user_id IN ($users)");
    }

    private static function page(int $userId): int
    {
        return self::insert('INSERT INTO 202_landing_pages (user_id, aff_campaign_id, landing_page_nickname,'
            . " landing_page_url, landing_page_time) VALUES ($userId, 4, 'lp', 'https://lp.example', 1)");
    }

    private static function click(int $clickId, int $landingPageId, int $at): ClickRecord
    {
        return new ClickRecord(
            clickId: $clickId,
            userId: self::USER,
            clickTime: $at,
            affCampaignId: 4,
            landingPageId: $landingPageId,
            ppcAccountId: 3,
            clickCpc: '0.1',
            clickPayout: '1',
            keywordId: 5,
            ipId: 11,
            clickIdPublic: (string) $clickId,
            clickLandingSiteUrlId: 41,
        );
    }

    private static function reClick(int $clickId, int $landingPageId, int $at): ClickRecord
    {
        return new ClickRecord(
            clickId: $clickId,
            userId: self::USER,
            clickTime: $at,
            affCampaignId: 4,
            landingPageId: $landingPageId,
            ppcAccountId: 3,
            clickCpc: '0.1',
            keywordId: 5,
            ipId: 13,
            recordsCampaignRows: false,
            clickOut: 1,
            rotator: new RotatorRoute(7, 8, 9),
        );
    }

    private static function repo(): MysqlClickRepository
    {
        return new MysqlClickRepository(new Connection(self::requireScratch()));
    }

    private static function seen(int $landingPageId): ?array
    {
        $result = self::requireScratch()->query(
            "SELECT user_id, last_click_at FROM 202_landing_page_last_seen WHERE landing_page_id = $landingPageId"
        );
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);
        $row = $result->fetch_assoc();

        return $row === null ? null : array_map('intval', $row);
    }

    private static function clicks(int $clickId): int
    {
        $result = self::requireScratch()->query("SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id = $clickId");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_assoc()['n'];
    }

    public function testAClickNotesItsPageAndTheTimeMovesOnlyOnceAWindow(): void
    {
        $page = self::page(self::USER);
        $at = 1_700_000_000;
        self::repo()->recordClick(self::click(self::FIRST_CLICK, $page, $at));
        self::assertSame(['user_id' => self::USER, 'last_click_at' => $at], self::seen($page));

        // Inside the window the stored time stands.
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 1, $page, $at + LandingPageLastSeen::WINDOW - 1));
        self::assertSame($at, self::seen($page)['last_click_at']);

        self::repo()->recordClick(self::click(self::FIRST_CLICK + 2, $page, $at + LandingPageLastSeen::WINDOW));
        self::assertSame($at + LandingPageLastSeen::WINDOW, self::seen($page)['last_click_at']);

        // A click recorded late does not take the time back.
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 3, $page, $at - 3600));
        self::assertSame($at + LandingPageLastSeen::WINDOW, self::seen($page)['last_click_at']);
    }

    public function testAReClickNotesThePageItIsMadeOn(): void
    {
        $first = self::page(self::USER);
        $second = self::page(self::USER);
        $at = 1_700_100_000;
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 10, $first, $at));
        self::repo()->recordReClick(self::reClick(self::FIRST_CLICK + 10, $second, $at + 5), static fn (): int => 52);
        self::assertSame(['user_id' => self::USER, 'last_click_at' => $at + 5], self::seen($second));
        self::assertSame($at, self::seen($first)['last_click_at']);
    }

    public function testAnotherAccountsPageIsNeverNoted(): void
    {
        $theirs = self::page(self::OTHER);
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 20, $theirs, 1_700_200_000));
        self::assertSame(1, self::clicks(self::FIRST_CLICK + 20));
        self::assertNull(self::seen($theirs));
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 21, 0, 1_700_200_000));
        self::assertSame(1, self::clicks(self::FIRST_CLICK + 21));
    }

    public function testARowLeftFromAnotherOwnerIsTheAccountsNow(): void
    {
        $page = self::page(self::USER);
        self::q('INSERT INTO 202_landing_page_last_seen (landing_page_id, user_id, last_click_at)'
            . " VALUES ($page, " . self::OTHER . ', 1800000000)');
        self::repo()->recordClick(self::click(self::FIRST_CLICK + 30, $page, 1_700_300_000));
        self::assertSame(['user_id' => self::USER, 'last_click_at' => 1_700_300_000], self::seen($page));
    }

    public function testANoteThatCannotBeWrittenCostsOnlyTheNote(): void
    {
        $page = self::page(self::USER);
        $log = tempnam(sys_get_temp_dir(), 'p202-last-seen-log');
        self::assertIsString($log);
        $before = ini_get('error_log');
        self::q('RENAME TABLE 202_landing_page_last_seen TO p202_test_last_seen_away');
        try {
            ini_set('error_log', $log);
            self::repo()->recordClick(self::click(self::FIRST_CLICK + 40, $page, 1_700_400_000));
        } finally {
            ini_set('error_log', (string) $before);
            self::q('RENAME TABLE p202_test_last_seen_away TO 202_landing_page_last_seen');
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertSame(1, self::clicks(self::FIRST_CLICK + 40));
        self::assertNull(self::seen($page));
        self::assertStringContainsString(
            'Prosper202 landing page last seen: click ' . (self::FIRST_CLICK + 40) . "'s landing page ($page)",
            $logged
        );
    }
}

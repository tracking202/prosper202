<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * The traffic source and cost a conversion's pixels announce, on a real
 * server: gpb.php's and upx.php's read (p202ClickSourceSql()) and the
 * outbox's and the goals' (TrafficSourcePixels::clickTokens()). Both take
 * the click's first visit, for the account the conversion is recorded for,
 * and its traffic source account only when it is that account's: a first
 * visit of another account reads as none, and another account's traffic
 * source account as no source, so none of its pixels is fired.
 *
 * @group integration
 */
final class ClickSourceReadIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990117;
    private const OTHER = 990118;
    private const CLICK = 99011701;
    private const MIXED = 99011702;
    private const FOREIGN_SOURCE = 99011703;
    private const FIRST = 1_790_000_000;

    /** @var array<string, int> the traffic source accounts: mine, link (both USER's) and theirs (OTHER's) */
    private static array $account = [];

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
        self::cleanUp();
        foreach (['mine' => self::USER, 'link' => self::USER, 'theirs' => self::OTHER] as $name => $user) {
            self::$account[$name] = self::insert("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = 0,"
                . " ppc_account_name = '$name', ppc_account_time = 0");
        }
        // The click: its first visit from its account's source at 0.25,
        // re-clicked before 1.9.79 with the link's source at 0.90.
        self::visit(self::CLICK, self::USER, self::FIRST, self::$account['mine'], '0.25');
        self::visit(self::CLICK, self::USER, self::FIRST + 600, self::$account['link'], '0.90');
        // A click whose first row is another account's.
        self::visit(self::MIXED, self::OTHER, self::FIRST, self::$account['theirs'], '0.40');
        self::visit(self::MIXED, self::USER, self::FIRST + 600, self::$account['mine'], '0.25');
        // A click of the account whose first visit names another account's
        // traffic source account (a tracker could name one before 229df10).
        self::visit(self::FOREIGN_SOURCE, self::USER, self::FIRST, self::$account['theirs'], '0.30');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        self::$db->query('DELETE FROM 202_clicks WHERE click_id IN (' . self::CLICK . ', ' . self::MIXED . ', '
            . self::FOREIGN_SOURCE . ')');
        self::$db->query('DELETE FROM 202_ppc_accounts WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
    }

    private static function visit(int $click, int $user, int $time, int $account, string $cpc): void
    {
        self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $user, aff_campaign_id = 0,"
            . ' landing_page_id = 0,'
            . " ppc_account_id = $account, click_cpc = $cpc, click_payout = 0, click_lead = 0, click_time = $time,"
            . ' rotator_id = ' . ($time === self::FIRST ? 0 : 5));
    }

    /** @return list<list<string|null>> */
    private static function source(int $click, int $user): array
    {
        $result = self::requireScratch()->query(p202ClickSourceSql($click, $user));
        self::assertInstanceOf(\mysqli_result::class, $result);

        $text = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return array_map(static fn (array $row): array => array_map($text, $row), $result->fetch_all(MYSQLI_NUM));
    }

    /** @return array{int, string}|null clickTokens()'s traffic source account and CPC */
    private static function tokens(int $click, int $user): ?array
    {
        $read = TrafficSourcePixels::clickTokens(new Connection(self::requireScratch()), $user, $click);

        return $read === null ? null : [$read['ppc_account_id'], (string) $read['tokens']['cpc2']];
    }

    public function testTheFirstVisitsSourceAndCostForTheClicksAccount(): void
    {
        self::assertSame([[(string) self::$account['mine'], '0.25000']], self::source(self::CLICK, self::USER));
        self::assertSame([self::$account['mine'], '0.25000'], self::tokens(self::CLICK, self::USER));
    }

    public function testAnotherAccountReadsNoSource(): void
    {
        self::assertSame([], self::source(self::CLICK, self::OTHER));
        self::assertNull(self::tokens(self::CLICK, self::OTHER));
    }

    public function testAFirstVisitOfAnotherAccountIsNoSource(): void
    {
        self::assertSame([], self::source(self::MIXED, self::USER), 'not the other account\'s source, nor its 0.40');
        self::assertNull(self::tokens(self::MIXED, self::USER));
    }

    public function testAnotherAccountsTrafficSourceAccountIsNoSource(): void
    {
        self::assertSame([[null, '0.30000']], self::source(self::FOREIGN_SOURCE, self::USER), 'gpb.php and upx.php');
        self::assertSame([0, '0.30000'], self::tokens(self::FOREIGN_SOURCE, self::USER), 'the outbox and the goals');
    }
}

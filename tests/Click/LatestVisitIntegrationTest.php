<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;

/**
 * LatestVisit on a real server: of a re-clicked click's 202_clicks rows, the
 * latest visit, whichever was stored first; of two in one second, the one on
 * the route the click holds now; and a click off the redirector, with no
 * route row, is still read. Each read is made in the shapes the click path
 * makes it: the derived table bound and with the id in the statement
 * (p202ClickOwner(), offrtr.php, the URL tokens), and the ORDER BY over a
 * join by public id (pci.php, off.php, cl.php).
 *
 * @group integration
 */
final class LatestVisitIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990601;
    private const CLICK = 99060101;
    private const ROTATOR = 77;

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
        foreach (['202_clicks', '202_clicks_record', '202_clicks_rotator'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function insertionOrders(): iterable
    {
        yield 'the latest visit stored first' => [true];
        yield 'the latest visit stored second' => [false];
    }

    /** @dataProvider insertionOrders */
    public function testTheLatestVisitIsTheClick(bool $latestFirst): void
    {
        $latest = self::visit(202, 2000, 0, 0);
        $earlier = self::visit(101, 1000, 0, 0);
        foreach ($latestFirst ? [$latest, $earlier] : [$earlier, $latest] as $sql) {
            self::q($sql);
        }
        self::record();

        self::assertSame([202, 202, 202], self::campaigns());
    }

    /** @dataProvider insertionOrders */
    public function testOfTwoVisitsInOneSecondTheOneOnTheClicksRouteIsTheClick(bool $currentFirst): void
    {
        $current = self::visit(202, 5000, self::ROTATOR, 12);
        $earlier = self::visit(101, 5000, self::ROTATOR, 11);
        foreach ($currentFirst ? [$current, $earlier] : [$earlier, $current] as $sql) {
            self::q($sql);
        }
        self::q('INSERT INTO 202_clicks_rotator SET click_id = ' . self::CLICK . ', rotator_id = ' . self::ROTATOR
            . ', rule_id = 3, rule_redirect_id = 12');
        self::record();

        self::assertSame([202, 202, 202], self::campaigns());
    }

    public function testAClickOffTheRedirectorIsRead(): void
    {
        self::q(self::visit(303, 1000, 0, 0));
        self::record();

        self::assertSame([303, 303, 303], self::campaigns());
    }

    private static function visit(int $campaign, int $time, int $rotator, int $redirect): string
    {
        return 'INSERT INTO 202_clicks'
            . ' (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_time, rotator_id, rule_id)'
            . ' VALUES (' . self::CLICK . ', ' . self::USER . ", $campaign, 0, 0, $time, $rotator, $redirect)";
    }

    private static function record(): void
    {
        self::q('INSERT INTO 202_clicks_record SET click_id = ' . self::CLICK . ', click_id_public = 7'
            . self::CLICK . '7');
    }

    /** @return list<int> the campaign each shape of the read answers: bound, inline, by public id */
    private static function campaigns(): array
    {
        $db = self::requireScratch();
        $stmt = $db->prepare('SELECT c.aff_campaign_id FROM ' . LatestVisit::of('?') . ' AS c');
        self::assertNotFalse($stmt, $db->error);
        $id = self::CLICK;
        $stmt->bind_param('i', $id);
        self::assertTrue($stmt->execute(), $stmt->error);
        $result = $stmt->get_result();
        self::assertNotFalse($result, $stmt->error);
        $bound = $result->fetch_all();
        $stmt->close();

        $inline = $db->query('SELECT c.aff_campaign_id FROM ' . LatestVisit::of((string) self::CLICK) . ' AS c');
        self::assertNotFalse($inline, $db->error);

        $byPublicId = $db->query('SELECT c.aff_campaign_id FROM 202_clicks_record AS r'
            . ' LEFT JOIN 202_clicks AS c ON c.click_id = r.click_id'
            . ' LEFT JOIN 202_clicks_rotator AS cro ON cro.click_id = r.click_id'
            . ' WHERE r.click_id_public = 7' . self::CLICK . '7 ' . LatestVisit::orderBy('c', 'cro') . ' LIMIT 1');
        self::assertNotFalse($byPublicId, $db->error);

        $answers = [$bound, $inline->fetch_all(), $byPublicId->fetch_all()];
        foreach ($answers as $rows) {
            self::assertCount(1, $rows, 'one row: the click\'s latest visit');
        }

        return array_map(static fn (array $rows): int => (int) $rows[0][0], $answers);
    }
}

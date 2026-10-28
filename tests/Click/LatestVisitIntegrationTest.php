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
        foreach (['202_clicks', '202_clicks_record', '202_clicks_rotator', '202_click_latest_visits'] as $table) {
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

    /**
     * isLatest(), as a report reads many clicks' rows: the re-clicked click
     * counts once, under its latest visit's campaign, whatever its earlier
     * visit's time window; two visits in one second both count.
     */
    public function testIsLatestCountsAClickOnceUnderItsLatestVisit(): void
    {
        self::q(self::visit(101, 1000, 0, 0));
        self::q(self::visit(202, 2000, 0, 0));
        self::refresh();
        self::assertSame([['202', '1']], self::counted(''));
        // A window holding only the earlier visit counts nothing: the click is its latest visit's.
        self::assertSame([], self::counted(' AND c.click_time < 1500'));

        self::q(self::visit(303, 2000, 0, 0));
        self::refresh();
        self::assertSame([['202', '1'], ['303', '1']], self::counted(''));
    }

    /**
     * A re-click not yet recorded (written before 1.9.79, ahead of the
     * backfill) counts at each visit, as every click did before.
     */
    public function testAReClickWithNoRecordCountsAtEachVisit(): void
    {
        self::q(self::visit(101, 1000, 0, 0));
        self::q(self::visit(202, 2000, 0, 0));

        self::assertSame([['101', '1'], ['202', '1']], self::counted(''));
    }

    /**
     * refresh() records the latest of the click's rows, not the visit just
     * written: a re-click a web server dated before the visit it follows
     * leaves the later row the latest. It never moves the record back.
     */
    public function testRefreshRecordsTheLatestRowWhateverWasWrittenLast(): void
    {
        self::q(self::visit(101, 2000, 0, 0));
        self::q(self::visit(202, 1000, 0, 0));
        self::refresh();
        self::assertSame([['101', '1']], self::counted(''));
        self::assertSame('2000', self::recorded());

        self::q('UPDATE 202_click_latest_visits SET click_time = 3000 WHERE click_id = ' . self::CLICK);
        self::refresh();
        self::assertSame('3000', self::recorded(), 'a later record stays');
    }

    /** @dataProvider insertionOrders */
    public function testFirstVisitIsTheEarliestRow(bool $latestFirst): void
    {
        $latest = self::visit(202, 2000, 0, 0);
        $earlier = self::visit(101, 1000, 0, 0);
        foreach ($latestFirst ? [$latest, $earlier] : [$earlier, $latest] as $sql) {
            self::q($sql);
        }
        $db = self::requireScratch();
        $result = $db->query('SELECT f.aff_campaign_id FROM ' . \Prosper202\Click\FirstVisit::of((string) self::CLICK)
            . ' AS f');
        self::assertNotFalse($result, $db->error);

        $rows = array_map(static fn (array $r): array => array_map('strval', $r), $result->fetch_all());
        self::assertSame([['101']], $rows);
    }

    private static function refresh(): void
    {
        LatestVisit::refresh(new \Prosper202\Database\Connection(self::requireScratch()), self::CLICK);
    }

    private static function recorded(): ?string
    {
        $db = self::requireScratch();
        $result = $db->query('SELECT click_time FROM 202_click_latest_visits WHERE click_id = ' . self::CLICK);
        self::assertNotFalse($result, $db->error);
        $row = $result->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    /** @return list<array{string, string}> campaign => clicks, over the click's rows that isLatest() keeps */
    private static function counted(string $window): array
    {
        $db = self::requireScratch();
        $result = $db->query('SELECT c.aff_campaign_id, COUNT(*) FROM 202_clicks AS c WHERE c.click_id = '
            . self::CLICK . $window . ' AND ' . LatestVisit::isLatest('c')
            . ' GROUP BY c.aff_campaign_id ORDER BY c.aff_campaign_id');
        self::assertNotFalse($result, $db->error);

        return array_map(static fn (array $row): array => array_map('strval', $row), $result->fetch_all());
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

<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * The global postback (gpb.php) records a conversion whether the network
 * sends subid, amount and the order number in the query string or in a
 * form-encoded POST body, as paid 1.9.56 did. Before, a body-only postback
 * was answered "SubID not found" and nothing was recorded. The query string
 * wins a name both carry with a value. gpb.php runs in a process of its own
 * (fixtures/gpb-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect2.php's DB class lives there) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class GlobalPostbackFormPostIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990715;
    private const CLICK = 99071501;
    private const OTHER_CLICK = 99071502;
    private const CLICKS = [self::CLICK, self::OTHER_CLICK];
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits',
        '202_conversion_logs', '202_dataengine'];

    private static int $campaign = 0;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect2.php has no DB class without one.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::user(self::USER);
        self::$campaign = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER
            . ", aff_network_id = 0, aff_campaign_name = 'Form post', aff_campaign_url = 'https://shop.example',"
            . ' aff_campaign_payout = 3.00, aff_campaign_foreign_payout = 3.00, aff_campaign_time = 0,'
            . " payout_mode = 'replace'");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        foreach (self::CLICKS as $click) {
            self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
                . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
            foreach (self::CLICK_TABLES as $table) {
                self::q("DELETE FROM $table WHERE click_id = $click");
            }
            $columns = "click_id = $click, user_id = " . self::USER . ', aff_campaign_id = ' . self::$campaign
                . ', landing_page_id = 0, ppc_account_id = 0, click_cpc = 0.25, click_payout = 3.00,'
                . ' click_lead = 0, click_time = ' . (time() - 600);
            self::q("INSERT INTO 202_clicks SET $columns");
            self::q("INSERT INTO 202_clicks_spy SET $columns");
            self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0");
            LatestVisit::refresh(new Connection(self::$db), $click);
        }
    }

    private static function cleanUp(): void
    {
        foreach (self::CLICKS as $click) {
            self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
                . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
            foreach (self::CLICK_TABLES as $table) {
                self::q("DELETE FROM $table WHERE click_id = $click");
            }
        }
        foreach (['202_aff_campaigns', '202_revenue_events', '202_dirty_hours', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @return array{int, string} status and body
     */
    private static function gpb(array $get, array $post = []): array
    {
        $env = getenv();
        $env['P202_TEST_GET'] = json_encode((object) $get, JSON_THROW_ON_ERROR);
        $env['P202_TEST_POST'] = json_encode((object) $post, JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/gpb-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "gpb-runner exited $exit: $err\n$out");
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
    }

    /** @return list<array{string, string, string}> click, payout and transaction id of each conversion */
    private static function conversions(): array
    {
        $sql = 'SELECT click_id, click_payout, COALESCE(transaction_id, \'\')'
            . ' FROM 202_conversion_logs WHERE click_id IN (' . implode(',', self::CLICKS) . ')'
            . ' ORDER BY conv_id';
        $result = self::$db->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }

        return $result->fetch_all(MYSQLI_NUM);
    }

    public function testAGetPostbackRecordsAsBefore(): void
    {
        [$status] = self::gpb(['subid' => (string) self::CLICK, 'amount' => '12.50', 'txid' => 'ord-get']);

        self::assertSame(200, $status);
        self::assertSame([[(string) self::CLICK, '12.50000', 'ord-get']], self::conversions());
    }

    public function testAFormPostBodyRecordsTheConversion(): void
    {
        [$status, $body] = self::gpb([], [
            'subid' => (string) self::CLICK, 'amount' => '19.99', 't202txid' => 'ord-post',
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '19.99000', 'ord-post']], self::conversions());
    }

    public function testSidInTheBodyNamesTheClickToo(): void
    {
        [$status, $body] = self::gpb([], ['sid' => (string) self::CLICK, 'amount' => '4']);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '4.00000', '']], self::conversions());
    }

    public function testTheBodyFillsThePastedUrlsBlankPlaceholders(): void
    {
        // The 1.9.56 postback URL as handed out: gpb.php?amount=&subid=
        [$status, $body] = self::gpb(['amount' => '', 'subid' => ''], [
            'subid' => (string) self::CLICK, 'amount' => '7.25', 't202txid' => 'ord-blank',
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '7.25000', 'ord-blank']], self::conversions());
    }

    public function testTheQueryStringWinsANameBothSend(): void
    {
        [$status, $body] = self::gpb(
            ['subid' => (string) self::CLICK, 'amount' => '5'],
            ['subid' => (string) self::OTHER_CLICK, 'amount' => '9', 'txid' => 'ord-both']
        );

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'ord-both']], self::conversions());
    }

    public function testAFormBodyWithNoClickIdRecordsNothing(): void
    {
        [$status, $body] = self::gpb([], ['amount' => '5']);

        self::assertSame(404, $status);
        self::assertStringContainsString('SubID not found', $body);
        self::assertSame([], self::conversions());
    }

    public function testABodySubidNeverOutranksTheQuerysSid(): void
    {
        [$status, $body] = self::gpb(['sid' => (string) self::CLICK], [
            'subid' => (string) self::OTHER_CLICK, 'amount' => '6', 'txid' => 'ord-sid',
        ]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '6.00000', 'ord-sid']], self::conversions());
    }

    public function testAMalformedBodyClickIdRecordsNothing(): void
    {
        [$status, $body] = self::gpb([], ['subid' => self::CLICK . '.0', 'amount' => '5']);

        self::assertSame(404, $status);
        self::assertStringContainsString('SubID not found', $body);
        self::assertSame([], self::conversions());
    }
}

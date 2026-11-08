<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * An amount a conversion row cannot hold is refused by gpb.php, through the
 * real file on the scratch database, served by `php -S` (Y5N2).
 *
 * The conversion columns are decimal(11,5): at most 999999.99999. Amount
 * read 13 whole digits, and connect.php runs every request under an empty
 * sql_mode, where MySQL and MariaDB store a larger value clamped to the
 * column's limit without an error: a postback for 1234567 was recorded as
 * 999999.99999 and answered as a success. It is a 422 naming the field now,
 * and nothing is written. The click's value, the sum of its conversions in
 * an accumulating campaign, is held to the same bound.
 *
 * @group integration
 */
final class ConversionAmountBoundsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 6621;
    private const CAMPAIGN = 6621;
    private const CLICK = 66210001;
    private const SUM_CLICK = 66210002;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null;

            return;
        }
        self::seed();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database or served config (P202_TEST_DB_HOST, 202-config.php).');
        }
        $clicks = self::CLICK . ', ' . self::SUM_CLICK;
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id IN ($clicks)");
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("UPDATE $table SET click_payout = 3, click_lead = 0 WHERE click_id IN ($clicks)");
        }
    }

    private static function assertRecordedAnswer(int $status, string $body): void
    {
        self::assertTrue($status >= 200 && $status < 300, 'recorded: ' . $status . ' ' . $body);
    }

    private static function cleanUp(): void
    {
        $clicks = self::CLICK . ', ' . self::SUM_CLICK;
        self::q('DELETE FROM 202_notification_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_inbound_log WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q('DELETE FROM 202_postback_auth WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_pref_privacy = 'disabled'");
        self::postbackAuthOff(self::USER);
        // Accumulating, so a click's value is the sum of its conversions.
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + 100000,
            'user_id' => self::USER, 'aff_network_id' => 0, 'aff_campaign_name' => 'store',
            'aff_campaign_url' => 'https://shop.example/', 'aff_campaign_payout' => 3,
            'aff_campaign_foreign_payout' => 3, 'aff_campaign_time' => time(), 'payout_mode' => 'accumulate',
        ]);
        $conn = new Connection(self::$db);
        foreach ([self::CLICK, self::SUM_CLICK] as $click) {
            $columns = "click_id = $click, user_id = " . self::USER . ', aff_campaign_id = ' . self::CAMPAIGN
                . ', ppc_account_id = 0, click_cpc = 0.25, click_payout = 3, click_lead = 0,'
                . ' click_time = ' . (time() - 60);
            self::q("INSERT INTO 202_clicks SET $columns");
            self::q("INSERT INTO 202_clicks_spy SET $columns");
            self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0");
            LatestVisit::refresh($conn, $click);
        }
    }

    /** @return array{0: int, 1: string} status and body */
    private static function get(string $path): array
    {
        $ch = curl_init(self::$base . $path);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30]);
        $body = (string) curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }

    /** @return list<string> the click's conversion amounts, oldest first */
    private static function amounts(int $click): array
    {
        $result = self::$db->query("SELECT click_payout FROM 202_conversion_logs WHERE click_id = $click ORDER BY conv_id");
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'click_payout');
    }

    private static function clickValue(int $click): string
    {
        $result = self::$db->query("SELECT click_payout FROM 202_clicks WHERE click_id = $click");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) ($result->fetch_row()[0] ?? '');
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    public function testAPayoutPastTheColumnIsRefusedNamingTheFieldAndNothingIsStored(): void
    {
        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::CLICK . '&amount=1234567&txid=Y5-big');
        self::assertSame(422, $status, $body . $this->serverLog());
        self::assertStringContainsString('payout 1234567.00000 is out of range', $body);
        self::assertStringContainsString('999999.99999', $body, 'it says what range a conversion holds');
        self::assertSame([], self::amounts(self::CLICK), 'nothing recorded, not a clamped 999999.99999');
        self::assertSame('3.00000', self::clickValue(self::CLICK));

        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::CLICK . '&amount=-1000000&txid=Y5-neg');
        self::assertSame(422, $status, $body);
        self::assertSame([], self::amounts(self::CLICK));
    }

    public function testThePayoutAtTheColumnsLimitIsRecordedExactly(): void
    {
        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::CLICK . '&amount=999999.99999&txid=Y5-max');
        self::assertRecordedAnswer($status, $body . $this->serverLog());
        self::assertSame(['999999.99999'], self::amounts(self::CLICK), 'the positive control: the limit itself is held');
    }

    public function testAClickValueSummedPastTheColumnIsRefused(): void
    {
        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::SUM_CLICK . '&amount=999999&txid=Y5-sum-1');
        self::assertRecordedAnswer($status, $body . $this->serverLog());

        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::SUM_CLICK . '&amount=5&txid=Y5-sum-2');
        self::assertSame(422, $status, $body . $this->serverLog());
        self::assertStringContainsString('click value 1000004.00000 is out of range', $body);
        self::assertSame(['999999.00000'], self::amounts(self::SUM_CLICK), 'the second is not recorded');
        self::assertSame('999999.00000', self::clickValue(self::SUM_CLICK), 'and the click keeps its value');
    }
}

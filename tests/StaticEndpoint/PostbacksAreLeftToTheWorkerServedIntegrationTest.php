<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;

/**
 * gpb.php and upx.php answer without calling the traffic source (66H8),
 * through the real files on the scratch database, served by `php -S`: the
 * conversion's server postback is queued, untried, and the notification
 * worker sends it, once, to its own URL.
 *
 * Both used to send the queue before answering, up to 20 URLs at 10 seconds
 * each, so a slow traffic source held the network's postback open.
 *
 * @group integration
 */
final class PostbacksAreLeftToTheWorkerServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 6608;
    private const ACCOUNT = 6608;
    private const CAMPAIGN = 6608;
    private const GPB_CLICK = 66080001;
    private const UPX_CLICK = 66080002;
    private const AFTER_CLICK = 66080003;
    private const SLOW_CLICK = 66080004;
    private const SLOW_ACCOUNT = 6609;

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
    }

    private static function cleanUp(): void
    {
        $clicks = implode(', ', [self::GPB_CLICK, self::UPX_CLICK, self::AFTER_CLICK, self::SLOW_CLICK]);
        self::q('DELETE FROM 202_notification_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        $tables = [
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        $accounts = self::ACCOUNT . ', ' . self::SLOW_ACCOUNT;
        self::q("DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id IN ($accounts)");
        self::q("DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN ($accounts)");
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::USER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::USER . ", user_pref_privacy = 'disabled'");
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + 100000,
            'user_id' => self::USER, 'aff_network_id' => 0, 'aff_campaign_name' => 'store',
            'aff_campaign_url' => 'https://shop.example/', 'aff_campaign_payout' => 3,
            'aff_campaign_foreign_payout' => 3, 'aff_campaign_time' => time(),
        ]);
        self::row('202_ppc_accounts', [
            'ppc_account_id' => self::ACCOUNT, 'user_id' => self::USER, 'ppc_network_id' => 0,
            'ppc_account_name' => 'search ads', 'ppc_account_time' => time(),
        ]);
        self::row('202_ppc_account_pixels', [
            'ppc_account_id' => self::ACCOUNT, 'pixel_type_id' => 4,
            'pixel_code' => 'https://ads.example/pb?s=[[subid]]&tx=[[transactionid]]',
        ]);
        self::row('202_ppc_accounts', [
            'ppc_account_id' => self::SLOW_ACCOUNT, 'user_id' => self::USER, 'ppc_network_id' => 0,
            'ppc_account_name' => 'social ads', 'ppc_account_time' => time(),
        ]);
        self::row('202_ppc_account_pixels', [
            'ppc_account_id' => self::SLOW_ACCOUNT, 'pixel_type_id' => 4,
            'pixel_code' => 'https://slow.example/a?s=[[subid]] https://slow.example/b?s=[[subid]]'
                . ' https://fast.example/c?s=[[subid]]',
        ]);
        $conn = new Connection(self::$db);
        $clicks = [
            self::GPB_CLICK => self::ACCOUNT, self::UPX_CLICK => self::ACCOUNT,
            self::AFTER_CLICK => self::ACCOUNT, self::SLOW_CLICK => self::SLOW_ACCOUNT,
        ];
        foreach ($clicks as $click => $account) {
            $columns = "click_id = $click, user_id = " . self::USER . ', aff_campaign_id = ' . self::CAMPAIGN
                . ", ppc_account_id = $account, click_cpc = 0.25, click_payout = 3, click_lead = 0,"
                . ' click_time = ' . (time() - 60);
            self::q("INSERT INTO 202_clicks SET $columns");
            self::q("INSERT INTO 202_clicks_spy SET $columns");
            self::q("INSERT INTO 202_clicks_tracking SET click_id = $click,"
                . ' c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0');
            LatestVisit::refresh($conn, $click);
        }
    }

    /** @return array{0: int, 1: string} status and body */
    private static function get(string $path): array
    {
        $ch = curl_init(self::$base . $path);
        self::assertNotFalse($ch);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }

    /** @return list<array<string, mixed>> */
    private static function queued(int $click): array
    {
        $result = self::$db->query('SELECT n.status, n.attempts, n.url FROM 202_notification_pending n'
            . " JOIN 202_conversion_logs c USING (conv_id) WHERE c.click_id = $click AND n.kind = 'reached'");
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<string> the URLs the worker sent */
    private static function runWorker(): array
    {
        $sent = [];
        $fetch = static function (string $url) use (&$sent): bool {
            $sent[] = $url;

            return true;
        };
        $outbox = new NotificationOutbox(new Connection(self::$db), null, $fetch);
        $outbox->sendDue(200);

        return $sent;
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    public function testTheGlobalPostbackQueuesAndTheWorkerSends(): void
    {
        [$status, $body] = self::get('/tracking202/static/gpb.php?subid=' . self::GPB_CLICK . '&amount=3&txid=G1');
        self::assertSame(202, $status, $body . $this->serverLog());
        self::assertStringContainsString('"msg":"Postback successful"', $body, 'the answer it always gave');

        $url = 'https://ads.example/pb?s=' . self::GPB_CLICK . '&tx=G1';
        self::assertSame(
            [['status' => 'pending', 'attempts' => '0', 'url' => $url]],
            self::queued(self::GPB_CLICK),
            'queued and not tried: the request did not call the traffic source'
        );

        self::assertContains($url, self::runWorker(), 'the worker sends it');
        self::assertSame('sent', self::queued(self::GPB_CLICK)[0]['status']);
        self::assertNotContains($url, self::runWorker(), 'once');
    }

    public function testTheUniversalPixelQueuesAndTheWorkerSends(): void
    {
        [$status, $body] = self::get('/tracking202/static/upx.php?subid=' . self::UPX_CLICK . '&amount=3&txid=U1');
        self::assertSame(200, $status, $body . $this->serverLog());

        $url = 'https://ads.example/pb?s=' . self::UPX_CLICK . '&tx=U1';
        self::assertSame(
            [['status' => 'pending', 'attempts' => '0', 'url' => $url]],
            self::queued(self::UPX_CLICK),
            'queued and not tried'
        );

        self::assertContains($url, self::runWorker(), 'the worker sends it');
        self::assertSame('sent', self::queued(self::UPX_CLICK)[0]['status']);
    }

    public function testWhereTheClientCanBeReleasedTheRequestSendsAfterItsAnswer(): void
    {
        [$status] = self::get('/tracking202/static/gpb.php?subid=' . self::AFTER_CLICK . '&amount=3&txid=A1');
        self::assertSame(202, $status, $this->serverLog());
        $attempts = (string) self::queued(self::AFTER_CLICK)[0]['attempts'];
        self::assertSame('0', $attempts, 'php -S cannot release the client');

        // PHP-FPM's release, which the CLI lacks; it records that the answer went first.
        if (!function_exists('fastcgi_finish_request')) {
            eval('function fastcgi_finish_request(): bool { $GLOBALS["p202_released"] = true; return true; }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} }');
        }
        require_once dirname(__DIR__, 2) . '/202-config/static-endpoint-helpers.php';
        $GLOBALS['p202_released'] = false;
        $result = self::$db->query('SELECT conv_id FROM 202_conversion_logs WHERE click_id = ' . self::AFTER_CLICK);
        self::assertInstanceOf(\mysqli_result::class, $result);
        p202SendQueuedPostbacksAfterAnswer(self::$db, (int) $result->fetch_row()[0]);

        self::assertTrue($GLOBALS['p202_released'], 'the client was released first');
        self::assertSame('1', (string) self::queued(self::AFTER_CLICK)[0]['attempts'], 'then the postback was tried');
    }

    public function testAHostThatFailsIsNotAskedAgainInTheSameRun(): void
    {
        [$status] = self::get('/tracking202/static/gpb.php?subid=' . self::SLOW_CLICK . '&amount=3&txid=S1');
        self::assertSame(202, $status, $this->serverLog());

        $asked = [];
        $fetch = static function (string $url) use (&$asked): bool {
            $asked[] = $url;

            return !str_contains($url, 'slow.example');
        };
        $convs = self::$db->query('SELECT conv_id FROM 202_conversion_logs WHERE click_id = ' . self::SLOW_CLICK);
        self::assertInstanceOf(\mysqli_result::class, $convs);
        $conv = (int) $convs->fetch_row()[0];
        // A window of two: the slow host's second row must not keep the third out.
        (new NotificationOutbox(new Connection(self::$db), null, $fetch))->sendDue(2, [$conv]);

        $s = self::SLOW_CLICK;
        self::assertSame(
            ["https://slow.example/a?s=$s", "https://fast.example/c?s=$s"],
            $asked,
            'the slow host was asked once; the next host was still asked'
        );
        $attempts = array_column(self::queued(self::SLOW_CLICK), 'attempts', 'url');
        self::assertSame('0', (string) $attempts["https://slow.example/b?s=$s"], 'left for the next run, untried');
    }
}

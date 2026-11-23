<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * Connections a click opens, counted by the server: MySQL's `Connections`
 * status, read before and after one request. Every connection counts against
 * max_connections, and a refused one is a click sent on unsaved, which under
 * load is how clicks were lost while each one opened two.
 *
 * Other suites on the same server open connections too, so a count can only
 * come out high; each case takes the least of several tries, and a click that
 * opens two never reads as one.
 *
 * - A dl.php click through the served checkout, on the 202-config.php the
 *   integration job writes from 202-config-sample.php: one connection, and
 *   the click saved.
 * - The sample of 1.9.55 to 1.9.79, which an install keeps across upgrades:
 *   two opened, and after ConnectionFactory::releaseReadConnection() the
 *   server holds only the primary.
 *
 * @group integration
 */
final class OneConnectionPerClickIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 826101;
    private const CAMPAIGN = 826111;
    private const TRACKER = 826121;
    private const PUBLIC_OFFSET = 100000;
    private const TRIES = 20;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static ?string $notServed = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $config = $root . '/202-config.php';
        if (!is_file($config)) {
            self::$notServed = 'no 202-config.php for the served checkout (tests/run-integration-suites.sh writes one)';

            return;
        }
        $installed = (string) file_get_contents($config);
        $sample = (string) file_get_contents($root . '/202-config-sample.php');
        if (self::classOf($installed) !== self::classOf($sample)) {
            self::$notServed = "202-config.php is not written from this checkout's sample, so it says nothing about it";

            return;
        }
        self::cleanUp();
        self::user(self::OWNER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER . ", user_pref_privacy = 'disabled'");
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + self::PUBLIC_OFFSET,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'Spring sale',
            'aff_campaign_url' => 'https://shop.example.com/sale?sid=[[subid]]', 'aff_campaign_payout' => 0,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_trackers', [
            'tracker_id' => self::TRACKER, 'user_id' => self::OWNER,
            'tracker_id_public' => self::TRACKER + self::PUBLIC_OFFSET, 'aff_campaign_id' => self::CAMPAIGN,
            'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0, 'rotator_id' => 0,
            'click_cloaking' => 0, 'tracker_time' => time(),
        ]);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                self::$base = "http://127.0.0.1:$port";
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

    private static function cleanUp(): void
    {
        $clicks = 'SELECT click_id FROM 202_clicks WHERE user_id = ' . self::OWNER;
        foreach (['202_clicks_tracking', '202_clicks_record', '202_clicks_site', '202_clicks_advance',
            '202_clicks_spy', '202_click_latest_visits', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_clicks WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_trackers WHERE tracker_id = ' . self::TRACKER);
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::OWNER);
    }

    /** Everything from `class DB` on: the part of a config the integration job's render shares with the sample. */
    private static function classOf(string $config): string
    {
        $at = strpos($config, 'class DB');

        return $at === false ? '' : rtrim(substr($config, $at));
    }

    private static function connections(): int
    {
        $result = self::requireScratch()->query("SHOW GLOBAL STATUS LIKE 'Connections'");
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[1];
    }

    private static function clicks(): int
    {
        $result = self::requireScratch()->query('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (int) $result->fetch_row()[0];
    }

    public function testADlClickOpensOneConnectionAndIsSaved(): void
    {
        self::requireScratch();
        if (self::$notServed !== null || self::$base === '') {
            self::fail('the click script could not be served: ' . (self::$notServed ?? 'php -S did not answer'));
        }
        $fewest = PHP_INT_MAX;
        for ($try = 0; $try < self::TRIES && $fewest > 1; $try++) {
            $clicks = self::clicks();
            $before = self::connections();
            $ch = curl_init(self::$base . '/tracking202/redirect/dl.php?t202id=' . (self::TRACKER + self::PUBLIC_OFFSET));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['X-Forwarded-For: 198.51.100.' . (10 + $try)],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
            $out = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $opened = self::connections() - $before;

            self::assertSame(302, $status, $out . (string) file_get_contents(self::$log));
            self::assertMatchesRegularExpression('~^Location: https://shop\.example\.com/sale\?sid=\d+~mi', $out);
            self::assertSame($clicks + 1, self::clicks(), 'the click was saved');
            $fewest = min($fewest, $opened);
        }

        self::assertSame(1, $fewest, 'connections one dl.php click opened, the least of ' . self::TRIES . ' tries');
    }

    /**
     * The retained sample opens two, and the click path's release leaves the
     * server holding only the primary.
     */
    public function testARetainedConfigsReadConnectionIsReleased(): void
    {
        $db = self::requireScratch();
        $config = (string) tempnam(sys_get_temp_dir(), 'p202cfg');
        $host = (string) getenv('P202_TEST_DB_HOST') . ':' . (string) (getenv('P202_TEST_DB_PORT') ?: 3306);
        $dbname = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
        file_put_contents($config, strtr((string) file_get_contents(__DIR__ . '/../Config/fixtures/202-config-sample-1.9.79.php.txt'), [
            "'putyourdbnamehere'" => var_export($dbname, true),
            "'usernamehere'" => var_export((string) (getenv('P202_TEST_DB_USER') ?: 'root'), true),
            "'yourpasswordhere'" => var_export((string) (getenv('P202_TEST_DB_PASS') ?: ''), true),
            "'localhosthere'" => var_export($host, true),
            "'localhostreplica'" => var_export($host, true),
        ]));
        $code = 'mysqli_report(MYSQLI_REPORT_STRICT);'
            . 'include ' . var_export($config, true) . ';'
            . '$ro = (new ReflectionProperty($database, "_connectionro"))->getValue($database);'
            . '$ids = ["primary" => $db->thread_id, "read" => $ro->thread_id];'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/202-config/Database/ConnectionFactory.php', true) . ';'
            . '$ids["released"] = \Prosper202\Database\ConnectionFactory::releaseReadConnection($database, $db);'
            . 'echo json_encode($ids), "\n";'
            . 'fgets(STDIN);';

        try {
            $fewest = PHP_INT_MAX;
            for ($try = 0; $try < self::TRIES && $fewest > 2; $try++) {
                $before = self::connections();
                $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $line = (string) fgets($pipes[1]);
                $opened = self::connections() - $before;
                $ids = json_decode($line, true);
                if (!is_array($ids)) {
                    fclose($pipes[0]);
                    self::fail('the retained config did not load: ' . $line . stream_get_contents($pipes[2]));
                }
                self::assertTrue($ids['released']);
                $held = [];
                for ($wait = 0; $wait < 40; $wait++) {
                    $found = $db->query('SELECT ID FROM information_schema.PROCESSLIST WHERE ID IN ('
                        . (int) $ids['primary'] . ', ' . (int) $ids['read'] . ') ORDER BY ID');
                    self::assertInstanceOf(\mysqli_result::class, $found);
                    $held = array_map('intval', array_column($found->fetch_all(), 0));
                    if ($held === [(int) $ids['primary']]) {
                        break;
                    }
                    usleep(50000);
                }
                fwrite($pipes[0], "\n");
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                self::assertSame([(int) $ids['primary']], $held, 'the server holds the primary alone');
                $fewest = min($fewest, $opened);
            }
            self::assertSame(2, $fewest, 'connections the retained config opened, the least of ' . self::TRIES . ' tries');
        } finally {
            unlink($config);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchDir;

/**
 * A dl.php click through the real connect2.php, on the 202-config.php of
 * 1.9.55 to 1.9.79 (tests/Config/fixtures/202-config-sample-1.9.79.php.txt),
 * which an install keeps across upgrades: its constructor opens the read
 * connection beside the primary and throws when either is refused. When
 * MySQL's max_connections refuses the second, the click is saved on the
 * primary alone (p202ClickPathPrimaryOnly() and
 * ConnectionFactory::adoptPrimary()).
 *
 * The refusal is real: the config connects as a MySQL user allowed one
 * connection at a time (MAX_USER_CONNECTIONS 1), with the read connection
 * named at the same server, so its second connection is refused as
 * max_connections refuses it, and anything later in the request that opened
 * another would be refused too. The checkout is copied beside that config
 * (connect2.php reads the 202-config.php one directory above its own), with
 * vendor/ and the GeoIP files linked.
 *
 * @group integration
 */
final class RetainedConfigClickIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 826201;
    private const CAMPAIGN = 826211;
    private const TRACKER = 826221;
    private const PUBLIC_OFFSET = 100000;
    private const CLICKS = 30;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $tree = '';
    private static string $limitedUser = '';
    private static ?string $notServed = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $db = self::requireScratchConnection();
        self::cleanUp();
        self::user(self::OWNER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER . ", user_pref_privacy = 'disabled'");
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + self::PUBLIC_OFFSET,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'Winter sale',
            'aff_campaign_url' => 'https://shop.example.com/winter?sid=[[subid]]', 'aff_campaign_payout' => 0,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_trackers', [
            'tracker_id' => self::TRACKER, 'user_id' => self::OWNER,
            'tracker_id_public' => self::TRACKER + self::PUBLIC_OFFSET, 'aff_campaign_id' => self::CAMPAIGN,
            'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0, 'rotator_id' => 0,
            'click_cloaking' => 0, 'tracker_time' => time(),
        ]);

        $dbname = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
        self::$limitedUser = 'p202one' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(12));
        try {
            self::q('CREATE USER ' . self::str(self::$limitedUser) . "@'%' IDENTIFIED BY " . self::str($password)
                . ' WITH MAX_USER_CONNECTIONS 1');
            self::q('GRANT ALL ON `' . str_replace('`', '``', $dbname) . '`.* TO '
                . self::str(self::$limitedUser) . "@'%'");
        } catch (\RuntimeException $e) {
            self::$notServed = 'a MySQL user limited to one connection could not be made: ' . $e->getMessage();

            return;
        }

        $root = dirname(__DIR__, 2);
        self::$tree = sys_get_temp_dir() . '/p202-retained-' . bin2hex(random_bytes(4));
        mkdir(self::$tree . '/202-config/temp', 0777, true);
        self::copyTree($root . '/202-config', self::$tree . '/202-config', ['temp', 'geo']);
        if (is_dir($root . '/202-config/geo')) {
            symlink($root . '/202-config/geo', self::$tree . '/202-config/geo');
        }
        self::copyTree($root . '/tracking202', self::$tree . '/tracking202', []);
        symlink($root . '/vendor', self::$tree . '/vendor');
        $host = (string) getenv('P202_TEST_DB_HOST') . ':' . (string) (getenv('P202_TEST_DB_PORT') ?: 3306);
        file_put_contents(self::$tree . '/202-config.php', strtr(
            (string) file_get_contents($root . '/tests/Config/fixtures/202-config-sample-1.9.79.php.txt'),
            [
                "'putyourdbnamehere'" => var_export($dbname, true),
                "'usernamehere'" => var_export(self::$limitedUser, true),
                "'yourpasswordhere'" => var_export($password, true),
                "'localhosthere'" => var_export($host, true),
                "'localhostreplica'" => var_export($host, true),
                "'localhostmemcache'" => var_export('127.0.0.1', true),
            ]
        ));

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', self::$tree],
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
        if (self::$tree !== '' && is_dir(self::$tree)) {
            self::removeTree(self::$tree);
        }
        if (self::$db !== null) {
            if (self::$limitedUser !== '') {
                self::$db->query('DROP USER IF EXISTS ' . self::str(self::$limitedUser) . "@'%'");
            }
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function requireScratchConnection(): \mysqli
    {
        return self::$db ?? throw new \LogicException('no scratch database');
    }

    private static function cleanUp(): void
    {
        $clicks = 'SELECT click_id FROM 202_clicks WHERE user_id = ' . self::OWNER;
        foreach (
            ['202_clicks_tracking', '202_clicks_record', '202_clicks_site', '202_clicks_advance',
            '202_clicks_spy', '202_click_latest_visits', '202_dataengine'] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_clicks WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_trackers WHERE tracker_id = ' . self::TRACKER);
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::OWNER);
    }

    /** @param list<string> $skip top-level names left out */
    private static function copyTree(string $from, string $to, array $skip): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot() || in_array($entry->getFilename(), $skip, true)) {
                continue;
            }
            $target = $to . '/' . $entry->getFilename();
            if ($entry->isDir() && !$entry->isLink()) {
                self::copyTree($entry->getPathname(), $target, []);
            } else {
                copy($entry->getPathname(), $target);
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        ScratchDir::remove($dir);
    }

    /** Connection attempts the server has had, refused ones included. */
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

    /**
     * Every click is saved, one after another: the read connection the
     * retained class asks for is refused each time, the primary alone is
     * opened again and kept as the class's singleton, and nothing later in
     * the request opens another (it would be refused, and the click lost).
     */
    public function testAClickIsSavedWhenTheRetainedConfigsReadConnectionIsRefused(): void
    {
        self::requireScratch();
        if (self::$notServed !== null || self::$base === '') {
            self::fail('the click script could not be served: ' . (self::$notServed ?? 'php -S did not answer'));
        }
        $saved = 0;
        $answers = [];
        $fewest = PHP_INT_MAX;
        for ($try = 0; $try < self::CLICKS; $try++) {
            $clicks = self::clicks();
            $before = self::connections();
            $ch = curl_init(
                self::$base . '/tracking202/redirect/dl.php?t202id=' . (self::TRACKER + self::PUBLIC_OFFSET)
            );
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['X-Forwarded-For: 198.51.100.' . (20 + $try)],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
            $out = (string) curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $fewest = min($fewest, self::connections() - $before);
            $answers[] = $status;
            if (
                $status === 302 && preg_match('~^Location: https://shop\.example\.com/winter\?sid=\d+~mi', $out) === 1
                && self::clicks() === $clicks + 1
            ) {
                $saved++;
            }
        }
        $log = (string) file_get_contents(self::$log);

        self::assertSame(self::CLICKS, $saved, 'clicks saved of ' . self::CLICKS
            . ', answered ' . implode(',', $answers)
            . "\n" . $log);
        self::assertStringContainsString('connected to the primary alone', $log, 'the read connection was refused');
        // The config's primary and its refused read connection, then the
        // primary alone: three asked of the server. A fourth would be later
        // code building the class again (DataEngine asks DB::getInstance()),
        // refused, with the click's report row left to the cron job.
        self::assertSame(3, $fewest, 'connections one click asked for, the least of ' . self::CLICKS);
        self::assertStringNotContainsString('Uncaught', $log);
    }
}

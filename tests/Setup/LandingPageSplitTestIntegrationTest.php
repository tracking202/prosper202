<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Setup\LandingPageSplitTest;
use UnexpectedValueException;

/**
 * A landing page's split test against a real database: saved and read only
 * by the page's account, and through the real files (served by `php -S`):
 * the test landing.php prints for a page, and the variant its script sends
 * as C4 stored on the click record_simple.php writes
 * (served-landing-beacon.js runs the served script under node).
 *
 * @group integration
 */
final class LandingPageSplitTestIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 7481;
    private const OTHER = 7482;
    private const PAGE = 7481;
    private const OTHER_PAGE = 7482;
    private const PUBLIC = 7481 + 100000;
    private const OTHER_PUBLIC = 7482 + 100000;

    private const AB = ['status' => 1, 'overwrite_c4' => 0, 'variants' => [
        ['name' => 'headline-a', 'weight' => 1],
        ['name' => 'headline-b', 'weight' => 0],
        ['name' => 'headline-c', 'weight' => 2],
    ]];

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
            return;
        }
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

    private static function cleanUp(): void
    {
        $users = self::USER . ', ' . self::OTHER;
        self::q("DELETE ct FROM 202_clicks_tracking ct JOIN 202_clicks c USING (click_id) WHERE c.user_id IN ($users)");
        $tables = [
            '202_landing_page_split_tests', '202_clicks', '202_landing_pages',
            '202_aff_campaigns', '202_users_pref', '202_users',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        foreach ([self::USER => self::PUBLIC, self::OTHER => self::OTHER_PUBLIC] as $user => $public) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_pref_privacy = 'disabled'");
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $user, 'aff_campaign_id_public' => $user + 200000, 'user_id' => $user,
                'aff_network_id' => 0, 'aff_campaign_name' => "offer $user",
                'aff_campaign_url' => 'https://offer.example/?s=[[subid]]', 'aff_campaign_payout' => 1,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(), 'identity_signals' => 1,
            ]);
            self::row('202_landing_pages', [
                'landing_page_id' => $user, 'user_id' => $user, 'landing_page_id_public' => $public,
                'aff_campaign_id' => $user, 'landing_page_nickname' => "page $user",
                'landing_page_url' => 'https://lp.example/', 'landing_page_type' => 0, 'landing_page_time' => time(),
            ]);
        }
    }

    private function store(): LandingPageSplitTest
    {
        return new LandingPageSplitTest(new Connection(self::requireScratch()));
    }

    private static function rowsWhere(string $where): string
    {
        $result = self::requireScratch()
            ->query('SELECT COUNT(*) AS n FROM 202_landing_page_split_tests WHERE ' . $where);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_assoc()['n'];
    }

    public function testATestIsSavedAndReadOnlyByItsPagesAccount(): void
    {
        $this->store()->save(self::USER, self::PAGE, self::AB);
        // Another account's page id writes nothing and reads nothing.
        $this->store()->save(self::USER, self::OTHER_PAGE, self::AB);
        self::assertSame(self::AB, $this->store()->load(self::USER, self::PAGE));
        self::assertNull($this->store()->load(self::OTHER, self::PAGE));
        self::assertNull($this->store()->load(self::USER, self::OTHER_PAGE));
        self::assertSame('0', self::rowsWhere('landing_page_id = ' . self::OTHER_PAGE));
        self::assertSame('1', self::rowsWhere('landing_page_id = ' . self::PAGE . ' AND user_id = ' . self::USER));

        // Saving again updates the one row.
        $off = ['status' => 0] + self::AB;
        $this->store()->save(self::USER, self::PAGE, $off);
        self::assertSame($off, $this->store()->load(self::USER, self::PAGE));

        // No test creates no row, and turns off and empties the one there.
        $this->store()->save(self::OTHER, self::OTHER_PAGE, LandingPageSplitTest::defaults());
        self::assertSame('0', self::rowsWhere('landing_page_id = ' . self::OTHER_PAGE));
        $this->store()->save(self::OTHER, self::PAGE, LandingPageSplitTest::defaults());
        self::assertSame($off, $this->store()->load(self::USER, self::PAGE), 'another account cannot clear it');
        $this->store()->save(self::USER, self::PAGE, LandingPageSplitTest::defaults());
        self::assertSame(LandingPageSplitTest::defaults(), $this->store()->load(self::USER, self::PAGE));
    }

    public function testTheScriptsTestIsThePagesOwnAndOnlyWhileItRuns(): void
    {
        self::assertNull($this->store()->forPage(self::PUBLIC), 'no row is no test');
        $this->store()->save(self::USER, self::PAGE, self::AB);
        self::assertSame(
            [
                'variants' => [['name' => 'headline-a', 'weight' => 1], ['name' => 'headline-c', 'weight' => 2]],
                'overwriteC4' => false,
            ],
            $this->store()->forPage(self::PUBLIC),
            'a variant with weight 0 is shown to no one'
        );
        // A row that names another account than its page's is not the page's.
        self::q('UPDATE 202_landing_page_split_tests SET user_id = ' . self::OTHER
            . ' WHERE landing_page_id = ' . self::PAGE);
        self::assertNull($this->store()->forPage(self::PUBLIC));
        self::assertNull($this->store()->load(self::OTHER, self::PAGE));
        self::q('UPDATE 202_landing_page_split_tests SET user_id = ' . self::USER
            . ' WHERE landing_page_id = ' . self::PAGE);

        $this->store()->save(self::USER, self::PAGE, ['status' => 0] + self::AB);
        self::assertNull($this->store()->forPage(self::PUBLIC), 'a test that is off');
    }

    public function testAStoredTestThatDoesNotReadIsAnErrorNotNoTest(): void
    {
        $this->store()->save(self::USER, self::PAGE, self::AB);
        self::q("UPDATE 202_landing_page_split_tests SET split_variants = '[{\"name\":\"a\"}'"
            . ' WHERE landing_page_id = ' . self::PAGE);
        try {
            $this->store()->forPage(self::PUBLIC);
            self::fail('a row that does not read was answered');
        } catch (UnexpectedValueException $e) {
            self::assertStringContainsString('split_variants', $e->getMessage());
        }

        // landing.php serves the page, with no variant and nothing forgotten (false), and logs why.
        [$status, $body] = self::get('/tracking202/static/landing.php?lpip=' . self::PUBLIC);
        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertStringContainsString('var p202Split = false;', $body);
        self::assertStringContainsString('could not read the split test for lpip ' . self::PUBLIC, $this->serverLog());
    }

    public function testTheServedScriptSendsTheVariantAsTheClicksC4(): void
    {
        [, $body] = self::get('/tracking202/static/landing.php?lpip=' . self::PUBLIC);
        self::assertStringContainsString('var p202Split = null;', $body, 'no test');

        $this->store()->save(self::USER, self::PAGE, self::AB);
        [$status, $body] = self::get('/tracking202/static/landing.php?lpip=' . self::PUBLIC);
        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertStringContainsString('var p202Split = {"variants":[{"name":"headline-a","weight":1},'
            . '{"name":"headline-c","weight":2}],"overwriteC4":false};', $body);

        // The page as a visitor loads it: the script picks a variant and sends it as C4,
        // which record_simple.php stores on the click.
        $sent = $this->beacon($body, '?lpip=' . self::PUBLIC . '&c1=one', 0.9);
        self::assertSame('headline-c', $sent['variant']);
        self::assertSame('headline-c', $sent['query']['c4'] ?? null, $sent['url']);
        self::assertSame('one', $sent['query']['c1'] ?? null);
        self::assertSame(['headline-c'], $this->recordedC4($sent['url']));

        // A C4 the traffic source sent is the click's.
        $kept = $this->beacon($body, '?lpip=' . self::PUBLIC . '&c4=src-c4', 0.1);
        self::assertSame('headline-a', $kept['variant']);
        self::assertSame(['headline-c', 'src-c4'], $this->recordedC4($kept['url']));

        // Unless the test replaces it.
        $this->store()->save(self::USER, self::PAGE, ['overwrite_c4' => 1] + self::AB);
        [, $body] = self::get('/tracking202/static/landing.php?lpip=' . self::PUBLIC);
        $replaced = $this->beacon($body, '?lpip=' . self::PUBLIC . '&c4=src-c4', 0.1);
        self::assertSame('headline-a', $replaced['query']['c4'] ?? null);
        self::assertSame(['headline-a', 'headline-c', 'src-c4'], $this->recordedC4($replaced['url']));
    }

    /**
     * The served script run as a page with $search loads it, its random draw
     * $draw: the variant and the record_simple.php URL it requests.
     *
     * @return array{variant: string, url: string, query: array<string, string>}
     */
    private function beacon(string $script, string $search, float $draw): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::assertFalse(getenv('CI') === 'true', 'node is not on PATH, so the script was not run');
            self::markTestSkipped('node is not on PATH');
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'p202lp');
        file_put_contents($file, $script);
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/served-landing-beacon.js') . ' '
            . escapeshellarg($file) . ' ' . escapeshellarg($search) . ' ' . escapeshellarg((string) $draw) . ' 2>&1';
        exec($command, $lines, $exit);
        @unlink($file);
        self::assertSame(0, $exit, implode("\n", $lines));
        $answer = json_decode(implode("\n", $lines), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $url = (string) $answer['url'];
        self::assertStringStartsWith(self::$base . '/tracking202/static/record.php?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['variant' => (string) $answer['variant'], 'url' => $url, 'query' => $query];
    }

    /**
     * Request the pageview as the browser would (record.php is the simple
     * page's record_simple.php), and the C4 of every click of the account.
     *
     * @return list<string>
     */
    private function recordedC4(string $url): array
    {
        [$status, $body] = self::get(substr($url, strlen(self::$base)));
        self::assertSame(200, $status, $body . $this->serverLog());
        $result = self::requireScratch()->query('SELECT t4.c4 FROM 202_clicks c'
            . ' JOIN 202_clicks_tracking ct USING (click_id)'
            . ' JOIN 202_tracking_c4 t4 ON t4.c4_id = ct.c4_id WHERE c.user_id = ' . self::USER . ' ORDER BY t4.c4');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'c4');
    }

    /** @return array{int, string} status, body */
    private static function get(string $path): array
    {
        if (self::$base === '') {
            self::fail('the landing scripts could not be served');
        }
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $body = (string) curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }
}

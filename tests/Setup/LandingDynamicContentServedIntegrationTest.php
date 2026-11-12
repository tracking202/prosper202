<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * The dynamic-content segments 1.9.56 had, through the real files on a real
 * database (served by `php -S`, as ClickDefaultsServedIntegrationTest is):
 * the visitor's IP only under the page's account's privacy setting, dates in
 * that account's zone, and the click's subid from record_simple.php only
 * when its cookies may be set and the visitor consents, with the proof
 * dcs.updateDataObj() changes that click's c1-c4 and keyword with
 * (static/dcs-update.php, and 1.9.56's redirect/u.php routed to it).
 *
 * @group integration
 */
final class LandingDynamicContentServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TRACKED = 7461;
    private const HELD_BACK = 7462;
    private const TRACKED_PAGE = 7461 + 100000;
    private const HELD_BACK_PAGE = 7462 + 100000;
    private const EU = 7463;
    private const EU_PAGE = 7463 + 100000;
    /** The tracked account's traffic source account, and a tracker on it. */
    private const SOURCE = 7464;
    private const TRACKER_PUBLIC = 746407;
    /** Another account's traffic source account and tracker (#27). */
    private const OTHER_SOURCE = 7465;
    private const OTHER_TRACKER_PUBLIC = 746507;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';

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
        // The server's state (its rate limit) where this process finds it too.
        self::$state = sys_get_temp_dir() . '/p202-dcs-state-' . bin2hex(random_bytes(6));
        putenv('P202_SERVER_STATE_DIR=' . self::$state);
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
        putenv('P202_SERVER_STATE_DIR');
        if (self::$state !== '' && is_dir(self::$state)) {
            exec('rm -rf ' . escapeshellarg(self::$state));
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $users = self::TRACKED . ', ' . self::HELD_BACK . ', ' . self::EU;
        $tables = ['202_trackers', '202_ppc_accounts', '202_ppc_networks', '202_landing_pages', '202_aff_campaigns',
            '202_users_pref', '202_users'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        \Api\V3\Apps\Android\InstallTokenKey::ensure(self::requireScratch());
        // 127.0.0.1 is a visitor GeoIP cannot place: 'all' and 'eu' hold it back.
        foreach ([self::TRACKED => ['disabled', 'Asia/Tokyo'], self::HELD_BACK => ['all', 'America/New_York'],
            self::EU => ['eu', 'Europe/Paris']]
            as $user => [$privacy, $zone]) {
            self::user($user, $zone);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_pref_privacy = " . self::str($privacy));
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $user, 'aff_campaign_id_public' => $user + 100000, 'user_id' => $user,
                'aff_network_id' => 0, 'aff_campaign_name' => "offer $user",
                'aff_campaign_url' => 'https://offer.example/?s=[[subid]]', 'aff_campaign_payout' => 1,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(), 'identity_signals' => 1,
            ]);
            self::row('202_landing_pages', [
                'landing_page_id' => $user, 'user_id' => $user, 'landing_page_id_public' => $user + 100000,
                'aff_campaign_id' => $user, 'landing_page_nickname' => "page $user",
                'landing_page_url' => 'https://lp.example/', 'landing_page_type' => 0, 'landing_page_time' => time(),
            ]);
        }
        $accounts = [
            [self::TRACKED, self::SOURCE, self::TRACKER_PUBLIC],
            [self::HELD_BACK, self::OTHER_SOURCE, self::OTHER_TRACKER_PUBLIC],
        ];
        foreach ($accounts as [$user, $source, $public]) {
            $mine = ['user_id' => $user];
            self::row('202_ppc_networks', $mine + [
                'ppc_network_id' => $source, 'ppc_network_name' => 'Source', 'ppc_network_time' => time(),
            ]);
            self::row('202_ppc_accounts', $mine + [
                'ppc_account_id' => $source, 'ppc_network_id' => $source, 'ppc_account_name' => 'Account',
                'ppc_account_time' => time(),
            ]);
            self::row('202_trackers', $mine + [
                'tracker_id' => $source, 'tracker_id_public' => $public, 'text_ad_id' => 0,
                'ppc_account_id' => $source, 'landing_page_id' => $user, 'aff_campaign_id' => $user,
                'rotator_id' => 0, 'click_cpc' => 0.1, 'click_cloaking' => -1, 'tracker_time' => time(),
            ]);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the landing scripts could not be served');
        }
    }

    /** @return array{int, string} status, body */
    private static function get(string $path): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$status, $body];
    }

    /** @return array{int, string, string} status, body, content type */
    private static function post(string $path, string|array $body): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body]);
        $answer = (string) curl_exec($ch);

        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $answer, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }

    /** @return array<string, string> record.php's answer to dcs */
    private function dcsAnswer(string $query): array
    {
        [$status, $body] = self::get('/tracking202/static/record_simple.php?' . $query);
        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertSame(1, preg_match('/window\.p202DcsClick\((\{.*?\})\); \} catch/', $body, $m), $body);

        return json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string|null> the click's values as the reports read them */
    private static function values(int $clickId): array
    {
        $result = self::requireScratch()->query('SELECT t1.c1, t2.c2, k.keyword, c.click_cpc, de.c1_id = ct.c1_id AS report_c1'
            . ' FROM 202_clicks c JOIN 202_clicks_tracking ct USING (click_id) JOIN 202_clicks_advance ca USING (click_id)'
            . ' LEFT JOIN 202_tracking_c1 t1 ON t1.c1_id = ct.c1_id LEFT JOIN 202_tracking_c2 t2 ON t2.c2_id = ct.c2_id'
            . ' LEFT JOIN 202_keywords k ON k.keyword_id = ca.keyword_id LEFT JOIN 202_dataengine de ON de.click_id = c.click_id'
            . " WHERE c.click_id = $clickId");
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, "click $clickId");

        return $row;
    }

    /** @return array<string, string> the script's server-side segments */
    private function segments(string $query): array
    {
        [$status, $body] = self::get('/tracking202/static/landing.php?' . $query);
        self::assertSame(200, $status, $body . $this->serverLog());
        self::assertSame(1, preg_match('/var serverData = (\{.*\});/', $body, $m), $body);

        return json_decode($m[1], true, 4, JSON_THROW_ON_ERROR);
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    public function testTheIpSegmentFollowsThePagesPrivacySetting(): void
    {
        self::assertSame('127.0.0.1', $this->segments('lpip=' . self::TRACKED_PAGE)['t202IP']);
        self::assertSame('', $this->segments('lpip=' . self::HELD_BACK_PAGE)['t202IP'], 'held back');
        self::assertSame('', $this->segments('lpip=' . self::EU_PAGE)['t202IP'], "'eu': a visitor GeoIP cannot place");
        self::assertSame('', $this->segments('lpip=' . (7469 + 100000))['t202IP'], 'no such page');
    }

    public function testDatesAreInThePagesZone(): void
    {
        foreach ([self::TRACKED_PAGE => 'Asia/Tokyo', self::HELD_BACK_PAGE => 'America/New_York'] as $page => $zone) {
            $before = new \DateTimeImmutable('now', new \DateTimeZone($zone));
            $segments = $this->segments('lpip=' . $page);
            $after = new \DateTimeImmutable('now', new \DateTimeZone($zone));
            $actual = [$segments['t202DayOfWeek'], $segments['t202DayOfWeekShort'], $segments['t202MonthOfYear'],
                $segments['t202MonthOfYearShort'], $segments['t202DayOfMonth'], $segments['t202Year'],
                $segments['t202YearShort']];
            $expected = static fn (\DateTimeImmutable $t): array => explode('|', $t->format('l|D|F|M|j|Y|y'));
            self::assertContains($actual, [$expected($before), $expected($after)], $zone);
        }
    }

    public function testTheSubidSegmentComesWithTheClickWhenItsCookiesMayBeSet(): void
    {
        [$status, $body] = self::get('/tracking202/static/record_simple.php?lpip=' . self::TRACKED_PAGE);
        self::assertSame(200, $status, $body . $this->serverLog());
        $click = self::requireScratch()->query('SELECT MAX(click_id) FROM 202_clicks WHERE user_id = ' . self::TRACKED)
            ->fetch_row()[0];
        self::assertNotNull($click, 'the click is recorded');
        // No tracker named: the click has no traffic source account.
        $answer = 'window.p202DcsClick({"t202Subid":"' . $click . '","t202SourceId":"",'
            . '"updateProof":"update.' . $click . '.';
        self::assertStringContainsString($answer, $body);

        // Through a tracker: the click's traffic source account, as [[sourceid]] takes it.
        $answer = $this->dcsAnswer('lpip=' . self::TRACKED_PAGE . '&t202id=' . self::TRACKER_PUBLIC);
        $db = self::requireScratch();
        $latest = 'SELECT MAX(click_id) FROM 202_clicks WHERE user_id = ' . self::TRACKED;
        $click = (string) $db->query($latest)->fetch_row()[0];
        self::assertSame([$click, (string) self::SOURCE], [$answer['t202Subid'], $answer['t202SourceId']]);
        $stored = $db->query("SELECT ppc_account_id FROM 202_clicks WHERE click_id = $click")->fetch_row()[0];
        self::assertSame((string) self::SOURCE, (string) $stored, 'the click is recorded on that account');

        // Another account's tracker names no traffic source account of this page's (#27).
        $answer = $this->dcsAnswer('lpip=' . self::TRACKED_PAGE . '&t202id=' . self::OTHER_TRACKER_PUBLIC);
        $click = (string) $db->query($latest)->fetch_row()[0];
        self::assertSame([$click, ''], [$answer['t202Subid'], $answer['t202SourceId']]);
        $stored = $db->query("SELECT ppc_account_id FROM 202_clicks WHERE click_id = $click")->fetch_row()[0];
        self::assertSame('0', (string) $stored, 'and the click is recorded on none');

        foreach (['refused' => self::TRACKED_PAGE . '&p202_consent=0', 'held back' => (string) self::HELD_BACK_PAGE,
            "'eu'" => (string) self::EU_PAGE] as $why => $query) {
            [$status, $body] = self::get('/tracking202/static/record_simple.php?lpip=' . $query);
            self::assertSame(200, $status, $why);
            self::assertStringContainsString('var subid =', $body, "$why: the click is answered");
            self::assertStringNotContainsString('p202DcsClick(', $body, $why);
        }
    }

    public function testThePagesUpdateChangesTheClickItRecordedAndNoOther(): void
    {
        $answer = $this->dcsAnswer('lpip=' . self::TRACKED_PAGE . '&c1=from+the+url&t202kw=url+keyword');
        $click = (int) $answer['t202Subid'];
        $proof = $answer['updateProof'];
        $other = (int) $this->dcsAnswer('lpip=' . self::TRACKED_PAGE . '&c1=another+visitor')['t202Subid'];
        self::assertSame(['from the url', 'url keyword'], [self::values($click)['c1'], self::values($click)['keyword']]);

        // What landing.php's dcs.updateDataObj() sends.
        $sent = self::post('/tracking202/static/dcs-update.php', http_build_query([
            'proof' => $proof, 't202c1' => 'Spring sale', 't202kw' => 'blue shoes',
            't202Subid' => (string) $other, 't202Id' => '1', 't202Utm_source' => 'news',
        ]));
        self::assertSame([204, ''], [$sent[0], $sent[1]], $this->serverLog());
        $values = self::values($click);
        self::assertSame(['Spring sale', 'blue shoes', '1'], [$values['c1'], $values['keyword'], $values['report_c1']]);
        self::assertSame('another visitor', self::values($other)['c1'], 'a subid in the request names no click');

        // 1.9.56's beacon: FormData to redirect/u.php, with the proof the new script adds.
        $sent = self::post('/tracking202/redirect/u.php', ['proof' => $proof, 't202C2' => 'from u.php', 't202Subid' => (string) $other]);
        self::assertSame([204, ''], [$sent[0], $sent[1]], $this->serverLog());
        self::assertSame('from u.php', self::values($click)['c2']);
        self::assertNotSame('from u.php', self::values($other)['c2']);

        // Nothing without a valid proof, answered without an error.
        $before = [self::values($click), self::values($other)];
        [$status, $gif] = self::get('/tracking202/redirect/u.php?t202Subid=' . $other . '&t202C1=hijack');
        self::assertSame(200, $status);
        self::assertSame('GIF89a', substr($gif, 0, 6), '1.9.56\'s image fallback still gets an image');
        foreach ([
            'u.php without a proof' => ['/tracking202/redirect/u.php', ['t202Subid' => (string) $other, 't202C1' => 'hijack']],
            'no proof' => ['/tracking202/static/dcs-update.php', 't202Subid=' . $other . '&t202c1=hijack'],
            'tampered' => ['/tracking202/static/dcs-update.php', 'proof=' . rawurlencode($proof . 'x') . '&t202c1=hijack'],
            'refused' => ['/tracking202/static/dcs-update.php', 'proof=' . rawurlencode($proof) . '&t202c1=hijack&p202_consent=0'],
            'refused in capitals' => ['/tracking202/static/dcs-update.php', 'proof=' . rawurlencode($proof) . '&t202c1=hijack&P202_CONSENT=0'],
            'too long' => ['/tracking202/static/dcs-update.php', 'proof=' . rawurlencode($proof) . '&t202c1=' . str_repeat('h', 30000)],
        ] as $why => [$path, $body]) {
            $sent = self::post($path, $body);
            self::assertSame([204, ''], [$sent[0], $sent[1]], $why);
        }
        self::assertSame($before, [self::values($click), self::values($other)]);

        $log = (string) file_get_contents(self::$log);
        foreach ([
            'dcs update refused (redirect/u.php): a GET carries no update proof',
            'dcs update refused (redirect/u.php): no update proof',
            'dcs update refused (dcs-update.php): the proof is invalid',
            'dcs update refused (dcs-update.php): the visitor refused tracking',
            'dcs update refused (dcs-update.php): body over',
            'dcs update (dcs-update.php): ignored fields a page may not change: t202id, t202utm_source',
        ] as $line) {
            self::assertStringContainsString($line, $log);
        }
    }

    /** Every refusal is logged, so none is past the limit. The window is emptied after. */
    public function testNothingIsLoggedPastTheRateLimit(): void
    {
        $store = \Api\V3\Support\ServerStateStore::forInstall(self::requireScratch());
        $buckets = $store->baseDir() . '/rate_limits/*dcs-update*';
        try {
            for ($i = 0; $i < 600; $i++) {
                $store->softIpRateLimit('dcs-update', 600, 60, '127.0.0.1');
            }
            $before = (string) file_get_contents(self::$log);
            self::assertSame(429, self::get('/tracking202/redirect/u.php?t202Subid=1&t202C1=x')[0], 'u.php\'s image');
            self::assertSame(429, self::get('/tracking202/static/dcs-update.php')[0], 'a GET to the endpoint');
            self::assertSame(429, self::post('/tracking202/static/dcs-update.php', 't202c1=x')[0], 'no proof');
            self::assertSame($before, (string) file_get_contents(self::$log), 'no refusal written');
        } finally {
            foreach ((array) glob($buckets) as $file) {
                @unlink((string) $file);
            }
        }
    }
}

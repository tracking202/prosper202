<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;

/**
 * The dynamic-content segments 1.9.56 had, through the real files on a real
 * database (served by `php -S`, as ClickDefaultsServedIntegrationTest is):
 * the visitor's IP only under the page's account's privacy setting, dates in
 * that account's zone, and the click's subid from record_simple.php only
 * when its cookies may be set and the visitor consents.
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

    private static function cleanUp(): void
    {
        $users = self::TRACKED . ', ' . self::HELD_BACK;
        foreach (['202_landing_pages', '202_aff_campaigns', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function seed(): void
    {
        self::cleanUp();
        // 127.0.0.1 is a visitor GeoIP cannot place: 'all' and 'eu' hold it back.
        foreach ([self::TRACKED => ['disabled', 'Asia/Tokyo'], self::HELD_BACK => ['all', 'America/New_York']]
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
        self::assertStringContainsString('window.p202DcsClick({"t202Subid":"' . $click . '"})', $body);

        foreach (['refused' => self::TRACKED_PAGE . '&p202_consent=0', 'held back' => (string) self::HELD_BACK_PAGE] as $why => $query) {
            [$status, $body] = self::get('/tracking202/static/record_simple.php?lpip=' . $query);
            self::assertSame(200, $status, $why);
            self::assertStringContainsString('var subid =', $body, "$why: the click is answered");
            self::assertStringNotContainsString('p202DcsClick(', $body, $why);
        }
    }
}

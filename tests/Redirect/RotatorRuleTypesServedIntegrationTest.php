<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Redirector rules of the paid 1.9.56 types (utm, c1-c4, keyword, referer,
 * IP range, visitor) through the real rtr.php and offrtr.php on a scratch
 * database, served by `php -S` over the integration job's 202-config.php.
 * Before 1.9.80 the redirects matched none of these types, so every visitor
 * went on to the default.
 *
 * Each visit comes from its own documentation address (X-Forwarded-For, which
 * the click path reads), so the repeat-visit filter sees each one new unless
 * a test repeats it on purpose.
 *
 * @group integration
 */
final class RotatorRuleTypesServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 7301;
    private const ROTATOR = 7311;
    private const TRACKER = 7331;
    private const PUBLIC = 100000;

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
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
                '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2)],
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
        self::q('DELETE FROM 202_rotator_rules_redirects WHERE rule_id BETWEEN 7340 AND 7399');
        self::q('DELETE FROM 202_rotator_rules_criteria WHERE rotator_id = ' . self::ROTATOR);
        self::q('DELETE FROM 202_rotator_rules WHERE rotator_id = ' . self::ROTATOR);
        self::q('DELETE FROM 202_rotators WHERE id = ' . self::ROTATOR);
        self::q('DELETE FROM 202_trackers WHERE tracker_id = ' . self::TRACKER);
        self::q('DELETE FROM 202_last_ips WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::OWNER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::OWNER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER
            . ", user_pref_privacy = 'disabled', user_keyword_searched_or_bidded = 'bidded'");
        self::row('202_rotators', [
            'id' => self::ROTATOR, 'public_id' => self::ROTATOR + self::PUBLIC, 'user_id' => self::OWNER,
            'name' => 'rule types', 'default_url' => 'https://default.example/', 'default_campaign' => null,
            'default_lp' => null,
        ]);
        self::row('202_trackers', [
            'tracker_id' => self::TRACKER, 'user_id' => self::OWNER, 'tracker_id_public' => self::TRACKER + self::PUBLIC,
            'aff_campaign_id' => 0, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0,
            'rotator_id' => self::ROTATOR, 'click_cloaking' => 0, 'tracker_time' => time(),
        ]);
        // Tried in this order; the visitor rules match everyone else, so they come last.
        $rules = [
            // The account has no IPQualityScore key: the score is unknown, so this matches nobody.
            7340 => ['fraud', 'is_not', 'true', 'https://not-fraud.example/'],
            7341 => ['utm_source', 'is', 'newsletter', 'https://utm.example/'],
            7342 => ['c1', 'is', 'spring', 'https://c1.example/'],
            7343 => ['t202kw', 'is', 'crm trial', 'https://keyword.example/'],
            7344 => ['referer', 'is', 'partner\.example', 'https://referer.example/'],
            7345 => ['iprange', 'is', '192.0.2.0/24', 'https://range.example/'],
            7346 => ['visitor', 'is', 'filtered', 'https://filtered.example/'],
            7347 => ['visitor', 'is_not', 'filtered', 'https://unique.example/'],
        ];
        $position = 0;
        foreach ($rules as $id => [$type, $statement, $value, $url]) {
            self::row('202_rotator_rules', [
                'id' => $id, 'rotator_id' => self::ROTATOR, 'rule_name' => "$type rule", 'status' => 1,
                'position' => ++$position,
            ]);
            self::row('202_rotator_rules_criteria', [
                'rotator_id' => self::ROTATOR, 'rule_id' => $id, 'type' => $type, 'statement' => $statement,
                'value' => $value,
            ]);
            self::row('202_rotator_rules_redirects', ['rule_id' => $id, 'redirect_url' => $url, 'weight' => '0', 'name' => '']);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    /** @return array{int, string} status, headers */
    private static function get(string $path, string $ip, string $referer = '', string $cookie = ''): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_REFERER => $referer, CURLOPT_COOKIE => $cookie,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['X-Forwarded-For: ' . $ip]]);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [$status, substr($out, 0, $size)];
    }

    private static function location(string $headers): string
    {
        return preg_match('~^Location:\s*(\S+)\s*$~mi', $headers, $m) === 1 ? $m[1] : '';
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    /** @return iterable<string, array{string, string, string, string}> query, address, referer, where the visitor goes */
    public static function visits(): iterable
    {
        yield 'utm_source' => ['&utm_source=newsletter', '198.51.100.11', '', 'https://utm.example/'];
        yield 'c1' => ['&c1=spring', '198.51.100.12', '', 'https://c1.example/'];
        yield 't202kw' => ['&t202kw=crm%20trial', '198.51.100.13', '', 'https://keyword.example/'];
        yield 'referer' => ['', '198.51.100.14', 'https://www.partner.example/post', 'https://referer.example/'];
        yield 'iprange' => ['', '192.0.2.77', '', 'https://range.example/'];
        yield 'visitor unique' => ['', '198.51.100.15', '', 'https://unique.example/'];
    }

    /** @dataProvider visits */
    public function testARuleOfEachTypeTakesItsVisitorThroughTheRedirectorLink(
        string $query,
        string $ip,
        string $referer,
        string $expected
    ): void {
        [$status, $headers] = self::get('/tracking202/redirect/rtr.php?t202id=' . (self::TRACKER + self::PUBLIC) . $query, $ip, $referer);
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertStringStartsWith($expected, self::location($headers), $headers);
    }

    /**
     * A visitor rule asks for the filter verdict before the click is written,
     * and the verdict's repeat-visit check records the address: asked twice,
     * the first visit would be filtered as a repeat of itself.
     */
    public function testAVisitorRuleSeesTheVerdictTheClickIsRecordedWith(): void
    {
        $ip = '198.51.100.16';
        $path = '/tracking202/redirect/rtr.php?t202id=' . (self::TRACKER + self::PUBLIC);
        [, $first] = self::get($path, $ip);
        self::assertStringStartsWith('https://unique.example/', self::location($first), $first . $this->serverLog());
        $recorded = self::requireScratch()->query('SELECT click_filtered FROM 202_clicks WHERE user_id = ' . self::OWNER
            . ' ORDER BY click_id DESC LIMIT 1')->fetch_row();
        self::assertSame('0', (string) ($recorded[0] ?? ''), 'the first visit is recorded unique, as the rule read it');

        [, $second] = self::get($path, $ip);
        self::assertStringStartsWith('https://filtered.example/', self::location($second), 'a repeat visit is filtered');
    }

    /**
     * offrtr.php is reached by the landing page's bare ?rpi= link, which
     * carries none of the visitor's values: its c1-c4, utm, keyword and
     * referer rules match on the click the landing page recorded (R7FX),
     * never on the offer request, and a click with none of them falls to the
     * visitor rule.
     *
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function offerLinkCases(): iterable
    {
        // [the visitor's own link query, its referer, ip, extra on the offer request, expected destination]
        yield 'utm from the click' => ['&utm_source=newsletter', '', '198.51.100.21', '', 'https://utm.example/'];
        yield 'c1 from the click' => ['&c1=spring', '', '198.51.100.22', '', 'https://c1.example/'];
        yield 'keyword from the click' => ['&t202kw=crm%20trial', '', '198.51.100.23', '', 'https://keyword.example/'];
        yield 'referer from the click' => ['', 'https://www.partner.example/post', '198.51.100.24', '', 'https://referer.example/'];
        yield 'the offer request is not read' => ['', '', '198.51.100.25', '&utm_source=newsletter&c1=spring', 'https://unique.example/'];
    }

    /** @dataProvider offerLinkCases */
    public function testTheOfferLinkMatchesTheRecordedClick(string $link, string $referer, string $ip, string $offerExtra, string $expected): void
    {
        self::get('/tracking202/redirect/rtr.php?t202id=' . (self::TRACKER + self::PUBLIC) . $link, $ip, $referer);
        $click = (int) (self::requireScratch()->query('SELECT MAX(click_id) FROM 202_clicks WHERE user_id = ' . self::OWNER)
            ->fetch_row()[0] ?? 0);
        self::assertGreaterThan(0, $click, 'the redirector link recorded a click' . $this->serverLog());
        $offer = '/tracking202/redirect/offrtr.php?rpi=' . (self::ROTATOR + self::PUBLIC) . $offerExtra;
        [$status, $headers] = self::get($offer, $ip, 'https://lp.example/landing', 'tracking202subid=' . $click);
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertStringStartsWith($expected, self::location($headers), $headers);
    }
}

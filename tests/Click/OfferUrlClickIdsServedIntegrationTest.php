<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;

/**
 * An offer URL that names an ad click id with its token gets the id once,
 * through the real dl.php, cl.php and rtr.php on the scratch database, served
 * by `php -S` over the 202-config.php the integration job writes.
 *
 * The link's gbraid, wbraid and ttclid are passed on to the offer
 * (getPrePopVars()), and `gbraid=[[gbraid]]` filled carried it too: the
 * offer got it twice (4V9P). A cloaked link packs the pass-on into 202vars,
 * which cl.php adds to the offer URL the click stored. An offer URL that
 * names none still gets the three passed on.
 *
 * @group integration
 */
final class OfferUrlClickIdsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 48901;
    private const PUBLIC = 100000;
    private const NAMED = 48911;
    private const UNNAMED = 48912;
    private const DIRECT = 48921;
    private const CLOAKED = 48922;
    private const PLAIN = 48923;
    private const ROTATED = 48924;
    private const ROTATOR = 48931;

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
            self::$db = null; // no config for the served tree: skipped, as without a database

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
        $ids = ['202_trackers' => 'tracker_id', '202_rotators' => 'id', '202_aff_campaigns' => 'aff_campaign_id'];
        foreach ($ids as $table => $id) {
            self::q("DELETE FROM $table WHERE $id BETWEEN 48900 AND 48999");
        }
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::OWNER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::OWNER);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER . ", user_pref_privacy = 'disabled'");
        $tokens = '';
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $tokens .= '&' . $name . '=[[' . $name . ']]';
        }
        $offers = [
            self::NAMED => 'https://offer.example/named?s=[[subid]]' . $tokens,
            self::UNNAMED => 'https://offer.example/plain?s=[[subid]]',
        ];
        foreach ($offers as $id => $url) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'aff_campaign_id_public' => $id + self::PUBLIC, 'user_id' => self::OWNER,
                'aff_network_id' => 0, 'aff_campaign_name' => "campaign $id", 'aff_campaign_url' => $url,
                'aff_campaign_payout' => 1, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
            ]);
        }
        self::row('202_rotators', [
            'id' => self::ROTATOR, 'public_id' => self::ROTATOR + self::PUBLIC, 'user_id' => self::OWNER,
            'name' => 'rotator', 'default_url' => null, 'default_campaign' => self::NAMED, 'default_lp' => null,
        ]);
        $trackers = [
            [self::DIRECT, self::NAMED, 0, 0],
            [self::CLOAKED, self::NAMED, 0, 1],
            [self::PLAIN, self::UNNAMED, 0, 0],
            [self::ROTATED, 0, self::ROTATOR, 0],
        ];
        foreach ($trackers as [$id, $campaign, $rotator, $cloak]) {
            self::row('202_trackers', [
                'tracker_id' => $id, 'user_id' => self::OWNER, 'tracker_id_public' => $id + self::PUBLIC,
                'aff_campaign_id' => $campaign, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => 0,
                'rotator_id' => $rotator, 'click_cloaking' => $cloak, 'tracker_time' => time(),
            ]);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    /** @return array{int, string, string} status, headers, body */
    private static function get(string $path): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private static function location(string $headers): string
    {
        self::assertSame(1, preg_match('~^Location: ([^\r\n]*)~mi', $headers, $m), $headers);

        return $m[1];
    }

    /** The link's query: every ad click id, and a parameter of the source's own. */
    private static function link(int $tracker): string
    {
        $query = 't202id=' . ($tracker + self::PUBLIC) . '&adgroup=spring';
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $query .= '&' . $name . '=ID-' . $name;
        }

        return $query;
    }

    /** @return array<string, list<string>> each parameter of $url's query with every value it carries */
    private static function parameters(string $url): array
    {
        $parameters = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $piece) {
            [$name, $value] = array_pad(explode('=', $piece, 2), 2, '');
            $parameters[urldecode($name)][] = urldecode($value);
        }

        return $parameters;
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    private function assertEachIdOnce(string $url): void
    {
        $parameters = self::parameters($url);
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            self::assertSame(['ID-' . $name], $parameters[$name] ?? [], "$name in $url" . $this->serverLog());
        }
        self::assertSame(['spring'], $parameters['adgroup'] ?? [], "the link's own parameter goes on, once: $url");
    }

    public function testADirectLinkSendsEachIdTheOfferUrlNamesOnce(): void
    {
        [$status, $headers] = self::get('/tracking202/redirect/dl.php?' . self::link(self::DIRECT));
        self::assertSame(302, $status, $headers . $this->serverLog());
        $offer = self::location($headers);
        self::assertStringStartsWith('https://offer.example/named?s=', $offer);
        $this->assertEachIdOnce($offer);
    }

    public function testACloakedLinkSendsEachIdTheOfferUrlNamesOnce(): void
    {
        [$status, $headers] = self::get('/tracking202/redirect/dl.php?' . self::link(self::CLOAKED));
        self::assertSame(302, $status, $headers . $this->serverLog());
        $cloaked = self::location($headers);
        self::assertStringContainsString('tracking202/redirect/cl.php?pci=', $cloaked);

        [$status, $headers, $body] = self::get($cloaked);
        self::assertSame(200, $status, $headers . $body . $this->serverLog());
        self::assertSame(1, preg_match('~http-equiv="refresh" content="0; url=([^"]*)"~', $body, $m), $body);
        $offer = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        self::assertStringStartsWith('https://offer.example/named?s=', $offer);
        $this->assertEachIdOnce($offer);
    }

    public function testARedirectorSendsEachIdTheOfferUrlNamesOnce(): void
    {
        [$status, $headers] = self::get('/tracking202/redirect/rtr.php?' . self::link(self::ROTATED));
        self::assertSame(302, $status, $headers . $this->serverLog());
        $offer = self::location($headers);
        self::assertStringStartsWith('https://offer.example/named?s=', $offer);
        $this->assertEachIdOnce($offer);
    }

    /** As before 4V9P: the gbraid, wbraid and ttclid go on under their own names, the others not. */
    public function testAnOfferUrlThatNamesNoIdGetsTheThreePassedOn(): void
    {
        [$status, $headers] = self::get('/tracking202/redirect/dl.php?' . self::link(self::PLAIN));
        self::assertSame(302, $status, $headers . $this->serverLog());
        $parameters = self::parameters(self::location($headers));
        foreach (array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $expected = in_array($name, ['gbraid', 'wbraid', 'ttclid'], true) ? ['ID-' . $name] : [];
            self::assertSame($expected, $parameters[$name] ?? [], $name);
        }
    }
}

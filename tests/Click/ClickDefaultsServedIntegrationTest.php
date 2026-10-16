<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * rtr.php's rotator default and landing.php's capture flag, through the real
 * files on a real database: the scratch database, served by `php -S` over the
 * 202-config.php the integration job writes (tests/run-integration-suites.sh).
 *
 * A rotator's default landing page is the one its account-tied join returns:
 * another account's page reads as none, and a rotator with no default this
 * account can send the visitor to records no click and sends no empty
 * Location. An advanced landing page reached with a t202id its account cannot
 * read leaves identity capture off (RequestSignals::trackerAllows()).
 *
 * @group integration
 */
final class ClickDefaultsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 3901;
    private const OTHER = 3902;

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
        $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
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
        foreach (['202_trackers' => 'tracker_id', '202_rotators' => 'id', '202_landing_pages' => 'landing_page_id',
            '202_aff_campaigns' => 'aff_campaign_id'] as $table => $id) {
            self::q("DELETE FROM $table WHERE $id BETWEEN 3900 AND 3999");
        }
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ')');
    }

    private static function seed(): void
    {
        self::cleanUp();
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user");
        }
        $page = static fn (int $id, int $user, string $url, int $type = 0) => self::row('202_landing_pages', [
            'landing_page_id' => $id, 'user_id' => $user, 'landing_page_id_public' => $id + 100000,
            'aff_campaign_id' => 0, 'landing_page_nickname' => "page $id", 'landing_page_url' => $url,
            'landing_page_type' => $type, 'landing_page_time' => time(),
        ]);
        $page(3901, self::OTHER, 'https://foreign.example/lp');
        $page(3902, self::OWNER, 'https://own.example/lp');
        $page(3903, self::OWNER, 'https://own.example/advanced', 1);
        $rotator = static fn (int $id, ?int $defaultLp) => self::row('202_rotators', [
            'id' => $id, 'public_id' => $id + 100000, 'user_id' => self::OWNER, 'name' => "rotator $id",
            'default_url' => null, 'default_campaign' => null, 'default_lp' => $defaultLp,
        ]);
        $rotator(3911, 3901);
        $rotator(3912, 3902);
        $rotator(3913, null);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => 3921, 'aff_campaign_id_public' => 3921 + 100000, 'user_id' => self::OWNER,
            'aff_network_id' => 0, 'aff_campaign_name' => 'capture off', 'aff_campaign_url' => 'https://offer.example/',
            'aff_campaign_payout' => 1, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
            'identity_signals' => 0,
        ]);
        foreach ([[3922, self::OWNER, 1], [3923, self::OTHER, 1]] as [$id, $user, $signals]) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'aff_campaign_id_public' => $id + 100000, 'user_id' => $user,
                'aff_network_id' => 0, 'aff_campaign_name' => "campaign $id",
                'aff_campaign_url' => "https://offer.example/$id?s=[[subid]]", 'aff_campaign_payout' => 1,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(), 'identity_signals' => $signals,
            ]);
        }
        $tracker = static fn (int $id, int $user, int $rotatorId, int $campaign, int $page) => self::row('202_trackers', [
            'tracker_id' => $id, 'user_id' => $user, 'tracker_id_public' => $id + 100000,
            'aff_campaign_id' => $campaign, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => $page,
            'rotator_id' => $rotatorId, 'click_cloaking' => 0, 'tracker_time' => time(),
        ]);
        $tracker(3931, self::OWNER, 3911, 0, 0);
        $tracker(3932, self::OWNER, 3912, 0, 0);
        $tracker(3933, self::OWNER, 3913, 0, 0);
        $tracker(3934, self::OWNER, 0, 3921, 3903);
        $tracker(3935, self::OTHER, 0, 0, 0);
        $tracker(3936, self::OWNER, 0, 0, 3903);
        $tracker(3937, self::OWNER, 0, 3922, 0);
        $tracker(3938, self::OWNER, 0, 3923, 0);
        foreach ([3914 => 3921, 3915 => 3922] as $id => $campaign) {
            self::row('202_rotators', [
                'id' => $id, 'public_id' => $id + 100000, 'user_id' => self::OWNER, 'name' => "rotator $id",
                'default_url' => null, 'default_campaign' => $campaign, 'default_lp' => null,
            ]);
        }
        $tracker(3939, self::OWNER, 3914, 0, 0);
        $tracker(3940, self::OWNER, 3915, 0, 0);
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    /** @return array{int, string, string} status, headers, body */
    private static function get(string $path, string $referer = ''): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_REFERER => $referer,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private static function clicks(): int
    {
        return (int) self::requireScratch()->query('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER)
            ->fetch_row()[0];
    }

    /** The site URLs the newest click of the account stored, by column. */
    private static function newestClickSites(): array
    {
        $db = self::requireScratch();
        $row = $db->query('SELECT cs.* FROM 202_clicks AS c JOIN 202_clicks_site AS cs ON cs.click_id = c.click_id'
            . ' WHERE c.user_id = ' . self::OWNER . ' ORDER BY c.click_id DESC LIMIT 1')->fetch_assoc();
        self::assertIsArray($row, 'the click has its site row');
        $urls = [];
        foreach (['click_referer_site_url_id', 'click_outbound_site_url_id', 'click_redirect_site_url_id'] as $col) {
            $address = $db->query('SELECT site_url_address FROM 202_site_urls WHERE site_url_id = '
                . (int) $row[$col])->fetch_row();
            $urls[$col] = $address[0] ?? null;
        }

        return $urls;
    }

    private static function cookieSet(string $headers, string $name): bool
    {
        return preg_match('~^Set-Cookie: ' . preg_quote($name, '~') . '=~mi', $headers) === 1;
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    public function testARotatorsOwnDefaultPageIsWhereTheVisitorGoes(): void
    {
        $before = self::clicks();
        $path = '/tracking202/redirect/rtr.php?t202id=' . (3932 + 100000) . '&c1=visit-1';
        [$status, $headers] = self::get($path, 'https://referer.example/page?q=1');
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertMatchesRegularExpression('~^Location: https://own\.example/lp\r?$~mi', $headers);
        self::assertSame($before + 1, self::clicks(), 'the click is recorded');
        // The URLs the visit brought are stored, looked up uncached.
        $sites = self::newestClickSites();
        self::assertSame('https://referer.example/page?q=1', $sites['click_referer_site_url_id']);
        self::assertStringContainsString('rtr.php?t202id=' . (3932 + 100000) . '&c1=visit-1', (string) $sites['click_outbound_site_url_id']);
    }

    /** dl.php: the visit's URLs are stored, and capture follows the tracker's campaign. */
    public function testADirectLinkStoresTheVisitsUrlsAndCapturesForItsOwnCampaign(): void
    {
        $path = '/tracking202/redirect/dl.php?t202id=' . (3937 + 100000) . '&c1=visit-2';
        [$status, $headers] = self::get($path, 'https://referer.example/dl?q=2');
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertTrue(self::cookieSet($headers, 'p202vid'), 'capture on: the visitor id is set. ' . $headers);
        $sites = self::newestClickSites();
        self::assertSame('https://referer.example/dl?q=2', $sites['click_referer_site_url_id']);
        self::assertStringContainsString('dl.php?t202id=' . (3937 + 100000) . '&c1=visit-2', (string) $sites['click_outbound_site_url_id']);
        self::assertStringStartsWith('https://offer.example/3922?s=', (string) $sites['click_redirect_site_url_id']);
    }

    public function testADirectLinkToAnotherAccountsCampaignCapturesNothing(): void
    {
        [, $headers] = self::get('/tracking202/redirect/dl.php?t202id=' . (3938 + 100000));
        self::assertFalse(self::cookieSet($headers, 'p202vid'), $headers . $this->serverLog());
    }

    public function testADirectLinkWithNoCampaignCaptures(): void
    {
        [, $headers] = self::get('/tracking202/redirect/dl.php?t202id=' . (3936 + 100000));
        self::assertTrue(self::cookieSet($headers, 'p202vid'), $headers . $this->serverLog());
    }

    public function testAnUnknownDirectLinkRecordsNothingAndCapturesNothing(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get('/tracking202/redirect/dl.php?t202id=' . (3999 + 100000));
        self::assertSame(404, $status, $headers . $this->serverLog());
        self::assertFalse(self::cookieSet($headers, 'p202vid'), $headers);
        self::assertSame($before, self::clicks());
    }

    public function testAnotherAccountsDefaultPageIsNoDefault(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get('/tracking202/redirect/rtr.php?t202id=' . (3931 + 100000));
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertDoesNotMatchRegularExpression('~^Location:~mi', $headers, 'no empty Location');
        self::assertSame($before, self::clicks(), 'no click is recorded');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function rotatorCampaigns(): iterable
    {
        yield 'the default campaign has capture off' => [3939, false];
        yield 'the default campaign has capture on' => [3940, true];
    }

    /**
     * A rotator click's capture follows its campaign's setting.
     *
     * @dataProvider rotatorCampaigns
     */
    public function testARotatorsCampaignDecidesCapture(int $tracker, bool $capture): void
    {
        [$status, $headers] = self::get('/tracking202/redirect/rtr.php?t202id=' . ($tracker + 100000));
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertMatchesRegularExpression('~^Location: https://offer\.example/~mi', $headers);
        self::assertSame($capture, self::cookieSet($headers, 'p202vid'), $headers);
    }

    public function testARotatorWithNoDefaultRecordsNothing(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get('/tracking202/redirect/rtr.php?t202id=' . (3933 + 100000));
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertDoesNotMatchRegularExpression('~^Location:~mi', $headers);
        self::assertSame($before, self::clicks());
        self::assertStringContainsString('redirector 3913 has no default this account can send a visitor to', $this->serverLog());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function advancedClicks(): iterable
    {
        yield 'the tracker\'s own campaign, capture on' => [3937, true];
        yield 'no tracker of the account' => [3999, false];
        yield 'a tracker whose campaign is another account\'s' => [3938, false];
    }

    /** A t202id sent as a list names no tracker the account can read: capture off. */
    public function testAnAdvancedPagesClickWithAListForATrackerCapturesNothing(): void
    {
        [$status, $headers] = self::get('/tracking202/static/record_adv.php?lpip=' . (3903 + 100000)
            . '&t202id[]=' . (3937 + 100000));
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertFalse(self::cookieSet($headers, 'p202vid'), $headers);
    }

    /**
     * record_adv.php, an advanced landing page's click: its tracker's
     * campaign decides capture, as on landing.php.
     *
     * @dataProvider advancedClicks
     */
    public function testAnAdvancedPagesClickCapturesOnlyForATrackerItsAccountCanRead(int $tracker, bool $capture): void
    {
        [$status, $headers] = self::get('/tracking202/static/record_adv.php?lpip=' . (3903 + 100000)
            . '&t202id=' . ($tracker + 100000));
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($capture, self::cookieSet($headers, 'p202vid'), $headers);
    }

    /** @return iterable<string, array{string, string}> */
    public static function trackers(): iterable
    {
        yield 'the tracker\'s campaign has capture off' => ['&t202id=' . (3934 + 100000), 'false'];
        yield 'no tracker of the account' => ['&t202id=' . (3999 + 100000), 'false'];
        yield 'another account\'s tracker' => ['&t202id=' . (3935 + 100000), 'false'];
        yield 'a malformed id' => ['&t202id=abc', 'false'];
        yield 'a tracker with no campaign' => ['&t202id=' . (3936 + 100000), 'true'];
        yield 'no tracker named' => ['', 'true'];
    }

    /** @dataProvider trackers */
    public function testAnAdvancedPagesCaptureFollowsATrackerItsAccountCanRead(string $query, string $capture): void
    {
        [$status, $headers, $body] = self::get('/tracking202/static/landing.php?lpip=' . (3903 + 100000) . $query);
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertStringContainsString("var p202CampaignCapture = $capture;", $body, $this->serverLog());
    }
}

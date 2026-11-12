<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;

/**
 * A link's own destination (p202_to, PurLinkDestination) through the real
 * go.php, lp.php and off.php on a scratch database, served by `php -S` over
 * the integration job's 202-config.php: the deep link is kept, it is the
 * request's own, and it is filled only where the campaign's URL is.
 *
 * @group integration
 */
final class PurLinkDeepLinkServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 7501;
    private const CAMPAIGN = 7511;
    private const ROTATING = 7512;
    private const PAGE = 7521;
    /** A simple page whose campaign is cloaked: lp.php sends the visitor through lpc.php. */
    private const CLOAKED = 7513;
    private const CLOAKED_PAGE = 7522;
    private const PUBLIC = 100000;
    /** One click per way in: each exit rewrites its click. */
    private const PAGE_CLICK = 7501001;
    private const OFFER_CLICK = 7501002;
    private const NAMED_CLICK = 7501003;
    private const NAMED_CLICK_PUBLIC = '175010033';
    private const CLOAKED_CLICK = 7501004;
    private const PLACE = 'http://shop.example/go';
    /** The campaign's URL holds [[subid]] and [[t202kw]], and no other token. */
    private const CAMPAIGN_URL = self::PLACE . '?sid=[[subid]]&kw=[[t202kw]]';
    /** Set on the campaign, which does not rotate: its redirect never uses it. */
    private const UNUSED_URL = 'http://unused.example/old?sid=[[subid]]';
    private const KEYWORD = 'kept keyword';
    private const C1 = 'c1-of-the-click';
    private const GCLID = 'GclidOfTheClick';

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
        $range = 'BETWEEN ' . self::PAGE_CLICK . ' AND ' . self::CLOAKED_CLICK;
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
            '202_attribution_rollup_dirty_clicks',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $campaigns = self::CAMPAIGN . ', ' . self::ROTATING . ', ' . self::CLOAKED;
        self::q('DELETE FROM 202_rotations WHERE aff_campaign_id IN (' . $campaigns . ')');
        self::q('DELETE FROM 202_landing_pages WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_aff_campaigns WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_tracking_c1 WHERE c1 = ' . self::str(self::C1));
        self::q('DELETE FROM 202_keywords WHERE keyword = ' . self::str(self::KEYWORD));
        self::q('DELETE FROM 202_last_ips WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::OWNER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::OWNER);
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::user(self::OWNER);
        self::row('202_users_pref', [
            'user_id' => self::OWNER, 'user_pref_privacy' => 'disabled', 'user_keyword_searched_or_bidded' => 'bidded',
        ]);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + self::PUBLIC,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'deep links',
            'aff_campaign_url' => self::CAMPAIGN_URL, 'aff_campaign_url_2' => self::UNUSED_URL,
            'aff_campaign_payout' => 5, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::ROTATING, 'aff_campaign_id_public' => self::ROTATING + self::PUBLIC,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'rotating',
            'aff_campaign_rotate' => 1, 'aff_campaign_url' => 'http://one.example/a?sid=[[subid]]',
            'aff_campaign_url_2' => 'http://two.example/b?sid=[[subid]]', 'aff_campaign_payout' => 5,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CLOAKED, 'aff_campaign_id_public' => self::CLOAKED + self::PUBLIC,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'cloaked',
            'aff_campaign_url' => self::CAMPAIGN_URL, 'aff_campaign_cloaking' => 1, 'aff_campaign_payout' => 5,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_landing_pages', [
            'landing_page_id' => self::CLOAKED_PAGE, 'user_id' => self::OWNER,
            'landing_page_id_public' => self::CLOAKED_PAGE + self::PUBLIC,
            'aff_campaign_id' => self::CLOAKED, 'landing_page_nickname' => 'cloaked',
            'landing_page_url' => 'https://lp.example/cloaked', 'landing_page_type' => 0, 'landing_page_time' => time(),
        ]);
        self::row('202_landing_pages', [
            'landing_page_id' => self::PAGE, 'user_id' => self::OWNER,
            'landing_page_id_public' => self::PAGE + self::PUBLIC,
            'aff_campaign_id' => self::CAMPAIGN, 'landing_page_nickname' => 'deep links',
            'landing_page_url' => 'https://lp.example/page', 'landing_page_type' => 0, 'landing_page_time' => time(),
        ]);
        $keyword = self::insert('INSERT INTO 202_keywords SET keyword = ' . self::str(self::KEYWORD));
        $c1 = self::insert('INSERT INTO 202_tracking_c1 SET c1 = ' . self::str(self::C1));
        $repo = new MysqlClickRepository(new Connection(self::$db));
        $clicks = [self::PAGE_CLICK => '0', self::OFFER_CLICK => '0', self::NAMED_CLICK => self::NAMED_CLICK_PUBLIC,
            self::CLOAKED_CLICK => '0'];
        foreach ($clicks as $id => $public) {
            $cloaked = $id === self::CLOAKED_CLICK;
            $repo->recordClick(new ClickRecord(
                clickId: $id,
                userId: self::OWNER,
                clickTime: time() - 60,
                affCampaignId: $cloaked ? self::CLOAKED : self::CAMPAIGN,
                landingPageId: $cloaked ? self::CLOAKED_PAGE : self::PAGE,
                clickCpc: '1.37',
                clickPayout: '5',
                keywordId: $keyword,
                c1Id: $c1,
                gclid: self::GCLID,
                clickIdPublic: $public,
            ));
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    /** Where go.php sends the visitor. */
    private function location(string $query, string $cookie, string $referer = ''): string
    {
        $ch = curl_init(self::$base . '/tracking202/redirect/go.php?' . $query);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_REFERER => $referer, CURLOPT_COOKIE => $cookie,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['X-Forwarded-For: 198.51.100.61']]);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headers = substr($out, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        $log = "\nserver log:\n" . (string) @file_get_contents(self::$log);
        self::assertSame(302, $status, $out . $log);
        self::assertSame(1, preg_match('~^Location:\s*(\S+)\s*$~mi', $headers, $m), $headers . $log);

        return $m[1];
    }

    /** The campaign's URL as its redirect fills it for a click. */
    private static function campaignUrlFor(int $click): string
    {
        return self::PLACE . '?sid=' . $click . '&kw=' . rawurlencode(self::KEYWORD);
    }

    /**
     * @return iterable<string, array{string, string, int}> the link's query less its p202_to, the visitor's
     *     cookie, the click it names
     */
    public static function waysIn(): iterable
    {
        yield 'a simple page (lp.php)' => [
            'lpip=' . (self::PAGE + self::PUBLIC),
            'tracking202subid_a_' . self::CAMPAIGN . '=' . self::PAGE_CLICK,
            self::PAGE_CLICK,
        ];
        yield 'an offer by the subid cookie (off.php)' => [
            'acip=' . (self::CAMPAIGN + self::PUBLIC),
            'tracking202subid=' . self::OFFER_CLICK,
            self::OFFER_CLICK,
        ];
        yield 'an offer and a click by its public id (off.php)' => [
            'acip=' . (self::CAMPAIGN + self::PUBLIC) . '&pci=' . self::NAMED_CLICK_PUBLIC,
            '',
            self::NAMED_CLICK,
        ];
    }

    /** @dataProvider waysIn */
    public function testADeepLinkIsKeptAndFilledWhereTheCampaignsUrlIs(string $query, string $cookie, int $click): void
    {
        $deep = self::PLACE . '?sid=[[subid]]&kw=[[t202kw]]&u=deep';

        self::assertSame(
            self::campaignUrlFor($click) . '&u=deep',
            $this->location($query . '&p202_to=' . rawurlencode($deep), $cookie)
        );
    }

    /**
     * @param array<string, string> $tokens
     * @return iterable<string, array{string, string, int, string}>
     */
    private static function waysInWith(array $tokens): iterable
    {
        foreach (self::waysIn() as $way => [$query, $cookie, $click]) {
            foreach ($tokens as $name => $token) {
                yield "$way, $name" => [$query, $cookie, $click, $token];
            }
        }
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function waysInAndTokensTheUrlLacks(): iterable
    {
        return self::waysInWith([
            'a value of the click' => '[[c1]]',
            'in capitals' => '[[GCLID]]',
            'its cost' => '[[cpc2]]',
        ]);
    }

    /** @dataProvider waysInAndTokensTheUrlLacks */
    public function testATokenTheCampaignsUrlLacksIsLeftEmptyInADeepLinkThatIsKept(
        string $query,
        string $cookie,
        int $click,
        string $token
    ): void {
        $deep = self::PLACE . '?sid=[[subid]]&kw=[[t202kw]]&u=deep&x=' . $token;

        self::assertSame(
            self::campaignUrlFor($click) . '&u=deep&x=',
            $this->location($query . '&p202_to=' . rawurlencode($deep), $cookie)
        );
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function waysInAndBracketsThatAreNoWholeToken(): iterable
    {
        return self::waysInWith([
            'a name around an empty token' => '[[c[[c2]]pc]]',
            'brackets joined by an empty token' => '[[[c2]][gclid]]',
        ]);
    }

    /** @dataProvider waysInAndBracketsThatAreNoWholeToken */
    public function testALinkWithBracketsThatAreNotAWholeTokenGoesToTheCampaignsUrl(
        string $query,
        string $cookie,
        int $click,
        string $token
    ): void {
        $deep = self::PLACE . '?sid=[[subid]]&u=deep&x=' . $token;

        self::assertSame(
            self::campaignUrlFor($click),
            $this->location($query . '&p202_to=' . rawurlencode($deep), $cookie)
        );
    }

    public function testACampaignThatDoesNotRotateTakesNoDeepLinkAtAUrlItsRedirectDoesNotUse(): void
    {
        [$query, $cookie, $click] = self::waysIn()->current();

        self::assertSame(
            self::campaignUrlFor($click),
            $this->location($query . '&p202_to=' . rawurlencode(self::UNUSED_URL . '&u=deep'), $cookie)
        );
    }

    public function testARotatingCampaignTakesItsUrlsInTurnAndADeepLinkAtEach(): void
    {
        $query = 'acip=' . (self::ROTATING + self::PUBLIC);
        $cookie = 'tracking202subid=' . self::OFFER_CLICK;
        $urls = ['http://one.example/a?sid=' . self::OFFER_CLICK, 'http://two.example/b?sid=' . self::OFFER_CLICK];

        $taken = [$this->location($query, $cookie), $this->location($query, $cookie)];
        sort($taken);
        self::assertSame($urls, $taken);
        foreach (['http://one.example/a', 'http://two.example/b'] as $place) {
            self::assertSame(
                $place . '?sid=' . self::OFFER_CLICK . '&u=deep',
                $this->location($query . '&p202_to=' . rawurlencode($place . '?sid=[[subid]]&u=deep'), $cookie)
            );
        }
    }

    /** The page's own address has a query: the deep link is the request's, and is kept. */
    public function testASimplePagesDeepLinkIsKeptWhenThePagesAddressHasAQuery(): void
    {
        [$query, $cookie, $click] = self::waysIn()->current();
        $deep = self::PLACE . '?sid=[[subid]]&u=deep';

        self::assertSame(
            self::PLACE . '?sid=' . $click . '&u=deep',
            $this->location(
                $query . '&p202_to=' . rawurlencode($deep),
                $cookie,
                'https://lp.example/page?t202kw=shoes&x=1'
            )
        );
    }

    /** The URL lpc.php's page sends the visitor to (its meta refresh). */
    private function cloakedPageSendsTo(string $lpc): string
    {
        $ch = curl_init($lpc);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIE => 'tracking202subid=' . self::CLOAKED_CLICK,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['X-Forwarded-For: 198.51.100.61']]);
        $body = (string) curl_exec($ch);
        $log = "\nserver log:\n" . (string) @file_get_contents(self::$log);
        self::assertSame(200, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body . $log);
        self::assertSame(1, preg_match('~http-equiv="refresh"\s+content="1; url=([^"]*)"~', $body, $m), $body . $log);

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** A cloaked simple page: lp.php hands the link to lpc.php, which keeps it under the same rule. */
    public function testACloakedSimplePageKeepsTheDeepLinkThroughItsCloakedHop(): void
    {
        $query = 'lpip=' . (self::CLOAKED_PAGE + self::PUBLIC);
        $cookie = 'tracking202subid_a_' . self::CLOAKED . '=' . self::CLOAKED_CLICK;
        $lpc = self::$base . '/tracking202/redirect/lpc.php?lpip=' . (self::CLOAKED_PAGE + self::PUBLIC);
        $deep = self::PLACE . '?sid=[[subid]]&kw=[[t202kw]]&u=deep';

        $hop = $this->location($query . '&p202_to=' . rawurlencode($deep), $cookie);
        self::assertSame($lpc . '&p202_to=' . rawurlencode($deep), $hop, 'lp.php hands the link to lpc.php');
        self::assertSame(self::campaignUrlFor(self::CLOAKED_CLICK) . '&u=deep', $this->cloakedPageSendsTo($hop));

        self::assertSame($lpc, $this->location($query, $cookie), 'no link, nothing handed on');
        self::assertSame(self::campaignUrlFor(self::CLOAKED_CLICK), $this->cloakedPageSendsTo($lpc));
        $others = [
            'http://evil.example/go?u=deep', self::PLACE . '/more?u=deep', 'http://shop.example@evil.example/go',
        ];
        foreach ($others as $other) {
            self::assertSame(
                self::campaignUrlFor(self::CLOAKED_CLICK),
                $this->cloakedPageSendsTo($lpc . '&p202_to=' . rawurlencode($other)),
                $other
            );
        }
    }

    /** Only the link names a destination: the page's address does not. */
    public function testADestinationInThePagesAddressIsNotTheLinks(): void
    {
        [$query, $cookie, $click] = self::waysIn()->current();
        $deep = self::PLACE . '?sid=[[subid]]&u=deep';

        self::assertSame(
            self::campaignUrlFor($click),
            $this->location($query, $cookie, 'https://lp.example/page?x=1&p202_to=' . rawurlencode($deep))
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * pt.php, the Google Ads tracking template paid 1.9.56 issued, through the
 * real files on a real database (served by `php -S`, as
 * ClickDefaultsServedIntegrationTest is): it records the landing page's
 * click, with the tracker's keyword, c1-c4 and gclid, and sends the caller
 * on; the page's own script then takes that click by its gclid instead of
 * recording a second one, only for the same account and page, and only
 * within ParallelTrackingClick::WINDOW.
 *
 * @group integration
 */
final class ParallelTrackingServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 4101;
    private const OTHER = 4102;
    private const CAMPAIGN = 4111;
    private const OTHER_CAMPAIGN = 4112;
    private const SIMPLE_LP = 4121;
    private const ADVANCED_LP = 4122;
    private const OTHER_LP = 4123;
    private const SIMPLE_TRACKER = 4131;
    private const ADVANCED_TRACKER = 4132;
    private const DIRECT_TRACKER = 4133;
    private const FOREIGN_PAGE_TRACKER = 4134;
    private const PUBLIC = 100000;
    /** A publisher the owner may credit (t202pubid), and the owner's role that grants it. */
    private const PUBLISHER = 4103;
    private const PUBLISHER_PUBLIC_ID = 'pt4103pub';
    private const GRANT_ROLE = 4141;

    private static ?int $addedGrantPermission = null;

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
            $pipes,
            null,
            // Workers, so two requests of one click can be in flight at once.
            array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4'])
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
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the click scripts could not be served');
        }
    }

    private static function cleanUp(): void
    {
        $tables = [
            '202_trackers' => 'tracker_id',
            '202_landing_pages' => 'landing_page_id',
            '202_aff_campaigns' => 'aff_campaign_id',
        ];
        foreach ($tables as $table => $id) {
            self::q("DELETE FROM $table WHERE $id BETWEEN 4100 AND 4199");
        }
        $users = self::OWNER . ', ' . self::OTHER . ', ' . self::PUBLISHER;
        self::q('DELETE FROM 202_clicks_publisher WHERE publisher_user_id = ' . self::PUBLISHER);
        self::q("DELETE FROM 202_user_role WHERE user_id IN ($users)");
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::GRANT_ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::GRANT_ROLE);
        if (self::$addedGrantPermission !== null) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . self::$addedGrantPermission);
            self::$addedGrantPermission = null;
        }
        self::q("DELETE FROM 202_clicks WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function seed(): void
    {
        self::cleanUp();
        foreach ([self::OWNER, self::OTHER] as $user) {
            self::user($user);
            // Nobody held back (a new account's 'eu' would hold back 127.0.0.1), keywords as bid.
            self::row('202_users_pref', [
                'user_id' => $user, 'user_pref_privacy' => 'disabled', 'user_keyword_searched_or_bidded' => 'bidded',
            ]);
        }
        foreach ([[self::CAMPAIGN, self::OWNER], [self::OTHER_CAMPAIGN, self::OTHER]] as [$id, $user]) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'aff_campaign_id_public' => $id + self::PUBLIC, 'user_id' => $user,
                'aff_network_id' => 0, 'aff_campaign_name' => "campaign $id",
                'aff_campaign_url' => "https://offer.example/$id?s=[[subid]]", 'aff_campaign_payout' => 2,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
            ]);
        }
        $page = static fn (int $id, int $user, int $campaign, string $url, int $type) => self::row(
            '202_landing_pages',
            [
                'landing_page_id' => $id, 'user_id' => $user, 'landing_page_id_public' => $id + self::PUBLIC,
                'aff_campaign_id' => $campaign, 'landing_page_nickname' => "page $id", 'landing_page_url' => $url,
                'landing_page_type' => $type, 'landing_page_time' => time(),
            ]
        );
        $page(self::SIMPLE_LP, self::OWNER, self::CAMPAIGN, 'https://shop.example/landing?x=1', 0);
        $page(self::ADVANCED_LP, self::OWNER, 0, 'https://shop.example/advanced', 1);
        $page(self::OTHER_LP, self::OTHER, self::OTHER_CAMPAIGN, 'https://other.example/landing', 0);
        $tracker = static fn (int $id, int $campaign, int $page) => self::row('202_trackers', [
            'tracker_id' => $id, 'user_id' => self::OWNER, 'tracker_id_public' => $id + self::PUBLIC,
            'aff_campaign_id' => $campaign, 'text_ad_id' => 0, 'ppc_account_id' => 0, 'landing_page_id' => $page,
            'rotator_id' => 0, 'click_cpc' => 0.25, 'click_cloaking' => 0, 'tracker_time' => time(),
        ]);
        $tracker(self::SIMPLE_TRACKER, self::CAMPAIGN, self::SIMPLE_LP);
        $tracker(self::ADVANCED_TRACKER, self::CAMPAIGN, self::ADVANCED_LP);
        $tracker(self::DIRECT_TRACKER, self::CAMPAIGN, 0);
        // A legacy row naming another account's page.
        $tracker(self::FOREIGN_PAGE_TRACKER, self::CAMPAIGN, self::OTHER_LP);

        // The owner may credit PUBLISHER (PublisherCredit::forClick()).
        $grant = 'permission_description = ' . self::str(\Prosper202\Click\PublisherCredit::GRANT_PERMISSION);
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $grant");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            self::$addedGrantPermission = self::insert("INSERT INTO 202_permissions SET $grant");
            $permission = self::$addedGrantPermission;
        }
        self::row('202_roles', ['role_id' => self::GRANT_ROLE, 'role_name' => 'pt grant test']);
        self::row('202_role_permission', ['role_id' => self::GRANT_ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::OWNER, 'role_id' => self::GRANT_ROLE]);
        $publisherRole = \Prosper202\Click\PublisherCredit::PUBLISHER_ROLE_ID;
        self::q("INSERT IGNORE INTO 202_roles (role_id, role_name) VALUES ($publisherRole, 'Publisher')");
        self::user(self::PUBLISHER);
        self::q("UPDATE 202_users SET install_hash = 'pt-test-install' WHERE user_id IN ("
            . self::OWNER . ', ' . self::PUBLISHER . ')');
        self::q('UPDATE 202_users SET user_public_publisher_id = ' . self::str(self::PUBLISHER_PUBLIC_ID)
            . ' WHERE user_id = ' . self::PUBLISHER);
        self::row('202_user_role', ['user_id' => self::PUBLISHER, 'role_id' => $publisherRole]);
    }

    /**
     * @param list<string> $headers request headers
     * @return array{int, string, string} status, headers, body
     */
    private static function get(string $path, string $referer = '', array $headers = []): array
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_REFERER => $referer, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private static function clicks(int $user = self::OWNER): int
    {
        return (int) self::requireScratch()
            ->query("SELECT COUNT(DISTINCT click_id) FROM 202_clicks WHERE user_id = $user")
            ->fetch_row()[0];
    }

    /** @return array<string, string|null> the click recorded with $gclid */
    private static function clickWithGclid(string $gclid): array
    {
        $row = self::requireScratch()->query(
            'SELECT c.click_id, c.landing_page_id, c.aff_campaign_id, c.click_alp, c.click_cpc, cr.click_id_public,
                    k.keyword, t1.c1, su.site_url_address AS landing_url
               FROM 202_google AS g
               JOIN 202_clicks AS c ON c.click_id = g.click_id
               JOIN 202_clicks_record AS cr ON cr.click_id = c.click_id
               LEFT JOIN 202_clicks_advance AS ca ON ca.click_id = c.click_id
               LEFT JOIN 202_keywords AS k ON k.keyword_id = ca.keyword_id
               LEFT JOIN 202_clicks_tracking AS ct ON ct.click_id = c.click_id
               LEFT JOIN 202_tracking_c1 AS t1 ON t1.c1_id = ct.c1_id
               LEFT JOIN 202_clicks_site AS cs ON cs.click_id = c.click_id
               LEFT JOIN 202_site_urls AS su ON su.site_url_id = cs.click_landing_site_url_id
              WHERE g.gclid = ' . self::str($gclid)
        )->fetch_assoc();
        self::assertIsArray($row, "a click with gclid $gclid");

        return $row;
    }

    private static function location(string $headers): ?string
    {
        return preg_match('~^Location: ([^\r\n]*)~mi', $headers, $m) === 1 ? $m[1] : null;
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    private static function pt(int $tracker, string $query): string
    {
        return '/tracking202/redirect/pt.php?t202id=' . ($tracker + self::PUBLIC) . $query;
    }

    /** The exact shape paid 1.9.56's Get Links issued, with the gclid Google appends. */
    public function testAPaid1956TemplateRecordsThePagesClickAndTheCleanPageTakesIt(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get(
            self::pt(self::SIMPLE_TRACKER, '&c1=summer&t202kw=running%20shoes&gclid=G-old-1'),
            'https://www.google.com/'
        );
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame('https://shop.example/landing?x=1&gclid=G-old-1', self::location($headers));
        self::assertMatchesRegularExpression('~^Cache-Control:[^\r\n]*\bno-store\b~mi', $headers);
        self::assertSame($before + 1, self::clicks(), 'the template records one click');
        $click = self::clickWithGclid('G-old-1');
        self::assertSame((string) self::SIMPLE_LP, (string) $click['landing_page_id']);
        self::assertSame((string) self::CAMPAIGN, (string) $click['aff_campaign_id']);
        self::assertSame('0', (string) $click['click_alp']);
        self::assertSame('running shoes', $click['keyword']);
        self::assertSame('summer', $click['c1']);
        self::assertSame('0.25000', (string) $click['click_cpc'], 'the tracker\'s cost');
        self::assertSame('https://shop.example/landing?x=1', $click['landing_url']);

        // The visitor's view of the clean final URL: the page's script, with the gclid Google added.
        [$status, $headers, $body] = self::get(
            '/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-old-1',
            'https://shop.example/landing?x=1&gclid=G-old-1'
        );
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($before + 1, self::clicks(), 'the page takes the template\'s click');
        self::assertStringContainsString('var subid =' . json_encode((string) $click['click_id']) . ';', $body);
        $cookie = '~^Set-Cookie: tracking202subid[^=]*=' . $click['click_id'] . '~mi';
        self::assertMatchesRegularExpression($cookie, $headers);
        self::assertStringContainsString('pci.php?pci=' . $click['click_id_public'], $body);
    }

    /** A publisher's template, as Get Links issues it, credits the publisher with the click. */
    public function testAPublishersTemplateCreditsThePublisher(): void
    {
        [$status, $headers] = self::get(
            self::pt(self::SIMPLE_TRACKER, '&t202pubid=' . self::PUBLISHER_PUBLIC_ID . '&t202kw=&gclid=G-pub-1'),
            'https://www.google.com/'
        );
        self::assertSame(302, $status, $headers . $this->serverLog());
        $click = self::clickWithGclid('G-pub-1');
        $credit = self::requireScratch()->query(
            'SELECT publisher_user_id FROM 202_clicks_publisher WHERE click_id = ' . (int) $click['click_id']
        )->fetch_row();
        self::assertSame((string) self::PUBLISHER, (string) ($credit[0] ?? ''), 'the click is credited to the publisher');
    }

    /**
     * Google sends the template's hit and the visitor at once, so the page's
     * script often records first: the template's hit then takes that click
     * and gives it the tracker's cost, the keyword and c1-c4.
     */
    public function testATemplateHitAfterThePagesScriptFillsThePagesClick(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get(
            '/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-pf-1',
            'https://shop.example/landing?x=1&gclid=G-pf-1'
        );
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($before + 1, self::clicks(), 'the page records its click');
        $page = self::clickWithGclid('G-pf-1');
        self::assertNull($page['keyword']);
        self::assertSame('0.00000', (string) $page['click_cpc']);

        [$status, $headers] = self::get(
            self::pt(self::SIMPLE_TRACKER, '&c1=autumn&t202kw=trail%20boots&gclid=G-pf-1'),
            'https://www.google.com/'
        );
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame('https://shop.example/landing?x=1&gclid=G-pf-1', self::location($headers));
        self::assertSame($before + 1, self::clicks(), 'the template takes the page\'s click');
        $click = self::clickWithGclid('G-pf-1');
        self::assertSame($page['click_id'], $click['click_id']);
        self::assertSame('trail boots', $click['keyword']);
        self::assertSame('autumn', $click['c1']);
        self::assertSame('0.25000', (string) $click['click_cpc'], 'the tracker\'s cost');
        $dirty = (int) self::requireScratch()->query(
            'SELECT COUNT(*) FROM 202_attribution_rollup_dirty_clicks WHERE click_id = ' . (int) $click['click_id']
        )->fetch_row()[0];
        self::assertGreaterThan(0, $dirty, 'the rollup recomputes the click\'s cost');
    }

    /**
     * The template's hit and the page's script in flight together record one
     * click: each waits for the gclid's lock, so the second finds the first's
     * click. The test holds the lock to start both before either records.
     */
    public function testATemplateHitAndPageViewTogetherRecordOneClick(): void
    {
        $db = self::requireScratch();
        $lock = self::str(\Prosper202\Click\ParallelTrackingClick::lockName(self::OWNER, self::SIMPLE_LP, 'G-tog-1'));
        self::assertSame('1', (string) $db->query("SELECT GET_LOCK($lock, 0)")->fetch_row()[0]);
        $before = self::clicks();

        $multi = curl_multi_init();
        $handles = [];
        foreach ([
            [self::pt(self::SIMPLE_TRACKER, '&c1=winter&t202kw=snow%20boots&gclid=G-tog-1'), 'https://www.google.com/'],
            ['/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-tog-1',
                'https://shop.example/landing?x=1&gclid=G-tog-1'],
        ] as [$path, $referer]) {
            $ch = curl_init(self::$base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_REFERER => $referer,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36']);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        $until = microtime(true) + 1.0;
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running > 0 && microtime(true) < $until);
        self::assertSame($before, self::clicks(), 'neither records while the other may be recording');

        $db->query("SELECT RELEASE_LOCK($lock)");
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running > 0);
        foreach ($handles as $ch) {
            self::assertLessThan(400, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $this->serverLog());
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);

        self::assertSame($before + 1, self::clicks(), 'one click for the two requests');
        $click = self::clickWithGclid('G-tog-1');
        self::assertSame('snow boots', $click['keyword']);
        self::assertSame('winter', $click['c1']);
        self::assertSame('0.25000', (string) $click['click_cpc'], 'the tracker\'s cost');
    }

    /** A passed-on value with '#' or a line break stays in the query, encoded. */
    public function testPassedOnValuesAreEncoded(): void
    {
        [$status, $headers] = self::get(self::pt(self::SIMPLE_TRACKER, '&adg=a%23b%0D%0AX-Evil:1&gclid=G-enc-1'));
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame('https://shop.example/landing?x=1&adg=a%23b%0D%0AX-Evil%3A1&gclid=G-enc-1', self::location($headers));
    }

    public function testAnAdvancedPagesTemplateClickIsTakenByItsScript(): void
    {
        [$status, $headers] = self::get(self::pt(self::ADVANCED_TRACKER, '&t202kw=boots&gclid=G-adv-1'));
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame('https://shop.example/advanced?gclid=G-adv-1', self::location($headers));
        $click = self::clickWithGclid('G-adv-1');
        self::assertSame('1', (string) $click['click_alp']);
        self::assertSame((string) self::CAMPAIGN, (string) $click['aff_campaign_id'], 'the tracker\'s campaign');

        $before = self::clicks();
        [$status, $headers, $body] = self::get(
            '/tracking202/static/record_adv.php?lpip=' . (self::ADVANCED_LP + self::PUBLIC) . '&gclid=G-adv-1'
        );
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($before, self::clicks());
        self::assertStringContainsString('var pci = ' . json_encode((string) $click['click_id_public']) . ';', $body);
    }

    public function testGooglesBackgroundCallEndsAtItsStandIn(): void
    {
        $standIn = 'https://www.google.com/asnc/dGVzdA';
        [$status, $headers] = self::get(self::pt(self::SIMPLE_TRACKER, '&t202kw=x&lpurl=' . rawurlencode($standIn)
            . '&gclid=G-bg-1&gb=1'));
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame($standIn, self::location($headers));
        self::clickWithGclid('G-bg-1');
    }

    public function testAnLpurlOffThePagesSiteIsNotFollowed(): void
    {
        [, $headers] = self::get(self::pt(self::SIMPLE_TRACKER, '&lpurl=' . rawurlencode('https://elsewhere.example/')
            . '&gclid=G-off-1'));
        self::assertSame('https://shop.example/landing?x=1&gclid=G-off-1', self::location($headers));
    }

    public function testAnotherAccountsPageDoesNotTakeTheClick(): void
    {
        self::get(self::pt(self::SIMPLE_TRACKER, '&gclid=G-shared-1'));
        $before = self::clicks(self::OTHER);
        [$status, $headers] = self::get(
            '/tracking202/static/record_simple.php?lpip=' . (self::OTHER_LP + self::PUBLIC) . '&gclid=G-shared-1'
        );
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($before + 1, self::clicks(self::OTHER), 'a click of its own');
    }

    public function testAClickOlderThanTheWindowIsNotTaken(): void
    {
        self::get(self::pt(self::SIMPLE_TRACKER, '&gclid=G-late-1'));
        $click = self::clickWithGclid('G-late-1');
        $late = \Prosper202\Click\ParallelTrackingClick::WINDOW + 5;
        self::q('UPDATE 202_clicks SET click_time = click_time - ' . $late
            . ' WHERE click_id = ' . (int) $click['click_id']);
        $before = self::clicks();
        self::get('/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-late-1');
        self::assertSame($before + 1, self::clicks());
    }

    /** The gclid is the visitor's to send: a view from another address is a click of its own. */
    public function testAViewFromAnotherAddressIsNotTaken(): void
    {
        self::get(self::pt(self::SIMPLE_TRACKER, '&gclid=G-addr-1'));
        $click = self::clickWithGclid('G-addr-1');
        // Stored as another address's id: no row of 202_ips needs to exist for the comparison.
        self::q('UPDATE 202_clicks_advance SET ip_id = ip_id + 1000000 WHERE click_id = ' . (int) $click['click_id']);
        $before = self::clicks();
        self::get('/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-addr-1');
        self::assertSame($before + 1, self::clicks());
    }

    public function testAPageViewWithAnotherGclidIsItsOwnClick(): void
    {
        self::get(self::pt(self::SIMPLE_TRACKER, '&gclid=G-one'));
        $before = self::clicks();
        self::get('/tracking202/static/record_simple.php?lpip=' . (self::SIMPLE_LP + self::PUBLIC) . '&gclid=G-two');
        self::assertSame($before + 1, self::clicks());
    }

    public function testATrackerWithNoLandingPageGoesToTheDirectLink(): void
    {
        $query = '&c1=a&gclid=G-dl-1';
        [$status, $headers] = self::get(self::pt(self::DIRECT_TRACKER, $query));
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertSame('dl.php?t202id=' . (self::DIRECT_TRACKER + self::PUBLIC) . $query, self::location($headers));
    }

    /** A tracker whose page is another account's is not a direct link: nothing is recorded or followed. */
    public function testATrackerNamingAnotherAccountsPageIsRefused(): void
    {
        $before = self::clicks();
        [$status, $headers] = self::get(self::pt(self::FOREIGN_PAGE_TRACKER, '&c1=a&gclid=G-fp-1'));
        self::assertSame(404, $status, $headers . $this->serverLog());
        self::assertNull(self::location($headers));
        self::assertSame($before, self::clicks());
    }

    public function testALinkThatNamesNoTrackerRecordsNothing(): void
    {
        foreach (['abc', '0', (string) (4199 + self::PUBLIC)] as $id) {
            $before = self::clicks();
            [$status, $headers] = self::get('/tracking202/redirect/pt.php?t202id=' . $id . '&gclid=G-none');
            self::assertSame(404, $status, "t202id=$id: " . $headers . $this->serverLog());
            self::assertNull(self::location($headers));
            self::assertSame($before, self::clicks());
        }
    }

    public function testAPrefetchRecordsNothing(): void
    {
        $before = self::clicks();
        [$status] = self::get(self::pt(self::SIMPLE_TRACKER, '&gclid=G-pre-1'), '', ['Sec-Purpose: prefetch']);
        self::assertSame(204, $status);
        self::assertSame($before, self::clicks());
    }
}

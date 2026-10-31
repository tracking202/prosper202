<?php

declare(strict_types=1);

namespace Tests\Setup;

use Api\V3\Controllers\SetupCodeController;
use Api\V3\Controllers\TrackersController;
use PHPUnit\Framework\TestCase;

/**
 * Get Links and Setup › Postback / Pixel hand out the URLs the API hands out
 * for the same tracker and the same request: https unless the tracking
 * domain is saved with http://, or none is saved with a scheme and the
 * request came over plain HTTP.
 *
 * Get Links wrote 'http://' before every direct and redirector link, and the
 * Postback page took its scheme from two request headers and never from the
 * saved domain, while GET /trackers/{id}/url and GET
 * /conversions/postback-code build on TrackingBaseUrl::buildForResponse().
 * Each page runs as the account in a process of its own
 * (fixtures/page-runner.php), with the request's HTTPS headers given, and
 * its links are compared with the controllers' answers to the same request.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class GetLinksSchemeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990081;
    private const ROLE = 990081;
    private const DIRECT = 990081;
    private const REDIRECTOR = 990082;

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::seed();
        self::$sessions = sys_get_temp_dir() . '/p202-page-runner-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        $u = self::USER;
        $tables = ['202_user_role', '202_users_pref', '202_users', '202_aff_networks', '202_aff_campaigns',
            '202_ppc_networks', '202_ppc_accounts', '202_rotators', '202_trackers'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        self::q("DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = $u");
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    private static function seed(): void
    {
        $t = time();
        $u = self::USER;
        self::user($u);
        self::row('202_users_pref', ['user_id' => $u]);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = $u");
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'link scheme test']);
        $setup = "permission_description = 'access_to_setup_section'";
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $setup");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            $permission = self::insert("INSERT INTO 202_permissions SET $setup");
            self::$addedPermissions[] = $permission;
        }
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => $u, 'role_id' => self::ROLE]);

        $mine = ['user_id' => $u];
        self::row('202_aff_networks', $mine + [
            'aff_network_id' => $u, 'aff_network_name' => 'Category', 'aff_network_time' => $t,
        ]);
        self::row('202_aff_campaigns', $mine + [
            'aff_campaign_id' => $u, 'aff_network_id' => $u, 'aff_campaign_name' => 'Offer',
            'aff_campaign_url' => 'https://offer.example/', 'aff_campaign_payout' => 1,
            'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
        ]);
        self::row('202_ppc_networks', $mine + [
            'ppc_network_id' => $u, 'ppc_network_name' => 'Source', 'ppc_network_time' => $t,
        ]);
        self::row('202_ppc_accounts', $mine + [
            'ppc_account_id' => $u, 'ppc_network_id' => $u, 'ppc_account_name' => 'Account', 'ppc_account_time' => $t,
        ]);
        self::row('202_ppc_network_variables', [
            'ppc_variable_id' => $u, 'ppc_network_id' => $u, 'name' => 'Ad',
            'parameter' => 'adid', 'placeholder' => '{ad_id}',
        ]);
        self::row('202_rotators', $mine + ['id' => $u, 'public_id' => $u, 'name' => 'Rotator']);
        $link = $mine + [
            'text_ad_id' => 0, 'ppc_account_id' => $u, 'landing_page_id' => 0, 'click_cpc' => 0.1,
            'click_cloaking' => -1, 'tracker_time' => $t,
        ];
        self::row('202_trackers', $link + [
            'tracker_id' => self::DIRECT, 'tracker_id_public' => self::publicId(self::DIRECT),
            'aff_campaign_id' => $u, 'rotator_id' => 0,
        ]);
        self::row('202_trackers', $link + [
            'tracker_id' => self::REDIRECTOR, 'tracker_id_public' => self::publicId(self::REDIRECTOR),
            'aff_campaign_id' => 0, 'rotator_id' => $u,
        ]);
    }

    private static function publicId(int $trackerId): int
    {
        return $trackerId * 100 + 7;
    }

    private static function saveDomain(string $domain): void
    {
        self::q('UPDATE 202_users_pref SET user_tracking_domain = ' . self::str($domain)
            . ' WHERE user_id = ' . self::USER);
    }

    /**
     * The request both sides answer: the runner's, with the document root
     * at the install, and the HTTPS values given.
     *
     * @param array<string, string> $https
     * @return array<string, string>
     */
    private static function server(array $https): array
    {
        return $https + [
            'SERVER_NAME' => 'localhost', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80',
            'DOCUMENT_ROOT' => dirname(__DIR__, 2),
        ];
    }

    /**
     * What a page printed, run as the account.
     *
     * @param array<string, string> $server
     */
    private static function page(string $page, array $server): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => '{}',
            'P202_TEST_SERVER' => (string) json_encode((object) $server),
        ] + getenv();
        $command = [
            PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
            __DIR__ . '/fixtures/page-runner.php', $page,
        ];
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, "$page: $err");

        return $out;
    }

    /** The link Get Links offers to copy for a tracker. */
    private static function listedLink(string $out, int $trackerId): string
    {
        $listed = preg_match('~data-tracker-id="' . $trackerId . '">(.*?)</li>~s', $out, $item);
        self::assertSame(1, $listed, "tracker $trackerId is listed");
        $copied = preg_match('~data-p202-copy="([^"]*)"~', $item[1], $copy);
        self::assertSame(1, $copied, "tracker $trackerId has a link to copy");

        return html_entity_decode($copy[1], ENT_QUOTES, 'UTF-8');
    }

    /** @return iterable<string, array{string, array<string, string>, string, string}> */
    public static function requests(): iterable
    {
        $overHttp = 'because this page was served over HTTP.';
        $overHttps = 'because this page was served over HTTPS.';
        $saved = 'because your tracking domain is saved with it.';
        $proxy = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        yield 'nothing saved, plain HTTP' => ['', [], 'http://localhost/', $overHttp];
        yield 'nothing saved, behind a TLS proxy' => ['', $proxy, 'https://localhost/', $overHttps];
        $https = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];
        yield 'nothing saved, HTTPS' => ['', $https, 'https://localhost/', $overHttps];
        yield 'a host saved, HTTPS' => ['track.example.com', $proxy, 'https://track.example.com/', $overHttps];
        yield 'saved with https, plain HTTP' => ['https://track.example.com', [], 'https://track.example.com/', $saved];
        yield 'saved with http, HTTPS' => ['http://track.example.com/', $proxy, 'http://track.example.com/', $saved];
    }

    /**
     * @dataProvider requests
     * @param array<string, string> $https
     */
    public function testGetLinksListsTheLinkTheApiGives(string $saved, array $https, string $base, string $why): void
    {
        $db = self::requireScratch();
        self::saveDomain($saved);
        $server = self::server($https);
        $out = self::page('tracking202/setup/get_trackers.php', $server);
        $api = new TrackersController($db, self::USER);
        foreach ([self::DIRECT => 'dl.php', self::REDIRECTOR => 'rtr.php'] as $tracker => $handler) {
            $expected = $base . 'tracking202/redirect/' . $handler . '?t202id=' . self::publicId($tracker)
                . '&adid={ad_id}&t202kw=';
            self::assertSame($expected, $api->getTrackingUrl($tracker, [], $server)['data']['direct_url'], 'the API');
            self::assertSame($expected, self::listedLink($out, $tracker), 'Get Links');
        }
        $scheme = substr($base, 0, (int) strpos($base, '://') + 3);
        $said = 'Direct and redirector links use ' . $scheme . ', ' . $why;
        self::assertStringContainsString($said, $out, 'the page says which scheme and why');
    }

    /**
     * @dataProvider requests
     * @param array<string, string> $https
     */
    public function testThePostbackPageGivesThePostbackTheApiGives(
        string $saved,
        array $https,
        string $base,
        string $why
    ): void {
        $db = self::requireScratch();
        self::saveDomain($saved);
        $server = self::server($https);
        $out = self::page('tracking202/setup/get_postback.php', $server);
        $expected = $base . 'tracking202/static/gpb.php?amount=&subid=';
        $api = (new SetupCodeController($db, self::USER))->postbackCode([], $server);
        self::assertSame($expected, $api['data']['simple']['postback_url'], 'the API');
        $shown = preg_match('~id="unsecure_postback">([^<]*)</pre>~', $out, $m);
        self::assertSame(1, $shown, 'the postback URL is shown');
        self::assertSame($expected, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), 'the page');
        $scheme = substr($base, 0, (int) strpos($base, '://') + 3);
        self::assertStringContainsString('Links use ' . $scheme . ', ' . $why, $out);
        $choice = str_starts_with($base, 'https') ? '1' : '0';
        $checked = '~id="secure_type' . $choice . '" value="' . $choice . '" checked~';
        self::assertMatchesRegularExpression($checked, $out, 'the protocol choice starts at it');
    }
}

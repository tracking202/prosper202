<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The addresses a paid 1.9.56 install handed out, called on a database that
 * was paid 1.9.56 and has been upgraded: landing-page code, pixels and
 * postbacks, publisher links. They live in pages, network settings and ad
 * accounts Prosper202 cannot rewrite, and they failed silently (a normal page,
 * GIF or 200) when they broke, so each one is pinned here.
 *
 * The scratch database is put in a paid 1.9.56's shape, seeded with an
 * account's setup, upgraded by the ladder as the upgrade page runs it, and
 * then served by `php -S` over the 202-config.php the integration job writes,
 * once both its connections are seen to reach the scratch database.
 *
 * documentation/upgrading/from-paid-1.9.56.md lists what changed on purpose.
 *
 * @group integration
 */
final class Paid1956ContractsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const INSTALL = 'paid1956-regression';
    private const OWNER = 995601;
    private const PUBLISHER = 995602;
    private const MANAGER = 995603;
    private const PUBLISHER_ID = 'p1956pub01';
    private const MANAGER_ID = 'p1956mgr01';
    private const CAMPAIGN = 995611;
    private const TRACKER = 995621;
    private const LANDING_PAGE = 995631;
    private const LP_TRACKER = 995622;
    private const PUBLIC_OFFSET = 100000;
    private const PAYOUT = '25.00';
    private const API_KEY = 'paid1956-regression-key';
    private const JVZIPN_KEY = 'jvzipn-paid1956-key';
    private const SHOPIFY_SECRET = 'demo-store-webhook-secret-1956';
    /** Rows single cases seed: traffic source, account, rotator. */
    private const NETWORK = 995641;
    private const PPC_ACCOUNT = 995642;
    private const ROTATOR = 995651;
    private const ROTATOR_TRACKER = 995652;
    private const RULE = 995653;
    private const POSTBACK_AUTH_KEPT = '202_postback_auth_p1956_kept';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static bool $backfillWasEmpty = false;

    /** @var array{string, string} what the ladder printed, and what it logged */
    private static array $upgrade = ['', ''];

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
        // The served tree reads that config, which on a developer checkout can
        // name another install: nothing is served unless it reaches this one.
        $mismatch = self::servedConfigMismatch($root . '/202-config.php');
        if ($mismatch !== null) {
            throw new \RuntimeException("202-config.php does not point at the scratch database, so it is not served: $mismatch");
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        if (!$found instanceof \mysqli_result) {
            throw new \RuntimeException('could not read 202_version: ' . self::$db->error);
        }
        self::$versions = array_column($found->fetch_all(MYSQLI_ASSOC), 'version');
        $marks = self::$db->query('SELECT COUNT(*) FROM 202_reclick_backfill');
        if (!$marks instanceof \mysqli_result) {
            throw new \RuntimeException('could not read 202_reclick_backfill: ' . self::$db->error);
        }
        self::$backfillWasEmpty = (int) $marks->fetch_row()[0] === 0;

        self::cleanUp();
        self::paidShape();
        self::seed();
        self::$upgrade = self::upgrade();
        self::serve($root);
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
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        if (self::$backfillWasEmpty) {
            self::q('DELETE FROM 202_reclick_backfill');
        }
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        if (self::$base === '') {
            self::fail('the tracker could not be served');
        }
    }

    /** Puts back the installed schema and removes every row this class wrote. */
    private static function cleanUp(): void
    {
        $users = implode(',', [self::OWNER, self::PUBLISHER, self::MANAGER]);
        $clicks = "SELECT click_id FROM 202_clicks WHERE user_id IN ($users)";
        foreach ([
            '202_conversion_logs', '202_clicks_publisher', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
            '202_clicks_record', '202_clicks_site', '202_click_latest_visits', '202_google',
            '202_attribution_rollup_dirty_clicks',
        ] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q("DELETE FROM 202_clicks WHERE user_id IN ($users)");
        self::q('DELETE FROM 202_trackers WHERE tracker_id IN (' . implode(', ', [self::TRACKER, self::LP_TRACKER,
            self::ROTATOR_TRACKER]) . ')');
        self::q('DELETE FROM 202_rotator_rules_redirects WHERE rule_id = ' . self::RULE);
        self::q('DELETE FROM 202_rotator_rules_criteria WHERE rule_id = ' . self::RULE);
        self::q('DELETE FROM 202_rotator_rules WHERE id = ' . self::RULE);
        self::q('DELETE FROM 202_rotators WHERE id = ' . self::ROTATOR);
        self::q('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = ' . self::PPC_ACCOUNT);
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = ' . self::PPC_ACCOUNT);
        self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id = ' . self::NETWORK);
        self::q('DELETE FROM 202_bot202_facebook_pixel_assistant WHERE landing_page_id = ' . self::LANDING_PAGE);
        self::q("DELETE FROM 202_api_keys WHERE user_id IN ($users)");
        self::q('DELETE FROM 202_landing_pages WHERE landing_page_id = ' . self::LANDING_PAGE);
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN);
        self::q("DELETE FROM 202_attribution_models WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_user_role WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
        // The paid 1.9.56 shape (paidShape()), back to the installed one.
        self::q('DROP TABLE IF EXISTS 202_clicks_purlink');
        self::q('ALTER TABLE 202_users MODIFY user_pass varchar(255) NOT NULL');
        self::q(\Prosper202\Database\Tables\CoreTables::userDataFeedback()->createStatement);
        // The installed 202_postback_auth paidShape() set aside, with its rows,
        // in place of the one the upgrade made.
        $kept = self::$db->query("SHOW TABLES LIKE '" . self::POSTBACK_AUTH_KEPT . "'");
        if ($kept instanceof \mysqli_result && $kept->num_rows > 0) {
            self::q('DROP TABLE IF EXISTS 202_postback_auth');
            self::q('RENAME TABLE ' . self::POSTBACK_AUTH_KEPT . ' TO 202_postback_auth');
        }
    }

    /**
     * What a paid 1.9.56 database has that a free one lacks, and the reverse:
     * the paid line stores the same "1.9.56", added 202_clicks_purlink, and
     * never created user_data_feedback or widened 202_users.user_pass. Nor
     * did it have 202_postback_auth (1.9.80), which the upgrade makes with a
     * row of mode `off` for each account it finds; the installed one is set
     * aside until cleanUp().
     */
    private static function paidShape(): void
    {
        self::q('DROP TABLE user_data_feedback');
        self::q('RENAME TABLE 202_postback_auth TO ' . self::POSTBACK_AUTH_KEPT);
        self::q('ALTER TABLE 202_users MODIFY user_pass char(32) NOT NULL');
        self::q('CREATE TABLE 202_clicks_purlink (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `click_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `acip` int(10) unsigned NOT NULL DEFAULT 0,
            `lpip` int(10) unsigned NOT NULL DEFAULT 0,
            `click_outbound_site_url_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `click_lead` tinyint(1) unsigned NOT NULL DEFAULT 0,
            `click_time` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `click_lead` (`click_lead`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::q('INSERT INTO 202_clicks_purlink SET click_id = 1, acip = ' . (self::CAMPAIGN + self::PUBLIC_OFFSET)
            . ', click_time = 1');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.56']);
    }

    /** An account's setup as paid 1.9.56 left it: an Admin, a granted Publisher, a campaign, links. */
    private static function seed(): void
    {
        $seeder = new \Prosper202\Database\DataSeeder(self::$db);
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        foreach ([
            [self::OWNER, 2, 'a1956own01'],
            [self::PUBLISHER, 6, self::PUBLISHER_ID],
            [self::MANAGER, 3, self::MANAGER_ID],
        ] as [$user, $role, $publicId]) {
            // A paid 1.9.56 password: salted MD5, 32 characters.
            self::q('INSERT INTO 202_users SET user_id = ' . $user . ", user_name = 'paid$user', user_fname = 'Paid',"
                . " user_lname = '$user', user_pass = '0123456789abcdef0123456789abcdef', user_email = 'paid$user@example.com',"
                . " user_dash_email = '', user_timezone = 'UTC', user_time_register = 1, install_hash = '" . self::INSTALL . "',"
                . " user_hash = '', user_active = 1, user_deleted = 0, user_public_publisher_id = '$publicId'");
            self::q("INSERT INTO 202_user_role SET user_id = $user, role_id = $role");
        }
        // The 1.9.50 step added the privacy column with 'disabled' for existing accounts.
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::OWNER . ", user_pref_privacy = 'disabled',"
            . " user_account_currency = 'USD'");
        self::row('202_api_keys', ['user_id' => self::OWNER, 'api_key' => self::API_KEY, 'created_at' => 1]);
        self::row('202_aff_campaigns', [
            'aff_campaign_id' => self::CAMPAIGN, 'aff_campaign_id_public' => self::CAMPAIGN + self::PUBLIC_OFFSET,
            'user_id' => self::OWNER, 'aff_network_id' => 0, 'aff_campaign_name' => 'Store checkout',
            'aff_campaign_url' => 'https://shop.example/checkout?sid=[[subid]]&pub=[[t202pubid]]',
            'aff_campaign_payout' => self::PAYOUT, 'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => time(),
        ]);
        self::row('202_landing_pages', [
            'landing_page_id' => self::LANDING_PAGE, 'user_id' => self::OWNER,
            'landing_page_id_public' => self::LANDING_PAGE + self::PUBLIC_OFFSET, 'aff_campaign_id' => self::CAMPAIGN,
            'landing_page_nickname' => 'Spring sale page', 'landing_page_url' => 'https://shop.example/spring',
            'landing_page_type' => 0, 'landing_page_time' => time(),
        ]);
        // Bot202 Facebook Pixel Assistant settings a paid 1.9.56 page kept.
        self::row('202_bot202_facebook_pixel_assistant', [
            'landing_page_id' => self::LANDING_PAGE, 'b202_fbpa_status' => 1, 'b202_fbpa_dynamic_epv' => 0,
            'b202_fbpa_content_name' => 'Spring sale', 'b202_fbpa_content_type' => 1, 'b202_fbpa_outbound_clicks' => 2,
        ]);
        foreach ([[self::TRACKER, 0], [self::LP_TRACKER, self::LANDING_PAGE]] as [$tracker, $page]) {
            self::row('202_trackers', [
                'tracker_id' => $tracker, 'user_id' => self::OWNER, 'tracker_id_public' => $tracker + self::PUBLIC_OFFSET,
                'aff_campaign_id' => self::CAMPAIGN, 'text_ad_id' => 0, 'ppc_account_id' => 0,
                'landing_page_id' => $page, 'rotator_id' => 0, 'click_cloaking' => 0, 'tracker_time' => time(),
            ]);
        }
    }

    /** @return array{string, string} what the ladder runner printed, and what was logged */
    private static function upgrade(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('could not start the ladder runner');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) === 2) {
            throw new \RuntimeException("the ladder runner could not set up: $err");
        }

        return [trim($out), $err];
    }

    /**
     * Null when the config's primary and read connections both reach the
     * scratch database (they read a token written there), else what they read.
     */
    private static function servedConfigMismatch(string $config): ?string
    {
        $table = 'p202_served_config_probe';
        $token = bin2hex(random_bytes(12));
        self::q("DROP TABLE IF EXISTS $table");
        self::q("CREATE TABLE $table (token varchar(32) NOT NULL)");
        try {
            self::row($table, ['token' => $token]);
            $out = shell_exec(implode(' ', array_map('escapeshellarg', [
                PHP_BINARY, __DIR__ . '/fixtures/served-config-probe.php', $config, $table,
            ])) . ' 2>&1');
        } finally {
            self::q("DROP TABLE IF EXISTS $table");
        }
        $found = json_decode((string) $out, true);
        if (!is_array($found)) {
            return 'the probe printed ' . var_export($out, true);
        }

        return $found === ['primary' => $token, 'read' => $token] ? null : (string) json_encode($found);
    }

    private static function serve(string $root): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
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

    /**
     * @param array<string, string> $cookies
     * @param array<string, string>|string|null $body a form (encoded) or a raw body; null is a GET
     * @param list<string> $headers
     * @return array{int, string, string} status, headers, body
     */
    private static function http(string $path, array $cookies = [], array|string|null $body = null, array $headers = []): array
    {
        $ch = curl_init(self::$base . $path);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers];
        if ($cookies !== []) {
            $options[CURLOPT_COOKIE] = implode('; ', array_map(
                static fn (string $k, string $v): string => "$k=$v",
                array_keys($cookies),
                $cookies
            ));
        }
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : $body;
        }
        curl_setopt_array($ch, $options);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [$status, substr($out, 0, $size), substr($out, $size)];
    }

    private function serverLog(): string
    {
        return "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    private static function scalar(string $sql): ?string
    {
        $result = self::requireScratch()->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
        $row = $result->fetch_row();

        return $row === null || $row === false ? null : ($row[0] === null ? null : (string) $row[0]);
    }

    /** A visitor follows the campaign's direct link; the click id is the subid the offer URL carries. */
    private function directLinkClick(string $query = ''): int
    {
        $before = (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER);
        [$status, $headers] = self::http('/tracking202/redirect/dl.php?t202id=' . (self::TRACKER + self::PUBLIC_OFFSET)
            . $query);
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertMatchesRegularExpression('~^Location: https://shop\.example/checkout\?sid=(\d+)~mi', $headers);
        preg_match('~^Location: https://shop\.example/checkout\?sid=(\d+)~mi', $headers, $m);
        self::assertSame($before + 1, (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER));

        return (int) $m[1];
    }

    /** @return list<array{string|null, string}> the click's live conversions: transaction id, amount */
    private static function conversions(int $click): array
    {
        $result = self::requireScratch()->query('SELECT transaction_id, click_payout FROM 202_conversion_logs'
            . " WHERE click_id = $click AND deleted = 0 ORDER BY conv_id");
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::$db->error);

        return array_map(
            static fn (array $r): array => [$r[0], number_format((float) $r[1], 2, '.', '')],
            $result->fetch_all(MYSQLI_NUM)
        );
    }

    /** A 202-config.php from the sample, its connections to the scratch server and $dbname as given. */
    private static function sampleConfig(string $dbname, string $readDbname = ''): string
    {
        $host = (string) getenv('P202_TEST_DB_HOST') . ':' . (string) (getenv('P202_TEST_DB_PORT') ?: 3306);
        $config = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/202-config-sample.php'), [
            "'putyourdbnamehere'" => var_export($dbname, true),
            "'usernamehere'" => var_export((string) (getenv('P202_TEST_DB_USER') ?: 'root'), true),
            "'yourpasswordhere'" => var_export((string) (getenv('P202_TEST_DB_PASS') ?: ''), true),
            "'localhosthere'" => var_export($host, true),
            "'localhostreplica'" => var_export($host, true),
        ]);
        if ($readDbname !== '') {
            // A replica that is another database: the read connection alone goes elsewhere.
            $config = str_replace(
                '$this->_connectionro = new mysqli($dbhostro, $dbuser, $dbpass, $dbname);',
                '$this->_connectionro = new mysqli($dbhostro, $dbuser, $dbpass, ' . var_export($readDbname, true) . ');',
                $config
            );
            self::assertStringContainsString(var_export($readDbname, true) . ');', $config);
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'p202cfg');
        file_put_contents($file, $config);

        return $file;
    }

    /** A config for another install is refused before anything is served from it. */
    public function testAServedConfigForAnotherDatabaseIsRefused(): void
    {
        $scratch = (string) self::scalar('SELECT DATABASE()');
        $files = [
            'scratch' => self::sampleConfig($scratch),
            'other' => self::sampleConfig('information_schema'),
            'other replica' => self::sampleConfig($scratch, 'information_schema'),
        ];
        try {
            self::assertNull(self::servedConfigMismatch($files['scratch']), 'the scratch config is accepted');
            self::assertNotNull(self::servedConfigMismatch($files['other']), 'another database is refused');
            self::assertNotNull(self::servedConfigMismatch($files['other replica']), 'a read connection elsewhere is refused');
        } finally {
            array_map('unlink', $files);
        }
    }

    public function testThePaidDatabaseUpgradesTo1980AndKeepsItsData(): void
    {
        [$out, $err] = self::$upgrade;
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', self::scalar('SELECT version FROM 202_version'));
        self::assertSame('0123456789abcdef0123456789abcdef', self::scalar(
            'SELECT user_pass FROM 202_users WHERE user_id = ' . self::OWNER
        ), 'the stored 1.9.56 password is kept, so its sign-in still works');
        self::assertSame('Store checkout', self::scalar(
            'SELECT aff_campaign_name FROM 202_aff_campaigns WHERE aff_campaign_id = ' . self::CAMPAIGN
        ));
        self::assertSame('1', self::scalar('SELECT COUNT(*) FROM 202_clicks_purlink'), 'paid-only rows are not dropped');
        self::assertSame('Spring sale', self::scalar('SELECT b202_fbpa_content_name FROM 202_bot202_facebook_pixel_assistant'
            . ' WHERE landing_page_id = ' . self::LANDING_PAGE));
        self::assertSame('disabled', self::scalar(
            'SELECT user_pref_privacy FROM 202_users_pref WHERE user_id = ' . self::OWNER
        ), 'an upgraded account keeps tracking as it did');
        self::assertSame('off', self::scalar(
            'SELECT GROUP_CONCAT(DISTINCT auth_mode) FROM 202_postback_auth WHERE user_id IN ('
            . implode(', ', [self::OWNER, self::PUBLISHER, self::MANAGER]) . ') HAVING COUNT(*) = 3'
        ), 'each account the upgrade found keeps its postbacks and pixels without a token');
    }

    /**
     * The paid line's 1.9.56 never widened user_pass or made user_data_feedback;
     * the upgrade has to, or a password set before first sign-in is cut.
     */
    public function testThePaidSchemaIsTheInstalledOneAfterTheUpgrade(): void
    {
        self::assertSame('varchar(255)', self::requireScratch()
            ->query("SHOW COLUMNS FROM 202_users LIKE 'user_pass'")->fetch_assoc()['Type'] ?? null);
        self::assertSame('1', self::scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_data_feedback'"
        ));
    }

    /** <script src=".../tracking202/static/landing.js?lpip=N" id="t202js" async>, Get LP Code's 1.9.56 snippet. */
    public function testTheLegacyLandingScriptLoadsTheTrackingScriptAndRecordsTheVisit(): void
    {
        $lpip = (string) (self::LANDING_PAGE + self::PUBLIC_OFFSET);
        $t202id = (string) (self::LP_TRACKER + self::PUBLIC_OFFSET);
        [$status, $headers, $loader] = self::http("/tracking202/static/landing.js?lpip=$lpip");
        self::assertSame(200, $status, $headers);
        self::assertMatchesRegularExpression('~^Content-Type: (application|text)/javascript~mi', $headers);

        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                self::fail('node is not on PATH in CI; the legacy loader must be run');
            }
            self::markTestSkipped('node is not installed');
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'p202lj');
        file_put_contents($file, $loader);
        $src = self::$base . "/tracking202/static/landing.js?lpip=$lpip";
        $command = escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/fixtures/legacy-landing-loader.js') . ' '
            . escapeshellarg($file) . ' ' . escapeshellarg($src) . ' '
            . escapeshellarg("https://shop.example/spring?t202id=$t202id&c1=spring");
        exec($command . ' 2>&1', $lines, $code);
        @unlink($file);
        $printed = implode("\n", $lines);
        self::assertSame(0, $code, $printed);
        $inserted = json_decode($printed, true);
        self::assertSame([[
            'src' => self::$base . "/tracking202/static/landing.php?lpip=$lpip&t202id=$t202id",
            'id' => 'upxif',
        ]], $inserted, 'the loader inserts the current landing script, once');

        [$status, $headers, $script] = self::http(substr($inserted[0]['src'], strlen(self::$base)));
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertStringContainsString('tracking202/static/record.php?lpip=', $script);

        // The beacon that script sends records the landing page's click.
        $before = (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE landing_page_id = ' . self::LANDING_PAGE);
        [$status, $headers] = self::http(
            "/tracking202/static/record.php?lpip=$lpip&t202id=$t202id&c1=spring"
                . '&referer=' . rawurlencode('https://search.example/?q=spring+sale'),
            [],
            null,
            ["Referer: https://shop.example/spring?t202id=$t202id&c1=spring"]
        );
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame($before + 1, (int) self::scalar(
            'SELECT COUNT(*) FROM 202_clicks WHERE landing_page_id = ' . self::LANDING_PAGE
        ), 'the visit is recorded against the landing page' . $this->serverLog());
    }

    /** @return array<string, array{string, bool}> endpoint => path, whether it reads the click from the cookie */
    public static function orderNumberEndpoints(): array
    {
        return [
            'global postback gpb.php' => ['/tracking202/static/gpb.php', false],
            'global pixel gpx.php' => ['/tracking202/static/gpx.php', true],
            'universal pixel upx.php' => ['/tracking202/static/upx.php', true],
        ];
    }

    /**
     * The order number in t202txid, the name paid 1.9.56 pixel and postback
     * code sends, records each sale on the click.
     *
     * @dataProvider orderNumberEndpoints
     */
    public function testEachOrderNumberInT202txidIsASale(string $path, bool $cookie): void
    {
        $click = $this->directLinkClick();
        $fire = function (string $query) use ($path, $cookie, $click): void {
            $query .= $cookie ? '' : "&subid=$click";
            [$status, $headers] = self::http(
                "$path?$query",
                $cookie ? ['tracking202subid' => (string) $click] : []
            );
            self::assertSame(200, $status, $headers . $this->serverLog());
        };

        $fire('amount=40.00&t202txid=ORDER-1001&t202dedupe=0');
        $fire('amount=15.00&t202txid=ORDER-1002&t202dedupe=0');

        self::assertSame([['ORDER-1001', '40.00'], ['ORDER-1002', '15.00']], self::conversions($click));
        self::assertSame('1', self::scalar("SELECT click_lead FROM 202_clicks WHERE click_id = $click"));
    }

    /**
     * Intentional: the same order number again is the same sale. 1.9.56
     * counted it again unless the pixel sent t202dedupe=1; that is no longer read.
     *
     * @dataProvider orderNumberEndpoints
     */
    public function testTheSameOrderNumberSentAgainCountsOnce(string $path, bool $cookie): void
    {
        $click = $this->directLinkClick();
        foreach (['t202dedupe=0', 't202dedupe=1', ''] as $dedupe) {
            [$status] = self::http(
                "$path?amount=40.00&t202txid=ORDER-2001&$dedupe" . ($cookie ? '' : "&subid=$click"),
                $cookie ? ['tracking202subid' => (string) $click] : []
            );
            self::assertSame(200, $status, $this->serverLog());
        }

        self::assertSame([['ORDER-2001', '40.00']], self::conversions($click));
    }

    /**
     * Intentional: with no order number (1.9.56's universal pixel shipped
     * `t202txid=` blank) a click converts once, since a reload of the
     * thank-you page cannot be told from a repeat sale.
     *
     * @dataProvider orderNumberEndpoints
     */
    public function testAPixelWithoutAnOrderNumberConvertsTheClickOnce(string $path, bool $cookie): void
    {
        $click = $this->directLinkClick();
        for ($i = 0; $i < 2; $i++) {
            [$status] = self::http(
                "$path?amount=40.00&t202txid=&t202dedupe=0" . ($cookie ? '' : "&subid=$click"),
                $cookie ? ['tracking202subid' => (string) $click] : []
            );
            self::assertSame(200, $status, $this->serverLog());
        }

        self::assertCount(1, self::conversions($click));
    }

    /** Get Links' publisher links carried t202pubid; the click is credited to a publisher the account granted. */
    public function testAPublisherLinkCreditsTheGrantedPublisher(): void
    {
        $click = $this->directLinkClick('&t202pubid=' . self::PUBLISHER_ID);
        self::assertSame((string) self::PUBLISHER, self::scalar(
            "SELECT publisher_user_id FROM 202_clicks_publisher WHERE click_id = $click"
        ));
        self::assertSame((string) self::OWNER, self::scalar("SELECT user_id FROM 202_clicks WHERE click_id = $click"),
            'the click stays the account\'s, so its reports keep it');

        // A public id of a user without the Publisher role is a claim nobody granted.
        $plain = $this->directLinkClick('&t202pubid=' . self::MANAGER_ID);
        self::assertNull(self::scalar("SELECT publisher_user_id FROM 202_clicks_publisher WHERE click_id = $plain"));
    }

    public function testTheClickCookieLastsThirtyDays(): void
    {
        [$status, $headers] = self::http('/tracking202/redirect/dl.php?t202id=' . (self::TRACKER + self::PUBLIC_OFFSET));
        self::assertSame(302, $status, $headers);
        self::assertMatchesRegularExpression('~^Set-Cookie: tracking202subid=\d+;[^\r\n]*Max-Age=(\d+)~mi', $headers);
        preg_match('~^Set-Cookie: tracking202subid=\d+;[^\r\n]*Max-Age=(\d+)~mi', $headers, $m);
        self::assertEqualsWithDelta(30 * 86400, (int) $m[1], 5, '1.9.56 kept it 90 days');
    }

    public function testTheDailyEmailReportIsRetired(): void
    {
        [$status, , $body] = self::http('/202-cronjobs/daily-email.php');
        self::assertSame(410, $status);
        self::assertStringContainsString('switched off', $body);
    }

    /** The v1 reports API, with a key the account made before the upgrade, counts today's click and sale. */
    public function testTheV1ReportsApiAnswersWithAKeyFromBeforeTheUpgrade(): void
    {
        $report = function (): array {
            [$status, $headers, $body] = self::http('/api/v1/reports/?apikey=' . self::API_KEY . '&type=landing_pages');
            self::assertSame(200, $status, $headers . $body . $this->serverLog());
            $data = json_decode($body, true);
            self::assertIsArray($data, $body);
            self::assertArrayHasKey('totals', $data, $body);

            return $data['totals'];
        };
        $before = $report();
        $click = $this->directLinkClick();
        [$status] = self::http("/tracking202/static/gpb.php?subid=$click&amount=40.00&t202txid=ORDER-5001");
        self::assertSame(200, $status);
        $after = $report();

        self::assertSame((int) $before['clicks'] + 1, (int) $after['clicks']);
        self::assertSame((int) $before['leads'] + 1, (int) $after['leads']);
    }

    /** 1.9.56's Conversion Logs listed every conversion on the account. */
    public function testTheConversionLogListsTheAccountsConversions(): void
    {
        $click = $this->directLinkClick();
        [$status] = self::http("/tracking202/static/gpb.php?subid=$click&amount=40.00&t202txid=ORDER-8001");
        self::assertSame(200, $status);
        $page = (new \Prosper202\Conversion\ConversionLog(new \Prosper202\Database\Connection(self::requireScratch())))
            ->page(self::OWNER, ['campaign_id' => null, 'click_id' => $click, 'from' => null, 'to' => null,
                'time_basis' => 'conversion', 'type' => null, 'deleted' => null], 0);
        self::assertSame(['ORDER-8001'], array_column($page['rows'], 'transaction_id'));
    }

    // Contracts restored after 1.9.80 branched.

    /**
     * 1.9.56's gpb.php took its values from a form POST body as well as the query.
     */
    public function testTheGlobalPostbackTakesAFormPostBody(): void
    {
        $click = $this->directLinkClick();
        [$status, $headers] = self::http('/tracking202/static/gpb.php?amount=&subid=', [],
            ['subid' => (string) $click, 'amount' => '40.00', 't202txid' => 'ORDER-6001']);
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame([['ORDER-6001', '40.00']], self::conversions($click));
    }

    /**
     * A PurLink SubID from 1.9.56 is `<click>-<campaign public id>`, and gpb.php takes it.
     */
    public function testTheGlobalPostbackTakesA1956PurLinkSubid(): void
    {
        $click = $this->directLinkClick();
        $acip = self::CAMPAIGN + self::PUBLIC_OFFSET;
        [$status, $headers] = self::http("/tracking202/static/gpb.php?subid=$click-$acip&amount=40.00&t202txid=ORDER-6101");
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertSame([['ORDER-6101', '40.00']], self::conversions($click));
        [$status] = self::http("/tracking202/static/gpb.php?subid=$click-" . ($acip + 1) . '&amount=1&t202txid=ORDER-6102');
        self::assertSame(404, $status, 'a SubID naming another campaign is not the click\'s');
    }

    /**
     * A 1.9.56 snippet's defpixel=1 turns the deferred pixel on.
     */
    public function testTheLegacySnippetsDeferredPixelIsRead(): void
    {
        $lpip = self::LANDING_PAGE + self::PUBLIC_OFFSET;
        [$status, , $body] = self::http("/tracking202/static/landing.php?lpip=$lpip&defpixel=1");
        self::assertSame(200, $status);
        self::assertStringContainsString('var p202PurLinkDeferred = true;', $body);
    }

    /**
     * 1.9.56's dynamic content handed the landing page the visit's SubID
     * (beside it now: the proof dcs.updateDataObj() changes the click with).
     */
    public function testDynamicContentHandsThePageTheSubid(): void
    {
        $lpip = self::LANDING_PAGE + self::PUBLIC_OFFSET;
        [$status, $headers, $body] = self::http("/tracking202/static/record_simple.php?lpip=$lpip");
        self::assertSame(200, $status, $headers . $this->serverLog());
        $click = self::scalar('SELECT MAX(click_id) FROM 202_clicks WHERE landing_page_id = ' . self::LANDING_PAGE);
        self::assertNotNull($click);
        self::assertMatchesRegularExpression('~window\.p202DcsClick\(\{"t202Subid":"' . $click . '"[,}]~', $body);
    }

    /**
     * The JVZIPN URL 1.9.56 showed named no role, and 1.9.56 read it as the
     * affiliate's (caffitid): a signed sale there records, once, as at ?role=affiliate.
     */
    public function testARolelessJvzooIpnRecordsTheSaleAsTheAffiliates(): void
    {
        self::q('UPDATE 202_users_pref SET jvzoo_ipn_secret_key = ' . self::str(self::JVZIPN_KEY)
            . ' WHERE user_id = ' . self::OWNER);
        $click = $this->directLinkClick();
        $fields = ['caffitid' => (string) $click, 'ctransaction' => 'SALE', 'ctransamount' => '47.00',
            'ctransreceipt' => 'JVZ-RECEIPT-1956', 'ctranstime' => (string) time(), 'ccustname' => 'Pat Doe',
            'ccustemail' => 'pat@example.com', 'cproditem' => '1956', 'cprodtitle' => 'Coffee subscription',
            'cprodtype' => 'STANDARD', 'ctranspaymentmethod' => 'PYPL', 'cvendthru' => ''];
        ksort($fields, SORT_STRING);
        $fields['cverify'] = strtoupper(substr(sha1(implode('|', $fields) . '|' . self::JVZIPN_KEY), 0, 8));
        [$status, $headers, $body] = self::http('/tracking202/static/jvzoo.php', [], $fields);
        self::assertSame(200, $status, $headers . $body . $this->serverLog());
        self::assertSame([['JVZ-RECEIPT-1956', '47.00']], self::conversions($click));
        [$status, $headers, $body] = self::http('/tracking202/static/jvzoo.php?role=affiliate', [], $fields);
        self::assertSame(200, $status, $headers . $body . $this->serverLog());
        self::assertSame([['JVZ-RECEIPT-1956', '47.00']], self::conversions($click), 'the same sale, once');
    }

    /**
     * 1.9.56's API v1 setup reads (campaigns, version) answer with the account's key.
     */
    public function testTheV1SetupApiAnswers(): void
    {
        [$status, , $body] = self::http('/api/v1/version/?apikey=' . self::API_KEY);
        self::assertSame(200, $status, $body);
        self::assertSame(['version' => '1.9.80'], json_decode($body, true));
        [$status, , $body] = self::http('/api/v1/campaigns/?apikey=' . self::API_KEY);
        self::assertSame(200, $status, $body);
        self::assertContains('Store checkout', array_column(json_decode($body, true)['campaigns'] ?? [], 'aff_campaign_name'));
    }

    /**
     * The scheduled Google feed URL says where its replacement is instead of a blank page.
     */
    public function testTheOldGoogleFeedUrlNamesItsReplacement(): void
    {
        self::q('DELETE FROM 202_legacy_google_feed_fetches');
        [$status, , $body] = self::http('/tracking202/static/feeds/google/offlineconversions/?cn=Purchase&ac=--');
        self::assertSame(410, $status);
        self::assertStringContainsString('Setup > Offline Conversions', $body);
        // The fetch is noted, so Setup > Offline Conversions lists the schedule to move over.
        [$status] = self::http('/tracking202/static/feeds/google/offlineconversions/?cn=Purchase&ac=' . self::CAMPAIGN);
        self::assertSame(410, $status);
        [$status] = self::http('/tracking202/static/feeds/google/offlineconversions/?cn=Purchase&ac=' . self::CAMPAIGN);
        self::assertSame(410, $status);
        // One that names no schedule is answered the same and noted nowhere.
        [$status] = self::http('/tracking202/static/feeds/google/offlineconversions/?cn=Purchase&ac=12abc');
        self::assertSame(410, $status);
        $noted = self::$db->query('SELECT aff_campaign_id, conversion_name, fetches FROM 202_legacy_google_feed_fetches ORDER BY fetch_id');
        self::assertInstanceOf(\mysqli_result::class, $noted);
        self::assertSame(
            [['0', 'Purchase', '1'], [(string) self::CAMPAIGN, 'Purchase', '2']],
            $noted->fetch_all(MYSQLI_NUM),
            $this->serverLog()
        );
        self::q('DELETE FROM 202_legacy_google_feed_fetches');
    }

    /**
     * A Google Ads tracking template from 1.9.56 (pt.php) records the click and sends the visitor on.
     */
    public function testAGoogleParallelTrackingLinkRecordsTheClick(): void
    {
        $before = (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER);
        [$status, $headers] = self::http('/tracking202/redirect/pt.php?t202id=' . (self::LP_TRACKER + self::PUBLIC_OFFSET)
            . '&gclid=G-paid-1956', [], null, ['Referer: https://www.google.com/']);
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertMatchesRegularExpression('~^Location: https://shop\.example/spring\?gclid=G-paid-1956~mi', $headers);
        self::assertSame($before + 1, (int) self::scalar('SELECT COUNT(*) FROM 202_clicks WHERE user_id = ' . self::OWNER));
        self::assertSame('1', self::scalar("SELECT COUNT(*) FROM 202_google WHERE gclid = 'G-paid-1956'"));
    }

    /**
     * Update Subids took `SUBID, PAYOUT, TRANSACTION ID, TIMESTAMP` per line.
     */
    public function testUpdateSubidsReadsPayoutOrderNumberAndTime(): void
    {
        $click = $this->directLinkClick();
        // A sale at the click's own second (a later one would be in the future), in 1.9.56's m/d/Y g:i:s A.
        $sold = (int) self::scalar("SELECT click_time FROM 202_clicks WHERE click_id = $click");
        $line = "$click, 12.50, ORDER-7001, " . gmdate('m/d/Y g:i:s A', $sold);
        $marked = (new \Prosper202\Update\SubidBatch(new \Prosper202\Database\Connection(self::requireScratch())))
            ->mark(self::OWNER, [$line], null, new \DateTimeZone('UTC'));
        self::assertSame(1, $marked['marked']);
        self::assertSame([['ORDER-7001', '12.50']], self::conversions($click));
        self::assertSame((string) $sold, self::scalar(
            "SELECT conv_time FROM 202_conversion_logs WHERE click_id = $click AND deleted = 0"
        ));
    }

    /**
     * 1.9.56 took Shopify's order webhook at the global postback.
     */
    public function testAShopifyOrderWebhookAtTheGlobalPostbackIsRecorded(): void
    {
        self::q('UPDATE 202_users_pref SET shopify_webhook_secret = ' . self::str(self::SHOPIFY_SECRET)
            . ' WHERE user_id = ' . self::OWNER);
        $click = $this->directLinkClick();
        $order = (string) json_encode([
            'id' => 5550001956001, 'name' => '#1956', 'currency' => 'USD', 'presentment_currency' => 'USD',
            'financial_status' => 'paid', 'test' => false, 'total_price' => '59.00',
            'created_at' => '2026-11-01T10:15:00-05:00', 'processed_at' => '2026-11-01T10:15:02-05:00',
            'line_items' => [['id' => 13001, 'title' => 'Coffee beans, 1 kg', 'quantity' => 1, 'price' => '59.00',
                'properties' => [['name' => 't202subid', 'value' => (string) $click]]]],
        ], JSON_UNESCAPED_SLASHES);
        [$status, $headers, $body] = self::http('/tracking202/static/gpb.php', [], $order, [
            'Content-Type: application/json', 'X-Shopify-Topic: orders/paid',
            'X-Shopify-Hmac-Sha256: ' . base64_encode(hash_hmac('sha256', $order, self::SHOPIFY_SECRET, true)),
        ]);
        self::assertSame(200, $status, $headers . $body . $this->serverLog());
        self::assertSame([['5550001956001', '59.00']], self::conversions($click));
    }

    /**
     * 1.9.56 redirector rules matched c1-c4, utm_*, t202kw, referer and more.
     */
    public function testARedirectorRuleOnC1Matches(): void
    {
        self::row('202_rotators', ['id' => self::ROTATOR, 'public_id' => self::ROTATOR + self::PUBLIC_OFFSET,
            'user_id' => self::OWNER, 'name' => 'Spring split', 'default_url' => 'https://shop.example/default',
            'default_campaign' => null, 'default_lp' => null]);
        self::row('202_trackers', ['tracker_id' => self::ROTATOR_TRACKER, 'user_id' => self::OWNER,
            'tracker_id_public' => self::ROTATOR_TRACKER + self::PUBLIC_OFFSET, 'aff_campaign_id' => 0, 'text_ad_id' => 0,
            'ppc_account_id' => 0, 'landing_page_id' => 0, 'rotator_id' => self::ROTATOR, 'click_cloaking' => 0,
            'tracker_time' => time()]);
        self::row('202_rotator_rules', ['id' => self::RULE, 'rotator_id' => self::ROTATOR, 'rule_name' => 'Newsletter',
            'status' => 1, 'position' => 0]);
        self::row('202_rotator_rules_criteria', ['rotator_id' => self::ROTATOR, 'rule_id' => self::RULE, 'type' => 'c1',
            'statement' => 'is', 'value' => 'spring']);
        self::row('202_rotator_rules_redirects', ['rule_id' => self::RULE, 'redirect_url' => 'https://shop.example/newsletter',
            'weight' => '0', 'name' => '']);
        $path = '/tracking202/redirect/rtr.php?t202id=' . (self::ROTATOR_TRACKER + self::PUBLIC_OFFSET);

        [$status, $headers] = self::http("$path&c1=spring", [], null, ['X-Forwarded-For: 198.51.100.21']);
        self::assertSame(302, $status, $headers . $this->serverLog());
        self::assertMatchesRegularExpression('~^Location: https://shop\.example/newsletter~mi', $headers);
        [, $headers] = self::http("$path&c1=autumn", [], null, ['X-Forwarded-For: 198.51.100.22']);
        self::assertMatchesRegularExpression('~^Location: https://shop\.example/default~mi', $headers);
    }

    /**
     * A page's Bot202 Facebook Pixel Assistant settings from 1.9.56 fire the account's pixel again.
     */
    public function testTheFacebookPixelAssistantFiresOnTheLandingPage(): void
    {
        self::row('202_ppc_networks', ['ppc_network_id' => self::NETWORK, 'user_id' => self::OWNER,
            'ppc_network_name' => 'Social ads', 'ppc_network_time' => time()]);
        self::row('202_ppc_accounts', ['ppc_account_id' => self::PPC_ACCOUNT, 'user_id' => self::OWNER,
            'ppc_network_id' => self::NETWORK, 'ppc_account_name' => 'Spring campaign', 'ppc_account_time' => time()]);
        self::row('202_ppc_account_pixels', ['ppc_account_id' => self::PPC_ACCOUNT, 'pixel_type_id' => 6,
            'pixel_code' => '123456789012345']);
        self::q('UPDATE 202_trackers SET ppc_account_id = ' . self::PPC_ACCOUNT . ' WHERE tracker_id = ' . self::LP_TRACKER);
        $lpip = self::LANDING_PAGE + self::PUBLIC_OFFSET;
        [$status, $headers, $body] = self::http("/tracking202/static/record_simple.php?lpip=$lpip&t202id="
            . (self::LP_TRACKER + self::PUBLIC_OFFSET));
        self::q('UPDATE 202_trackers SET ppc_account_id = 0 WHERE tracker_id = ' . self::LP_TRACKER);
        self::assertSame(200, $status, $headers . $this->serverLog());
        self::assertStringContainsString('window.p202FbPixelAssistant({"pixelId":"123456789012345"', $body);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AdministrationController;
use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * The Administration endpoints (AdministrationController) and the LTV product
 * edit/delete and webhook delivery log (LtvController), against a real
 * database: the rules of 202-account/administration.php and
 * tracking202/ajax/ltv_products.php, which row each setting lands on, and
 * what is never served (the login log's passwords, a webhook's secret).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME).
 * It installs the schema there, writes users 5701-5703 (and user 1, when the
 * database has none) and removes the rows it created afterwards, changes
 * user 1's retention settings and puts them back, and — because the
 * one-off deletion is install-wide — removes every click below id 1000 and
 * every click recorded before 2002 from the click tables first (and runs
 * the cron job's scheduled deletion over what it then wrote). Point it at
 * a scratch database, never one an instance is using.
 *
 * @group integration
 */
final class AccountAdministrationIntegrationTest extends TestCase
{
    private const ADMIN = 5702;
    private const LTV_USER = 5701;
    private const OTHER = 5703;

    private static ?\mysqli $db = null;

    /** @var array<string, mixed>|null user 1's retention settings before the test */
    private ?array $ownerBefore = null;

    /** @var array<string, list<int>> table => the user rows this class inserted, removed afterwards */
    private static array $created = ['202_users' => [], '202_users_pref' => []];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            foreach (self::$created as $table => $users) {
                if ($users !== []) {
                    self::$db->query("DELETE FROM $table WHERE user_id IN (" . implode(',', $users) . ')');
                }
            }
        }
        self::$created = ['202_users' => [], '202_users_pref' => []];
        self::$db?->close();
        self::$db = null;
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    /** @return array<string, mixed>|null */
    private static function row(string $sql): ?array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_assoc();
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        foreach ([1, self::ADMIN, self::LTV_USER, self::OTHER] as $user) {
            self::q("INSERT IGNORE INTO 202_users SET user_id = $user, user_name = 'admin-it-$user', user_pass = '', user_email = 'admin-it-$user@example.com', user_dash_email = '', user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = ''");
            if (self::$db->affected_rows === 1) {
                self::$created['202_users'][] = $user;
            }
            self::q("INSERT IGNORE INTO 202_users_pref SET user_id = $user");
            if (self::$db->affected_rows === 1) {
                self::$created['202_users_pref'][] = $user;
            }
        }
        self::q("UPDATE 202_users SET user_timezone = 'UTC' WHERE user_id = " . self::ADMIN);
        $this->ownerBefore = self::row('SELECT user_auto_database_optimization_days, user_delete_data_before, user_delete_data_clickid, maxmind_isp, user_tracking_domain FROM 202_users_pref WHERE user_id = 1');

        foreach (AdministrationController::CLICK_DATA_TABLES as $table) {
            self::q("DELETE FROM `$table` WHERE click_id < 1000");
        }
        $old = self::$db->query('SELECT click_id FROM 202_clicks WHERE click_time < ' . gmmktime(0, 0, 0, 1, 1, 2002));
        $ids = array_map(static fn (array $r): int => (int) $r['click_id'], $old->fetch_all(MYSQLI_ASSOC));
        if ($ids !== []) {
            foreach (AdministrationController::CLICK_DATA_TABLES as $table) {
                self::q("DELETE FROM `$table` WHERE click_id IN (" . implode(',', $ids) . ')');
            }
        }
        // Three clicks in 2001 (UTC), each with its advance row.
        foreach ([101 => gmmktime(12, 0, 0, 1, 10, 2001), 102 => gmmktime(12, 0, 0, 2, 10, 2001), 103 => gmmktime(12, 0, 0, 3, 10, 2001)] as $id => $at) {
            self::q("INSERT INTO 202_clicks SET click_id = $id, user_id = " . self::ADMIN . ", ppc_account_id = 0, click_cpc = 0, click_time = $at");
            self::q("INSERT INTO 202_clicks_advance SET click_id = $id, keyword_id = 0, ip_id = 0, country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0");
        }
        self::q('UPDATE 202_users_pref SET user_delete_data_before = NULL, user_delete_data_clickid = NULL, user_auto_database_optimization_days = 0 WHERE user_id = 1');

        self::q('DELETE FROM 202_products WHERE user_id IN (' . self::LTV_USER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_revenue_line_items WHERE user_id IN (' . self::LTV_USER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_ltv_webhooks WHERE user_id IN (' . self::LTV_USER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_ltv_webhook_deliveries WHERE user_id IN (' . self::LTV_USER . ', ' . self::OTHER . ')');
    }

    protected function tearDown(): void
    {
        if (self::$db === null) {
            return;
        }
        foreach (AdministrationController::CLICK_DATA_TABLES as $table) {
            self::q("DELETE FROM `$table` WHERE click_id IN (101, 102, 103, 104)");
        }
        if ($this->ownerBefore !== null) {
            $stmt = self::$db->prepare('UPDATE 202_users_pref SET user_auto_database_optimization_days = ?, user_delete_data_before = ?, user_delete_data_clickid = ?, maxmind_isp = ?, user_tracking_domain = ? WHERE user_id = 1');
            $days = (int) $this->ownerBefore['user_auto_database_optimization_days'];
            $cutoff = $this->ownerBefore['user_delete_data_before'] === null ? null : (int) $this->ownerBefore['user_delete_data_before'];
            $marker = $this->ownerBefore['user_delete_data_clickid'] === null ? null : (int) $this->ownerBefore['user_delete_data_clickid'];
            $isp = (int) $this->ownerBefore['maxmind_isp'];
            $domain = (string) $this->ownerBefore['user_tracking_domain'];
            $stmt->bind_param('iiiis', $days, $cutoff, $marker, $isp, $domain);
            self::assertTrue($stmt->execute());
            $stmt->close();
        }
        putenv('P202_GEO_DIR');
    }

    private function admin(): AdministrationController
    {
        return new AdministrationController(self::$db, self::ADMIN);
    }

    /** @param callable(): mixed $call */
    private static function refused(string $exception, callable $call, string $field = ''): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e, $e->getMessage());
            if ($field !== '' && $e instanceof ValidationException) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), 'the refusal names ' . $field);
            }
            return;
        }
        self::fail('expected ' . $exception);
    }

    /**
     * The preview counts what the cron job deletes, by the cron job's own
     * selection, and the write schedules that day's time — and then the
     * cron job's deletion removes exactly the clicks the preview counted.
     *
     * The day is 2001-03-01. 104 is the newest id recorded before it, 101
     * was visited again after it (a rotator re-click's second row), and 103
     * is after it. The id marker this replaced was MAX(click_id) at or
     * before the day — 104 — and the clicks below it went: 101 and 103
     * with 102, while 104 stayed.
     */
    public function testTheDeletionPreviewCountsWhatTheCronJobDeletesAndTheWriteSchedulesThatDay(): void
    {
        $click = 'INSERT INTO 202_clicks SET user_id = ' . self::ADMIN . ', ppc_account_id = 0, click_cpc = 0, ';
        self::q($click . 'click_id = 101, click_time = ' . gmmktime(9, 0, 0, 3, 5, 2001));
        self::q($click . 'click_id = 104, click_time = ' . gmmktime(12, 0, 0, 1, 20, 2001));
        self::q('INSERT INTO 202_clicks_advance SET click_id = 104, keyword_id = 0, ip_id = 0, country_id = 0,'
            . ' region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0');
        $stored = static fn (): ?array => self::row(
            'SELECT user_delete_data_clickid AS m, user_delete_data_before AS b FROM 202_users_pref WHERE user_id = 1'
        );
        // An id an earlier version scheduled: the state shows it, and the
        // write replaces it.
        self::q('UPDATE 202_users_pref SET user_delete_data_clickid = 103 WHERE user_id = 1');
        $legacy = ['before' => '2001-03-10', 'cutoff_time' => null, 'through_click_id' => 103, 'clicks_remaining' => 3];
        self::assertSame(
            $legacy,
            $this->admin()->retention()['data']['scheduled_deletion'],
            'the clicks below 103, and the day of the click the id names'
        );

        $cutoff = gmmktime(0, 0, 0, 3, 1, 2001);
        $preview = $this->admin()->scheduleDeletion(['before' => '2001-03-01'], true)['data'];
        self::assertTrue($preview['dry_run']);
        self::assertFalse($preview['scheduled']);
        self::assertSame('UTC', $preview['timezone']);
        self::assertSame($cutoff, $preview['cutoff_time']);
        self::assertSame(2, $preview['clicks'], '102 and 104: 101 was visited again after the day, 103 is after it');
        self::assertSame(2, $preview['rows']->{'202_clicks'});
        self::assertSame(2, $preview['rows']->{'202_clicks_advance'});
        self::assertSame(0, $preview['rows']->{'202_clicks_spy'});
        self::assertArrayNotHasKey('through_click_id', $preview);
        self::assertSame($legacy, $preview['current']);
        self::assertSame(['m' => '103', 'b' => null], $stored(), 'a dry run writes nothing');

        $write = fn (array $body): array => $this->admin()->scheduleDeletion(['before' => '2001-03-01'] + $body, false);
        self::refused(ValidationException::class, fn () => $write([]), 'cutoff_time');
        self::refused(ConflictException::class, fn () => $write(['cutoff_time' => $cutoff + 3600]));
        self::refused(ValidationException::class, fn () => $write(['cutoff_time' => (string) $cutoff]), 'cutoff_time');
        self::refused(ValidationException::class, fn () => $write(['cutoff_time' => null]), 'cutoff_time');
        self::refused(ValidationException::class, fn () => $write(['through_click_id' => 104]), 'through_click_id');
        self::assertSame(['m' => '103', 'b' => null], $stored(), 'a refused write schedules nothing');

        $done = $write(['cutoff_time' => $cutoff])['data'];
        self::assertTrue($done['scheduled']);
        self::assertSame(2, $done['clicks']);
        self::assertSame($legacy, $done['current'], 'what was scheduled before this request');
        // On user 1's row, which the cron job reads — not the caller's — and
        // the earlier version's id is gone with it.
        self::assertSame(['m' => null, 'b' => (string) $cutoff], $stored());
        self::assertSame(
            ['b' => null],
            self::row('SELECT user_delete_data_before AS b FROM 202_users_pref WHERE user_id = ' . self::ADMIN)
        );

        self::assertSame(
            ['before' => '2001-03-01', 'cutoff_time' => $cutoff, 'through_click_id' => null, 'clicks_remaining' => 2],
            $this->admin()->retention()['data']['scheduled_deletion']
        );

        // The cron job's deletion removes what the preview counted, and
        // nothing else.
        $retention = new \Prosper202\Click\ClickRetention(new \Prosper202\Database\Connection(self::$db));
        $report = $retention->runScheduled(PHP_INT_MAX);
        self::assertSame($cutoff, $report['before']);
        self::assertSame($preview['clicks'], $report['clicks']);
        self::assertSame((array) $preview['rows'], $report['rows']);
        $left = self::$db->query(
            'SELECT DISTINCT click_id FROM 202_clicks WHERE click_id IN (101, 102, 103, 104) ORDER BY click_id'
        );
        self::assertSame([['101'], ['103']], $left->fetch_all());
        self::assertSame(0, $this->admin()->retention()['data']['scheduled_deletion']['clicks_remaining']);

        // A day before every click: nothing to delete, nothing written.
        $early = gmmktime(0, 0, 0, 1, 1, 2000);
        self::assertSame(0, $this->admin()->scheduleDeletion(['before' => '2000-01-01'], true)['data']['clicks']);
        $none = $this->admin()->scheduleDeletion(['before' => '2000-01-01', 'cutoff_time' => $early], false)['data'];
        self::assertFalse($none['scheduled']);
        self::assertSame(0, $none['clicks']);
        self::assertSame(['m' => null, 'b' => (string) $cutoff], $stored());
    }

    /**
     * The cutoff is the caller's midnight: the same day in another zone is
     * another time, and a write carrying the time a dry run answered before
     * the account's zone changed is refused rather than scheduling a cutoff
     * nobody previewed.
     */
    public function testTheCutoffIsMidnightInTheCallersZone(): void
    {
        self::q("UPDATE 202_users SET user_timezone = 'Asia/Kolkata' WHERE user_id = " . self::ADMIN);
        $kolkata = $this->admin()->scheduleDeletion(['before' => '2001-03-01'], true)['data'];
        self::assertSame('Asia/Kolkata', $kolkata['timezone']);
        self::assertSame(gmmktime(18, 30, 0, 2, 28, 2001), $kolkata['cutoff_time']);

        self::q("UPDATE 202_users SET user_timezone = 'UTC' WHERE user_id = " . self::ADMIN);
        $body = ['before' => '2001-03-01', 'cutoff_time' => $kolkata['cutoff_time']];
        self::refused(ConflictException::class, fn () => $this->admin()->scheduleDeletion($body, false));
        self::assertSame(['b' => null], self::row('SELECT user_delete_data_before AS b FROM 202_users_pref WHERE user_id = 1'));
    }

    public function testTheDayIsReadStrictly(): void
    {
        $tomorrow = gmdate('Y-m-d', time() + 86400 * 2);
        foreach (['2001-02-30', '2001-3-01', '01-03-2001', '', ' 2001-03-01', $tomorrow] as $bad) {
            self::refused(ValidationException::class, fn () => $this->admin()->scheduleDeletion(['before' => $bad], true), 'before');
        }
        self::refused(ValidationException::class, fn () => $this->admin()->scheduleDeletion(['before' => 20010301], true), 'before');
        self::refused(ValidationException::class, fn () => $this->admin()->scheduleDeletion([], true), 'before');
        self::refused(ValidationException::class, fn () => $this->admin()->scheduleDeletion(['before' => '2001-03-01', 'dry_run' => 1], true), 'dry_run');
    }

    public function testATimeZoneTheServerDoesNotKnowIsRefusedNotReadAsUtc(): void
    {
        self::q("UPDATE 202_users SET user_timezone = 'Mars/Olympus_Mons' WHERE user_id = " . self::ADMIN);
        self::refused(ConflictException::class, fn () => $this->admin()->scheduleDeletion(['before' => '2001-03-01'], true));
    }

    public function testAutomaticDeletionIsUserOnesSettingWithThePagesValues(): void
    {
        $set = $this->admin()->setRetention(['auto_delete_days' => 90])['data'];
        self::assertSame(0, $set['previous_auto_delete_days']);
        self::assertSame(90, $set['auto_delete_days']);
        self::assertSame('90', self::row('SELECT user_auto_database_optimization_days AS d FROM 202_users_pref WHERE user_id = 1')['d']);
        self::assertSame('0', self::row('SELECT user_auto_database_optimization_days AS d FROM 202_users_pref WHERE user_id = ' . self::ADMIN)['d']);

        self::assertSame(36500, $this->admin()->setRetention(['auto_delete_days' => '36500'])['data']['auto_delete_days']);
        self::assertSame(0, $this->admin()->setRetention(['auto_delete_days' => 0])['data']['auto_delete_days']);
        foreach ([36501, -1, '30 days', true, 1.5, '030', null, '', [30]] as $bad) {
            self::refused(ValidationException::class, fn () => $this->admin()->setRetention(['auto_delete_days' => $bad]), 'auto_delete_days');
        }
        self::refused(ValidationException::class, fn () => $this->admin()->setRetention([]), 'auto_delete_days');
        self::refused(ValidationException::class, fn () => $this->admin()->setRetention(['auto_delete_days' => 5, 'days' => 5]), 'days');
        self::assertSame('0', self::row('SELECT user_auto_database_optimization_days AS d FROM 202_users_pref WHERE user_id = 1')['d'], 'a refused value is not written');
    }

    public function testTheLoginLogServesWhoWhenWhereAndNeverThePassword(): void
    {
        $first = self::q("INSERT INTO 202_users_log SET user_name = 'admin-it-old', user_pass = 'plaintext-from-an-old-install', ip_address = '198.51.100.7', login_time = 1000000000, login_success = 0, login_error = 'a:0:{}', login_server = 'server-snapshot', login_session = 'session-snapshot'");
        $second = self::q("INSERT INTO 202_users_log SET user_name = 'admin-it-new', user_pass = '[filtered]', ip_address = '198.51.100.8', login_time = 1000000100, login_success = 1, login_error = '', login_server = '', login_session = ''");
        try {
            $log = $this->admin()->loginLog(['limit' => '2']);
            self::assertSame(2, $log['meta']['limit']);
            self::assertSame(
                [
                    ['login_id' => $second, 'user_name' => 'admin-it-new', 'ip_address' => '198.51.100.8', 'login_time' => 1000000100, 'login_success' => true],
                    ['login_id' => $first, 'user_name' => 'admin-it-old', 'ip_address' => '198.51.100.7', 'login_time' => 1000000000, 'login_success' => false],
                ],
                $log['data'],
                'newest first, and only who, when, where and whether it passed'
            );
            self::assertStringNotContainsString('plaintext-from-an-old-install', (string) json_encode($this->admin()->loginLog([])));
            self::assertSame(50, $this->admin()->loginLog([])['meta']['limit'], 'the page shows 50');
            foreach (['0', '501', 'abc', '05', ['1']] as $bad) {
                self::refused(ValidationException::class, fn () => $this->admin()->loginLog(['limit' => $bad]), 'limit');
            }
            self::refused(ValidationException::class, fn () => $this->admin()->loginLog(['since' => '1']), 'since');
        } finally {
            self::q("DELETE FROM 202_users_log WHERE login_id IN ($first, $second)");
        }
    }

    public function testIspLookupNeedsItsDatabaseAndIsTheCallersOwnSwitch(): void
    {
        $dir = sys_get_temp_dir() . '/p202-geo-' . bin2hex(random_bytes(4));
        mkdir($dir);
        putenv('P202_GEO_DIR=' . $dir);
        try {
            self::q('UPDATE 202_users_pref SET maxmind_isp = 0 WHERE user_id IN (1, ' . self::ADMIN . ')');
            self::assertSame(['enabled' => false, 'database_file' => null, 'database_dir' => $dir], array_intersect_key(
                $this->admin()->ispLookup()['data'],
                ['enabled' => 1, 'database_file' => 1, 'database_dir' => 1]
            ));
            self::refused(ValidationException::class, fn () => $this->admin()->setIspLookup(['enabled' => true]), 'enabled');
            self::assertSame('0', self::row('SELECT maxmind_isp AS m FROM 202_users_pref WHERE user_id = ' . self::ADMIN)['m']);
            foreach (['true', 1, null] as $bad) {
                self::refused(ValidationException::class, fn () => $this->admin()->setIspLookup(['enabled' => $bad]), 'enabled');
            }

            touch($dir . '/GeoIP2-ISP.mmdb');
            $on = $this->admin()->setIspLookup(['enabled' => true])['data'];
            self::assertTrue($on['enabled']);
            self::assertSame('GeoIP2-ISP.mmdb', $on['database_file']);
            self::assertSame('1', self::row('SELECT maxmind_isp AS m FROM 202_users_pref WHERE user_id = ' . self::ADMIN)['m']);
            self::assertSame('0', self::row('SELECT maxmind_isp AS m FROM 202_users_pref WHERE user_id = 1')['m'], "another user's switch is not touched");

            self::assertFalse($this->admin()->setIspLookup(['enabled' => false])['data']['enabled']);
        } finally {
            @unlink($dir . '/GeoIP2-ISP.mmdb');
            @rmdir($dir);
        }
    }

    public function testIntegrationsServeTheirUrlsOnTheTrackingBaseAndNeverTheSecrets(): void
    {
        self::q("UPDATE 202_users_pref SET user_tracking_domain = 'track.example.com' WHERE user_id = 1");
        self::q("UPDATE 202_users_pref SET cb_key = 'cb-secret-xyz', cb_verified = 1, jvzoo_ipn_secret_key = '', zaxaa_api_signature = NULL WHERE user_id = " . self::ADMIN);
        $answer = $this->admin()->integrations(['HTTPS' => 'on', 'SERVER_NAME' => 'ignored.example', 'SERVER_PORT' => 443, 'DOCUMENT_ROOT' => dirname(__DIR__, 3)])['data'];
        self::assertSame('https://track.example.com/', $answer['base_url']);
        $byKey = array_column($answer['integrations'], null, 'integration');
        self::assertSame(['clickbank', 'jvzoo', 'zaxaa', 'slack', 'paykickstart'], array_keys($byKey));
        self::assertSame('https://track.example.com/tracking202/static/cb202.php', $byKey['clickbank']['url']);
        self::assertSame('https://track.example.com/tracking202/static/jvzoo.php', $byKey['jvzoo']['url']);
        self::assertSame('https://track.example.com/tracking202/static/zpn.php', $byKey['zaxaa']['url']);
        self::assertSame('https://track.example.com/tracking202/static/slack.php', $byKey['slack']['url']);
        self::assertSame('https://track.example.com/tracking202/static/paykickstart.php', $byKey['paykickstart']['url']);
        self::assertTrue($byKey['clickbank']['secret_stored']);
        self::assertTrue($byKey['clickbank']['verified']);
        self::assertFalse($byKey['jvzoo']['secret_stored']);
        self::assertFalse($byKey['zaxaa']['secret_stored']);
        self::assertNull($byKey['paykickstart']['secret_stored'], 'PayKickstart needs no secret');
        self::assertStringNotContainsString('cb-secret-xyz', (string) json_encode($answer));
        self::q("UPDATE 202_users_pref SET cb_key = NULL, cb_verified = 0 WHERE user_id = " . self::ADMIN);
    }

    public function testInfoNamesTheCodeAndDatabaseVersions(): void
    {
        $info = $this->admin()->info()['data'];
        require_once dirname(__DIR__, 3) . '/202-config/version.php';
        self::assertSame(PROSPER202_VERSION, $info['version']);
        $schema = self::row('SELECT version FROM 202_version LIMIT 1');
        self::assertSame($schema === null ? null : (string) $schema['version'], $info['schema_version']);
        self::assertSame($info['schema_version'] !== PROSPER202_VERSION, $info['database_upgrade_needed']);
        self::assertSame(PHP_VERSION, $info['php_version']);
        foreach (['post_max_size', 'upload_max_filesize', 'max_input_time', 'max_execution_time'] as $limit) {
            self::assertSame((string) ini_get($limit), $info['php_limits'][$limit]);
        }
        self::assertIsInt($info['database_size_bytes']);
        self::assertSame(['installed', 'running'], array_keys($info['memcache']));
    }

    // ─── LTV ──────────────────────────────────────────────────────────

    private function ltv(int $user = self::LTV_USER): LtvController
    {
        return new LtvController(self::$db, $user);
    }

    private static function product(int $user, string $key, ?string $name = 'Original'): int
    {
        return self::q("INSERT INTO 202_products SET user_id = $user, external_product_id = '$key', sku = 'SKU-$key', name = " . ($name === null ? 'NULL' : "'$name'") . ', price = 5, created_at = 1, updated_at = 1');
    }

    public function testAProductIsEditedByIdWithThePagesRules(): void
    {
        $id = self::product(self::LTV_USER, 'it-p1');
        $theirs = self::product(self::OTHER, 'it-p1');

        $edited = $this->ltv()->updateProduct($id, ['name' => '  Renamed  ', 'price' => '12.5'])['data'];
        self::assertSame('Renamed', $edited['name']);
        self::assertSame('SKU-it-p1', $edited['sku'], 'a field not sent keeps its value');
        self::assertSame('12.50000', (string) $edited['price']);
        self::assertSame('it-p1', $edited['external_product_id']);

        $cleared = $this->ltv()->updateProduct($id, ['sku' => '', 'price' => null])['data'];
        self::assertNull($cleared['sku']);
        self::assertNull($cleared['price']);

        foreach ([['name' => ''], ['name' => '   '], ['name' => null], ['name' => str_repeat('x', 256)], ['sku' => str_repeat('s', 192)],
            ['price' => -1], ['price' => 'abc'], ['price' => true], ['price' => 1e10], ['external_product_id' => 'x'], []] as $bad) {
            $field = array_key_first($bad) ?? 'name';
            self::refused(ValidationException::class, fn () => $this->ltv()->updateProduct($id, $bad), (string) $field);
        }
        self::refused(NotFoundException::class, fn () => $this->ltv()->updateProduct($theirs, ['name' => 'Mine now']));
        self::assertSame('Original', self::row("SELECT name FROM 202_products WHERE product_id = $theirs")['name'], "another account's product is untouched");
    }

    public function testAProductIsDeletedOnlyWhileNoOrderLineItemNamesIt(): void
    {
        $sold = self::product(self::LTV_USER, 'it-sold');
        $unsold = self::product(self::LTV_USER, 'it-unsold');
        $theirs = self::product(self::OTHER, 'it-unsold');
        self::q("INSERT INTO 202_revenue_line_items SET user_id = " . self::LTV_USER . ", event_id = 1, product_id = $sold, product_name = 'x', amount = 1, created_at = 1");

        $preview = $this->ltv()->deleteProductPreview($sold)['data'];
        self::assertSame('This product appears on 1 order line item(s) and cannot be deleted.', $preview['refused']);
        try {
            $this->ltv()->deleteProduct($sold);
            self::fail('a sold product was deleted');
        } catch (ConflictException $e) {
            self::assertSame($preview['refused'], $e->getMessage(), 'the preview names the refusal the delete answers');
            self::assertSame(['line_items' => 1], $e->getDetails());
        }
        self::assertNotNull(self::row("SELECT 1 FROM 202_products WHERE product_id = $sold"));

        self::assertNull($this->ltv()->deleteProductPreview($unsold)['data']['refused']);
        $this->ltv()->deleteProduct($unsold);
        self::assertNull(self::row("SELECT 1 FROM 202_products WHERE product_id = $unsold"));
        self::refused(NotFoundException::class, fn () => $this->ltv()->deleteProduct($unsold));
        self::refused(NotFoundException::class, fn () => $this->ltv()->deleteProduct($theirs));
        self::refused(NotFoundException::class, fn () => $this->ltv()->deleteProductPreview($theirs));
        self::assertNotNull(self::row("SELECT 1 FROM 202_products WHERE product_id = $theirs"));
    }

    public function testTheDeliveryLogServesStatusAttemptsAndLastErrorNeverTheSecretOrPayload(): void
    {
        $hook = self::q("INSERT INTO 202_ltv_webhooks SET user_id = " . self::LTV_USER . ", webhook_url = 'https://hooks.example/p202', webhook_secret = 'whsec-never-shown', subscribed_events = '', status = 'active', created_at = 1, updated_at = 1");
        $theirs = self::q("INSERT INTO 202_ltv_webhooks SET user_id = " . self::OTHER . ", webhook_url = 'https://hooks.example/other', webhook_secret = 'x', subscribed_events = '', status = 'active', created_at = 1, updated_at = 1");
        $failed = self::q("INSERT INTO 202_ltv_webhook_deliveries SET webhook_id = $hook, user_id = " . self::LTV_USER . ", event_name = 'revenue.recorded', payload = '{\"email\":\"payload-never-shown\"}', status = 'failed', attempts = 6, next_attempt_at = 99, last_status_code = 500, last_response_body = 'upstream exploded', created_at = 10, updated_at = 20");
        $pending = self::q("INSERT INTO 202_ltv_webhook_deliveries SET webhook_id = $hook, user_id = " . self::LTV_USER . ", event_name = 'customer.updated', payload = '{}', status = 'pending', attempts = 1, next_attempt_at = 500, last_status_code = NULL, last_response_body = 'curl: Could not resolve host', created_at = 30, updated_at = 40");

        $log = $this->ltv()->webhookDeliveries($hook, []);
        self::assertSame([$pending, $failed], array_column($log['data'], 'delivery_id'), 'newest first');
        self::assertSame(
            ['delivery_id' => $failed, 'event_name' => 'revenue.recorded', 'status' => 'failed', 'attempts' => 6, 'next_attempt_at' => null,
                'last_status_code' => 500, 'last_response_body' => 'upstream exploded', 'created_at' => 10, 'updated_at' => 20],
            $log['data'][1]
        );
        self::assertSame(500, $log['data'][0]['next_attempt_at'], 'a pending delivery says when it is retried');
        self::assertSame(25, $log['meta']['limit']);
        $encoded = (string) json_encode($log);
        self::assertStringNotContainsString('whsec-never-shown', $encoded);
        self::assertStringNotContainsString('payload-never-shown', $encoded);

        self::assertSame([$failed], array_column($this->ltv()->webhookDeliveries($hook, ['status' => 'failed'])['data'], 'delivery_id'));
        self::assertCount(1, $this->ltv()->webhookDeliveries($hook, ['limit' => '1'])['data']);
        foreach ([['status' => 'lost'], ['limit' => '0'], ['limit' => '101'], ['limit' => 'x'], ['page' => '2']] as $bad) {
            self::refused(ValidationException::class, fn () => $this->ltv()->webhookDeliveries($hook, $bad), (string) array_key_first($bad));
        }
        self::refused(NotFoundException::class, fn () => $this->ltv()->webhookDeliveries($theirs, []));
    }
}

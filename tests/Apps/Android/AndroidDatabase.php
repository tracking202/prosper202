<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallEventsIntake;
use Api\V3\Apps\Android\InstallIntake;
use Api\V3\Apps\Android\InstallToken;
use Api\V3\Apps\Android\InstallTokenKey;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\MysqlGoalRepository;

/**
 * A scratch MySQL/MariaDB for the Android intake's integration tests,
 * installed from the installer's own definitions (the install-token key
 * minted as the installer mints it) and run under strict mode like
 * production. Skips unless P202_TEST_DB_HOST (and friends) name a scratch
 * database.
 *
 * Fixtures: user 1 owns registration 5 (com.example.summit, its token
 * self::TOKEN) and campaign 30 (linked to it, accumulate, default payout
 * 2.50, traffic-source account 70 with one server postback pixel and one
 * image pixel the server must never send); user 2
 * owns registration 6 (com.other.app).
 */
trait AndroidDatabase
{
    private static ?\mysqli $db = null;
    private Connection $conn;
    private MysqlGoalRepository $goals;
    private int $clock = 1_727_200_200;
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CLICK_TIME = 1_727_200_000;
    /** @var list<string> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
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
        foreach ($db->query("SHOW TRIGGERS")->fetch_all(MYSQLI_ASSOC) as $trigger) {
            $db->query('DROP TRIGGER IF EXISTS `' . $trigger['Trigger'] . '`');
        }
        // Another suite may have left a table of its own shape behind (the
        // upgrade tests build pre-ledger ones); these tests read the
        // installer's shape, so the tables they touch are rebuilt from it.
        $rebuilt = ['202_aff_campaigns', '202_clicks', '202_clicks_spy', '202_ppc_account_pixels'];
        foreach ([
            ...\Prosper202\Database\Tables\AppTables::getDefinitions(),
            ...\Prosper202\Database\Tables\ConversionTables::getDefinitions(),
            ...\Prosper202\Database\Tables\GoalTables::getDefinitions(),
            ...\Prosper202\Database\Tables\SecretTables::getDefinitions(),
        ] as $definition) {
            $rebuilt[] = $definition->tableName;
        }
        foreach ($rebuilt as $table) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        (new SchemaInstaller($db))->install();
        InstallTokenKey::ensure($db);
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (self::$db->query('SHOW TRIGGERS')->fetch_all(MYSQLI_ASSOC) as $trigger) {
            self::$db->query('DROP TRIGGER IF EXISTS `' . $trigger['Trigger'] . '`');
        }
        foreach ([
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending', '202_dataengine',
            '202_goals', '202_goal_versions', '202_campaign_goals', '202_goal_subjects', '202_goal_events', '202_goal_progress',
            '202_goal_outcomes', '202_app_registrations', '202_app_installs', '202_notification_pending', '202_ppc_account_pixels',
            '202_app_integrity_credentials', '202_notification_correction_urls',
        ] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->conn = new Connection(self::$db);
        $this->goals = new MysqlGoalRepository($this->conn);
        $this->sent = [];
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=5, user_id=1, platform='android', app_key='com.example.summit',
            app_name='Summit', accept_test_signals=0, attribution_window_days=7, trust_client_revenue=0, app_token='" . self::TOKEN . "', created_at=1, updated_at=1");
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=6, user_id=2, platform='android', app_key='com.other.app',
            app_name='Other', accept_test_signals=0, attribution_window_days=7, trust_client_revenue=0, app_token='" . self::OTHER_TOKEN . "', created_at=1, updated_at=1");
        $this->campaign(30, 5);
        self::fixture("INSERT INTO 202_ppc_account_pixels SET pixel_id=90, ppc_account_id=70, pixel_type_id=4,
            pixel_code='https://ts.example/pb?sub=[[subid]]&goal=[[p202_goal]]&v=[[p202_goal_value]]&tx=[[transactionid]]&p=[[payout]]'");
        // A browser (image) pixel on the same account: the page fires it,
        // so the server outbox must never queue it (only type 4 is server-side).
        self::fixture("INSERT INTO 202_ppc_account_pixels SET pixel_id=91, ppc_account_id=70, pixel_type_id=1,
            pixel_code='https://ts.example/img?sub=[[subid]]'");
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function campaign(int $id, ?int $registration, string $mode = 'accumulate', string $payout = '2.50', int $user = 1): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=$user, aff_network_id=1, aff_campaign_name='c$id',
            aff_campaign_url='http://x', aff_campaign_payout=$payout, aff_campaign_time=1, aff_campaign_foreign_payout=$payout, payout_mode='$mode',
            app_registration_id=" . ($registration === null ? 'NULL' : $registration));
    }

    private function click(int $id, int $campaign = 30, int $user = 1, int $time = self::CLICK_TIME): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=$user, aff_campaign_id=$campaign, ppc_account_id=70, click_payout=2.5, click_cpc=0, click_lead=0, click_time=$time");
        }
    }

    private static function key(): string
    {
        $key = InstallTokenKey::load(self::$db);
        self::assertIsString($key);

        return $key;
    }

    private static function tokenFor(int $clickId): string
    {
        return InstallToken::forClick($clickId, self::key());
    }

    /**
     * An SDK-shaped install body.
     *
     * @param array<string, mixed> $override top-level fields
     * @param array<string, mixed> $referrer referrer fields
     * @return array<string, mixed>
     */
    private static function body(string $uuid, string $installReferrer, array $override = [], array $referrer = []): array
    {
        return $override + [
            'install_uuid' => $uuid,
            'app_key' => 'com.example.summit',
            'store' => 'google_play',
            'referrer' => $referrer + [
                'status' => 'ok',
                'install_referrer' => $installReferrer,
                'referrer_click_timestamp_seconds' => self::CLICK_TIME + 5,
                'install_begin_timestamp_seconds' => self::CLICK_TIME + 60,
                'referrer_click_timestamp_server_seconds' => self::CLICK_TIME + 6,
                'install_begin_timestamp_server_seconds' => self::CLICK_TIME + 61,
                'install_version' => '3.2.0',
                'google_play_instant' => false,
            ],
            'first_open_at' => self::CLICK_TIME + 100,
            'app_version' => '3.2.0',
            'sdk_version' => '1.0.0',
            'os_version' => '15',
            'test' => false,
            'integrity_token' => null,
        ];
    }

    /**
     * POST /apps/installs, as the route hands it over.
     *
     * @param array<string, mixed>|string $body
     * @return array{status: int, body: array<string, mixed>}
     */
    private function install(array|string $body, string $token = self::TOKEN): array
    {
        $this->clock += 5;

        return (new InstallIntake(self::$db, fn (): int => $this->clock))
            ->receive($token, is_string($body) ? $body : (string) json_encode($body), '203.0.113.9');
    }

    /**
     * POST /apps/installs/{uuid}/events.
     *
     * @param list<array<string, mixed>> $events
     * @return array{status: int, body: array<string, mixed>}
     */
    private function events(string $uuid, array $events, string $token = self::TOKEN): array
    {
        $this->clock += 5;

        return (new InstallEventsIntake(self::$db, fn (): int => $this->clock))
            ->receive($token, $uuid, (string) json_encode(['events' => $events]));
    }

    /** @return array<string, mixed>|null */
    private static function installRow(string $uuid): ?array
    {
        return self::$db->query("SELECT * FROM 202_app_installs WHERE install_uuid = '" . self::$db->real_escape_string($uuid) . "'")->fetch_assoc();
    }

    /** @return list<array<string, mixed>> */
    private static function ledger(int $clickId): array
    {
        return self::$db->query("SELECT conv_id, click_payout, payable, deleted, source, source_ref, event_name, dedupe_key, pixel_type,
                conv_time, superseded_reason, transaction_id FROM 202_conversion_logs WHERE click_id=$clickId ORDER BY conv_id")->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array{lead: int, payout: string} */
    private static function clickValue(int $id): array
    {
        $c = self::$db->query("SELECT click_lead, click_payout FROM 202_clicks WHERE click_id=$id")->fetch_assoc();

        return ['lead' => (int) $c['click_lead'], 'payout' => (string) $c['click_payout']];
    }

    /** @return list<array<string, mixed>> */
    private static function outbox(): array
    {
        return self::$db->query('SELECT conv_id, pixel_id, kind, status, url, attempts, last_error FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC);
    }

    private static function rows(string $table, string $where = '1=1'): int
    {
        return (int) self::$db->query("SELECT COUNT(*) AS n FROM $table WHERE $where")->fetch_assoc()['n'];
    }

    /** @param array<string, mixed> $definition */
    private function campaignGoal(int $campaignId, array $definition, ?string $payout = null, bool $notify = true): int
    {
        $id = $this->goals->create(1, GoalScope::CAMPAIGN, $campaignId, GoalDefinition::parse($definition), 1);
        $this->goals->attach(1, $campaignId, $id, $payout === null ? null : \Prosper202\Conversion\Ledger\Amount::toUnits($payout), $notify, 1);

        return $id;
    }
}

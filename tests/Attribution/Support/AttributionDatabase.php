<?php

declare(strict_types=1);

namespace Tests\Attribution\Support;

use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\WorkerReport;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;

/**
 * A scratch database with the real schema, and the fixtures the attribution
 * integration tests share: an account, campaigns, clicks linked into
 * visitors through the real identity graph, conversions through the real
 * ledger writer, and the real worker.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 */
trait AttributionDatabase
{
    private static ?\mysqli $db = null;
    private Connection $conn;
    private MysqlConversionRepository $ledger;
    private IdentityGraph $graph;

    private const RESET = [
        '202_users', '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_aff_campaigns',
        '202_ppc_accounts', '202_attribution_pending', '202_attribution_models', '202_attribution_credits',
        '202_attribution_journeys', '202_attribution_journey_meta', '202_attribution_audit',
        '202_identity_keys', '202_identity_visitors', '202_identity_signals', '202_identity_observations',
        '202_identity_merges', '202_clicks_visitor', '202_browsers', '202_attribution_exports',
    ];

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
        (new SchemaInstaller($db))->install();
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
        foreach (self::RESET as $t) {
            // 202_users is referenced by a foreign key, which TRUNCATE refuses.
            $sql = $t === '202_users' ? 'DELETE FROM 202_users' : 'TRUNCATE TABLE ' . $t;
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('reset failed: ' . $sql . ': ' . self::$db->error);
            }
        }
        $this->conn = new Connection(self::$db);
        $this->ledger = new MysqlConversionRepository($this->conn);
        $this->graph = new IdentityGraph($this->conn);
        self::fixture("INSERT INTO 202_users SET user_id=1, user_name='owner', user_email='o@example.test', user_time_register=1, user_deleted=0, user_active=1");
        \Prosper202\Attribution\DefaultModel::ensureFor($this->conn, 1);
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

    private static function scalar(string $sql): mixed
    {
        $row = self::$db->query($sql)->fetch_row();

        return $row[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private static function all(string $sql): array
    {
        return self::$db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    private function campaign(int $id, string $mode = 'replace', ?int $modelId = null): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1, aff_network_id=1, aff_campaign_name='Campaign $id',
            aff_campaign_url='http://x', aff_campaign_payout=1, aff_campaign_time=1, aff_campaign_foreign_payout=1, payout_mode='$mode',
            attribution_model_id=" . ($modelId ?? 'NULL'));
    }

    private function click(int $id, int $campaign, int $time, string $cpc = '0.10', int $ppcAccount = 0, int $filtered = 0, int $bot = 0): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=1, aff_campaign_id=$campaign, ppc_account_id=$ppcAccount,
                click_payout=0, click_cpc=$cpc, click_lead=0, click_filtered=$filtered, click_bot=$bot, click_time=$time");
        }
    }

    private function visit(int $clickId, int $time, IdentitySignal ...$signals): ?int
    {
        return $this->conn->transaction(fn (): ?int => $this->graph->attachClick(1, $clickId, $time, $signals));
    }

    private static function cookie(string $v): IdentitySignal
    {
        return new IdentitySignal(SignalType::VISITOR_COOKIE, str_pad($v, 32, '0', STR_PAD_LEFT));
    }

    private static function customer(string $v): IdentitySignal
    {
        return new IdentitySignal(SignalType::CUSTOMER, $v);
    }

    /** @return int conv_id */
    private function convert(int $clickId, string $payout, ?string $tx = null, ?int $convTime = null): int
    {
        $data = ['click_id' => $clickId, 'source' => 'postback', 'payout' => $payout];
        if ($tx !== null) {
            $data['transaction_id'] = $tx;
        }
        if ($convTime !== null) {
            $data['conv_time'] = $convTime;
        }
        $result = $this->ledger->record(1, $data);
        self::assertGreaterThan(0, $result['convId'], 'the conversion was recorded');

        return $result['convId'];
    }

    private function work(): WorkerReport
    {
        return (new AttributionWorker($this->conn))->run(30, 200);
    }

    private function defaultModelId(): int
    {
        return (int) self::scalar('SELECT model_id FROM 202_attribution_models WHERE user_id=1 AND is_default=1');
    }

    /** @param array<string, float|int> $config */
    private function addModel(string $name, ModelType $type, array $config = [], int $lookbackDays = 30): int
    {
        return (new ModelRepository($this->conn))->insert(1, $name, ModelRepository::slugFor($name), $type, ModelConfig::normalize($type, $config), $lookbackDays, 'active', false);
    }

    /** @return array<int, string> click_id => credit */
    private static function credits(int $convId, int $modelId): array
    {
        $out = [];
        foreach (self::all("SELECT click_id, credit FROM 202_attribution_credits WHERE conv_id=$convId AND model_id=$modelId ORDER BY position") as $r) {
            $out[(int) $r['click_id']] = (string) $r['credit'];
        }

        return $out;
    }

    /** @return list<int> */
    private static function journeyClicks(int $convId): array
    {
        return array_map('intval', array_column(self::all("SELECT click_id FROM 202_attribution_journeys WHERE conv_id=$convId ORDER BY position"), 'click_id'));
    }

    private static function revenueUnder(int $modelId): string
    {
        return (string) self::scalar("SELECT COALESCE(SUM(revenue), 0) FROM 202_attribution_credits WHERE model_id=$modelId");
    }
}

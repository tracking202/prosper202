<?php

declare(strict_types=1);

namespace Tests\Goals;

use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\MysqlGoalRepository;

/**
 * A scratch MySQL/MariaDB for the goal integration tests, installed from the
 * installer's own definitions and run under strict mode like production.
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 */
trait GoalDatabase
{
    private static ?\mysqli $db = null;
    private Connection $conn;
    private MysqlGoalRepository $goals;
    private GoalEngine $engine;
    private int $clock = 1_700_000_000;

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
        foreach ([
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending', '202_dataengine',
            '202_goals', '202_goal_versions', '202_campaign_goals', '202_goal_subjects', '202_goal_events', '202_goal_progress',
            '202_goal_outcomes', '202_app_registrations', '202_app_skan_encodings', '202_app_skan_encoding_history', '202_app_postbacks',
        ] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->conn = new Connection(self::$db);
        $this->goals = new MysqlGoalRepository($this->conn);
        $this->engine = new GoalEngine($this->conn, $this->goals, null, fn (): int => $this->clock);
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

    private function campaign(int $id, string $mode = 'accumulate', string $payout = '0.00'): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1, aff_network_id=1, aff_campaign_name='c$id',
            aff_campaign_url='http://x', aff_campaign_payout=$payout, aff_campaign_time=1, aff_campaign_foreign_payout=$payout, payout_mode='$mode'");
    }

    private function setMode(int $campaignId, string $mode): void
    {
        self::fixture("UPDATE 202_aff_campaigns SET payout_mode='$mode' WHERE aff_campaign_id=$campaignId");
    }

    private function click(int $id, int $campaign, int $time = 1_600_000_000): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=1, aff_campaign_id=$campaign, click_payout=0, click_cpc=0, click_lead=0, click_time=$time");
        }
    }

    /**
     * A campaign goal, created now (at the test clock) and, when $payout is
     * not false, attached as payable.
     *
     * @param array<string, mixed> $definition
     */
    private function goal(int $campaignId, array $definition, string|false|null $payout = null): int
    {
        $id = $this->goals->create(1, GoalScope::CAMPAIGN, $campaignId, GoalDefinition::parse($definition), $this->clock);
        if ($payout !== false) {
            $this->goals->attach(1, $campaignId, $id, $payout === null ? null : \Prosper202\Conversion\Ledger\Amount::toUnits($payout), true, $this->clock);
        }

        return $id;
    }

    /** @param array<string, mixed> $props */
    private function event(string $id, string $name, int $at, array $props = [], int|float|null $revenue = null, bool $trusted = false, ?string $tx = null): GoalEvent
    {
        return new GoalEvent($id, $name, $at, $this->clock, $props, $revenue, $trusted, $tx);
    }

    /**
     * Ingest events for a click, one request, at the test clock (which each
     * call advances, so arrival order is the call order).
     *
     * @param list<GoalEvent> $events
     * @return array<string, mixed>
     */
    private function ingest(int $clickId, array $events): array
    {
        $this->clock += 10;
        $stamped = array_map(fn (GoalEvent $e): GoalEvent => new GoalEvent(
            $e->eventId, $e->name, $e->occurredAt, $this->clock, $e->properties, $e->revenue, $e->revenueTrusted, $e->transactionId
        ), $events);

        return $this->engine->ingest(1, $this->engine->clickSubject(1, $clickId), $stamped);
    }

    /** @return array{lead: int, payout: string} */
    private function clickState(int $id): array
    {
        $c = self::$db->query("SELECT click_lead, click_payout FROM 202_clicks WHERE click_id=$id")->fetch_assoc();

        return ['lead' => (int) $c['click_lead'], 'payout' => (string) $c['click_payout']];
    }

    /** @return list<array<string, mixed>> */
    private function ledger(int $clickId): array
    {
        return self::$db->query("SELECT conv_id, click_payout, payable, deleted, source, source_ref, event_name, dedupe_key,
                superseded_by, superseded_reason, transaction_id
            FROM 202_conversion_logs WHERE click_id=$clickId ORDER BY conv_id")->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<array<string, mixed>> the counted rows: payable, live, not superseded */
    private function counted(int $clickId): array
    {
        return array_values(array_filter($this->ledger($clickId), static fn (array $r): bool => (int) $r['payable'] === 1
            && (int) $r['deleted'] === 0 && $r['superseded_reason'] === null));
    }

    /**
     * The live outcomes of a click as goal:version:n@event=value.
     *
     * @return list<string>
     */
    private function liveOutcomes(int $clickId): array
    {
        $rows = $this->goals->liveOutcomes(1, ['subject_type' => 'click', 'subject_id' => $clickId]);
        $out = array_map(static fn (array $r): string => $r['goal_id'] . ':' . $r['goal_version'] . ':' . $r['n'] . '@' . $r['event_id']
            . '=' . ($r['value'] ?? 'null') . ($r['payable'] ? '' : ' unpaid'), $rows);
        sort($out);

        return $out;
    }
}

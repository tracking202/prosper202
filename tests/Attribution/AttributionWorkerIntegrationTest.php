<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\DefaultModel;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\WorkerHalted;
use Prosper202\Database\Tables\AttributionTables;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * The attribution worker against a real MySQL/MariaDB: the outbox the
 * ledger writes, journeys from the identity graph, credits under every
 * active model, counted state, failure isolation and idempotency (plan
 * §6.3, §7.4, §7.6).
 *
 * @group integration
 */
final class AttributionWorkerIntegrationTest extends TestCase
{
    use AttributionDatabase;

    public function testAJourneyIsOnePersonsClicksAcrossCampaignsAndNothingElse(): void
    {
        $now = time();
        $this->campaign(1);
        $this->campaign(2);
        // One browser: three clicks across two campaigns.
        $this->click(10, 1, $now - 3 * 3600);
        $this->click(11, 2, $now - 2 * 3600);
        $this->click(12, 1, $now - 3600);
        foreach ([10, 11, 12] as $c) {
            $this->visit($c, (int) self::scalar("SELECT click_time FROM 202_clicks WHERE click_id=$c"), self::cookie('alice'));
        }
        // A stranger on the same campaign; a bot click and a later click from
        // the same browser, which are left out; and a click Prosper202
        // filtered (a repeat IP within a day), which is a real touch and kept.
        $this->click(20, 1, $now - 1800);
        $this->visit(20, $now - 1800, self::cookie('bob'));
        $this->click(13, 1, $now - 5000, '0.10', 0, 0, 1);
        $this->visit(13, $now - 5000, self::cookie('alice'));
        $this->click(14, 2, $now - 5500, '0.10', 0, 1, 0);
        $this->visit(14, $now - 5500, self::cookie('alice'));
        $this->click(15, 2, $now - 60);
        $this->visit(15, $now - 60, self::cookie('alice'));

        $conv = $this->convert(12, '9.00', 'A-1');
        self::assertSame('recorded', self::scalar("SELECT reason FROM 202_attribution_pending WHERE conv_id=$conv"));

        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $first = $this->addModel('First', ModelType::FIRST_TOUCH);
        $report = $this->work();

        self::assertSame(1, $report->outcomes['credited'] ?? 0, $report->summary());
        self::assertSame([10, 11, 14, 12], self::journeyClicks($conv), 'the same browser, both campaigns, up to the converting click');
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'));
        self::assertSame(['touches' => '4', 'built_lookback_days' => '30', 'truncated' => '0', 'identified' => '1'],
            self::all("SELECT touches, built_lookback_days, truncated, identified FROM 202_attribution_journey_meta WHERE conv_id=$conv")[0]);

        self::assertSame([12 => '1.00000000'], self::credits($conv, $this->defaultModelId()));
        self::assertSame([10 => '1.00000000'], self::credits($conv, $first));
        self::assertSame([10 => '0.25000000', 11 => '0.25000000', 14 => '0.25000000', 12 => '0.25000000'], self::credits($conv, $linear));
        foreach ([$this->defaultModelId(), $linear, $first] as $m) {
            self::assertSame('9.00000', self::revenueUnder($m), "model $m: revenue sums to the conversion");
        }
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_credits WHERE click_id IN (13,15,20)'), 'no stranger, bot or later click earns credit');
    }

    public function testAClickWithoutAVisitorIsAnUnidentifiedOneTouchJourney(): void
    {
        $this->campaign(1);
        $this->click(30, 1, time() - 100);
        $conv = $this->convert(30, '4');
        $this->work();

        self::assertSame([30], self::journeyClicks($conv));
        self::assertSame('0', (string) self::scalar("SELECT identified FROM 202_attribution_journey_meta WHERE conv_id=$conv"));
        self::assertSame([30 => '1.00000000'], self::credits($conv, $this->defaultModelId()));
    }

    public function testCreditsFollowWhatTheLedgerCounts(): void
    {
        // plan §7.6 "Counted-state test": replace campaign, $5 then $10.
        $this->campaign(1, 'replace');
        $this->click(40, 1, time() - 100);
        $five = $this->convert(40, '5', 'T5');
        $ten = $this->convert(40, '10', 'T10');
        $this->work();

        self::assertSame('10.00000', self::revenueUnder($this->defaultModelId()), 'MTA shows the $10 the click shows, not $15');
        self::assertSame([], self::credits($five, $this->defaultModelId()), 'the superseded row has no credits');

        $this->ledger->softDelete($ten, 1);
        self::assertSame('counted_state', self::scalar("SELECT reason FROM 202_attribution_pending WHERE conv_id=$five"));
        $this->work();
        self::assertSame('5.00000', self::revenueUnder($this->defaultModelId()), 'deleting the $10 brings the $5 back');
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_journey_meta WHERE conv_id=$ten"));
    }

    public function testReversalsNetAgainstTheSaleTheyName(): void
    {
        $this->campaign(1, 'accumulate');
        $this->click(50, 1, time() - 100);
        $sale = $this->convert(50, '10', 'S-1');
        $this->work();
        self::assertSame('10.00000', self::revenueUnder($this->defaultModelId()));

        $this->ledger->record(1, ['click_id' => 50, 'source' => 'postback', 'transaction_id' => 'S-1', 'payout' => '-4']);
        $this->work();
        self::assertSame('6.00000', self::revenueUnder($this->defaultModelId()), 'a partial reversal leaves the rest');

        $this->click(51, 1, time() - 90);
        $other = $this->convert(51, '3', 'S-2');
        $this->ledger->record(1, ['click_id' => 51, 'source' => 'postback', 'transaction_id' => 'S-2', 'reversal' => true]);
        $this->work();
        self::assertSame([], self::credits($other, $this->defaultModelId()), 'a fully reversed sale earns nothing');
        self::assertSame('6.00000', self::revenueUnder($this->defaultModelId()));
        self::assertSame(1, (int) self::scalar('SELECT COUNT(DISTINCT conv_id) FROM 202_attribution_credits'), 'reversal rows never get credits of their own');
    }

    public function testProcessingTwiceWritesTheSameRows(): void
    {
        $this->campaign(1);
        $this->click(60, 1, time() - 500);
        $this->click(61, 1, time() - 100);
        $this->visit(60, time() - 500, self::cookie('c'));
        $this->visit(61, time() - 100, self::cookie('c'));
        $conv = $this->convert(61, '2.50');
        $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        $snapshot = self::all('SELECT conv_id, model_id, click_id, position, credit, revenue FROM 202_attribution_credits ORDER BY model_id, position');

        $this->conn->transaction(fn () => (new AttributionWorker($this->conn))->processConversion($conv, AttributionWorker::REASON_RECORDED));
        self::assertSame($snapshot, self::all('SELECT conv_id, model_id, click_id, position, credit, revenue FROM 202_attribution_credits ORDER BY model_id, position'));
        self::assertSame(2, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_journeys WHERE conv_id=$conv"));
    }

    public function testARowRequeuedWhileItWasProcessedStaysQueued(): void
    {
        $this->campaign(1);
        $this->click(70, 1, time() - 100);
        $conv = $this->convert(70, '1');
        // The re-queue lands between the credit write and the pending delete,
        // inside the worker's own transaction — the window the sequence
        // number exists for.
        self::$db->query('CREATE TRIGGER p202_test_requeue AFTER INSERT ON 202_attribution_credits FOR EACH ROW
            UPDATE 202_attribution_pending SET enqueue_seq = enqueue_seq + 1 WHERE conv_id = NEW.conv_id');
        try {
            $report = (new AttributionWorker($this->conn))->run(5, 1);
        } finally {
            self::$db->query('DROP TRIGGER IF EXISTS p202_test_requeue');
        }
        self::assertSame(1, $report->outcomes['requeued'] ?? 0, $report->summary());
        self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id=$conv"), 'still queued for the change that arrived');
        $this->work();
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending'));
    }

    public function testOneBrokenConversionDelaysOnlyItself(): void
    {
        $this->campaign(1);
        $this->click(80, 1, time() - 100);
        $this->click(81, 1, time() - 100);
        $bad = $this->convert(80, '1');
        $good = $this->convert(81, '2');
        // A click naming a visitor key the graph has no row for.
        self::$db->query('INSERT INTO 202_clicks_visitor (click_id, user_id, visitor_key, click_time) VALUES (80, 1, 999999, ' . (time() - 100) . ')');

        $report = $this->work();
        self::assertSame(1, $report->outcomes['failed'] ?? 0, $report->summary());
        self::assertSame(1, $report->outcomes['credited'] ?? 0, $report->summary());
        self::assertSame([81 => '1.00000000'], self::credits($good, $this->defaultModelId()));
        $row = self::all("SELECT attempts, last_error, retry_at FROM 202_attribution_pending WHERE conv_id=$bad")[0];
        self::assertSame('1', (string) $row['attempts']);
        self::assertStringContainsString('visitor key 999999', (string) $row['last_error']);
        self::assertGreaterThan(time(), (int) $row['retry_at'], 'backed off, not retried in a hot loop');
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_attribution_pending WHERE retry_at <= UNIX_TIMESTAMP()'));

        // A re-queue (the ledger changing the row) makes it due again.
        self::$db->query('DELETE FROM 202_clicks_visitor WHERE click_id = 80');
        (new AttributionWorker($this->conn))->enqueue([$bad], AttributionWorker::REASON_COUNTED_STATE, true);
        self::assertSame('0', (string) self::scalar("SELECT attempts FROM 202_attribution_pending WHERE conv_id=$bad"));
        $this->work();
        self::assertSame([80 => '1.00000000'], self::credits($bad, $this->defaultModelId()));
    }

    public function testABrokenEngineLosesNothingAndTheConversionPathNeverNotices(): void
    {
        // plan §7.6 "Isolation test", at the worker: drop an engine table.
        $this->campaign(1);
        $this->click(90, 1, time() - 100);
        self::$db->query('DROP TABLE 202_attribution_credits');
        try {
            $conv = $this->convert(90, '7');
            self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id=$conv"), 'recorded with its outbox row');

            try {
                $this->work();
                self::fail('the worker ran against a missing table');
            } catch (WorkerHalted $e) {
                self::assertStringContainsString('202_attribution_credits', $e->getMessage());
            }
            self::assertSame(['0', '0'], array_values(self::all("SELECT attempts, retry_at FROM 202_attribution_pending WHERE conv_id=$conv")[0]),
                'an engine failure is not charged to the row');
        } finally {
            self::$db->query(AttributionTables::attributionCredits()->createStatement);
        }
        $this->work();
        self::assertSame([90 => '1.00000000'], self::credits($conv, $this->defaultModelId()));
    }

    public function testAModelChangeRecomputesFromTheStoredJourney(): void
    {
        $this->campaign(1);
        $t = time();
        foreach ([100 => 400, 101 => 300, 102 => 200] as $click => $age) {
            $this->click($click, 1, $t - $age);
            $this->visit($click, $t - $age, self::cookie('d'));
        }
        $conv = $this->convert(102, '3');
        $this->work();
        $builtAt = (int) self::scalar("SELECT built_at FROM 202_attribution_journey_meta WHERE conv_id=$conv");

        // A model created after the conversion: the fan-out queues it.
        self::$db->query("UPDATE 202_attribution_journey_meta SET built_at = built_at - 1000 WHERE conv_id=$conv");
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $report = $this->work();
        self::assertSame(1, $report->modelsFannedOut);
        self::assertSame([100 => '0.33333333', 101 => '0.33333333', 102 => '0.33333334'], self::credits($conv, $linear));
        self::assertSame($builtAt - 1000, (int) self::scalar("SELECT built_at FROM 202_attribution_journey_meta WHERE conv_id=$conv"),
            'a model within the built lookback reads the stored journey');
        self::assertNull(self::scalar("SELECT recompute_requested_at FROM 202_attribution_models WHERE model_id=$linear"));

        // Deactivating removes its credits in the same write.
        (new \Prosper202\Attribution\ModelRepository($this->conn))->update(1, $linear, 'Linear', 'linear', ModelType::LINEAR, [], 30, 'inactive', false, false);
        self::assertSame([], self::credits($conv, $linear));
    }

    public function testEveryAccountHasExactlyOneDefault(): void
    {
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='two', user_email='t@example.test', user_time_register=1");
        self::fixture("INSERT INTO 202_users SET user_id=3, user_name='three', user_email='3@example.test', user_time_register=1");
        self::assertSame('2', (string) self::scalar(DefaultModel::MISSING_SQL));

        self::$db->query(DefaultModel::SEED_ALL_SQL);
        self::$db->query(DefaultModel::SEED_ALL_SQL);
        self::assertSame('0', (string) self::scalar(DefaultModel::MISSING_SQL));
        self::assertSame([['user_id' => '1', 'n' => '1'], ['user_id' => '2', 'n' => '1'], ['user_id' => '3', 'n' => '1']],
            self::all('SELECT user_id, COUNT(*) AS n FROM 202_attribution_models WHERE is_default = 1 GROUP BY user_id ORDER BY user_id'));
        self::assertSame(
            (int) self::scalar('SELECT model_id FROM 202_attribution_models WHERE user_id=2'),
            DefaultModel::ensureFor($this->conn, 2),
            'ensureFor finds the seeded default instead of adding one'
        );
        self::assertSame('last_touch', self::scalar('SELECT model_type FROM 202_attribution_models WHERE user_id=3'));

        // The database refuses a second default outright.
        self::assertFalse(self::$db->query("INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_default, created_at, updated_at)
            VALUES (1, 'x', 'x', 'linear', '{}', 1, 1, 1)"));
        self::assertSame(1062, self::$db->errno, self::$db->error);
        // And any value but 1 or NULL for is_default is not a default.
        self::assertTrue(self::$db->query("INSERT INTO 202_attribution_models (user_id, model_name, model_slug, model_type, weighting_config, is_default, created_at, updated_at)
            VALUES (1, 'y', 'y', 'linear', '{}', NULL, 1, 1)"));
    }

    public function testAnInvalidStoredModelIsMarkedAndTheOthersStillCompute(): void
    {
        $this->campaign(1);
        $this->click(110, 1, time() - 100);
        $broken = $this->addModel('Decay', ModelType::TIME_DECAY);
        self::$db->query("UPDATE 202_attribution_models SET weighting_config = '{\"half_life\": 4}' WHERE model_id = $broken");
        $conv = $this->convert(110, '1');
        $report = $this->work();

        self::assertSame(1, $report->outcomes['credited'] ?? 0);
        self::assertSame([110 => '1.00000000'], self::credits($conv, $this->defaultModelId()));
        self::assertSame([], self::credits($conv, $broken));
        $row = self::all("SELECT status, status_reason FROM 202_attribution_models WHERE model_id = $broken")[0];
        self::assertSame('invalid', $row['status']);
        self::assertStringContainsString('half_life', (string) $row['status_reason']);
    }

    public function testRunExclusiveSkipsWhileAnotherWorkerHoldsTheLock(): void
    {
        $other = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertSame('1', (string) $other->query("SELECT GET_LOCK('" . AttributionWorker::LOCK_NAME . "', 0)")->fetch_row()[0]);
        try {
            self::assertNull(AttributionWorker::runExclusive($this->conn, 1));
        } finally {
            $other->close();
        }
        self::assertNotNull(AttributionWorker::runExclusive($this->conn, 1), 'the lock is released with its session');
    }
}

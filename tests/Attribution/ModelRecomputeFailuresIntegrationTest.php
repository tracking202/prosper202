<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\WorkerReport;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * A model whose recompute fails no longer stops the models after it, or the
 * rest of the worker's run (AttributionWorker::fanOutModelRecomputes()).
 *
 * The models were taken in request order and each one's transaction threw
 * out of the run: a model that failed on every run was the head of every
 * run, and neither the models after it, nor the outbox, nor the rollup ran
 * again. The failing model here is requested ahead of one that must fan out.
 *
 * @group integration
 */
final class ModelRecomputeFailuresIntegrationTest extends TestCase
{
    use AttributionDatabase;

    /** The worker's clock: early enough that the rollup finds no hour sealed. */
    private const T = 1_000_000;
    /** Refuses the model's fan-out write, but not the write that records its failure. */
    private const REFUSE_MODEL = "CREATE TRIGGER p202_test_refuse_model BEFORE UPDATE ON 202_attribution_models
        FOR EACH ROW IF NEW.model_id = %d AND NEW.recompute_failures <= OLD.recompute_failures THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused for the test';
        END IF";

    protected function tearDown(): void
    {
        self::$db?->query('DROP TRIGGER IF EXISTS p202_test_refuse_model');
    }

    public function testAFailingModelBeforeAGoodOneIsPassedAndTheGoodOneAndTheOutboxStillRun(): void
    {
        $conv = $this->attributedConversion();
        $bad = $this->addModel('Linear', ModelType::LINEAR);
        $good = $this->addModel('First touch', ModelType::FIRST_TOUCH);
        $this->requestedAt($bad, 10);
        $this->requestedAt($good, 20);
        $late = $this->convert(502, '3.00', 'late');
        self::fixture(sprintf(self::REFUSE_MODEL, $bad));

        $report = $this->workAt(self::T);

        self::assertModels([1, 1], $report);
        $row = $this->model($good);
        self::assertNull($row['recompute_requested_at'], 'the model after it fanned out');
        self::assertSame(
            [501 => '1.00000000'],
            self::credits($conv, $good),
            'and its credits were computed in the same run'
        );
        self::assertSame([502 => '1.00000000'], self::credits($late, $good), 'the outbox ran in the same run');

        $row = $this->model($bad);
        self::assertSame(
            [10, 1, self::T],
            [$row['recompute_requested_at'], $row['recompute_failures'], $row['recompute_retry_at']],
            'still requested, tried again on the next run'
        );
        self::assertStringContainsString('refused for the test', (string) $row['recompute_error']);

        $queue = (new AttributionReports($this->conn))->queue(1);
        self::assertSame([1, 1], [$queue['models_awaiting_recompute'], $queue['models_failing']]);
        self::assertSame(
            [$bad, 'Linear', 1, self::T],
            [
                $queue['model_rows'][0]['model_id'],
                $queue['model_rows'][0]['model_name'],
                $queue['model_rows'][0]['failures'],
                $queue['model_rows'][0]['retry_at'],
            ]
        );
        self::assertSame($row['recompute_error'], $queue['model_rows'][0]['last_error']);
        self::assertStringContainsString('model changes fanned out 1 (1 failed)', $report->summary());
    }

    public function testAFailureIsRetriedLaterEachTimeAndABatchThatGoesThroughEndsTheRun(): void
    {
        $conv = $this->attributedConversion();
        $bad = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture(sprintf(self::REFUSE_MODEL, $bad));
        $minute = AttributionWorker::MERGE_FIRST_BACKOFF;
        $hour = AttributionWorker::MERGE_MAX_BACKOFF;

        self::assertModels([0, 1], $this->workAt(self::T));
        self::assertModels([0, 1], $this->workAt(self::T), 'the first failure waits for the next run only');
        $row = $this->model($bad);
        self::assertSame([2, self::T + $minute], [$row['recompute_failures'], $row['recompute_retry_at']]);

        self::assertModels([0, 0], $this->workAt(self::T + $minute - 1), 'not taken before its turn');

        self::$db->query("UPDATE 202_attribution_models SET recompute_failures = 400 WHERE model_id = $bad");
        $this->workAt(self::T + $minute);
        $row = $this->model($bad);
        self::assertSame(
            [401, self::T + $minute + $hour],
            [$row['recompute_failures'], $row['recompute_retry_at']],
            'any number of failures waits at most an hour, and the model stays requested'
        );
        self::assertNotNull($row['recompute_requested_at']);

        self::$db->query('DROP TRIGGER p202_test_refuse_model');
        self::assertModels([1, 0], $this->workAt(self::T + $minute + $hour));
        $row = $this->model($bad);
        self::assertSame(
            [null, 0, 0, null],
            [$row['recompute_requested_at'], $row['recompute_failures'], $row['recompute_retry_at'], $row['recompute_error']]
        );
        self::assertSame([501 => '1.00000000'], self::credits($conv, $bad));
        self::assertSame(0, (new AttributionReports($this->conn))->queue(1)['models_failing']);
    }

    public function testAnEditThatAsksForANewRecomputeStartsItsTurnsAfresh(): void
    {
        $this->attributedConversion();
        $bad = $this->addModel('Linear', ModelType::LINEAR);
        self::fixture(sprintf(self::REFUSE_MODEL, $bad));
        $this->workAt(self::T);
        $this->workAt(self::T);
        self::assertSame(self::T + AttributionWorker::MERGE_FIRST_BACKOFF, $this->model($bad)['recompute_retry_at']);
        self::$db->query('DROP TRIGGER p202_test_refuse_model');

        $type = ModelType::POSITION_BASED;
        (new ModelRepository($this->conn))->update(
            1,
            $bad,
            'Linear',
            ModelRepository::slugFor('Linear'),
            $type,
            ModelConfig::normalize($type, []),
            30,
            'active',
            false,
            true
        );
        $row = $this->model($bad);
        self::assertSame([0, 0, null], [$row['recompute_failures'], $row['recompute_retry_at'], $row['recompute_error']]);

        self::assertModels([1, 0], $this->workAt(self::T), 'taken at once, not after the old backoff');
    }

    public function testModelsNeverFailedComeBeforeOnesWhoseRetryIsDue(): void
    {
        $this->attributedConversion();
        $bad = $this->addModel('Linear', ModelType::LINEAR);
        $good = $this->addModel('First touch', ModelType::FIRST_TOUCH);
        $this->requestedAt($bad, 10);
        $this->requestedAt($good, 20);
        self::fixture(sprintf(self::REFUSE_MODEL, $bad));
        // Only the two added models: the default's own request is done.
        self::$db->query('UPDATE 202_attribution_models SET recompute_requested_at = NULL WHERE is_default = 1');

        $worker = new AttributionWorker($this->conn, static fn (): int => self::T);
        self::assertSame(['fanned_out' => 0, 'failed' => 1], $worker->fanOutModelRecomputes(1));
        self::assertSame(
            ['fanned_out' => 1, 'failed' => 0],
            $worker->fanOutModelRecomputes(1),
            'the model never failed goes first, though requested later'
        );
        self::assertNull($this->model($good)['recompute_requested_at']);
        self::assertSame(
            ['fanned_out' => 0, 'failed' => 1],
            $worker->fanOutModelRecomputes(1),
            'then the one whose turn came'
        );
        self::assertSame(2, $this->model($bad)['recompute_failures']);
    }

    /** One conversion on click 501, credited by the default model, with its journey built. */
    private function attributedConversion(): int
    {
        $now = time();
        $this->campaign(1);
        $this->click(501, 1, $now - 3600);
        $this->visit(501, $now - 3600, self::cookie('m501'));
        $this->click(502, 1, $now - 1800);
        $this->visit(502, $now - 1800, self::cookie('m502'));
        $conv = $this->convert(501, '1.00', 'first');
        $this->workAt(self::T);
        self::assertSame([501], self::journeyClicks($conv));

        return $conv;
    }

    private function requestedAt(int $modelId, int $at): void
    {
        self::$db->query("UPDATE 202_attribution_models SET recompute_requested_at = $at WHERE model_id = $modelId");
    }

    /** @param array{int, int} $expected fanned out, failed */
    private static function assertModels(array $expected, WorkerReport $report, string $why = ''): void
    {
        self::assertSame(
            $expected,
            [$report->modelsFannedOut, $report->modelsFailed],
            trim($why . ' ' . $report->summary())
        );
    }

    private function workAt(int $now): WorkerReport
    {
        return (new AttributionWorker($this->conn, static fn (): int => $now))->run(30, 200);
    }

    /**
     * @return array{recompute_requested_at: ?int, recompute_failures: int, recompute_retry_at: int,
     *               recompute_error: ?string}
     */
    private function model(int $modelId): array
    {
        $row = self::all("SELECT recompute_requested_at, recompute_failures, recompute_retry_at, recompute_error
            FROM 202_attribution_models WHERE model_id = $modelId")[0];

        return [
            'recompute_requested_at' => $row['recompute_requested_at'] === null ? null : (int) $row['recompute_requested_at'],
            'recompute_failures' => (int) $row['recompute_failures'],
            'recompute_retry_at' => (int) $row['recompute_retry_at'],
            'recompute_error' => $row['recompute_error'],
        ];
    }
}

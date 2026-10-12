<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\RetentionLoadProbe;
use Prosper202\Click\RetentionPacer;
use Prosper202\Click\RetentionPressure;

/**
 * How the click-retention jobs pace themselves, on a simulated clock: batch
 * sizes follow how long batches take, load caps them and lengthens the
 * pauses, severe load allows one small batch, and a run ends at its budget
 * or after repeated lock contention. ClickRetentionIntegrationTest drives the real deletes.
 */
final class RetentionPacerTest extends TestCase
{
    private float $now = 1000.0;
    /** @var list<float> */
    private array $slept = [];
    private RetentionPressure $pressure = RetentionPressure::NONE;

    private function pacer(float $budget = RetentionPacer::BUDGET_SECONDS): RetentionPacer
    {
        return new RetentionPacer(
            fn (): RetentionPressure => $this->pressure,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            $budget,
        );
    }

    /** Run one batch that takes $seconds and deletes all it was asked to. */
    private function batch(RetentionPacer $pacer, float $seconds): array
    {
        $size = $pacer->nextBatch();
        if ($size === 0) {
            return [0, 0.0];
        }
        $this->now += $seconds;

        return [$size, $pacer->afterBatch($size, $size, $seconds)];
    }

    public function testAnIdleFastServerRampsUpToTheLargestBatch(): void
    {
        $pacer = $this->pacer();
        $sizes = [];
        for ($i = 0; $i < 8; $i++) {
            [$sizes[]] = $this->batch($pacer, 0.05);
        }
        self::assertSame([500, 1000, 2000, 4000, 8000, 10000, 10000, 10000], $sizes);
    }

    public function testASlowServerSettlesAtWhatItDeletesInHalfASecond(): void
    {
        $pacer = $this->pacer();
        self::assertSame(500, $this->batch($pacer, 2.0)[0]);
        self::assertSame(125, $pacer->nextBatch(), 'four times over the target: a quarter of the size');
        $pacer->afterBatch(125, 125, 0.4);
        self::assertSame(157, $pacer->nextBatch(), 'within the target: grows by a quarter');
    }

    public function testBatchesNeverLeaveTheirBounds(): void
    {
        $pacer = $this->pacer();
        for ($i = 0; $i < 12; $i++) {
            $this->batch($pacer, 30.0 / 20);
        }
        self::assertSame(RetentionPacer::MIN_BATCH, $pacer->batchSize());
    }

    public function testAPartialBatchDoesNotGrowTheNext(): void
    {
        $pacer = $this->pacer();
        $pacer->nextBatch();
        $pacer->afterBatch(10, 500, 0.01);
        self::assertSame(500, $pacer->nextBatch());
    }

    public function testModerateLoadCapsBatchesAndPausesAsLongAsEachBatchTook(): void
    {
        $pacer = $this->pacer();
        for ($i = 0; $i < 6; $i++) {
            $this->batch($pacer, 0.05);
        }
        self::assertGreaterThan(RetentionPacer::MODERATE_CAP, $pacer->batchSize(), 'learned while idle');

        $this->pressure = RetentionPressure::MODERATE;
        [$size, $pause] = $this->batch($pacer, 0.3);
        self::assertSame(RetentionPacer::MODERATE_CAP, $size);
        self::assertSame(0.3, $pause, 'half the time spent deleting');
        [, $pause] = $this->batch($pacer, 0.01);
        self::assertSame(0.2, $pause, 'never less than a fifth of a second');
    }

    public function testHighLoadDeletesTheSmallestBatchesWithLongPauses(): void
    {
        $pacer = $this->pacer();
        $this->batch($pacer, 0.05);
        $this->pressure = RetentionPressure::HIGH;
        [$size, $pause] = $this->batch($pacer, 0.1);
        self::assertSame(RetentionPacer::MIN_BATCH, $size);
        self::assertSame(2.0, $pause);
        [, $pause] = $this->batch($pacer, 1.0);
        self::assertSame(4.0, $pause, 'four times the batch: a fifth of the time deleting');
    }

    public function testSevereLoadAllowsARunOneSmallBatch(): void
    {
        $pacer = $this->pacer();
        $this->pressure = RetentionPressure::SEVERE;
        [$size] = $this->batch($pacer, 0.05);
        self::assertSame(RetentionPacer::MIN_BATCH, $size, 'a server that is always saturated is still cleaned');
        self::assertSame(0, $pacer->nextBatch());
        self::assertSame('server under heavy load', $pacer->stopReason());
        $this->pressure = RetentionPressure::NONE;
        self::assertSame(0, $pacer->nextBatch(), 'a stopped run stays stopped');
    }

    public function testSevereLoadPartWayThroughARunEndsIt(): void
    {
        $pacer = $this->pacer();
        $this->batch($pacer, 0.05);
        $this->pressure = RetentionPressure::SEVERE;
        self::assertSame(0, $pacer->nextBatch());
        self::assertSame('server under heavy load', $pacer->stopReason());
    }

    public function testTheRunStopsBeforeItWouldOverrunItsBudget(): void
    {
        $pacer = $this->pacer(10.0);
        $batches = 0;
        while (true) {
            [$size, $pause] = $this->batch($pacer, 1.5);
            if ($size === 0) {
                break;
            }
            $batches++;
            $pacer->pause($pause);
        }
        self::assertSame('time budget spent', $pacer->stopReason());
        self::assertLessThanOrEqual(10.0, $this->now - 1000.0, 'deleting and pausing both count');
        self::assertGreaterThanOrEqual(4, $batches);
    }

    public function testAPauseNeverRunsPastTheBudget(): void
    {
        $pacer = $this->pacer(5.0);
        $this->now += 4.0;
        $pacer->pause(30.0);
        self::assertSame([1.0], $this->slept);
        $pacer->pause(1.0);
        self::assertSame([1.0], $this->slept, 'nothing left to sleep');
    }

    public function testLockContentionShrinksTheBatchAndThreeEndTheRun(): void
    {
        $pacer = $this->pacer();
        for ($i = 0; $i < 3; $i++) {
            $this->batch($pacer, 0.05);
        }
        self::assertSame(4000, $pacer->batchSize());
        self::assertSame(2.0, $pacer->afterContention());
        self::assertSame(1000, $pacer->nextBatch());
        $pacer->afterContention();
        self::assertSame(250, $pacer->nextBatch());
        $pacer->afterContention();
        self::assertSame(0, $pacer->nextBatch());
        self::assertSame('lock contention', $pacer->stopReason());
    }

    /** @dataProvider loadReadings */
    public function testWhatTheLoadReadingsMean(
        ?float $loadPerCpu,
        ?int $otherQueries,
        int $cpus,
        RetentionPressure $expected,
    ): void {
        self::assertSame($expected, RetentionLoadProbe::classify($loadPerCpu, $otherQueries, $cpus));
    }

    /** @return iterable<string, array{?float, ?int, int, RetentionPressure}> */
    public static function loadReadings(): iterable
    {
        yield 'nothing readable' => [null, null, 1, RetentionPressure::NONE];
        yield 'idle' => [0.2, 0, 4, RetentionPressure::NONE];
        yield 'busy host' => [1.0, 0, 4, RetentionPressure::MODERATE];
        yield 'saturated host' => [2.0, 0, 4, RetentionPressure::HIGH];
        yield 'host queueing' => [3.5, 0, 4, RetentionPressure::SEVERE];
        yield 'busy database' => [0.2, 4, 4, RetentionPressure::MODERATE];
        yield 'contended database' => [0.2, 8, 4, RetentionPressure::HIGH];
        yield 'database the bottleneck' => [0.2, 16, 4, RetentionPressure::SEVERE];
        yield 'one CPU, two other queries' => [null, 2, 1, RetentionPressure::HIGH];
        yield 'the worse of the two' => [1.0, 8, 4, RetentionPressure::HIGH];
    }
}

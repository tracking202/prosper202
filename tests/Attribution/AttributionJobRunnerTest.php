<?php

declare(strict_types=1);

namespace Tests\Attribution;

require_once __DIR__ . '/Support/RepositoryFakes.php';

use Prosper202\Attribution\AttributionJobRunner;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;
use Tests\TestCase;

final class AttributionJobRunnerTest extends TestCase
{
    private int $baseTime = 1700000000;

    private function makeModel(int $modelId = 1, int $userId = 1): ModelDefinition
    {
        $now = time();
        return new ModelDefinition(
            modelId: $modelId,
            userId: $userId,
            name: 'Last Touch Test',
            slug: 'last-touch-test',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: true,
            createdAt: $now,
            updatedAt: $now
        );
    }

    private function makeConversion(int $conversionId, int $convTime, float $payout = 10.0, float $cost = 2.0): ConversionRecord
    {
        return new ConversionRecord(
            conversionId: $conversionId,
            clickId: $conversionId + 1000,
            userId: 1,
            campaignId: 100,
            ppcAccountId: 200,
            convTime: $convTime,
            clickTime: $convTime - 3600,
            clickPayout: $payout,
            clickCost: $cost,
            journey: []
        );
    }

    /**
     * Creates an in-memory model repository with the given models.
     *
     * @param ModelDefinition[] $models
     */
    private function makeModelRepo(array $models = []): ModelRepositoryInterface
    {
        return new class($models) implements ModelRepositoryInterface {
            /** @var array<int, ModelDefinition> */
            private array $models = [];

            /** @param ModelDefinition[] $models */
            public function __construct(array $models)
            {
                foreach ($models as $model) {
                    if ($model->modelId !== null) {
                        $this->models[$model->modelId] = $model;
                    }
                }
            }

            public function findById(int $modelId): ?ModelDefinition
            {
                return $this->models[$modelId] ?? null;
            }

            public function findDefaultForUser(int $userId): ?ModelDefinition
            {
                foreach ($this->models as $model) {
                    if ($model->userId === $userId && $model->isDefault) {
                        return $model;
                    }
                }
                return null;
            }

            public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
            {
                return array_values(array_filter(
                    $this->models,
                    static function (ModelDefinition $model) use ($userId, $type, $onlyActive): bool {
                        if ($model->userId !== $userId) {
                            return false;
                        }
                        if ($onlyActive && !$model->isActive) {
                            return false;
                        }
                        if ($type !== null && $model->type !== $type) {
                            return false;
                        }
                        return true;
                    }
                ));
            }

            public function findBySlug(int $userId, string $slug): ?ModelDefinition
            {
                foreach ($this->models as $model) {
                    if ($model->userId === $userId && $model->slug === $slug) {
                        return $model;
                    }
                }
                return null;
            }

            public function save(ModelDefinition $model): ModelDefinition
            {
                if ($model->modelId !== null) {
                    $this->models[$model->modelId] = $model;
                }
                return $model;
            }

            public function promoteToDefault(ModelDefinition $model): void {}
            public function setAsDefault(int $userId, int $modelId): bool { return true; }
            public function delete(int $modelId, int $userId): void {}
        };
    }

    /**
     * Creates an in-memory snapshot repository that auto-assigns IDs.
     */
    private function makeSnapshotRepo(array $initialSnapshots = []): SnapshotRepositoryInterface
    {
        return new class($initialSnapshots) implements SnapshotRepositoryInterface {
            /** @var Snapshot[] */
            public array $snapshots;
            private int $nextId;

            /** @param Snapshot[] $initialSnapshots */
            public function __construct(array $initialSnapshots = [])
            {
                $this->snapshots = $initialSnapshots;
                $maxId = 0;
                foreach ($initialSnapshots as $s) {
                    if ($s->snapshotId !== null && $s->snapshotId > $maxId) {
                        $maxId = $s->snapshotId;
                    }
                }
                $this->nextId = $maxId + 1;
            }

            public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
            {
                return array_values(array_filter(
                    $this->snapshots,
                    static function (Snapshot $snapshot) use ($modelId, $startHour, $endHour): bool {
                        return $snapshot->modelId === $modelId
                            && $snapshot->dateHour >= $startHour
                            && $snapshot->dateHour <= $endHour;
                    }
                ));
            }

            public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot
            {
                $filtered = $this->findForRange($modelId, $scopeType, $scopeId, 0, PHP_INT_MAX);
                return $filtered !== [] ? end($filtered) : null;
            }

            public function save(Snapshot $snapshot): Snapshot
            {
                if ($snapshot->snapshotId === null) {
                    $snapshot = new Snapshot(
                        snapshotId: $this->nextId++,
                        modelId: $snapshot->modelId,
                        userId: $snapshot->userId,
                        scopeType: $snapshot->scopeType,
                        scopeId: $snapshot->scopeId,
                        dateHour: $snapshot->dateHour,
                        lookbackStart: $snapshot->lookbackStart,
                        lookbackEnd: $snapshot->lookbackEnd,
                        attributedClicks: $snapshot->attributedClicks,
                        attributedConversions: $snapshot->attributedConversions,
                        attributedRevenue: $snapshot->attributedRevenue,
                        attributedCost: $snapshot->attributedCost,
                        createdAt: $snapshot->createdAt
                    );
                } else {
                    // Update existing snapshot (replace by snapshotId).
                    $this->snapshots = array_values(array_filter(
                        $this->snapshots,
                        static fn (Snapshot $s): bool => $s->snapshotId !== $snapshot->snapshotId
                    ));
                }
                $this->snapshots[] = $snapshot;
                return $snapshot;
            }

            public function purgeOlderThan(int $timestamp): int
            {
                $before = count($this->snapshots);
                $this->snapshots = array_values(array_filter(
                    $this->snapshots,
                    static fn (Snapshot $s): bool => $s->dateHour >= $timestamp
                ));
                return $before - count($this->snapshots);
            }
        };
    }

    /**
     * Creates an in-memory conversion repository that returns the given
     * conversions on the first call, then empty on subsequent calls.
     *
     * @param ConversionRecord[] $conversions
     */
    private function makeConversionRepo(array $conversions = []): ConversionRepositoryInterface
    {
        return new class($conversions) implements ConversionRepositoryInterface {
            /** @var ConversionRecord[] */
            private array $conversions;
            private bool $returned = false;

            /** @param ConversionRecord[] $conversions */
            public function __construct(array $conversions)
            {
                $this->conversions = $conversions;
            }

            public function fetchForUser(int $userId, int $startTime, int $endTime, ?int $afterConversionId = null, int $limit = 5000): ConversionBatch
            {
                if ($this->returned) {
                    return new ConversionBatch($userId, $startTime, $endTime, []);
                }
                $this->returned = true;
                return new ConversionBatch($userId, $startTime, $endTime, $this->conversions);
            }
        };
    }

    /**
     * Creates an in-memory audit repository that records calls.
     */
    private function makeAuditRepo(): AuditRepositoryInterface
    {
        return new class implements AuditRepositoryInterface {
            /** @var array<int, array{userId: int, modelId: ?int, action: string, metadata: array}> */
            public array $records = [];

            public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
            {
                $this->records[] = [
                    'userId' => $userId,
                    'modelId' => $modelId,
                    'action' => $action,
                    'metadata' => $metadata,
                ];
            }
        };
    }

    /**
     * Creates a touchpoint repository that tracks saveBatch and deleteBySnapshot calls.
     */
    private function makeTrackingTouchpointRepo(): TouchpointRepositoryInterface
    {
        return new class implements TouchpointRepositoryInterface {
            /** @var array<int, Touchpoint[]> */
            public array $savedBatches = [];

            /** @var int[] */
            public array $deletedSnapshotIds = [];

            public function findBySnapshot(int $snapshotId): array
            {
                return [];
            }

            public function saveBatch(array $touchpoints): void
            {
                $this->savedBatches[] = $touchpoints;
            }

            public function deleteBySnapshot(int $snapshotId): void
            {
                $this->deletedSnapshotIds[] = $snapshotId;
            }
        };
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testStartTimeAfterEndTimeThrows(): void
    {
        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $this->makeSnapshotRepo(),
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo(),
            $this->makeAuditRepo()
        );

        $this->expectException(\RuntimeException::class);
        $runner->runForUser(1, $this->baseTime + 7200, $this->baseTime);
    }

    public function testStartTimeEqualsEndTimeThrows(): void
    {
        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $this->makeSnapshotRepo(),
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo(),
            $this->makeAuditRepo()
        );

        $this->expectException(\RuntimeException::class);
        $runner->runForUser(1, $this->baseTime, $this->baseTime);
    }

    public function testNoModelsReturnsEarly(): void
    {
        $auditRepo = $this->makeAuditRepo();
        $runner = new AttributionJobRunner(
            $this->makeModelRepo([]),
            $this->makeSnapshotRepo(),
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([
                $this->makeConversion(1, $this->baseTime + 100),
            ]),
            $auditRepo
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // No models means no audit entries and no crash.
        self::assertSame([], $auditRepo->records);
    }

    public function testNoConversionsReturnsEarly(): void
    {
        $auditRepo = $this->makeAuditRepo();
        $snapshotRepo = $this->makeSnapshotRepo();
        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $snapshotRepo,
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([]),
            $auditRepo
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // No conversions means no snapshots created and no audit entries.
        self::assertSame([], $auditRepo->records);
        self::assertSame([], $snapshotRepo->snapshots);
    }

    public function testSingleConversionLastTouch(): void
    {
        $snapshotRepo = $this->makeSnapshotRepo();
        $auditRepo = $this->makeAuditRepo();
        $convTime = $this->baseTime + 1800; // Middle of the hour bucket starting at baseTime.

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $snapshotRepo,
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([
                $this->makeConversion(1, $convTime, 25.0, 5.0),
            ]),
            $auditRepo
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // A snapshot should have been created for the hour bucket.
        self::assertNotEmpty($snapshotRepo->snapshots);

        // Find the finalised snapshot (the last save for this hour bucket).
        $bucket = $this->baseTime - ($this->baseTime % 3600);
        $finalised = null;
        foreach (array_reverse($snapshotRepo->snapshots) as $s) {
            if ($s->dateHour === $bucket && $s->snapshotId !== null) {
                $finalised = $s;
                break;
            }
        }

        self::assertNotNull($finalised, 'Expected a finalised snapshot for the hour bucket.');
        self::assertSame(1, $finalised->attributedClicks);
        self::assertSame(1, $finalised->attributedConversions);
        self::assertEqualsWithDelta(25.0, $finalised->attributedRevenue, 0.001);
        self::assertEqualsWithDelta(5.0, $finalised->attributedCost, 0.001);

        // Audit should have been recorded.
        self::assertCount(1, $auditRepo->records);
        self::assertSame('snapshot_rebuild', $auditRepo->records[0]['action']);
    }

    public function testMultipleConversionsLastTouch(): void
    {
        $snapshotRepo = $this->makeSnapshotRepo();
        $auditRepo = $this->makeAuditRepo();

        // Three conversions in the same hour bucket.
        $convTime1 = $this->baseTime + 100;
        $convTime2 = $this->baseTime + 200;
        $convTime3 = $this->baseTime + 300;

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $snapshotRepo,
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([
                $this->makeConversion(1, $convTime1, 10.0, 2.0),
                $this->makeConversion(2, $convTime2, 20.0, 4.0),
                $this->makeConversion(3, $convTime3, 30.0, 6.0),
            ]),
            $auditRepo
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // Locate the finalised snapshot for the bucket.
        $bucket = $this->baseTime - ($this->baseTime % 3600);
        $finalised = null;
        foreach (array_reverse($snapshotRepo->snapshots) as $s) {
            if ($s->dateHour === $bucket && $s->snapshotId !== null) {
                $finalised = $s;
                break;
            }
        }

        self::assertNotNull($finalised, 'Expected a finalised snapshot.');
        self::assertSame(3, $finalised->attributedClicks);
        self::assertSame(3, $finalised->attributedConversions);
        self::assertEqualsWithDelta(60.0, $finalised->attributedRevenue, 0.001);
        self::assertEqualsWithDelta(12.0, $finalised->attributedCost, 0.001);
    }

    public function testConversionsAcrossHours(): void
    {
        $snapshotRepo = $this->makeSnapshotRepo();

        // baseTime is 1700000000 which floors to 1699999200 for the hour bucket (1700000000 % 3600 = 800).
        $hourBucket1 = $this->baseTime - ($this->baseTime % 3600);       // 1699999200
        $hourBucket2 = $hourBucket1 + 3600;                               // 1700002800

        $convTimeHour1 = $hourBucket1 + 500;  // In first bucket.
        $convTimeHour2 = $hourBucket2 + 500;  // In second bucket.

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $snapshotRepo,
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([
                $this->makeConversion(1, $convTimeHour1, 10.0, 2.0),
                $this->makeConversion(2, $convTimeHour2, 20.0, 4.0),
            ]),
            $this->makeAuditRepo()
        );

        $runner->runForUser(1, $hourBucket1, $hourBucket2 + 3600);

        // Collect unique hour buckets from saved snapshots.
        $buckets = [];
        foreach ($snapshotRepo->snapshots as $s) {
            $buckets[$s->dateHour] = true;
        }

        self::assertArrayHasKey($hourBucket1, $buckets, 'Expected a snapshot for the first hour bucket.');
        self::assertArrayHasKey($hourBucket2, $buckets, 'Expected a snapshot for the second hour bucket.');
        self::assertCount(2, $buckets, 'Expected exactly two distinct hour buckets.');
    }

    public function testAuditRecorded(): void
    {
        $auditRepo = $this->makeAuditRepo();

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $this->makeSnapshotRepo(),
            $this->makeTrackingTouchpointRepo(),
            $this->makeConversionRepo([
                $this->makeConversion(1, $this->baseTime + 100),
            ]),
            $auditRepo
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        self::assertCount(1, $auditRepo->records);

        $entry = $auditRepo->records[0];
        self::assertSame(1, $entry['userId']);
        self::assertSame(1, $entry['modelId']);
        self::assertSame('snapshot_rebuild', $entry['action']);
        self::assertArrayHasKey('conversions', $entry['metadata']);
        self::assertArrayHasKey('clicks', $entry['metadata']);
        self::assertArrayHasKey('revenue', $entry['metadata']);
        self::assertArrayHasKey('cost', $entry['metadata']);
        self::assertArrayHasKey('batches', $entry['metadata']);
        self::assertArrayHasKey('hours_processed', $entry['metadata']);
    }

    public function testTouchpointsPersisted(): void
    {
        $touchpointRepo = $this->makeTrackingTouchpointRepo();

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $this->makeSnapshotRepo(),
            $touchpointRepo,
            $this->makeConversionRepo([
                $this->makeConversion(1, $this->baseTime + 100, 10.0, 2.0),
                $this->makeConversion(2, $this->baseTime + 200, 20.0, 4.0),
            ]),
            $this->makeAuditRepo()
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // saveBatch should have been called at least once.
        self::assertNotEmpty($touchpointRepo->savedBatches, 'Expected touchpoint saveBatch to be called.');

        // Flatten all saved touchpoints and verify count matches conversions.
        $allTouchpoints = array_merge(...$touchpointRepo->savedBatches);
        self::assertCount(2, $allTouchpoints, 'Expected one touchpoint per conversion for LAST_TOUCH.');

        // Each touchpoint should have credit 1.0 (last-touch gives full credit).
        foreach ($allTouchpoints as $tp) {
            self::assertInstanceOf(Touchpoint::class, $tp);
            self::assertEqualsWithDelta(1.0, $tp->credit, 0.001);
        }
    }

    public function testExistingSnapshotsGetTouchpointsDeleted(): void
    {
        $touchpointRepo = $this->makeTrackingTouchpointRepo();
        $hourBucket = $this->baseTime - ($this->baseTime % 3600);

        // Pre-seed the snapshot repo with an existing snapshot in the range.
        $existingSnapshot = new Snapshot(
            snapshotId: 50,
            modelId: 1,
            userId: 1,
            scopeType: ScopeType::GLOBAL,
            scopeId: null,
            dateHour: $hourBucket,
            lookbackStart: $this->baseTime,
            lookbackEnd: $this->baseTime + 3600,
            attributedClicks: 5,
            attributedConversions: 2,
            attributedRevenue: 50.0,
            attributedCost: 10.0,
            createdAt: $this->baseTime
        );
        $snapshotRepo = $this->makeSnapshotRepo([$existingSnapshot]);

        $runner = new AttributionJobRunner(
            $this->makeModelRepo([$this->makeModel()]),
            $snapshotRepo,
            $touchpointRepo,
            $this->makeConversionRepo([
                $this->makeConversion(1, $this->baseTime + 100),
            ]),
            $this->makeAuditRepo()
        );

        $runner->runForUser(1, $this->baseTime, $this->baseTime + 3600);

        // The existing snapshot's touchpoints should have been deleted (snapshotId 50).
        self::assertContains(50, $touchpointRepo->deletedSnapshotIds, 'Expected touchpoints for the pre-existing snapshot to be deleted.');
    }
}

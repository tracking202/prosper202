<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionJobRunner;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\Touchpoint;

final class AttributionJobRunnerTest extends TestCase
{
    public function testPersistsSnapshotsAndTouchpoints(): void
    {
        $timestamp = strtotime('2024-02-10 08:30:00');
        $model = new ModelDefinition(
            modelId: 50,
            userId: 9,
            name: 'LT Model',
            slug: 'lt',
            type: ModelType::LAST_TOUCH,
            weightingConfig: [],
            isActive: true,
            isDefault: false,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $modelRepo = new class($model) implements ModelRepositoryInterface {
            public array $models;

            public function __construct(private readonly ModelDefinition $model)
            {
                $this->models = [$model];
            }

            public function findById(int $modelId): ?ModelDefinition
            {
                return $modelId === ($this->model->modelId ?? -1) ? $this->model : null;
            }

            public function findDefaultForUser(int $userId): ?ModelDefinition
            {
                return null;
            }

            public function findForUser(int $userId, ?ModelType $type = null, bool $onlyActive = true): array
            {
                return $this->models;
            }

            public function findBySlug(int $userId, string $slug): ?ModelDefinition
            {
                return null;
            }

            public function save(ModelDefinition $model): ModelDefinition
            {
                return $model;
            }

            public function promoteToDefault(ModelDefinition $model): void
            {
            }

            public function delete(int $modelId, int $userId): void
            {
            }

            public function setAsDefault(int $userId, int $modelId): bool
            {
                return true;
            }
        };

        $conversionRepo = new class($timestamp) implements ConversionRepositoryInterface {
            /** @var ConversionRecord[] */
            private array $records;

            public function __construct(private readonly int $baseTime)
            {
                $this->records = [
                    new ConversionRecord(
                        conversionId: 301,
                        clickId: 4001,
                        userId: 9,
                        campaignId: 99,
                        ppcAccountId: 55,
                        convTime: $this->baseTime,
                        clickTime: $this->baseTime - 120,
                        clickPayout: 8.00,
                        clickCost: 2.00
                    ),
                    new ConversionRecord(
                        conversionId: 302,
                        clickId: 4002,
                        userId: 9,
                        campaignId: 99,
                        ppcAccountId: 55,
                        convTime: $this->baseTime + 60,
                        clickTime: $this->baseTime,
                        clickPayout: 5.00,
                        clickCost: 1.50
                    ),
                ];
            }

            public function fetchForUser(int $userId, int $startTime, int $endTime, ?int $afterConversionId = null, int $limit = 5000): ConversionBatch
            {
                $last = $afterConversionId ?? 0;
                $filtered = array_values(array_filter(
                    $this->records,
                    static fn (ConversionRecord $record): bool => $record->conversionId > $last
                ));

                $chunk = array_slice($filtered, 0, 1); // force batching to simulate chunk processing

                return new ConversionBatch($userId, $startTime, $endTime, $chunk);
            }
        };

        $snapshotRepo = new class implements SnapshotRepositoryInterface {
            public array $store = [];
            private int $nextId = 1;

            public function findForRange(int $modelId, ScopeType $scopeType, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
            {
                return array_values(array_filter(
                    $this->store,
                    static fn (Snapshot $snapshot): bool =>
                        $snapshot->modelId === $modelId &&
                        $snapshot->scopeType === $scopeType &&
                        $snapshot->scopeId === $scopeId &&
                        $snapshot->dateHour >= $startHour &&
                        $snapshot->dateHour <= $endHour
                ));
            }

            public function findLatest(int $modelId, ScopeType $scopeType, ?int $scopeId): ?Snapshot
            {
                $filtered = $this->findForRange($modelId, $scopeType, $scopeId, 0, PHP_INT_MAX);
                if ($filtered === []) {
                    return null;
                }

                usort($filtered, static fn (Snapshot $a, Snapshot $b): int => $b->dateHour <=> $a->dateHour);
                return $filtered[0];
            }

            public function save(Snapshot $snapshot): Snapshot
            {
                if ($snapshot->snapshotId === null) {
                    $assigned = new Snapshot(
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
                    $this->store[$assigned->snapshotId] = $assigned;
                    return $assigned;
                }

                $this->store[$snapshot->snapshotId] = $snapshot;
                return $snapshot;
            }

            public function purgeOlderThan(int $timestamp): int
            {
                return 0;
            }
        };

        $touchpointRepo = new class implements TouchpointRepositoryInterface {
            public array $touchpoints = [];

            public function findBySnapshot(int $snapshotId): array
            {
                return $this->touchpoints[$snapshotId] ?? [];
            }

            public function saveBatch(array $touchpoints): void
            {
                foreach ($touchpoints as $touchpoint) {
                    if (!$touchpoint instanceof Touchpoint) {
                        continue;
                    }
                    $this->touchpoints[$touchpoint->snapshotId ?? -1][] = $touchpoint;
                }
            }

            public function deleteBySnapshot(int $snapshotId): void
            {
                unset($this->touchpoints[$snapshotId]);
            }
        };

        $auditRepo = new class implements AuditRepositoryInterface {
            public array $entries = [];

            public function record(int $userId, ?int $modelId, string $action, array $metadata = []): void
            {
                $this->entries[] = compact('userId', 'modelId', 'action', 'metadata');
            }
        };

        $runner = new AttributionJobRunner($modelRepo, $snapshotRepo, $touchpointRepo, $conversionRepo, $auditRepo);
        $runner->runForUser(9, $timestamp - 3600, $timestamp + 3600);

        self::assertCount(1, $snapshotRepo->store);
        $snapshot = array_values($snapshotRepo->store)[0];
        self::assertSame(2, $snapshot->attributedConversions);
        self::assertSame(2, $snapshot->attributedClicks);
        self::assertEquals(13.00, $snapshot->attributedRevenue);
        self::assertEquals(3.50, $snapshot->attributedCost);

        $touchpoints = array_values($touchpointRepo->touchpoints);
        self::assertCount(1, $touchpoints);
        self::assertCount(2, $touchpoints[0]);
        self::assertSame(4001, $touchpoints[0][0]->clickId);
        self::assertSame(4002, $touchpoints[0][1]->clickId);

        self::assertNotEmpty($auditRepo->entries);
        $entry = $auditRepo->entries[0];
        self::assertSame(9, $entry['userId']);
        self::assertSame(50, $entry['modelId']);
        self::assertSame('snapshot_rebuild', $entry['action']);
        self::assertSame(2, $entry['metadata']['batches']);
        self::assertSame(2, $entry['metadata']['conversions']);
    }
}

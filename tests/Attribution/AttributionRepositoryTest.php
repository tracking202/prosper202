<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\InMemoryAttributionRepository;
use RuntimeException;

final class AttributionRepositoryTest extends TestCase
{
    private function makeRepo(): InMemoryAttributionRepository
    {
        return new InMemoryAttributionRepository();
    }

    // --- Models ---

    public function testCreateModelReturnsId(): void
    {
        $repo = $this->makeRepo();

        $id = $repo->createModel(1, ['model_name' => 'First Touch', 'model_type' => 'first_touch']);

        self::assertSame(1, $id);
    }

    public function testCreateModelStoresData(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, [
            'model_name' => 'Linear',
            'model_type' => 'linear',
            'weighting_config' => ['weight' => 0.5],
            'is_active' => 1,
            'is_default' => 0,
        ]);

        $model = $repo->findModel($id, 1);

        self::assertNotNull($model);
        self::assertSame('Linear', $model['model_name']);
        self::assertSame('linear', $model['model_type']);
        self::assertSame('{"weight":0.5}', $model['weighting_config']);
        self::assertSame(1, $model['is_active']);
    }

    public function testCreateModelGeneratesSlug(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, ['model_name' => 'My Custom Model', 'model_type' => 'first_touch']);

        $model = $repo->findModel($id, 1);
        self::assertSame('my-custom-model', $model['model_slug']);
    }

    public function testFindModelReturnsNullForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, ['model_name' => 'Test', 'model_type' => 'linear']);

        self::assertNull($repo->findModel($id, 2));
    }

    public function testListModelsFiltersByUser(): void
    {
        $repo = $this->makeRepo();
        $repo->createModel(1, ['model_name' => 'A', 'model_type' => 'linear']);
        $repo->createModel(2, ['model_name' => 'B', 'model_type' => 'linear']);

        $models = $repo->listModels(1, [], 0, 10);

        self::assertCount(1, $models);
        self::assertSame('A', $models[0]['model_name']);
    }

    public function testListModelsFiltersByType(): void
    {
        $repo = $this->makeRepo();
        $repo->createModel(1, ['model_name' => 'Linear', 'model_type' => 'linear']);
        $repo->createModel(1, ['model_name' => 'First', 'model_type' => 'first_touch']);

        $models = $repo->listModels(1, ['type' => 'linear'], 0, 10);

        self::assertCount(1, $models);
        self::assertSame('Linear', $models[0]['model_name']);
    }

    public function testUpdateModelModifiesFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, ['model_name' => 'Original', 'model_type' => 'linear']);

        $repo->updateModel($id, 1, ['model_name' => 'Updated', 'is_active' => 0]);

        $model = $repo->findModel($id, 1);
        self::assertSame('Updated', $model['model_name']);
        self::assertSame(0, $model['is_active']);
    }

    public function testUpdateModelThrowsForWrongUser(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, ['model_name' => 'Test', 'model_type' => 'linear']);

        $this->expectException(RuntimeException::class);
        $repo->updateModel($id, 2, ['model_name' => 'Hacked']);
    }

    public function testUpdateModelThrowsForNoFields(): void
    {
        $repo = $this->makeRepo();
        $id = $repo->createModel(1, ['model_name' => 'Test', 'model_type' => 'linear']);

        $this->expectException(RuntimeException::class);
        $repo->updateModel($id, 1, []);
    }

    // --- Cascade Delete ---

    public function testDeleteModelCascades(): void
    {
        $repo = $this->makeRepo();
        $modelId = $repo->createModel(1, ['model_name' => 'Test', 'model_type' => 'linear']);

        // Add a snapshot and touchpoint
        $snapshotId = 100;
        $repo->snapshots[$snapshotId] = [
            'snapshot_id' => $snapshotId, 'model_id' => $modelId, 'user_id' => 1,
            'scope_type' => 'global', 'scope_id' => 0, 'date_hour' => 1709280000,
        ];
        $repo->touchpoints[1] = ['snapshot_id' => $snapshotId, 'data' => 'test'];
        $repo->exports[1] = ['export_id' => 1, 'model_id' => $modelId, 'user_id' => 1];

        $repo->deleteModel($modelId, 1);

        self::assertNull($repo->findModel($modelId, 1));
        self::assertEmpty($repo->snapshots);
        self::assertEmpty($repo->touchpoints);
        self::assertEmpty($repo->exports);
    }

    // --- Snapshots ---

    public function testListSnapshotsFiltersByModelAndUser(): void
    {
        $repo = $this->makeRepo();
        $repo->snapshots = [
            1 => ['snapshot_id' => 1, 'model_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'date_hour' => 1000],
            2 => ['snapshot_id' => 2, 'model_id' => 1, 'user_id' => 1, 'scope_type' => 'campaign', 'date_hour' => 2000],
            3 => ['snapshot_id' => 3, 'model_id' => 2, 'user_id' => 1, 'scope_type' => 'global', 'date_hour' => 3000],
        ];

        $result = $repo->listSnapshots(1, 1, [], 0, 100);

        self::assertCount(2, $result);
    }

    public function testListSnapshotsFiltersByScopeType(): void
    {
        $repo = $this->makeRepo();
        $repo->snapshots = [
            1 => ['snapshot_id' => 1, 'model_id' => 1, 'user_id' => 1, 'scope_type' => 'global', 'date_hour' => 1000],
            2 => ['snapshot_id' => 2, 'model_id' => 1, 'user_id' => 1, 'scope_type' => 'campaign', 'date_hour' => 2000],
        ];

        $result = $repo->listSnapshots(1, 1, ['scope_type' => 'campaign'], 0, 100);

        self::assertCount(1, $result);
        self::assertSame('campaign', $result[0]['scope_type']);
    }

    // --- Exports ---

    public function testScheduleExportCreatesRecord(): void
    {
        $repo = $this->makeRepo();

        $id = $repo->scheduleExport(1, 1, [
            'scope_type' => 'campaign',
            'scope_id' => 5,
            'format' => 'csv',
        ]);

        $exports = $repo->listExports(1, 1);
        self::assertCount(1, $exports);
        self::assertSame($id, $exports[0]['export_id']);
        self::assertSame('queued', $exports[0]['status']);
        self::assertSame('campaign', $exports[0]['scope_type']);
    }

    public function testListExportsFiltersByModelAndUser(): void
    {
        $repo = $this->makeRepo();
        $repo->scheduleExport(1, 1, []);
        $repo->scheduleExport(2, 1, []);

        $exports = $repo->listExports(1, 1);

        self::assertCount(1, $exports);
    }
}

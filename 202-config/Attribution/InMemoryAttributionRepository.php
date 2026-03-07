<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use RuntimeException;

final class InMemoryAttributionRepository implements AttributionRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $models = [];
    private int $nextModelId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $snapshots = [];

    /** @var array<int, array<string, mixed>> */
    public array $exports = [];
    private int $nextExportId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $touchpoints = [];

    public function listModels(int $userId, array $filters, int $offset, int $limit): array
    {
        $filtered = array_filter($this->models, function (array $m) use ($userId, $filters): bool {
            if ($m['user_id'] !== $userId) {
                return false;
            }
            if (!empty($filters['type']) && ($m['model_type'] ?? '') !== $filters['type']) {
                return false;
            }
            return true;
        });

        usort($filtered, fn(array $a, array $b) => $b['model_id'] <=> $a['model_id']);

        return array_slice($filtered, $offset, $limit);
    }

    public function findModel(int $id, int $userId): ?array
    {
        $model = $this->models[$id] ?? null;
        if ($model === null || $model['user_id'] !== $userId) {
            return null;
        }

        return $model;
    }

    public function createModel(int $userId, array $data): int
    {
        $id = $this->nextModelId++;
        $name = (string) ($data['model_name'] ?? '');
        $now = time();

        $this->models[$id] = [
            'model_id' => $id,
            'user_id' => $userId,
            'model_name' => $name,
            'model_slug' => $data['model_slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($name)),
            'model_type' => (string) ($data['model_type'] ?? ''),
            'weighting_config' => is_array($data['weighting_config'] ?? null)
                ? json_encode($data['weighting_config'])
                : (string) ($data['weighting_config'] ?? '{}'),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'is_default' => (int) ($data['is_default'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $id;
    }

    public function updateModel(int $id, int $userId, array $data): void
    {
        if (!isset($this->models[$id]) || $this->models[$id]['user_id'] !== $userId) {
            throw new RuntimeException("Model $id not found");
        }

        $updated = false;
        foreach (['model_name', 'model_slug', 'model_type', 'is_active', 'is_default'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->models[$id][$field] = $data[$field];
                $updated = true;
            }
        }
        if (array_key_exists('weighting_config', $data)) {
            $this->models[$id]['weighting_config'] = is_array($data['weighting_config'])
                ? json_encode($data['weighting_config'])
                : (string) $data['weighting_config'];
            $updated = true;
        }

        if (!$updated) {
            throw new RuntimeException('No fields to update');
        }

        $this->models[$id]['updated_at'] = time();
    }

    public function deleteModel(int $id, int $userId): void
    {
        // Cascade: touchpoints → snapshots → exports → model
        $snapshotIds = array_keys(array_filter(
            $this->snapshots,
            fn(array $s) => $s['model_id'] === $id && $s['user_id'] === $userId,
        ));

        foreach ($snapshotIds as $sid) {
            $this->touchpoints = array_filter(
                $this->touchpoints,
                fn(array $t) => ($t['snapshot_id'] ?? 0) !== $sid,
            );
            unset($this->snapshots[$sid]);
        }

        $this->exports = array_filter(
            $this->exports,
            fn(array $e) => !($e['model_id'] === $id && $e['user_id'] === $userId),
        );

        unset($this->models[$id]);
    }

    public function listSnapshots(int $modelId, int $userId, array $filters, int $offset, int $limit): array
    {
        $filtered = array_filter($this->snapshots, function (array $s) use ($modelId, $userId, $filters): bool {
            if ($s['model_id'] !== $modelId || $s['user_id'] !== $userId) {
                return false;
            }
            if (!empty($filters['scope_type']) && ($s['scope_type'] ?? '') !== $filters['scope_type']) {
                return false;
            }
            return true;
        });

        usort($filtered, fn(array $a, array $b) => ($b['date_hour'] ?? 0) <=> ($a['date_hour'] ?? 0));

        return array_slice($filtered, $offset, $limit);
    }

    public function listExports(int $modelId, int $userId): array
    {
        $filtered = array_filter(
            $this->exports,
            fn(array $e) => $e['model_id'] === $modelId && $e['user_id'] === $userId,
        );
        usort($filtered, fn(array $a, array $b) => $b['export_id'] <=> $a['export_id']);

        return $filtered;
    }

    public function scheduleExport(int $modelId, int $userId, array $data): int
    {
        $id = $this->nextExportId++;
        $now = time();

        $this->exports[$id] = [
            'export_id' => $id,
            'user_id' => $userId,
            'model_id' => $modelId,
            'scope_type' => (string) ($data['scope_type'] ?? 'global'),
            'scope_id' => (int) ($data['scope_id'] ?? 0),
            'start_hour' => (int) ($data['start_hour'] ?? 0),
            'end_hour' => (int) ($data['end_hour'] ?? time()),
            'requested_format' => (string) ($data['format'] ?? 'csv'),
            'status' => 'queued',
            'queued_at' => $now,
            'started_at' => null,
            'completed_at' => null,
            'file_path' => null,
            'webhook_url' => (string) ($data['webhook_url'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $id;
    }
}

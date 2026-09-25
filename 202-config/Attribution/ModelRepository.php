<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * Attribution model rows: reading them as validated Models, and the writes
 * the API makes.
 *
 * Every write that changes what a model computes (create, a type, config or
 * lookback change, activation) asks the worker to re-derive that model's
 * credits by setting recompute_requested_at and resetting the cursor; the
 * worker fans the request out over the account's journeys in batches
 * (AttributionWorker::fanOutModelRecomputes). Deactivation and deletion
 * remove the model's credits in the same transaction, so a report can never
 * read credits from a model that is no longer computed.
 */
final class ModelRepository
{
    private const COLUMNS = 'model_id, user_id, model_name, model_slug, model_type, weighting_config, lookback_days,
        status, status_reason, is_default, recompute_requested_at, created_at, updated_at';

    public function __construct(private Connection $conn)
    {
    }

    /** @return list<array<string, mixed>> */
    public function rows(int $userId): array
    {
        $stmt = $this->conn->prepareRead(
            'SELECT ' . self::COLUMNS . ' FROM 202_attribution_models WHERE user_id = ? ORDER BY model_id'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchAll($stmt);
    }

    /** @return array<string, mixed>|null */
    public function row(int $userId, int $modelId, bool $forUpdate = false): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM 202_attribution_models WHERE user_id = ? AND model_id = ? LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $this->conn->bind($stmt, 'ii', [$userId, $modelId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array<string, mixed>|null */
    public function defaultRow(int $userId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT ' . self::COLUMNS . ' FROM 202_attribution_models WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return $this->conn->fetchOne($stmt);
    }

    /**
     * Build a Model from a row, validating its stored definition.
     *
     * @param array<string, mixed> $row
     * @throws InvalidModelConfig
     */
    public static function fromRow(array $row): Model
    {
        $def = ModelConfig::fromStored(
            (string) $row['model_type'],
            (string) $row['weighting_config'],
            $row['lookback_days']
        );

        return new Model(
            id: (int) $row['model_id'],
            userId: (int) $row['user_id'],
            name: (string) $row['model_name'],
            slug: (string) $row['model_slug'],
            type: $def['type'],
            config: $def['config'],
            lookbackDays: (int) $row['lookback_days'],
            status: (string) $row['status'],
            isDefault: (int) ($row['is_default'] ?? 0) === 1,
        );
    }

    /**
     * The account's computable models. A row marked active whose stored
     * definition no longer validates is marked invalid with the reason and
     * left out; it never takes the other models down with it.
     *
     * @return list<Model>
     */
    public function activeModels(int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT " . self::COLUMNS . " FROM 202_attribution_models WHERE user_id = ? AND status = 'active' ORDER BY model_id"
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        $models = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            try {
                $models[] = self::fromRow($row);
            } catch (InvalidModelConfig $e) {
                $this->markInvalid((int) $row['model_id'], $e->getMessage());
            }
        }

        return $models;
    }

    public function markInvalid(int $modelId, string $reason): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_models SET status = 'invalid', status_reason = ?, updated_at = ? WHERE model_id = ?"
        );
        $this->conn->bind($stmt, 'sii', [mb_substr($reason, 0, 255), time(), $modelId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * @param array<string, float> $config
     */
    public function insert(int $userId, string $name, string $slug, ModelType $type, array $config, int $lookbackDays, string $status, bool $isDefault): int
    {
        $now = time();
        if ($isDefault) {
            $this->clearDefault($userId);
        }
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_models
                (user_id, model_name, model_slug, model_type, weighting_config, lookback_days, status, status_reason,
                 is_default, recompute_requested_at, recompute_cursor, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 0, ?, ?)'
        );
        $this->conn->bind($stmt, 'issssisiiii', [
            $userId, $name, $slug, $type->value, ModelConfig::encode($config), $lookbackDays, $status,
            $isDefault ? 1 : null,
            $status === Model::STATUS_ACTIVE ? $now : null,
            $now, $now,
        ]);
        $id = $this->conn->executeInsert($stmt);
        if ($id <= 0) {
            throw new \RuntimeException('the attribution model was not inserted');
        }

        return $id;
    }

    /**
     * @param array<string, float> $config
     */
    public function update(int $userId, int $modelId, string $name, string $slug, ModelType $type, array $config, int $lookbackDays, string $status, bool $isDefault, bool $recompute): void
    {
        $now = time();
        if ($isDefault) {
            $this->clearDefault($userId, $modelId);
        }
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_attribution_models
             SET model_name = ?, model_slug = ?, model_type = ?, weighting_config = ?, lookback_days = ?,
                 status = ?, status_reason = NULL, is_default = ?, updated_at = ?,
                 recompute_requested_at = IF(?, ?, recompute_requested_at),
                 recompute_cursor = IF(?, 0, recompute_cursor)
             WHERE model_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'ssssisiiiiiii', [
            $name, $slug, $type->value, ModelConfig::encode($config), $lookbackDays,
            $status, $isDefault ? 1 : null, $now,
            $recompute ? 1 : 0, $now,
            $recompute ? 1 : 0,
            $modelId, $userId,
        ]);
        $this->conn->executeUpdate($stmt);

        if ($status !== Model::STATUS_ACTIVE) {
            $this->deleteCredits($modelId);
        }
    }

    public function delete(int $userId, int $modelId): void
    {
        $this->deleteCredits($modelId);
        // Exports that read this model, as the model or as the comparison,
        // go with it (their files are removed by the caller after commit).
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_exports WHERE (model_id = ? OR compare_model_id = ?) AND user_id = ?');
        $this->conn->bind($stmt, 'iii', [$modelId, $modelId, $userId]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_models WHERE model_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'ii', [$modelId, $userId]);
        $this->conn->executeUpdate($stmt);
        // A campaign pointing at a deleted model falls back to the default;
        // clear the pointer so it does not name a row that will never exist.
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_aff_campaigns SET attribution_model_id = NULL WHERE user_id = ? AND attribution_model_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $modelId]);
        $this->conn->executeUpdate($stmt);
    }

    public function creditCount(int $modelId): int
    {
        $stmt = $this->conn->prepareRead('SELECT COUNT(*) AS c FROM 202_attribution_credits WHERE model_id = ?');
        $this->conn->bind($stmt, 'i', [$modelId]);
        $row = $this->conn->fetchOne($stmt);

        return (int) ($row['c'] ?? 0);
    }

    public function slugTaken(int $userId, string $slug, ?int $exceptModelId = null): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT model_id FROM 202_attribution_models WHERE user_id = ? AND model_slug = ? AND model_id <> ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'isi', [$userId, $slug, $exceptModelId ?? 0]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) $row['model_id'] : null;
    }

    /** A name as the slug the uniqueness key compares. */
    public static function slugFor(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' ? mb_substr($slug, 0, 191) : 'model';
    }

    private function clearDefault(int $userId, int $exceptModelId = 0): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_attribution_models SET is_default = NULL WHERE user_id = ? AND is_default = 1 AND model_id <> ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $exceptModelId]);
        $this->conn->executeUpdate($stmt);
    }

    private function deleteCredits(int $modelId): void
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_attribution_credits WHERE model_id = ?');
        $this->conn->bind($stmt, 'i', [$modelId]);
        $this->conn->executeUpdate($stmt);
    }
}

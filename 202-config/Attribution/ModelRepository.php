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
 * remove the model's credits in the same transaction, as marking a model
 * invalid does, so a report can never read credits from a model that is no
 * longer computed.
 *
 * A model's status is a ModelStatus and is written only here (and by
 * DefaultModel's seed): every move names the state it leaves in its WHERE.
 * The default model is active, or invalid with the reason: it is never
 * switched off, stops being the default only when another model becomes it,
 * and defaultThatComputes() refuses an invalid one by name rather than
 * handing a report the id of a model with no credits.
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
     * The default model's row for a read that leans on its credits (a report
     * with no model named). Null when the account has none.
     *
     * @return array<string, mixed>|null
     * @throws DefaultModelNotComputed when the default is not computed
     */
    public function defaultThatComputes(int $userId): ?array
    {
        $row = $this->defaultRow($userId);
        if ($row !== null && ModelStatus::tryFrom((string) $row['status'])?->computes() !== true) {
            throw new DefaultModelNotComputed((int) $row['model_id'], (string) $row['status'], $row['status_reason'] !== null ? (string) $row['status_reason'] : null);
        }

        return $row;
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
            'SELECT ' . self::COLUMNS . ' FROM 202_attribution_models WHERE user_id = ? AND status = ? ORDER BY model_id'
        );
        $this->conn->bind($stmt, 'is', [$userId, ModelStatus::Active->value]);

        $models = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            try {
                $models[] = self::fromRow($row);
            } catch (InvalidModelConfig $e) {
                $this->markInvalid($userId, (int) $row['model_id'], $e->getMessage(), $row);
            }
        }

        return $models;
    }

    /**
     * active → invalid, with the model's credits removed. With $asRead (the
     * row whose definition failed), only while the model still holds that
     * definition: an edit made since is not marked for what it replaced.
     *
     * False when nothing was marked: the model is not this account's, is no
     * longer active, or has another definition now. Someone else moved it
     * first, and their write stands.
     *
     * @param array<string, mixed>|null $asRead
     */
    public function markInvalid(int $userId, int $modelId, string $reason, ?array $asRead = null): bool
    {
        [$to, $from] = self::move(ModelStatus::Active, ModelStatus::Invalid);
        $sql = 'UPDATE 202_attribution_models SET status = ?, status_reason = ?, updated_at = ? WHERE model_id = ? AND user_id = ? AND status = ?';
        $types = 'ssiiis';
        $binds = [$to, mb_substr($reason, 0, 255), time(), $modelId, $userId, $from];
        if ($asRead !== null) {
            // Byte for byte: the collation would call `{"A": 1}` and `{"a": 1}` one definition.
            $sql .= ' AND model_type = ? AND weighting_config = BINARY ? AND lookback_days = ?';
            $types .= 'ssi';
            array_push($binds, (string) $asRead['model_type'], (string) $asRead['weighting_config'], (int) $asRead['lookback_days']);
        }
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, $types, $binds);
        if ($this->conn->executeUpdate($stmt) !== 1) {
            return false;
        }
        $this->deleteCredits($userId, $modelId);
        if ((int) ($this->defaultRow($userId)['model_id'] ?? 0) === $modelId) {
            error_log('attribution: the default model ' . $modelId . ' of account ' . $userId . ' was marked invalid (' . $reason
                . '); reports with no model named are refused until it is edited or another model is the default');
        }

        return true;
    }

    /**
     * @param array<string, float> $config
     * @throws InvalidModelConfig when the definition does not validate
     * @throws \DomainException for a state no model is created in, or a default that is not active
     * @throws \Prosper202\Lifecycle\UnknownState for a status that is not one
     */
    public function insert(int $userId, string $name, string $slug, ModelType $type, array $config, int $lookbackDays, ModelStatus|string $status, bool $isDefault): int
    {
        $status = $status instanceof ModelStatus ? $status : ModelStatus::ofStored($status, 'the status asked for a new attribution model');
        if (!in_array($status, ModelStatus::initial(), true)) {
            throw new \DomainException('An attribution model is not created ' . $status->value . '.');
        }
        self::refuseDefaultThatIsNot($status, $isDefault);
        $config = ModelConfig::normalize($type, $config);
        ModelConfig::lookbackDays($lookbackDays);
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
            $userId, $name, $slug, $type->value, ModelConfig::encode($config), $lookbackDays, $status->value,
            $isDefault ? 1 : null,
            $status->computes() ? $now : null,
            $now, $now,
        ]);
        $id = $this->conn->executeInsert($stmt);
        if ($id <= 0) {
            throw new \RuntimeException('the attribution model was not inserted');
        }

        return $id;
    }

    /**
     * Rewrite a model that is in $from (the state the caller read and decided
     * on; read here when not given): its definition, and $status when the
     * lifecycle holds that move. The definition is validated here, so a model
     * is written active only with one the worker can compute. A model becomes
     * the default with $isDefault (inside the caller's transaction, since the
     * old default is cleared first) and never stops being it here.
     *
     * False when nothing was written and nothing changed: the model is not
     * this account's, is no longer in $from, or is the default and was not
     * asked to stay it.
     *
     * @param array<string, float> $config
     * @throws InvalidModelConfig when the definition does not validate
     * @throws \Prosper202\Lifecycle\IllegalTransition
     * @throws \Prosper202\Lifecycle\UnknownState for a status, asked for or stored, that is not one
     * @throws \DomainException for a default that is not active, or a move to invalid (markInvalid()'s alone)
     */
    public function update(int $userId, int $modelId, string $name, string $slug, ModelType $type, array $config, int $lookbackDays, ModelStatus|string $status, bool $isDefault, bool $recompute, ?ModelStatus $from = null): bool
    {
        $status = $status instanceof ModelStatus ? $status : ModelStatus::ofStored($status, 'the status asked for attribution model ' . $modelId);
        if ($from === null) {
            $row = $this->row($userId, $modelId);
            if ($row === null) {
                return false;
            }
            $from = ModelStatus::ofStored($row['status'], 'attribution model ' . $modelId);
        }
        if ($status === ModelStatus::Invalid) {
            throw new \DomainException('An edit does not make an attribution model invalid; the engine does, when its stored definition fails.');
        }
        if ($from !== $status) {
            $from->moveTo($status);
        }
        self::refuseDefaultThatIsNot($status, $isDefault);
        $config = ModelConfig::normalize($type, $config);
        ModelConfig::lookbackDays($lookbackDays);
        $recompute = $recompute && $status->computes();

        $write = function () use ($userId, $modelId, $from, $name, $slug, $type, $config, $lookbackDays, $status, $isDefault, $recompute): bool {
            $now = time();
            if ($isDefault) {
                $this->clearDefault($userId, $modelId);
            }
            $stmt = $this->conn->prepareWrite(
                'UPDATE 202_attribution_models
                 SET model_name = ?, model_slug = ?, model_type = ?, weighting_config = ?, lookback_days = ?,
                     status = ?, status_reason = NULL, is_default = IF(?, 1, is_default), updated_at = ?,
                     recompute_requested_at = IF(?, ?, recompute_requested_at),
                     recompute_cursor = IF(?, 0, recompute_cursor),
                     recompute_failures = IF(?, 0, recompute_failures),
                     recompute_retry_at = IF(?, 0, recompute_retry_at),
                     recompute_error = IF(?, NULL, recompute_error)
                 WHERE model_id = ? AND user_id = ? AND status = ? AND (? OR is_default IS NULL)'
            );
            $this->conn->bind($stmt, 'ssssisiiiiiiiiiisi', [
                $name, $slug, $type->value, ModelConfig::encode($config), $lookbackDays,
                $status->value, $isDefault ? 1 : 0, $now,
                $recompute ? 1 : 0, $now,
                $recompute ? 1 : 0,
                $recompute ? 1 : 0,
                $recompute ? 1 : 0,
                $recompute ? 1 : 0,
                $modelId, $userId, $from->value, $isDefault ? 1 : 0,
            ]);
            if ($this->conn->executeUpdate($stmt) === 1) {
                return true;
            }
            // No row changed: one that already held every value written (the
            // same edit again within the second) reads as one that matched none.
            $row = $this->row($userId, $modelId);

            return $row !== null && $from === $status && (string) $row['status'] === $status->value
                && ((int) ($row['is_default'] ?? 0) === 1) === $isDefault && (int) $row['updated_at'] === $now;
        };

        if ($isDefault) {
            // The old default is cleared before the row is known to take it:
            // undone when it does not, so the account keeps a default.
            $refused = new \RuntimeException('not written');
            try {
                $this->conn->withSavepoint(static function () use ($write, $refused): void {
                    if (!$write()) {
                        throw $refused;
                    }
                });
            } catch (\RuntimeException $e) {
                if ($e !== $refused) {
                    throw $e;
                }

                return false;
            }
        } elseif (!$write()) {
            return false;
        }

        if (!$status->computes()) {
            $this->deleteCredits($userId, $modelId);
        }

        return true;
    }

    public function delete(int $userId, int $modelId): void
    {
        $this->deleteCredits($userId, $modelId);
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

    /**
     * A move the lifecycle holds, as the values its UPDATE binds: the state
     * it sets and the one its WHERE names.
     *
     * @return array{0: string, 1: string}
     */
    private static function move(ModelStatus $from, ModelStatus $to): array
    {
        return [$from->moveTo($to)->value, $from->value];
    }

    private static function refuseDefaultThatIsNot(ModelStatus $status, bool $isDefault): void
    {
        if ($isDefault && !$status->computes()) {
            throw new \DomainException('The default attribution model must be active; it was asked to be ' . $status->value . '.');
        }
    }

    private function clearDefault(int $userId, int $exceptModelId = 0): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_attribution_models SET is_default = NULL WHERE user_id = ? AND is_default = 1 AND model_id <> ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $exceptModelId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The model's credits, and its rows in the report rollup with them: a
     * model with no credits sums to nothing in every hour, so the rollup is
     * exact without them rather than dirty. Recomputing the model later
     * rewrites its credits through the worker, which marks each hour.
     */
    private function deleteCredits(int $userId, int $modelId): void
    {
        // Credits carry no account: they are the account's through their model.
        $stmt = $this->conn->prepareWrite(
            'DELETE c FROM 202_attribution_credits c JOIN 202_attribution_models m ON m.model_id = c.model_id
             WHERE c.model_id = ? AND m.user_id = ?'
        );
        $this->conn->bind($stmt, 'ii', [$modelId, $userId]);
        $this->conn->executeUpdate($stmt);
        $stmt = $this->conn->prepareWrite(
            'DELETE r FROM 202_attribution_rollup r JOIN 202_attribution_models m ON m.model_id = r.model_id AND m.user_id = r.user_id
             WHERE r.model_id = ? AND r.user_id = ? AND r.part IN (?, ?)'
        );
        $this->conn->bind($stmt, 'iiii', [$modelId, $userId, AttributionRollup::PART_CREDITS, AttributionRollup::PART_TOTALS]);
        $this->conn->executeUpdate($stmt);
    }
}

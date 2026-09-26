<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\InvalidModelConfig;
use Prosper202\Attribution\Model;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Prosper202\Database\Connection;

/**
 * Multi-touch attribution over v3 (plan §6.3): models, and the reports over
 * the credits the attribution worker computes.
 *
 * The model list is ModelType; a value outside it is refused with the list
 * in the message. Input is read exactly as sent — a lookback of "30" (a
 * string) or a weighting config sent as a JSON string is refused, not cast
 * (CLAUDE.md error patterns #4 and #18). Every change that alters what a
 * model computes is recorded and handed to the worker, which recomputes
 * that model's credits; the response says so with `recompute_pending`.
 */
class AttributionController
{
    private const PERIODS = ['today', 'yesterday', 'last7', 'last30', 'last90'];

    private Connection $conn;
    private ModelRepository $models;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($db);
        $this->models = new ModelRepository($this->conn);
    }

    // --- Models ---

    public function listModels(array $params): array
    {
        $type = $params['type'] ?? null;
        if ($type !== null && $type !== '' && ModelType::tryFrom((string) $type) === null) {
            throw new ValidationException('Invalid type', ['type' => 'Valid: ' . implode(', ', ModelType::values())]);
        }
        $rows = [];
        foreach ($this->models->rows($this->userId) as $row) {
            if ($type !== null && $type !== '' && $row['model_type'] !== $type) {
                continue;
            }
            $rows[] = self::present($row);
        }

        return ['data' => $rows];
    }

    public function getModel(int $id): array
    {
        return ['data' => self::present($this->requireModelRow($id))];
    }

    public function createModel(array $payload): array
    {
        $name = self::requiredName($payload);
        $typeValue = $payload['model_type'] ?? null;
        if (!is_string($typeValue) || $typeValue === '') {
            throw new ValidationException('model_type is required', ['model_type' => 'Valid: ' . implode(', ', ModelType::values())]);
        }
        $type = self::type($typeValue);
        $config = self::config($type, array_key_exists('weighting_config', $payload) ? $payload['weighting_config'] : null);
        $lookback = self::lookback($payload['lookback_days'] ?? null);
        $status = self::status($payload['status'] ?? Model::STATUS_ACTIVE);
        $isDefault = self::flag($payload, 'is_default') ?? false;
        if ($isDefault && $status !== Model::STATUS_ACTIVE) {
            throw new ValidationException('The default model must be active', ['is_default' => 'A default model must have status active']);
        }
        $slug = ModelRepository::slugFor($name);

        $id = $this->conn->transaction(function () use ($name, $slug, $type, $config, $lookback, $status, $isDefault): int {
            $taken = $this->models->slugTaken($this->userId, $slug);
            if ($taken !== null) {
                throw new ConflictException(
                    'A model named like "' . $name . '" already exists (model ' . $taken . '); update it, or choose another name.',
                    ['model_id' => $taken]
                );
            }
            $id = $this->models->insert($this->userId, $name, $slug, $type, $config, $lookback, $status, $isDefault);
            $this->audit($id, 'model_created', ['model_type' => $type->value, 'is_default' => $isDefault]);

            return $id;
        });

        try {
            return $this->getModel($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('attribution model', $e);
        }
    }

    public function updateModel(int $id, array $payload): array
    {
        $known = ['model_name', 'model_type', 'weighting_config', 'lookback_days', 'status', 'is_default'];
        $unknown = array_diff(array_keys($payload), $known);
        if ($unknown !== []) {
            throw new ValidationException(
                'Unknown field(s): ' . implode(', ', $unknown),
                array_fill_keys(array_values($unknown), 'Not a model field; valid: ' . implode(', ', $known))
            );
        }
        if ($payload === []) {
            throw new ValidationException('No fields to update', ['model' => 'Send at least one of: ' . implode(', ', $known)]);
        }

        $this->conn->transaction(function () use ($id, $payload): void {
            $row = $this->models->row($this->userId, $id, true);
            if ($row === null) {
                throw new NotFoundException('Attribution model not found');
            }

            $name = array_key_exists('model_name', $payload) ? self::requiredName($payload) : (string) $row['model_name'];
            $typeChanged = array_key_exists('model_type', $payload) && $payload['model_type'] !== $row['model_type'];
            $type = array_key_exists('model_type', $payload)
                ? self::type(is_string($payload['model_type']) ? $payload['model_type'] : '')
                : self::type((string) $row['model_type']);

            if (array_key_exists('weighting_config', $payload)) {
                $config = self::config($type, $payload['weighting_config']);
            } elseif ($typeChanged) {
                // The old type's parameters mean nothing to the new one.
                $config = self::config($type, null);
            } else {
                $config = self::storedConfig($type, (string) $row['weighting_config']);
            }
            $lookback = array_key_exists('lookback_days', $payload)
                ? self::lookback($payload['lookback_days'])
                : (int) $row['lookback_days'];

            $wasDefault = (int) ($row['is_default'] ?? 0) === 1;
            $isDefault = self::flag($payload, 'is_default') ?? $wasDefault;
            if ($wasDefault && !$isDefault) {
                throw new ValidationException(
                    'Every account has one default model',
                    ['is_default' => 'Make another model the default (is_default: true on it) instead of unsetting this one']
                );
            }
            // A model whose stored definition had gone invalid becomes active
            // again once this update has validated a whole definition.
            $status = array_key_exists('status', $payload)
                ? self::status($payload['status'])
                : ((string) $row['status'] === Model::STATUS_INVALID ? Model::STATUS_ACTIVE : (string) $row['status']);
            if ($isDefault && $status !== Model::STATUS_ACTIVE) {
                throw new ValidationException(
                    'The default model must be active',
                    ['status' => 'Make another model the default before deactivating this one']
                );
            }

            $slug = ModelRepository::slugFor($name);
            $taken = $this->models->slugTaken($this->userId, $slug, $id);
            if ($taken !== null) {
                throw new ConflictException(
                    'A model named like "' . $name . '" already exists (model ' . $taken . ').',
                    ['model_id' => $taken]
                );
            }

            $computes = $status === Model::STATUS_ACTIVE;
            $changed = $type->value !== $row['model_type']
                || ModelConfig::encode($config) !== self::normalizedStored($row)
                || $lookback !== (int) $row['lookback_days']
                || ($computes && (string) $row['status'] !== Model::STATUS_ACTIVE);

            $this->models->update($this->userId, $id, $name, $slug, $type, $config, $lookback, $status, $isDefault, $computes && $changed);
            $this->audit($id, 'model_updated', ['fields' => array_keys($payload), 'recompute' => $computes && $changed]);
        });

        return $this->getModel($id);
    }

    public function deleteModelPreview(int $id): array
    {
        $row = $this->requireModelRow($id);
        $campaigns = $this->count(
            'SELECT COUNT(*) AS c FROM 202_aff_campaigns WHERE user_id = ? AND attribution_model_id = ?',
            [$this->userId, $id]
        );
        $exports = $this->count(
            'SELECT COUNT(*) AS c FROM 202_attribution_exports WHERE user_id = ? AND model_id = ?',
            [$this->userId, $id]
        );

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'attribution-models',
            'mode' => 'hard',
            'record' => self::present($row),
            'refused' => (int) ($row['is_default'] ?? 0) === 1
                ? 'This is the default model; make another model the default first.'
                : null,
            'cascade' => [
                ['resource' => 'attribution-credits', 'count' => $this->models->creditCount($id)],
                ['resource' => 'attribution-exports', 'count' => $exports],
                ['resource' => 'campaign-model-overrides-cleared', 'count' => $campaigns],
            ],
        ]];
    }

    public function deleteModel(int $id): void
    {
        $this->conn->transaction(function () use ($id): void {
            $row = $this->models->row($this->userId, $id, true);
            if ($row === null) {
                throw new NotFoundException('Attribution model not found');
            }
            if ((int) ($row['is_default'] ?? 0) === 1) {
                throw new ConflictException(
                    'Model ' . $id . ' is the default model and cannot be deleted; make another model the default first.',
                    ['model_id' => $id]
                );
            }
            $this->models->delete($this->userId, $id);
            $this->audit($id, 'model_deleted', ['model_type' => (string) $row['model_type']]);
        });
    }

    // --- Reports ---

    /**
     * GET /attribution/reports/breakdown
     */
    public function breakdown(array $params): array
    {
        self::rejectUnknown($params, ['group_by', 'model_id', 'compare_model_id', 'time_from', 'time_to', 'period', 'limit']);
        $groupBy = (string) ($params['group_by'] ?? 'campaign');
        if (!in_array($groupBy, AttributionReports::dimensions(), true)) {
            throw new ValidationException('Invalid group_by', ['group_by' => 'Valid: ' . implode(', ', AttributionReports::dimensions())]);
        }
        [$from, $to] = self::range($params);
        $limit = self::positiveInt($params, 'limit') ?? 100;
        if ($limit > AttributionReports::MAX_LIMIT) {
            throw new ValidationException('limit too large', ['limit' => 'At most ' . AttributionReports::MAX_LIMIT]);
        }

        $default = $this->models->defaultRow($this->userId);
        if ($default === null) {
            throw new ConflictException('This account has no default attribution model; create one with is_default: true.');
        }
        $modelId = self::positiveInt($params, 'model_id');
        $model = $modelId !== null ? $this->reportableModel($modelId, 'model_id') : null;
        $compareId = self::positiveInt($params, 'compare_model_id');
        $compare = null;
        if ($compareId !== null) {
            $compare = $this->reportableModel($compareId, 'compare_model_id');
            if ($compareId === $modelId) {
                throw new ValidationException('compare_model_id must differ from model_id', ['compare_model_id' => 'Pick a different model to compare against']);
            }
        }

        $result = (new AttributionReports($this->conn))->breakdown(
            $this->userId,
            $modelId,
            $compareId,
            (int) $default['model_id'],
            $groupBy,
            $from,
            $to,
            $limit
        );

        return [
            'data' => $result['rows'],
            'totals' => $result['totals'],
            'meta' => [
                'group_by' => $groupBy,
                'time_from' => $from,
                'time_to' => $to,
                'model' => $model !== null ? self::present($model) : [
                    'mode' => 'effective',
                    'description' => "Each conversion under its campaign's model override when that model is active, otherwise the account default.",
                    'default_model_id' => (int) $default['model_id'],
                ],
                'compare_model' => $compare !== null ? self::present($compare) : null,
            ],
        ];
    }

    /**
     * GET /attribution/reports/journeys
     */
    public function journeyMetrics(array $params): array
    {
        self::rejectUnknown($params, ['time_from', 'time_to', 'period']);
        [$from, $to] = self::range($params);

        return [
            'data' => (new AttributionReports($this->conn))->journeyMetrics($this->userId, $from, $to),
            'meta' => ['time_from' => $from, 'time_to' => $to],
        ];
    }

    /**
     * GET /attribution/conversions/{id}/journey
     */
    public function journey(int $convId): array
    {
        $journey = (new AttributionReports($this->conn))->journey($this->userId, $convId);
        if ($journey === null) {
            throw new NotFoundException('Conversion not found');
        }

        return ['data' => $journey];
    }

    /**
     * GET /attribution/queue
     */
    public function queue(array $params): array
    {
        self::rejectUnknown($params, ['limit']);
        $limit = self::positiveInt($params, 'limit') ?? 50;

        return ['data' => (new AttributionReports($this->conn))->queue($this->userId, min(500, $limit))];
    }

    // --- Helpers ---

    /** @return array<string, mixed> */
    private function requireModelRow(int $id): array
    {
        $row = $this->models->row($this->userId, $id);
        if ($row === null) {
            throw new NotFoundException('Attribution model not found');
        }

        return $row;
    }

    /**
     * A model a report can read: the account's, and currently computed.
     *
     * @return array<string, mixed>
     */
    private function reportableModel(int $id, string $field): array
    {
        $row = $this->models->row($this->userId, $id);
        if ($row === null) {
            throw new ValidationException('Unknown ' . $field, [$field => 'No model ' . $id . ' in this account; list them with GET /attribution/models']);
        }
        if ((string) $row['status'] !== Model::STATUS_ACTIVE) {
            throw new ConflictException(
                'Model ' . $id . ' is ' . $row['status'] . ' and has no credits'
                . ($row['status_reason'] !== null ? ' (' . $row['status_reason'] . ')' : '')
                . '; activate or fix it first.',
                ['model_id' => $id, 'status' => $row['status']]
            );
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $config = null;
        try {
            $decoded = json_decode((string) $row['weighting_config'], true, 16, JSON_THROW_ON_ERROR);
            $config = is_array($decoded) ? (object) $decoded : null;
        } catch (\JsonException) {
            $config = null;
        }

        return [
            'model_id' => (int) $row['model_id'],
            'model_name' => (string) $row['model_name'],
            'model_slug' => (string) $row['model_slug'],
            'model_type' => (string) $row['model_type'],
            'weighting_config' => $config ?? (string) $row['weighting_config'],
            'lookback_days' => (int) $row['lookback_days'],
            'status' => (string) $row['status'],
            'status_reason' => $row['status_reason'],
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'recompute_pending' => $row['recompute_requested_at'] !== null,
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
    }

    private static function requiredName(array $payload): string
    {
        $name = $payload['model_name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ValidationException('model_name is required', ['model_name' => 'A non-empty string']);
        }
        $name = trim($name);
        if (mb_strlen($name) > 255) {
            throw new ValidationException('model_name too long', ['model_name' => 'At most 255 characters']);
        }

        return $name;
    }

    private static function type(string $value): ModelType
    {
        $type = ModelType::tryFrom($value);
        if ($type === null) {
            throw new ValidationException(
                'Invalid model_type',
                ['model_type' => 'Valid: ' . implode(', ', ModelType::values())]
            );
        }

        return $type;
    }

    /** @return array<string, float> */
    private static function config(ModelType $type, mixed $config): array
    {
        try {
            return ModelConfig::normalize($type, $config);
        } catch (InvalidModelConfig $e) {
            throw new ValidationException('Invalid weighting_config for ' . $type->value, $e->fieldErrors());
        }
    }

    /** @return array<string, float> */
    private static function storedConfig(ModelType $type, string $json): array
    {
        try {
            return ModelConfig::fromStored($type->value, $json, ModelConfig::DEFAULT_LOOKBACK_DAYS)['config'];
        } catch (InvalidModelConfig $e) {
            throw new ValidationException(
                'The stored weighting_config is invalid; send a new weighting_config with this update',
                $e->fieldErrors()
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function normalizedStored(array $row): string
    {
        try {
            return ModelConfig::encode(ModelConfig::fromStored((string) $row['model_type'], (string) $row['weighting_config'], $row['lookback_days'])['config']);
        } catch (InvalidModelConfig) {
            return '';
        }
    }

    private static function lookback(mixed $value): int
    {
        try {
            return ModelConfig::lookbackDays($value);
        } catch (InvalidModelConfig $e) {
            throw new ValidationException('Invalid lookback_days', $e->fieldErrors());
        }
    }

    private static function status(mixed $value): string
    {
        if ($value !== Model::STATUS_ACTIVE && $value !== Model::STATUS_INACTIVE) {
            throw new ValidationException('Invalid status', ['status' => 'Valid: active, inactive (invalid is set by the engine, not by a request)']);
        }

        return $value;
    }

    /** A boolean field: true/false, or the integers 1/0. Anything else is refused. */
    private static function flag(array $payload, string $field): ?bool
    {
        if (!array_key_exists($field, $payload)) {
            return null;
        }
        $v = $payload[$field];
        if ($v === true || $v === 1) {
            return true;
        }
        if ($v === false || $v === 0) {
            return false;
        }
        throw new ValidationException('Invalid ' . $field, [$field => 'true or false']);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $known
     */
    private static function rejectUnknown(array $params, array $known): void
    {
        $unknown = array_diff(array_keys($params), $known);
        if ($unknown !== []) {
            throw new ValidationException(
                'Unknown parameter(s): ' . implode(', ', $unknown),
                array_fill_keys(array_values($unknown), 'Valid parameters: ' . implode(', ', $known))
            );
        }
    }

    private static function positiveInt(array $params, string $field): ?int
    {
        if (!array_key_exists($field, $params) || $params[$field] === '') {
            return null;
        }
        $v = $params[$field];
        if (!is_string($v) && !is_int($v)) {
            throw new ValidationException('Invalid ' . $field, [$field => 'A positive whole number']);
        }
        $v = (string) $v;
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $v) !== 1) {
            throw new ValidationException('Invalid ' . $field, [$field => 'A positive whole number']);
        }

        return (int) $v;
    }

    /**
     * The report range: time_from/time_to (unix seconds), or a period, or
     * the last 30 days.
     *
     * @return array{0: int, 1: int}
     */
    private static function range(array $params): array
    {
        $hasPeriod = isset($params['period']) && $params['period'] !== '';
        $hasTimes = (isset($params['time_from']) && $params['time_from'] !== '') || (isset($params['time_to']) && $params['time_to'] !== '');
        if ($hasPeriod && $hasTimes) {
            throw new ValidationException('period and time_from/time_to are exclusive', ['period' => 'Send a period or a time range, not both']);
        }
        $now = time();
        if ($hasPeriod) {
            $period = (string) $params['period'];
            $todayStart = strtotime('today midnight');
            return match ($period) {
                'today' => [$todayStart, $now],
                'yesterday' => [$todayStart - 86400, $todayStart - 1],
                'last7' => [$now - 7 * 86400, $now],
                'last30' => [$now - 30 * 86400, $now],
                'last90' => [$now - 90 * 86400, $now],
                default => throw new ValidationException('Invalid period', ['period' => 'Valid: ' . implode(', ', self::PERIODS)]),
            };
        }
        $from = self::timestamp($params, 'time_from') ?? $now - 30 * 86400;
        $to = self::timestamp($params, 'time_to') ?? $now;
        if ($from > $to) {
            throw new ValidationException('time_from is after time_to', ['time_from' => 'Must not be after time_to']);
        }

        return [$from, $to];
    }

    private static function timestamp(array $params, string $field): ?int
    {
        if (!isset($params[$field]) || $params[$field] === '') {
            return null;
        }
        $v = $params[$field];
        if ((!is_string($v) && !is_int($v)) || preg_match('/^[0-9]{1,10}$/D', (string) $v) !== 1) {
            throw new ValidationException('Invalid ' . $field, [$field => 'Unix time in seconds']);
        }

        return (int) $v;
    }

    /** @param list<int> $binds */
    private function count(string $sql, array $binds): int
    {
        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, str_repeat('i', count($binds)), $binds);
        $row = $this->conn->fetchOne($stmt);

        return (int) ($row['c'] ?? 0);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(int $modelId, string $action, array $metadata): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_audit (user_id, model_id, action, metadata, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'iissi', [$this->userId, $modelId, $action, json_encode($metadata, JSON_THROW_ON_ERROR), time()]);
        $this->conn->executeUpdate($stmt);
    }
}

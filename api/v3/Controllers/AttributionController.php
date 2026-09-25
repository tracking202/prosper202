<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ExportFiles;
use Prosper202\Attribution\ExportStore;
use Prosper202\Attribution\InvalidModelConfig;
use Prosper202\Attribution\Model;
use Prosper202\Attribution\ModelConfig;
use Prosper202\Attribution\ModelRepository;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookRefused;
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
            'SELECT COUNT(*) AS c FROM 202_attribution_exports WHERE user_id = ? AND (model_id = ? OR compare_model_id = ?)',
            [$this->userId, $id, $id]
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
        $files = [];
        $this->conn->transaction(function () use ($id, &$files): void {
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
            $files = (new ExportStore($this->conn))->fileNames($this->userId, $id);
            $this->models->delete($this->userId, $id);
            $this->audit($id, 'model_deleted', ['model_type' => (string) $row['model_type']]);
        });
        // After the commit: a rolled-back delete must not lose its files.
        $this->removeFiles($files);
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
                // How many groups the report has; more than `limit` means
                // the rows above are the top `limit` by attributed revenue.
                'groups' => $result['groups'],
                'limit' => $limit,
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

    // --- Exports ---

    /** How far ahead an export may be scheduled. */
    public const MAX_SCHEDULE_AHEAD = 366 * 86400;

    /**
     * GET /attribution/exports
     */
    public function listExports(array $params): array
    {
        self::rejectUnknown($params, ['status', 'limit']);
        $status = isset($params['status']) && $params['status'] !== '' ? (string) $params['status'] : null;
        if ($status !== null && !in_array($status, ExportStore::STATUSES, true)) {
            throw new ValidationException('Invalid status', ['status' => 'Valid: ' . implode(', ', ExportStore::STATUSES)]);
        }
        $limit = self::positiveInt($params, 'limit') ?? 50;
        if ($limit > 200) {
            throw new ValidationException('limit too large', ['limit' => 'At most 200']);
        }

        return ['data' => array_map([self::class, 'presentExport'], (new ExportStore($this->conn))->rows($this->userId, $status, $limit))];
    }

    /**
     * GET /attribution/exports/{id}
     */
    public function getExport(int $id): array
    {
        return ['data' => self::presentExport($this->requireExportRow($id))];
    }

    /**
     * POST /attribution/exports
     *
     * Fields: group_by (default campaign), model_id (default: the account
     * default, stored as its id so the job reads a fixed model),
     * compare_model_id, time_from/time_to or period (default the last 30
     * days), run_at (unix seconds; default now), webhook_url and
     * webhook_secret (generated when a webhook is given without one). Every
     * number is a JSON number, read as sent (CLAUDE.md error pattern #18).
     */
    public function createExport(array $payload): array
    {
        $known = ['group_by', 'model_id', 'compare_model_id', 'time_from', 'time_to', 'period', 'run_at', 'webhook_url', 'webhook_secret'];
        $unknown = array_diff(array_keys($payload), $known);
        if ($unknown !== []) {
            throw new ValidationException(
                'Unknown field(s): ' . implode(', ', $unknown),
                array_fill_keys(array_values($unknown), 'Not an export field; valid: ' . implode(', ', $known))
            );
        }

        $groupBy = $payload['group_by'] ?? 'campaign';
        if (!is_string($groupBy) || !in_array($groupBy, AttributionReports::dimensions(), true)) {
            throw new ValidationException('Invalid group_by', ['group_by' => 'Valid: ' . implode(', ', AttributionReports::dimensions())]);
        }

        $rangeParams = [];
        foreach (['time_from', 'time_to', 'period'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($field === 'period' ? !is_string($value) : (!is_int($value) || $value < 0)) {
                throw new ValidationException('Invalid ' . $field, [$field => $field === 'period' ? 'One of: ' . implode(', ', self::PERIODS) : 'Unix time in seconds, as a JSON number']);
            }
            $rangeParams[$field] = (string) $value;
        }
        [$from, $to] = self::range($rangeParams);

        $default = $this->models->defaultRow($this->userId);
        if ($default === null) {
            throw new ConflictException('This account has no default attribution model; create one with is_default: true.');
        }
        $modelId = self::bodyId($payload, 'model_id') ?? (int) $default['model_id'];
        $this->reportableModel($modelId, 'model_id');
        $compareId = self::bodyId($payload, 'compare_model_id');
        if ($compareId !== null) {
            if ($compareId === $modelId) {
                throw new ValidationException('compare_model_id must differ from model_id', ['compare_model_id' => 'Pick a different model to compare against']);
            }
            $this->reportableModel($compareId, 'compare_model_id');
        }

        $now = time();
        $runAt = $now;
        if (array_key_exists('run_at', $payload) && $payload['run_at'] !== null) {
            if (!is_int($payload['run_at']) || $payload['run_at'] < 0) {
                throw new ValidationException('Invalid run_at', ['run_at' => 'Unix time in seconds, as a JSON number']);
            }
            if ($payload['run_at'] > $now + self::MAX_SCHEDULE_AHEAD) {
                throw new ValidationException('run_at too far ahead', ['run_at' => 'At most a year from now']);
            }
            // A time already past means "as soon as possible", not an error.
            $runAt = max($now, $payload['run_at']);
        }

        [$webhookUrl, $secret] = self::webhook($payload);

        $id = (new ExportStore($this->conn))->insert($this->userId, $modelId, $compareId, $groupBy, $from, $to, $webhookUrl, $secret, $runAt);

        try {
            $this->audit($modelId, 'export_created', ['export_id' => $id, 'group_by' => $groupBy, 'webhook' => $webhookUrl !== null]);
            $out = $this->getExport($id);
        } catch (\Throwable $e) {
            throw new WriteCommittedException('attribution export', $e);
        }
        if ($secret !== null) {
            // The only time the secret is returned: the receiver needs it to
            // check the signature, and no later read shows it again.
            $out['data']['webhook_secret'] = $secret;
        }

        return $out;
    }

    /**
     * GET /attribution/exports/{id}/download — the file, as text/csv.
     *
     * @return array{_file: array{body: string, filename: string, content_type: string}}
     */
    public function downloadExport(int $id): array
    {
        $row = $this->requireExportRow($id);
        $name = $row['file_path'] !== null ? (string) $row['file_path'] : '';
        if ($name === '') {
            throw new ConflictException(
                'Export ' . $id . ' has no file yet (status ' . $row['status'] . '); it is written when the export runs.',
                ['export_id' => $id, 'status' => $row['status']]
            );
        }
        try {
            $body = (new ExportFiles())->read($name);
        } catch (\RuntimeException $e) {
            // A corrupt name (UnexpectedValueException) or an unreadable file.
            throw new ConflictException('Export ' . $id . ': ' . $e->getMessage(), ['export_id' => $id]);
        }
        if ($body === null) {
            throw new ConflictException(
                'The file for export ' . $id . ' is no longer on disk; retry the export to write it again.',
                ['export_id' => $id]
            );
        }

        return ['_file' => [
            'body' => $body,
            'filename' => 'attribution-' . preg_replace('/[^a-z0-9_]/', '', (string) $row['group_by']) . '-export-' . $id . '.csv',
            'content_type' => 'text/csv; charset=utf-8',
        ]];
    }

    /**
     * POST /attribution/exports/{id}/retry — a failed export, queued again.
     */
    public function retryExport(int $id): array
    {
        $row = $this->requireExportRow($id);
        if (!(new ExportStore($this->conn))->retry($this->userId, $id)) {
            throw new ConflictException(
                'Only a failed export can be retried; export ' . $id . ' is ' . $row['status'] . '.',
                ['export_id' => $id, 'status' => $row['status']]
            );
        }

        return $this->getExport($id);
    }

    public function deleteExportPreview(int $id): array
    {
        $row = $this->requireExportRow($id);

        return ['data' => [
            'dry_run' => true,
            'action' => 'delete',
            'resource' => 'attribution-exports',
            'mode' => 'hard',
            'record' => self::presentExport($row),
            'refused' => $row['status'] === 'running' ? 'The export is running; delete it once it has finished.' : null,
            'cascade' => [
                ['resource' => 'export-files', 'count' => $row['file_path'] !== null ? 1 : 0],
            ],
        ]];
    }

    /**
     * DELETE /attribution/exports/{id} — the row and its file.
     */
    public function deleteExport(int $id): void
    {
        $row = $this->requireExportRow($id);
        if (!(new ExportStore($this->conn))->delete($this->userId, $id)) {
            throw new ConflictException(
                'Export ' . $id . ' is running and cannot be deleted until it has finished; try again in a minute.',
                ['export_id' => $id, 'status' => 'running']
            );
        }
        $this->removeFiles($row['file_path'] !== null ? [(string) $row['file_path']] : []);
    }

    /** @return array<string, mixed> */
    private function requireExportRow(int $id): array
    {
        $row = (new ExportStore($this->conn))->row($this->userId, $id);
        if ($row === null) {
            throw new NotFoundException('Attribution export not found');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentExport(array $row): array
    {
        $hasFile = $row['file_path'] !== null && $row['file_path'] !== '';

        return [
            'export_id' => (int) $row['export_id'],
            'model_id' => (int) $row['model_id'],
            'compare_model_id' => $row['compare_model_id'] !== null ? (int) $row['compare_model_id'] : null,
            'group_by' => (string) $row['group_by'],
            'time_from' => (int) $row['range_start'],
            'time_to' => (int) $row['range_end'],
            'status' => (string) $row['status'],
            'rows_exported' => $row['rows_exported'] !== null ? (int) $row['rows_exported'] : null,
            'file_ready' => $hasFile,
            'download_path' => $hasFile ? '/attribution/exports/' . (int) $row['export_id'] . '/download' : null,
            'webhook_url' => $row['webhook_url'],
            'webhook_signed' => $row['webhook_url'] !== null && (string) $row['webhook_secret'] !== '',
            'webhook_status_code' => $row['webhook_status_code'] !== null ? (int) $row['webhook_status_code'] : null,
            'attempts' => (int) $row['attempts'],
            'last_error' => $row['last_error'],
            'run_at' => (int) $row['queued_at'],
            'started_at' => $row['started_at'] !== null ? (int) $row['started_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
            'created_at' => (int) $row['created_at'],
        ];
    }

    /**
     * The webhook URL, checked now so the person saving it is told (the
     * sender checks again at send time), and its signing secret.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function webhook(array $payload): array
    {
        $url = $payload['webhook_url'] ?? null;
        $secret = $payload['webhook_secret'] ?? null;
        if ($url === null || $url === '') {
            if ($secret !== null && $secret !== '') {
                throw new ValidationException('webhook_secret without webhook_url', ['webhook_secret' => 'A secret signs webhook deliveries; send webhook_url with it, or leave both out']);
            }

            return [null, null];
        }
        if (!is_string($url)) {
            throw new ValidationException('Invalid webhook_url', ['webhook_url' => 'An https:// URL, as a string']);
        }
        try {
            (new WebhookGuard())->check($url);
        } catch (WebhookRefused $e) {
            throw new ValidationException('webhook_url refused', ['webhook_url' => $e->getMessage()]);
        } catch (\UnexpectedValueException $e) {
            throw new ValidationException('Webhooks are unavailable on this server', ['webhook_url' => $e->getMessage()]);
        }

        if ($secret === null || $secret === '') {
            return [$url, bin2hex(random_bytes(32))];
        }
        if (!is_string($secret) || preg_match('/^[\x21-\x7E]{16,255}$/D', $secret) !== 1) {
            throw new ValidationException('Invalid webhook_secret', ['webhook_secret' => '16 to 255 printable ASCII characters, no spaces; or leave it out and one is generated']);
        }

        return [$url, $secret];
    }

    /** A positive id in a JSON body: a JSON number, nothing else. */
    private static function bodyId(array $payload, string $field): ?int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }
        if (!is_int($payload[$field]) || $payload[$field] < 1) {
            throw new ValidationException('Invalid ' . $field, [$field => 'A positive whole number, as a JSON number; list models with GET /attribution/models']);
        }

        return $payload[$field];
    }

    /** @param list<string> $names */
    private function removeFiles(array $names): void
    {
        $files = new ExportFiles();
        foreach ($names as $name) {
            try {
                if (!$files->remove($name)) {
                    error_log('p202 attribution: export file ' . $name . ' could not be removed');
                }
            } catch (\UnexpectedValueException $e) {
                error_log('p202 attribution: ' . $e->getMessage());
            }
        }
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

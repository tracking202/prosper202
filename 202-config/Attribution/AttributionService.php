<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use InvalidArgumentException;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportJob;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\Repository\ExportRepositoryInterface;
use Prosper202\Attribution\Analytics\AnalyticsSummary;
use Prosper202\Attribution\Analytics\AnalyticsSnapshot;
use Prosper202\Attribution\Analytics\TouchpointMix;
use Prosper202\Attribution\Analytics\AnomalyAlert;
use Prosper202\Attribution\Touchpoint;
use Prosper202\Attribution\ModelDefinition;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\Snapshot;
use Prosper202\Attribution\ExportJob;
use Prosper202\Attribution\ExportFormat;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ExportWebhook;

/**
 * High-level façade for attribution operations consumed by controllers and CLI jobs.
 */
final class AttributionService
{
    public function __construct(
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly SnapshotRepositoryInterface $snapshotRepository,
        private readonly TouchpointRepositoryInterface $touchpointRepository,
        private readonly AuditRepositoryInterface $auditRepository,
        private readonly ExportRepositoryInterface $exportRepository
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listModels(int $userId, ?ModelType $filter = null): array
    {
        $models = $this->modelRepository->findForUser($userId, $filter);

        return array_map(static function (ModelDefinition $model): array {
            return self::formatModel($model);
        }, $models);
    }

    /**
     * Returns attribution snapshots for charting needs.
     *
     * @param int $userId
     * @param int $modelId
     * @return array<int, array<string, mixed>>
     */
    public function getSnapshots(int $userId, int $modelId, ScopeType $scope, ?int $scopeId, int $startHour, int $endHour, int $limit = 500, int $offset = 0): array
    {
        $this->requireOwnedModel($userId, $modelId);

        $snapshots = $this->snapshotRepository->findForRange($modelId, $scope, $scopeId, $startHour, $endHour, $limit, $offset);

        return array_map(static function (Snapshot $snapshot): array {
            return [
                'snapshot_id' => $snapshot->snapshotId,
                'date_hour' => $snapshot->dateHour,
                'attributed_clicks' => $snapshot->attributedClicks,
                'attributed_conversions' => $snapshot->attributedConversions,
                'attributed_revenue' => $snapshot->attributedRevenue,
                'attributed_cost' => $snapshot->attributedCost,
            ];
        }, $snapshots);
    }

    public function getAnalyticsOverview(
        int $userId,
        int $modelId,
        ScopeType $scope,
        ?int $scopeId,
        int $startHour,
        int $endHour,
        int $limit = 168
    ): AnalyticsSummary {
        $this->requireOwnedModel($userId, $modelId);

        $limit = max(1, min(500, $limit));
        $snapshots = $this->snapshotRepository->findForRange($modelId, $scope, $scopeId, $startHour, $endHour, $limit, 0);

        $analyticsSnapshots = array_map(
            static fn (Snapshot $snapshot): AnalyticsSnapshot => new AnalyticsSnapshot(
                $snapshot->snapshotId,
                $snapshot->dateHour,
                $snapshot->attributedClicks,
                $snapshot->attributedConversions,
                $snapshot->attributedRevenue,
                $snapshot->attributedCost
            ),
            $snapshots
        );

        usort(
            $analyticsSnapshots,
            static fn (AnalyticsSnapshot $a, AnalyticsSnapshot $b): int => $a->dateHour <=> $b->dateHour
        );

        $totals = $this->calculateTotals($analyticsSnapshots);
        $mix = $this->buildTouchpointMix($snapshots);
        $anomalies = $this->detectAnomalies($analyticsSnapshots);

        return new AnalyticsSummary($totals, $analyticsSnapshots, $mix, $anomalies);
    }

    /**
     * Schedules an export job for attribution snapshots.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function scheduleSnapshotExport(int $userId, int $modelId, array $payload): array
    {
        $model = $this->requireOwnedModel($userId, $modelId);

        $scopeValue = isset($payload['scope']) ? (string) $payload['scope'] : ScopeType::GLOBAL->value;
        $scopeType = ScopeType::tryFrom($scopeValue);
        if ($scopeType === null) {
            throw new InvalidArgumentException('Invalid scope value supplied.');
        }

        $scopeId = null;
        if ($scopeType->requiresIdentifier()) {
            if (!isset($payload['scope_id']) || !is_numeric($payload['scope_id'])) {
                throw new InvalidArgumentException('Scope identifier required for the selected scope.');
            }
            $scopeId = (int) $payload['scope_id'];
        }

        $startHour = isset($payload['start_hour']) ? (int) $payload['start_hour'] : (time() - (24 * 3600));
        $endHour = isset($payload['end_hour']) ? (int) $payload['end_hour'] : time();
        if ($startHour >= $endHour) {
            throw new InvalidArgumentException('Start hour must be before end hour for exports.');
        }

        $formatValue = isset($payload['format']) ? strtolower((string) $payload['format']) : ExportFormat::CSV->value;
        $format = ExportFormat::tryFrom($formatValue);
        if ($format === null) {
            throw new InvalidArgumentException('Unsupported export format requested.');
        }

        $webhook = null;
        if (isset($payload['webhook']) && is_array($payload['webhook'])) {
            $webhookPayload = array_filter($payload['webhook'], static fn ($value) => $value !== null && $value !== '');
            if (!empty($webhookPayload)) {
                $webhook = ExportWebhook::fromArray($webhookPayload);
            }
        }

        $queuedAt = time();
        $options = [
            'requested_by' => $userId,
            'created_via' => 'api',
        ];

        $job = new ExportJob(
            exportId: null,
            userId: $userId,
            modelId: $model->modelId ?? $modelId,
            scopeType: $scopeType,
            scopeId: $scopeId,
            startHour: $startHour,
            endHour: $endHour,
            format: $format,
            options: $options,
            webhook: $webhook,
            status: ExportStatus::PENDING,
            queuedAt: $queuedAt,
            startedAt: null,
            completedAt: null,
            failedAt: null,
            filePath: null,
            rowsExported: null,
            lastError: null,
            webhookAttemptedAt: null,
            webhookStatusCode: null,
            webhookResponseBody: null,
            createdAt: $queuedAt,
            updatedAt: $queuedAt
        );

        $created = $this->exportRepository->create($job);

        $this->auditRepository->record($userId, $modelId, 'export_scheduled', [
            'export_id' => $created->exportId,
            'scope' => $created->scopeType->value,
            'scope_id' => $created->scopeId,
            'start_hour' => $created->startHour,
            'end_hour' => $created->endHour,
            'format' => $created->format->value,
            'webhook' => $created->webhook?->url,
        ]);

        return $created->toSummary();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSnapshotExports(int $userId, int $modelId, int $limit = 20): array
    {
        $this->requireOwnedModel($userId, $modelId);
        $jobs = $this->exportRepository->listRecentForModel($userId, $modelId, $limit);

        return array_map(static fn (ExportJob $job): array => $job->toSummary(), $jobs);
    }

    /**
     * Builds a sandbox comparison payload. Actual weighting logic is pending implementation.
     *
     * @param string[] $modelSlugs
     *
     * @return array<string, mixed>
     */
    public function runSandboxComparison(
        int $userId,
        array $modelSlugs,
        ScopeType $scope,
        ?int $scopeId,
        int $startHour,
        int $endHour
    ): array {
        $models = array_filter(
            $this->modelRepository->findForUser($userId, null, true),
            static function (ModelDefinition $model) use ($modelSlugs): bool {
                return in_array($model->slug, $modelSlugs, true);
            }
        );

        return [
            'models' => array_map(static fn (ModelDefinition $model): array => self::formatModel($model), $models),
            'summary' => [
                'message' => 'Attribution sandbox scaffolding ready. Computation engine will populate metrics in a subsequent iteration.',
                'scope' => $scope->value,
                'scope_id' => $scopeId,
                'start_hour' => $startHour,
                'end_hour' => $endHour,
            ],
            'comparisons' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createModel(int $userId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Model name is required.');
        }

        $typeValue = strtolower((string) ($payload['type'] ?? ''));
        $modelType = ModelType::tryFrom($typeValue);
        if ($modelType === null) {
            throw new InvalidArgumentException('Invalid model type supplied.');
        }

        $weightingConfig = $this->normaliseWeightingConfig($payload['weighting_config'] ?? []);
        $slugInput = (string) ($payload['slug'] ?? '');
        $slug = $slugInput !== '' ? $this->slugify($slugInput) : $this->slugify($name);
        $slug = $this->ensureUniqueSlug($userId, $slug, null);

        $timestamp = time();
        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
        $isDefaultRequested = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : false;

        $definition = new ModelDefinition(
            modelId: null,
            userId: $userId,
            name: $name,
            slug: $slug,
            type: $modelType,
            weightingConfig: $weightingConfig,
            isActive: $isActive,
            isDefault: $isDefaultRequested,
            createdAt: $timestamp,
            updatedAt: $timestamp
        );

        $saved = $this->modelRepository->save($definition);
        if ($isDefaultRequested) {
            $this->modelRepository->promoteToDefault($saved);
            $saved = $this->requireModel($saved->modelId ?? 0);
        }

        $this->auditRepository->record($userId, $saved->modelId, 'model_create', [
            'name' => $saved->name,
            'slug' => $saved->slug,
            'type' => $saved->type->value,
        ]);

        return self::formatModel($saved);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateModel(int $userId, int $modelId, array $payload): array
    {
        $existing = $this->requireOwnedModel($userId, $modelId);

        $name = trim((string) ($payload['name'] ?? $existing->name));
        if ($name === '') {
            throw new InvalidArgumentException('Model name is required.');
        }

        $type = $existing->type;
        if (array_key_exists('type', $payload)) {
            $typeCandidate = ModelType::tryFrom(strtolower((string) $payload['type']));
            if ($typeCandidate === null) {
                throw new InvalidArgumentException('Invalid model type supplied.');
            }
            $type = $typeCandidate;
        }

        $weightingConfig = array_key_exists('weighting_config', $payload)
            ? $this->normaliseWeightingConfig($payload['weighting_config'])
            : $existing->weightingConfig;

        $slugInput = array_key_exists('slug', $payload) ? (string) $payload['slug'] : '';
        $slugSeed = $slugInput !== '' ? $slugInput : $name;
        $slug = $this->slugify($slugSeed);
        $slug = $this->ensureUniqueSlug($userId, $slug, $existing->slug);

        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : $existing->isActive;
        $isDefaultRequested = array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : $existing->isDefault;

        $updated = new ModelDefinition(
            modelId: $existing->modelId,
            userId: $userId,
            name: $name,
            slug: $slug,
            type: $type,
            weightingConfig: $weightingConfig,
            isActive: $isActive,
            isDefault: $isDefaultRequested,
            createdAt: $existing->createdAt,
            updatedAt: time()
        );

        $saved = $this->modelRepository->save($updated);
        if ($isDefaultRequested) {
            $this->modelRepository->promoteToDefault($saved);
            $saved = $this->requireModel($saved->modelId ?? 0);
        }

        $this->auditRepository->record($userId, $saved->modelId, 'model_update', [
            'name' => $saved->name,
            'slug' => $saved->slug,
            'type' => $saved->type->value,
            'is_active' => $saved->isActive,
            'is_default' => $saved->isDefault,
        ]);

        return self::formatModel($saved);
    }

    public function deleteModel(int $userId, int $modelId): void
    {
        $existing = $this->requireOwnedModel($userId, $modelId);
        $this->modelRepository->delete($modelId, $userId);
        $this->auditRepository->record($userId, $modelId, 'model_delete', [
            'name' => $existing->name,
            'slug' => $existing->slug,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listExports(int $userId, ?int $modelId = null, int $limit = 25): array
    {
        if ($modelId !== null) {
            $this->requireOwnedModel($userId, $modelId);
        }

        $jobs = $this->exportRepository->findForUser($userId, $modelId, $limit);

        return array_map(fn (ExportJob $job): array => $this->formatExportJob($job), $jobs);
    }

    /**
     * @param array<string, mixed> $webhookOptions
     */
    public function scheduleSnapshotExport(
        int $userId,
        int $modelId,
        ScopeType $scope,
        ?int $scopeId,
        int $startHour,
        int $endHour,
        ExportFormat $format,
        array $webhookOptions = []
    ): array {
        $this->requireOwnedModel($userId, $modelId);

        if ($startHour > $endHour) {
            throw new \InvalidArgumentException('Start hour must be before end hour.');
        }

        $token = bin2hex(random_bytes(16));
        $timestamp = time();

        $headers = $this->normaliseWebhookHeaders($webhookOptions['headers'] ?? []);
        $webhookUrl = isset($webhookOptions['url']) ? trim((string) $webhookOptions['url']) : null;
        $webhookMethod = isset($webhookOptions['method']) ? strtoupper((string) $webhookOptions['method']) : 'POST';

        $job = new ExportJob(
            exportId: null,
            userId: $userId,
            modelId: $modelId,
            scopeType: $scope,
            scopeId: $scopeId,
            startHour: $startHour,
            endHour: $endHour,
            format: $format,
            status: ExportStatus::PENDING,
            filePath: null,
            downloadToken: $token,
            webhookUrl: $webhookUrl !== '' ? $webhookUrl : null,
            webhookMethod: $webhookMethod,
            webhookHeaders: $headers,
            webhookStatusCode: null,
            webhookResponseBody: null,
            lastAttemptedAt: null,
            completedAt: null,
            errorMessage: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );

        $saved = $this->exportRepository->create($job);

        return $this->formatExportJob($saved);
    }

    /**
     * @return array<string, string>
     */
    private function normaliseWebhookHeaders(mixed $headers): array
    {
        $normalised = [];

        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                $headerKey = trim((string) $key);
                if ($headerKey === '') {
                    continue;
                }

                $headerValue = is_scalar($value) ? trim((string) $value) : '';
                if ($headerValue === '') {
                    continue;
                }

                $normalised[$headerKey] = $headerValue;
            }
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExportJob(ExportJob $job): array
    {
        return [
            'export_id' => $job->exportId,
            'user_id' => $job->userId,
            'model_id' => $job->modelId,
            'scope_type' => $job->scopeType->value,
            'scope_id' => $job->scopeId,
            'start_hour' => $job->startHour,
            'end_hour' => $job->endHour,
            'format' => $job->format->value,
            'status' => $job->status->value,
            'file_path' => $job->filePath,
            'download_token' => $job->downloadToken,
            'webhook_url' => $job->webhookUrl,
            'webhook_method' => $job->webhookMethod,
            'webhook_headers' => $job->webhookHeaders,
            'webhook_status_code' => $job->webhookStatusCode,
            'webhook_response_body' => $job->webhookResponseBody,
            'last_attempted_at' => $job->lastAttemptedAt,
            'completed_at' => $job->completedAt,
            'error_message' => $job->errorMessage,
            'created_at' => $job->createdAt,
            'updated_at' => $job->updatedAt,
        ];
    }

    private static function formatModel(ModelDefinition $model): array
    {
        return [
            'model_id' => $model->modelId,
            'user_id' => $model->userId,
            'name' => $model->name,
            'slug' => $model->slug,
            'type' => $model->type->value,
            'is_active' => $model->isActive,
            'is_default' => $model->isDefault,
            'created_at' => $model->createdAt,
            'updated_at' => $model->updatedAt,
            'weighting_config' => $model->weightingConfig,
        ];
    }

    private function ensureUniqueSlug(int $userId, string $slug, ?string $currentSlug): string
    {
        $base = substr($slug, 0, 191);
        if ($currentSlug !== null && $currentSlug === $base) {
            return $base;
        }

        $candidate = $base;
        $suffix = 1;
        while (true) {
            $existing = $this->modelRepository->findBySlug($userId, $candidate);
            if ($existing === null || ($currentSlug !== null && $existing->slug === $currentSlug)) {
                return $candidate;
            }

            $candidate = substr($base, 0, 180) . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * @param mixed $config
     * @return array<string, mixed>
     */
    private function normaliseWeightingConfig(mixed $config): array
    {
        if ($config === null) {
            return [];
        }

        if (!is_array($config)) {
            throw new InvalidArgumentException('Weighting configuration must be an object.');
        }

        return $config;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug ?? '');
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'model-' . time();
        }

        return substr($slug, 0, 191);
    }

    private function requireModel(int $modelId): ModelDefinition
    {
        $model = $this->modelRepository->findById($modelId);
        if ($model === null) {
            throw new InvalidArgumentException('Attribution model not found.');
        }

        return $model;
    }

    private function requireOwnedModel(int $userId, int $modelId): ModelDefinition
    {
        $model = $this->requireModel($modelId);
        if ($model->userId !== $userId) {
            throw new InvalidArgumentException('Attribution model not found.');
        }

        return $model;
    }

    /**
     * @param AnalyticsSnapshot[] $snapshots
     * @return array<string, float|null>
     */
    private function calculateTotals(array $snapshots): array
    {
        $totals = [
            'revenue' => 0.0,
            'conversions' => 0.0,
            'clicks' => 0.0,
            'cost' => 0.0,
            'roi' => null,
        ];

        foreach ($snapshots as $snapshot) {
            $totals['revenue'] += $snapshot->attributedRevenue;
            $totals['conversions'] += $snapshot->attributedConversions;
            $totals['clicks'] += $snapshot->attributedClicks;
            $totals['cost'] += $snapshot->attributedCost;
        }

        if ($totals['cost'] > 0.0) {
            $totals['roi'] = (($totals['revenue'] - $totals['cost']) / $totals['cost']) * 100.0;
        }

        return $totals;
    }

    /**
     * @param Snapshot[] $snapshots
     * @return TouchpointMix[]
     */
    private function buildTouchpointMix(array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }

        $buckets = [
            'first_touch' => ['label' => 'First Touch', 'credit' => 0.0, 'touches' => 0],
            'assist_touch' => ['label' => 'Assist', 'credit' => 0.0, 'touches' => 0],
            'last_touch' => ['label' => 'Last Touch', 'credit' => 0.0, 'touches' => 0],
        ];

        foreach ($snapshots as $snapshot) {
            if ($snapshot->snapshotId === null) {
                continue;
            }

            $touchpoints = $this->touchpointRepository->findBySnapshot($snapshot->snapshotId);
            if ($touchpoints === []) {
                continue;
            }

            $maxPosition = max(array_map(static fn (Touchpoint $touchpoint): int => $touchpoint->position, $touchpoints));

            foreach ($touchpoints as $touchpoint) {
                $bucketKey = 'assist_touch';
                if ($touchpoint->position === 0) {
                    $bucketKey = 'first_touch';
                } elseif ($touchpoint->position === $maxPosition) {
                    $bucketKey = 'last_touch';
                }

                $buckets[$bucketKey]['credit'] += $touchpoint->credit;
                $buckets[$bucketKey]['touches']++;
            }
        }

        $totalCredit = array_reduce(
            $buckets,
            static fn (float $carry, array $bucket): float => $carry + (float) $bucket['credit'],
            0.0
        );

        $mix = [];
        foreach ($buckets as $key => $bucket) {
            $share = $totalCredit > 0.0 ? ($bucket['credit'] / $totalCredit) * 100.0 : 0.0;
            $mix[] = new TouchpointMix(
                $key,
                $bucket['label'],
                (float) $bucket['credit'],
                (int) $bucket['touches'],
                $share
            );
        }

        return $mix;
    }

    /**
     * @param AnalyticsSnapshot[] $snapshots
     * @return AnomalyAlert[]
     */
    private function detectAnomalies(array $snapshots): array
    {
        $count = count($snapshots);
        if ($count < 2) {
            return [];
        }

        $recent = $snapshots[$count - 1];
        $history = array_slice($snapshots, 0, -1);

        $alerts = [];
        $alerts = array_merge(
            $alerts,
            $this->buildMetricAlert(
                'conversions',
                array_map(static fn (AnalyticsSnapshot $snapshot): float => (float) $snapshot->attributedConversions, $history),
                (float) $recent->attributedConversions
            )
        );

        $alerts = array_merge(
            $alerts,
            $this->buildMetricAlert(
                'revenue',
                array_map(static fn (AnalyticsSnapshot $snapshot): float => $snapshot->attributedRevenue, $history),
                $recent->attributedRevenue
            )
        );

        return $alerts;
    }

    /**
     * @param float[] $historicalValues
     * @return AnomalyAlert[]
     */
    private function buildMetricAlert(string $metric, array $historicalValues, float $latest): array
    {
        $historyCount = count($historicalValues);
        if ($historyCount === 0) {
            return [];
        }

        $historyAverage = array_sum($historicalValues) / $historyCount;
        if ($historyAverage <= 0.0) {
            return [];
        }

        $delta = ($latest - $historyAverage) / $historyAverage;
        $severityThreshold = 0.25;

        if (abs($delta) < $severityThreshold) {
            return [];
        }

        $severity = abs($delta) >= 0.5 ? 'critical' : 'warning';
        $direction = $delta >= 0 ? 'up' : 'down';
        $percentageChange = round($delta * 100.0, 2);
        $message = sprintf(
            'Latest %s changed by %s%% compared to the trailing average.',
            $metric,
            number_format(abs($percentageChange), 2)
        );

        return [new AnomalyAlert($metric, $severity, $direction, $percentageChange, $message)];
    }
}

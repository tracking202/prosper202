<?php

declare(strict_types=1);

namespace Api\Attribution;

use InvalidArgumentException;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\ScopeType;
use Prosper202\Attribution\Analytics\AnalyticsResponseTransformer;

/**
 * Thin controller that prepares request parameters for the attribution service.
 */
final class Controller
{
    private AttributionService $service;

    public function __construct(AttributionService $service)
    {
        $this->service = $service;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function listModels(array $params): array
    {
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $type = isset($params['type']) ? (string) $params['type'] : '';

        $modelType = null;
        if ($type !== '') {
            $modelType = ModelType::tryFrom($type);
            if ($modelType === null) {
                return [
                    'status' => 400,
                    'payload' => [
                        'error' => true,
                        'message' => 'Unknown model type filter provided.',
                    ],
                ];
            }
        }

        $models = $this->service->listModels($userId, $modelType);

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => $models,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function getMetrics(array $params): array
    {
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $modelId = isset($params['model_id']) ? (int) $params['model_id'] : 0;
        if ($modelId <= 0) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => 'A valid model_id parameter is required.',
                ],
            ];
        }

        $scope = isset($params['scope']) ? (string) $params['scope'] : ScopeType::GLOBAL->value;
        $scopeType = ScopeType::tryFrom($scope);
        if ($scopeType === null) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => 'Invalid scope value.',
                ],
            ];
        }

        $scopeId = isset($params['scope_id']) && is_numeric($params['scope_id']) ? (int) $params['scope_id'] : null;
        $startHour = isset($params['start_hour']) ? (int) $params['start_hour'] : (time() - (24 * 3600));
        $endHour = isset($params['end_hour']) ? (int) $params['end_hour'] : time();
        $limit = isset($params['limit']) ? max(1, min(500, (int) $params['limit'])) : 168;

        try {
            $summary = $this->service->getAnalyticsOverview(
                $userId,
                $modelId,
                $scopeType,
                $scopeId,
                $startHour,
                $endHour,
                $limit
            );
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => AnalyticsResponseTransformer::summaryToArray($summary),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function getAnomalies(array $params): array
    {
        $metrics = $this->getMetrics($params);
        if ($metrics['status'] !== 200) {
            return $metrics;
        }

        $data = $metrics['payload']['data'];

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => [
                    'anomalies' => isset($data['anomalies']) && is_array($data['anomalies'])
                        ? $data['anomalies']
                        : [],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $path
     *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function getSnapshots(array $params, array $path): array
    {
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $modelId = isset($path['modelId']) ? (int) $path['modelId'] : 0;
        $scope = isset($params['scope']) ? (string) $params['scope'] : ScopeType::GLOBAL->value;
        $scopeType = ScopeType::tryFrom($scope);
        if ($scopeType === null) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => 'Invalid scope value.',
                ],
            ];
        }

        $scopeId = isset($params['scope_id']) && is_numeric($params['scope_id']) ? (int) $params['scope_id'] : null;
        $startHour = isset($params['start_hour']) ? (int) $params['start_hour'] : time();
        $endHour = isset($params['end_hour']) ? (int) $params['end_hour'] : time();

        $limit = isset($params['limit']) ? max(1, min(1000, (int) $params['limit'])) : 500;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        try {
            $snapshots = $this->service->getSnapshots(
                $userId,
                $modelId,
                $scopeType,
                $scopeId,
                $startHour,
                $endHour,
                $limit,
                $offset
            );
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => $snapshots,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $path
     *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function listExports(array $params, array $path): array
    {
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $modelId = isset($path['modelId']) ? (int) $path['modelId'] : 0;
        $limit = isset($params['limit']) ? max(1, min(100, (int) $params['limit'])) : 20;

        try {
            $jobs = $this->service->listSnapshotExports($userId, $modelId, $limit);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => $jobs,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function scheduleExport(int $modelId, array $payload): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        try {
            $job = $this->service->scheduleSnapshotExport($userId, $modelId, $payload);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 201,
            'payload' => [
                'error' => false,
                'data' => $job,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function sandbox(array $params): array
    {
        $userId = isset($params['user_id']) ? (int) $params['user_id'] : 0;
        $scope = isset($params['scope']) ? (string) $params['scope'] : ScopeType::GLOBAL->value;
        $scopeType = ScopeType::tryFrom($scope);
        if ($scopeType === null) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => 'Invalid scope value.',
                ],
            ];
        }

        $scopeId = isset($params['scope_id']) && is_numeric($params['scope_id']) ? (int) $params['scope_id'] : null;
        $startHour = isset($params['start_hour']) ? (int) $params['start_hour'] : time();
        $endHour = isset($params['end_hour']) ? (int) $params['end_hour'] : time();

        $modelSlugs = [];
        if (isset($params['models'])) {
            if (is_array($params['models'])) {
                $modelSlugs = array_map('strval', $params['models']);
            } elseif (is_string($params['models'])) {
                $modelSlugs = array_filter(array_map('trim', explode(',', $params['models'])));
            }
        }

        $result = $this->service->runSandboxComparison(
            $userId,
            $modelSlugs,
            $scopeType,
            $scopeId,
            $startHour,
            $endHour
        );

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => $result,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createModel(array $payload): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        try {
            $model = $this->service->createModel($userId, $payload);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 201,
            'payload' => [
                'error' => false,
                'data' => $model,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateModel(int $modelId, array $payload): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        try {
            $model = $this->service->updateModel($userId, $modelId, $payload);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'error' => false,
                'data' => $model,
            ],
        ];
    }

    public function deleteModel(int $modelId, array $payload): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);

        try {
            $this->service->deleteModel($userId, $modelId);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 400,
                'payload' => [
                    'error' => true,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 204,
            'payload' => ['error' => false],
        ];
    }
}

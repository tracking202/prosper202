<?php

declare(strict_types=1);

use Api\Attribution\Controller;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\AttributionServiceFactory;

require_once __DIR__ . '/../../202-config/connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

\Slim\Slim::registerAutoloader();

const ATTRIBUTION_AUTH_OVERRIDE_KEY = '__prosper_attribution_auth_override';

function create_attribution_app(?AttributionService $service = null): \Slim\Slim
{
    $app = new \Slim\Slim();
    $service ??= AttributionServiceFactory::create();
    $controller = new Controller($service);

    register_attribution_routes($app, $controller);

    return $app;
}

function register_attribution_routes(\Slim\Slim $app, Controller $controller): void
{
    $app->group('/attribution', function () use ($app, $controller): void {
        $app->get('/models', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $userId = attribution_authorized_user_id($app);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->listModels($params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'view_attribution_reports'));

        $app->post('/models', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $auth = attribution_authorized_request($app);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($app));
            $result = $controller->createModel($payload);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'manage_attribution_models'));

        $app->get('/models/:modelId/snapshots', function ($modelId) use ($app, $controller): void {
            $params = $app->request()->params();
            $userId = attribution_authorized_user_id($app);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getSnapshots($params, ['modelId' => $modelId]);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'view_attribution_reports'));

        $app->patch('/models/:modelId', function ($modelId) use ($app, $controller): void {
            $params = $app->request()->params();
            $auth = attribution_authorized_request($app);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($app));
            $result = $controller->updateModel((int) $modelId, $payload);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'manage_attribution_models'));

        $app->delete('/models/:modelId', function ($modelId) use ($app, $controller): void {
            $params = $app->request()->params();
            $auth = attribution_authorized_request($app);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $result = $controller->deleteModel((int) $modelId, $params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'manage_attribution_models'));

        $app->get('/sandbox', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $auth = attribution_authorized_request($app);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $result = $controller->sandbox($params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'manage_attribution_models'));

        $app->post('/models/:modelId/exports', function ($modelId) use ($app, $controller): void {
            $params = $app->request()->params();
            $auth = attribution_authorized_request($app);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($app));
            $result = $controller->scheduleExport((int) $modelId, $payload);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'manage_attribution_models'));

        $app->get('/exports', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $userId = attribution_authorized_user_id($app);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->listExports($params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'view_attribution_reports'));

        $app->get('/metrics', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $userId = attribution_authorized_user_id($app);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getMetrics($params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'view_attribution_reports'));

        $app->get('/anomalies', function () use ($app, $controller): void {
            $params = $app->request()->params();
            $userId = attribution_authorized_user_id($app);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getAnomalies($params);
            respond_json($app, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware($app, 'view_attribution_reports'));
    });

    $app->get('/health', function () use ($app): void {
        respond_json($app, ['error' => false, 'message' => 'Attribution API ready']);
    });
}

function attribution_authorization_middleware(\Slim\Slim $app, string $permission): callable
{
    return function () use ($app, $permission): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, $permission);
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            $app->stop();
            return;
        }

        $environment = $app->environment();
        $environment['prosper.attribution_auth'] = $auth;
    };
}

function attribution_authorized_user_id(\Slim\Slim $app): ?int
{
    $environment = $app->environment();
    if (isset($environment['prosper.attribution_auth']['user_id'])) {
        return (int) $environment['prosper.attribution_auth']['user_id'];
    }

    return null;
}

function attribution_authorized_request(\Slim\Slim $app): ?int
{
    return attribution_authorized_user_id($app);
}

/**
 * @return array{status:int,payload:array<string,mixed>,user_id?:int}
 */
function authorize_attribution_request(array $params, string $permission): array
{
    if (PHP_SAPI === 'cli' && isset($GLOBALS[ATTRIBUTION_AUTH_OVERRIDE_KEY])) {
        $override = $GLOBALS[ATTRIBUTION_AUTH_OVERRIDE_KEY];
        if (!in_array($permission, $override['permissions'], true)) {
            return [
                'status' => 403,
                'payload' => [
                    'error' => true,
                    'message' => 'Insufficient permissions to access attribution resources.',
                ],
            ];
        }

        return ['status' => 200, 'payload' => ['error' => false], 'user_id' => (int) $override['user_id']];
    }

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId !== null && user_has_permission((int) $userId, $permission)) {
        return ['status' => 200, 'payload' => ['error' => false], 'user_id' => (int) $userId];
    }

    $apiKey = isset($params['apikey']) ? trim((string) $params['apikey']) : '';
    if ($apiKey === '') {
        return [
            'status' => 401,
            'payload' => [
                'error' => true,
                'message' => 'API key required or user session must be active.',
            ],
        ];
    }

    $dbInstance = \DB::getInstance();
    $connection = $dbInstance?->getConnection();
    if (!$connection instanceof \mysqli) {
        return [
            'status' => 500,
            'payload' => [
                'error' => true,
                'message' => 'Database connection unavailable.',
            ],
        ];
    }

    $stmt = $connection->prepare('SELECT user_id FROM 202_api_keys WHERE api_key = ? LIMIT 1');
    if ($stmt === false) {
        return [
            'status' => 500,
            'payload' => [
                'error' => true,
                'message' => 'Unable to validate API key.',
            ],
        ];
    }

    $stmt->bind_param('s', $apiKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row || !isset($row['user_id'])) {
        return [
            'status' => 401,
            'payload' => [
                'error' => true,
                'message' => 'Invalid API key.',
            ],
        ];
    }

    $userId = (int) $row['user_id'];
    if (!user_has_permission($userId, $permission)) {
        return [
            'status' => 403,
            'payload' => [
                'error' => true,
                'message' => 'Insufficient permissions to access attribution resources.',
            ],
        ];
    }

    return ['status' => 200, 'payload' => ['error' => false], 'user_id' => $userId];
}

function override_attribution_authorization(?int $userId, array $permissions = []): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }

    if ($userId === null) {
        unset($GLOBALS[ATTRIBUTION_AUTH_OVERRIDE_KEY]);
        return;
    }

    $GLOBALS[ATTRIBUTION_AUTH_OVERRIDE_KEY] = [
        'user_id' => $userId,
        'permissions' => array_values(array_unique(array_map('strval', $permissions))),
    ];
}

function user_has_permission(int $userId, string $permission): bool
{
    if ($userId <= 0) {
        return false;
    }

    $user = new \User($userId);
    return $user->hasPermission($permission);
}

/**
 * @return array<string, mixed>
 */
function decode_json_body(\Slim\Slim $app): array
{
    $body = (string) $app->request()->getBody();
    if ($body === '') {
        return [];
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function respond_json(\Slim\Slim $app, array $payload, int $status = 200): void
{
    $response = $app->response();
    $response->status($status);
    $response->header('Content-Type', 'application/json');
    $response->body(json_encode($payload));
}

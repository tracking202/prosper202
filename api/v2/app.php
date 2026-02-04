<?php

declare(strict_types=1);

use Api\Attribution\Controller;
use Prosper202\Attribution\AttributionService;
use Prosper202\Attribution\AttributionServiceFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../../202-config/connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

const ATTRIBUTION_AUTH_OVERRIDE_KEY = '__prosper_attribution_auth_override';

function create_attribution_app(?AttributionService $service = null): \Slim\App
{
    $app = new \Slim\App();
    $service ??= AttributionServiceFactory::create();
    $controller = new Controller($service);

    register_attribution_routes($app, $controller);

    return $app;
}

function register_attribution_routes(\Slim\App $app, Controller $controller): void
{
    $app->group('/attribution', function () use ($app, $controller): void {
        $app->get('/models', function (Request $request, Response $response) use ($controller): Response {
            $params = $request->getQueryParams();
            $userId = attribution_authorized_user_id($request);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->listModels($params);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('view_attribution_reports'));

        $app->post('/models', function (Request $request, Response $response) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($request));
            $result = $controller->createModel($payload);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->get('/models/{modelId}/snapshots', function (Request $request, Response $response, array $args) use ($controller): Response {
            $params = $request->getQueryParams();
            $userId = attribution_authorized_user_id($request);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getSnapshots($params, ['modelId' => $args['modelId']]);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('view_attribution_reports'));

        $app->get('/models/{modelId}/exports', function (Request $request, Response $response, array $args) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $result = $controller->listExports($params, ['modelId' => $args['modelId']]);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->post('/models/{modelId}/exports', function (Request $request, Response $response, array $args) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($request));
            $result = $controller->scheduleExport((int) $args['modelId'], $payload);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->patch('/models/{modelId}', function (Request $request, Response $response, array $args) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $payload = array_merge($params, decode_json_body($request));
            $result = $controller->updateModel((int) $args['modelId'], $payload);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->delete('/models/{modelId}', function (Request $request, Response $response, array $args) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $result = $controller->deleteModel((int) $args['modelId'], $params);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->get('/sandbox', function (Request $request, Response $response) use ($controller): Response {
            $params = $request->getQueryParams();
            $auth = attribution_authorized_user_id($request);
            if ($auth !== null) {
                $params['user_id'] = $auth;
            }

            $result = $controller->sandbox($params);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('manage_attribution_models'));

        $app->get('/metrics', function (Request $request, Response $response) use ($controller): Response {
            $params = $request->getQueryParams();
            $userId = attribution_authorized_user_id($request);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getMetrics($params);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('view_attribution_reports'));

        $app->get('/anomalies', function (Request $request, Response $response) use ($controller): Response {
            $params = $request->getQueryParams();
            $userId = attribution_authorized_user_id($request);
            if ($userId !== null) {
                $params['user_id'] = $userId;
            }

            $result = $controller->getAnomalies($params);
            return respond_json($response, $result['payload'], $result['status']);
        })->add(attribution_authorization_middleware('view_attribution_reports'));
    });

    $app->get('/health', function (Request $request, Response $response): Response {
        return respond_json($response, ['error' => false, 'message' => 'Attribution API ready']);
    });
}

function attribution_authorization_middleware(string $permission): callable
{
    return function (Request $request, Response $response, callable $next) use ($permission) {
        $params = $request->getQueryParams();
        $auth = authorize_attribution_request($params, $permission);
        if ($auth['status'] !== 200) {
            return respond_json($response, $auth['payload'], $auth['status']);
        }

        $request = $request->withAttribute('prosper.attribution_auth', $auth);
        return $next($request, $response);
    };
}

function attribution_authorized_user_id(Request $request): ?int
{
    $auth = $request->getAttribute('prosper.attribution_auth');
    if (is_array($auth) && isset($auth['user_id'])) {
        return (int) $auth['user_id'];
    }

    return null;
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
function decode_json_body(Request $request): array
{
    $body = (string) $request->getBody();
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

function respond_json(Response $response, array $payload, int $status = 200): Response
{
    $response = $response->withStatus($status);
    $response = $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write(json_encode($payload));
    return $response;
}

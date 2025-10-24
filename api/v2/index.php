<?php

declare(strict_types=1);

use Api\Attribution\Controller;
use Prosper202\Attribution\AttributionServiceFactory;

require_once __DIR__ . '/../../202-config/connect.php';

require '/Slim/Slim.php';
require_once __DIR__ . '/../../vendor/autoload.php';

\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$service = AttributionServiceFactory::create();
$controller = new Controller($service);

$app->group('/attribution', function () use ($app, $controller): void {
    $app->get('/models', function () use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'view_attribution_reports');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $params['user_id'] = $auth['user_id'];
        $result = $controller->listModels($params);
        respond_json($app, $result['payload'], $result['status']);
    });

    $app->post('/models', function () use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'manage_attribution_models');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $payload = array_merge($params, decode_json_body($app));
        $payload['user_id'] = $auth['user_id'];
        $result = $controller->createModel($payload);
        respond_json($app, $result['payload'], $result['status']);
    });

    $app->get('/models/:modelId/snapshots', function ($modelId) use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'view_attribution_reports');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $params['user_id'] = $auth['user_id'];
        $result = $controller->getSnapshots($params, ['modelId' => $modelId]);
        respond_json($app, $result['payload'], $result['status']);
    });

    $app->patch('/models/:modelId', function ($modelId) use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'manage_attribution_models');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $payload = array_merge($params, decode_json_body($app));
        $payload['user_id'] = $auth['user_id'];
        $result = $controller->updateModel((int) $modelId, $payload);
        respond_json($app, $result['payload'], $result['status']);
    });

    $app->delete('/models/:modelId', function ($modelId) use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'manage_attribution_models');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $payload = $params;
        $payload['user_id'] = $auth['user_id'];
        $result = $controller->deleteModel((int) $modelId, $payload);
        respond_json($app, $result['payload'], $result['status']);
    });

    $app->get('/sandbox', function () use ($app, $controller): void {
        $params = $app->request()->params();
        $auth = authorize_attribution_request($params, 'manage_attribution_models');
        if ($auth['status'] !== 200) {
            respond_json($app, $auth['payload'], $auth['status']);
            return;
        }

        $params['user_id'] = $auth['user_id'];
        $result = $controller->sandbox($params);
        respond_json($app, $result['payload'], $result['status']);
    });
});

$app->get('/health', function () use ($app): void {
    respond_json($app, ['error' => false, 'message' => 'Attribution API ready']);
});

$app->run();

/**
 * Sends a JSON response with standard headers.
 */
function respond_json(\Slim\Slim $app, array $payload, int $status = 200): void
{
    $response = $app->response();
    $response->status($status);
    $response->header('Content-Type', 'application/json');
    $response->body(json_encode($payload));
}

/**
 * Authorize API access using either the current session or an API key.
 *
 * @param array<string,mixed> $params
 * @return array{status:int,payload:array<string,mixed>,user_id?:int}
 */
function authorize_attribution_request(array $params, string $permission): array
{
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
    } catch (Throwable) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

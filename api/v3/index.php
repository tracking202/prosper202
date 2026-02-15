<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Api\V3\Bootstrap;
use Api\V3\AuthException;

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// CORS — restrict by default; set API_CORS_ORIGIN in 202-config.php to enable
$allowedOrigin = defined('API_CORS_ORIGIN') ? API_CORS_ORIGIN : '';
if ($allowedOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    Bootstrap::init();

    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];

    // Strip base path
    $basePath = '/api/v3';
    $pos = strpos($path, $basePath);
    if ($pos !== false) {
        $path = substr($path, $pos + strlen($basePath));
    }

    $path = strtok($path, '?') ?: '/';
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }

    $params = $_GET;
    $headers = getallheaders() ?: [];

    // Unauthenticated health probe for monitoring
    if ($path === '/system/health' && $method === 'GET') {
        $db = Bootstrap::db();
        Bootstrap::jsonResponse([
            'data' => ['status' => 'healthy', 'timestamp' => time(), 'api_version' => 'v3'],
        ]);
        exit;
    }

    Bootstrap::authenticate($params, $headers);

    $payload = [];
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $payload = json_decode($raw, true) ?? [];
        }
    }

    $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
    $resource = $segments[0] ?? '';
    $id = $segments[1] ?? null;
    $sub = $segments[2] ?? null;
    $subId = $segments[3] ?? null;

    $response = null;

    // --- CRUD resources ---
    $crudMap = [
        'campaigns'     => \Api\V3\Controllers\CampaignsController::class,
        'aff-networks'  => \Api\V3\Controllers\AffNetworksController::class,
        'ppc-networks'  => \Api\V3\Controllers\PpcNetworksController::class,
        'ppc-accounts'  => \Api\V3\Controllers\PpcAccountsController::class,
        'trackers'      => \Api\V3\Controllers\TrackersController::class,
        'landing-pages' => \Api\V3\Controllers\LandingPagesController::class,
        'text-ads'      => \Api\V3\Controllers\TextAdsController::class,
    ];

    if (isset($crudMap[$resource])) {
        $ctrl = new $crudMap[$resource]();

        if ($resource === 'trackers' && $id !== null && $sub === 'url' && $method === 'GET') {
            $response = $ctrl->getTrackingUrl((int)$id);
        } else {
            $response = match (true) {
                $method === 'GET' && $id === null    => $ctrl->list($params),
                $method === 'GET' && $id !== null     => $ctrl->get((int)$id),
                $method === 'POST' && $id === null    => array_merge($ctrl->create($payload), ['_status' => 201]),
                $method === 'PUT' && $id !== null      => $ctrl->update((int)$id, $payload),
                $method === 'PATCH' && $id !== null    => $ctrl->update((int)$id, $payload),
                $method === 'DELETE' && $id !== null   => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['_status' => 204]; })(),
                default => null,
            };
        }
    }

    // --- Clicks ---
    elseif ($resource === 'clicks') {
        $ctrl = new \Api\V3\Controllers\ClicksController();
        $response = match (true) {
            $method === 'GET' && $id === null  => $ctrl->list($params),
            $method === 'GET' && $id !== null   => $ctrl->get((int)$id),
            default => null,
        };
    }

    // --- Conversions ---
    elseif ($resource === 'conversions') {
        $ctrl = new \Api\V3\Controllers\ConversionsController();
        $response = match (true) {
            $method === 'GET' && $id === null     => $ctrl->list($params),
            $method === 'GET' && $id !== null      => $ctrl->get((int)$id),
            $method === 'POST' && $id === null     => array_merge($ctrl->create($payload), ['_status' => 201]),
            $method === 'DELETE' && $id !== null    => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['_status' => 204]; })(),
            default => null,
        };
    }

    // --- Reports ---
    elseif ($resource === 'reports') {
        $ctrl = new \Api\V3\Controllers\ReportsController();
        $response = match ($id) {
            'summary'    => $method === 'GET' ? $ctrl->summary($params) : null,
            'breakdown'  => $method === 'GET' ? $ctrl->breakdown($params) : null,
            'timeseries' => $method === 'GET' ? $ctrl->timeseries($params) : null,
            default      => null,
        };
    }

    // --- Rotators ---
    elseif ($resource === 'rotators') {
        $ctrl = new \Api\V3\Controllers\RotatorsController();

        if ($id !== null && $sub === 'rules') {
            $response = match (true) {
                $method === 'GET' && $subId === null     => $ctrl->listRules((int)$id),
                $method === 'POST' && $subId === null    => $ctrl->createRule((int)$id, $payload),
                $method === 'DELETE' && $subId !== null   => (function () use ($ctrl, $id, $subId) { $ctrl->deleteRule((int)$id, (int)$subId); return ['_status' => 204]; })(),
                default => null,
            };
        } else {
            $response = match (true) {
                $method === 'GET' && $id === null     => $ctrl->list($params),
                $method === 'GET' && $id !== null      => $ctrl->get((int)$id),
                $method === 'POST' && $id === null     => array_merge($ctrl->create($payload), ['_status' => 201]),
                $method === 'PUT' && $id !== null       => $ctrl->update((int)$id, $payload),
                $method === 'PATCH' && $id !== null     => $ctrl->update((int)$id, $payload),
                $method === 'DELETE' && $id !== null    => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['_status' => 204]; })(),
                default => null,
            };
        }
    }

    // --- Attribution ---
    elseif ($resource === 'attribution' && ($id === 'models' || $id === null)) {
        $ctrl = new \Api\V3\Controllers\AttributionController();
        $modelId = $sub;
        $modelSub = $segments[3] ?? null;

        if ($modelId === null) {
            $response = match ($method) {
                'GET'  => $ctrl->listModels($params),
                'POST' => array_merge($ctrl->createModel($payload), ['_status' => 201]),
                default => null,
            };
        } elseif ($modelSub === null) {
            $response = match ($method) {
                'GET'    => $ctrl->getModel((int)$modelId),
                'PUT', 'PATCH' => $ctrl->updateModel((int)$modelId, $payload),
                'DELETE' => (function () use ($ctrl, $modelId) { $ctrl->deleteModel((int)$modelId); return ['_status' => 204]; })(),
                default  => null,
            };
        } elseif ($modelSub === 'snapshots' && $method === 'GET') {
            $response = $ctrl->listSnapshots((int)$modelId, $params);
        } elseif ($modelSub === 'exports') {
            $response = match ($method) {
                'GET'  => $ctrl->listExports((int)$modelId),
                'POST' => array_merge($ctrl->scheduleExport((int)$modelId, $payload), ['_status' => 201]),
                default => null,
            };
        }
    }

    // --- Users (admin-gated writes, self-or-admin for reads) ---
    elseif ($resource === 'users') {
        $ctrl = new \Api\V3\Controllers\UsersController();

        if ($id === 'roles' && $method === 'GET') {
            $response = $ctrl->listRoles();
        } elseif ($id !== null && $sub === 'roles') {
            Bootstrap::requireAdmin();
            $response = match (true) {
                $method === 'POST' && $subId === null     => $ctrl->assignRole((int)$id, $payload),
                $method === 'DELETE' && $subId !== null    => (function () use ($ctrl, $id, $subId) { $ctrl->removeRole((int)$id, (int)$subId); return ['_status' => 204]; })(),
                default => null,
            };
        } elseif ($id !== null && $sub === 'api-keys') {
            Bootstrap::requireSelfOrAdmin((int)$id);
            $response = match (true) {
                $method === 'GET'                          => $ctrl->listApiKeys((int)$id),
                $method === 'POST'                         => array_merge($ctrl->createApiKey((int)$id), ['_status' => 201]),
                $method === 'DELETE' && $subId !== null     => (function () use ($ctrl, $id, $subId) { $ctrl->deleteApiKey((int)$id, $subId); return ['_status' => 204]; })(),
                default => null,
            };
        } elseif ($id !== null && $sub === 'preferences') {
            Bootstrap::requireSelfOrAdmin((int)$id);
            $response = match ($method) {
                'GET' => $ctrl->getPreferences((int)$id),
                'PUT', 'PATCH' => $ctrl->updatePreferences((int)$id, $payload),
                default => null,
            };
        } else {
            if ($method === 'GET' && $id === null) {
                Bootstrap::requireAdmin();
                $response = $ctrl->list();
            } elseif ($method === 'GET' && $id !== null) {
                Bootstrap::requireSelfOrAdmin((int)$id);
                $response = $ctrl->get((int)$id);
            } elseif ($method === 'POST' && $id === null) {
                Bootstrap::requireAdmin();
                $response = array_merge($ctrl->create($payload), ['_status' => 201]);
            } elseif (($method === 'PUT' || $method === 'PATCH') && $id !== null) {
                Bootstrap::requireSelfOrAdmin((int)$id);
                $response = $ctrl->update((int)$id, $payload);
            } elseif ($method === 'DELETE' && $id !== null) {
                Bootstrap::requireAdmin();
                $ctrl->delete((int)$id);
                $response = ['_status' => 204];
            }
        }
    }

    // --- System (admin only, except health which is pre-auth above) ---
    elseif ($resource === 'system') {
        Bootstrap::requireAdmin();
        $ctrl = new \Api\V3\Controllers\SystemController();
        $response = match ($id) {
            'health'     => $method === 'GET' ? $ctrl->health() : null,
            'version'    => $method === 'GET' ? $ctrl->version() : null,
            'db-stats'   => $method === 'GET' ? $ctrl->dbStats() : null,
            'cron'       => $method === 'GET' ? $ctrl->cronStatus() : null,
            'errors'     => $method === 'GET' ? $ctrl->errors($params) : null,
            'dataengine' => $method === 'GET' ? $ctrl->dataengineStatus() : null,
            default      => null,
        };
    }

    // --- Root ---
    elseif ($resource === '') {
        $response = [
            'api' => 'Prosper202 API v3',
            'endpoints' => [
                'campaigns' => '/campaigns', 'aff_networks' => '/aff-networks',
                'ppc_networks' => '/ppc-networks', 'ppc_accounts' => '/ppc-accounts',
                'trackers' => '/trackers', 'landing_pages' => '/landing-pages',
                'text_ads' => '/text-ads', 'clicks' => '/clicks',
                'conversions' => '/conversions', 'reports' => '/reports/{summary|breakdown|timeseries}',
                'rotators' => '/rotators', 'attribution' => '/attribution/models',
                'users' => '/users', 'system' => '/system/{health|version|db-stats|cron|errors|dataengine}',
            ],
            'auth' => 'Authorization: Bearer <api_key>',
        ];
    }

    if ($response === null) {
        Bootstrap::errorResponse('Not found', 404);
    } else {
        $status = $response['_status'] ?? 200;
        unset($response['_status']);
        if ($status === 204) {
            http_response_code(204);
        } else {
            Bootstrap::jsonResponse($response, $status);
        }
    }

} catch (AuthException $e) {
    Bootstrap::errorResponse($e->getMessage(), $e->getCode() ?: 401);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    $code = ($code >= 400 && $code < 600) ? $code : 500;
    $message = $code === 500 ? 'Internal server error' : $e->getMessage();
    Bootstrap::errorResponse($message, $code);
} catch (\Throwable $e) {
    Bootstrap::errorResponse('Internal server error', 500);
}

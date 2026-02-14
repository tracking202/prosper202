<?php

declare(strict_types=1);

/**
 * Prosper202 API v3 - Single entry point
 *
 * All requests are routed through this file via .htaccess rewrite.
 * Authentication is via API key: Authorization: Bearer <key> or ?apikey=<key>
 *
 * Routes:
 *   GET    /campaigns              GET    /campaigns/:id
 *   POST   /campaigns              PUT    /campaigns/:id          DELETE /campaigns/:id
 *   GET    /aff-networks           GET    /aff-networks/:id
 *   POST   /aff-networks           PUT    /aff-networks/:id       DELETE /aff-networks/:id
 *   GET    /ppc-networks           GET    /ppc-networks/:id
 *   POST   /ppc-networks           PUT    /ppc-networks/:id       DELETE /ppc-networks/:id
 *   GET    /ppc-accounts           GET    /ppc-accounts/:id
 *   POST   /ppc-accounts           PUT    /ppc-accounts/:id       DELETE /ppc-accounts/:id
 *   GET    /trackers               GET    /trackers/:id
 *   POST   /trackers               PUT    /trackers/:id           DELETE /trackers/:id
 *   GET    /trackers/:id/url
 *   GET    /landing-pages          GET    /landing-pages/:id
 *   POST   /landing-pages          PUT    /landing-pages/:id      DELETE /landing-pages/:id
 *   GET    /text-ads               GET    /text-ads/:id
 *   POST   /text-ads               PUT    /text-ads/:id           DELETE /text-ads/:id
 *   GET    /clicks                 GET    /clicks/:id
 *   GET    /conversions            GET    /conversions/:id
 *   POST   /conversions            DELETE /conversions/:id
 *   GET    /reports/summary        GET    /reports/breakdown      GET    /reports/timeseries
 *   GET    /rotators               GET    /rotators/:id
 *   POST   /rotators               PUT    /rotators/:id           DELETE /rotators/:id
 *   GET    /rotators/:id/rules     POST   /rotators/:id/rules     DELETE /rotators/:id/rules/:ruleId
 *   GET    /attribution/models     GET    /attribution/models/:id
 *   POST   /attribution/models     PUT    /attribution/models/:id DELETE /attribution/models/:id
 *   GET    /attribution/models/:id/snapshots
 *   GET    /attribution/models/:id/exports
 *   POST   /attribution/models/:id/exports
 *   GET    /users                  GET    /users/:id
 *   POST   /users                  PUT    /users/:id              DELETE /users/:id
 *   GET    /users/roles            POST   /users/:id/roles        DELETE /users/:id/roles/:roleId
 *   GET    /users/:id/api-keys     POST   /users/:id/api-keys     DELETE /users/:id/api-keys/:key
 *   GET    /users/:id/preferences  PUT    /users/:id/preferences
 *   GET    /system/health          GET    /system/version         GET    /system/db-stats
 *   GET    /system/cron            GET    /system/errors          GET    /system/dataengine
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Api\V3\Bootstrap;
use Api\V3\AuthException;

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    Bootstrap::init();

    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['REQUEST_URI'];

    // Strip base path (everything up to and including /api/v3)
    $basePath = '/api/v3';
    $pos = strpos($path, $basePath);
    if ($pos !== false) {
        $path = substr($path, $pos + strlen($basePath));
    }

    // Strip query string
    $path = strtok($path, '?') ?: '/';
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }

    $params = $_GET;
    $headers = getallheaders() ?: [];

    // Health check doesn't require auth
    if ($path === '/system/health' && $method === 'GET') {
        Bootstrap::init();
        $db = Bootstrap::db();
        Bootstrap::jsonResponse([
            'data' => ['status' => 'healthy', 'timestamp' => time(), 'api_version' => 'v3'],
        ]);
        exit;
    }

    // Authenticate
    Bootstrap::authenticate($params, $headers);

    // Parse JSON body for POST/PUT/PATCH
    $payload = [];
    if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $payload = json_decode($raw, true) ?? [];
        }
    }

    // Route matching
    $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
    $response = route($method, $segments, $params, $payload);

    if ($response === null) {
        Bootstrap::errorResponse('Not found', 404);
    } else {
        $status = isset($response['_status']) ? $response['_status'] : 200;
        unset($response['_status']);
        Bootstrap::jsonResponse($response, $status);
    }

} catch (AuthException $e) {
    Bootstrap::errorResponse($e->getMessage(), $e->getCode() ?: 401);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    $code = ($code >= 400 && $code < 600) ? $code : 500;
    Bootstrap::errorResponse($e->getMessage(), $code);
} catch (\Throwable $e) {
    Bootstrap::errorResponse('Internal server error', 500);
}

function route(string $method, array $seg, array $params, array $payload): ?array
{
    $resource = $seg[0] ?? '';
    $id = isset($seg[1]) ? $seg[1] : null;
    $sub = $seg[2] ?? null;
    $subId = $seg[3] ?? null;

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

        // Special: GET /trackers/:id/url
        if ($resource === 'trackers' && $id !== null && $sub === 'url' && $method === 'GET') {
            return $ctrl->getTrackingUrl((int)$id);
        }

        return match (true) {
            $method === 'GET' && $id === null    => $ctrl->list($params),
            $method === 'GET' && $id !== null     => $ctrl->get((int)$id),
            $method === 'POST' && $id === null    => array_merge($ctrl->create($payload), ['_status' => 201]),
            $method === 'PUT' && $id !== null      => $ctrl->update((int)$id, $payload),
            $method === 'PATCH' && $id !== null    => $ctrl->update((int)$id, $payload),
            $method === 'DELETE' && $id !== null   => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['message' => 'Deleted']; })(),
            default => null,
        };
    }

    // --- Clicks ---
    if ($resource === 'clicks') {
        $ctrl = new \Api\V3\Controllers\ClicksController();
        return match (true) {
            $method === 'GET' && $id === null  => $ctrl->list($params),
            $method === 'GET' && $id !== null   => $ctrl->get((int)$id),
            default => null,
        };
    }

    // --- Conversions ---
    if ($resource === 'conversions') {
        $ctrl = new \Api\V3\Controllers\ConversionsController();
        return match (true) {
            $method === 'GET' && $id === null     => $ctrl->list($params),
            $method === 'GET' && $id !== null      => $ctrl->get((int)$id),
            $method === 'POST' && $id === null     => array_merge($ctrl->create($payload), ['_status' => 201]),
            $method === 'DELETE' && $id !== null    => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['message' => 'Deleted']; })(),
            default => null,
        };
    }

    // --- Reports ---
    if ($resource === 'reports') {
        $ctrl = new \Api\V3\Controllers\ReportsController();
        return match ($id) {
            'summary'    => $method === 'GET' ? $ctrl->summary($params) : null,
            'breakdown'  => $method === 'GET' ? $ctrl->breakdown($params) : null,
            'timeseries' => $method === 'GET' ? $ctrl->timeseries($params) : null,
            default      => null,
        };
    }

    // --- Rotators ---
    if ($resource === 'rotators') {
        $ctrl = new \Api\V3\Controllers\RotatorsController();

        // Sub-resources: /rotators/:id/rules, /rotators/:id/rules/:ruleId
        if ($id !== null && $sub === 'rules') {
            return match (true) {
                $method === 'GET' && $subId === null     => $ctrl->listRules((int)$id),
                $method === 'POST' && $subId === null    => $ctrl->createRule((int)$id, $payload),
                $method === 'DELETE' && $subId !== null   => (function () use ($ctrl, $id, $subId) { $ctrl->deleteRule((int)$id, (int)$subId); return ['message' => 'Deleted']; })(),
                default => null,
            };
        }

        return match (true) {
            $method === 'GET' && $id === null     => $ctrl->list($params),
            $method === 'GET' && $id !== null      => $ctrl->get((int)$id),
            $method === 'POST' && $id === null     => array_merge($ctrl->create($payload), ['_status' => 201]),
            $method === 'PUT' && $id !== null       => $ctrl->update((int)$id, $payload),
            $method === 'PATCH' && $id !== null     => $ctrl->update((int)$id, $payload),
            $method === 'DELETE' && $id !== null    => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['message' => 'Deleted']; })(),
            default => null,
        };
    }

    // --- Attribution ---
    if ($resource === 'attribution' && ($id === 'models' || $id === null)) {
        $ctrl = new \Api\V3\Controllers\AttributionController();
        $modelId = $sub;
        $modelSub = $seg[3] ?? null;

        if ($modelId === null) {
            return match ($method) {
                'GET'  => $ctrl->listModels($params),
                'POST' => array_merge($ctrl->createModel($payload), ['_status' => 201]),
                default => null,
            };
        }

        if ($modelSub === null) {
            return match ($method) {
                'GET'    => $ctrl->getModel((int)$modelId),
                'PUT', 'PATCH' => $ctrl->updateModel((int)$modelId, $payload),
                'DELETE' => (function () use ($ctrl, $modelId) { $ctrl->deleteModel((int)$modelId); return ['message' => 'Deleted']; })(),
                default  => null,
            };
        }

        if ($modelSub === 'snapshots' && $method === 'GET') {
            return $ctrl->listSnapshots((int)$modelId, $params);
        }

        if ($modelSub === 'exports') {
            return match ($method) {
                'GET'  => $ctrl->listExports((int)$modelId),
                'POST' => array_merge($ctrl->scheduleExport((int)$modelId, $payload), ['_status' => 201]),
                default => null,
            };
        }
    }

    // --- Users ---
    if ($resource === 'users') {
        $ctrl = new \Api\V3\Controllers\UsersController();

        // /users/roles (list all roles)
        if ($id === 'roles' && $method === 'GET') {
            return $ctrl->listRoles();
        }

        // /users/:id/roles
        if ($id !== null && $sub === 'roles') {
            return match (true) {
                $method === 'POST' && $subId === null     => $ctrl->assignRole((int)$id, $payload),
                $method === 'DELETE' && $subId !== null    => (function () use ($ctrl, $id, $subId) { $ctrl->removeRole((int)$id, (int)$subId); return ['message' => 'Deleted']; })(),
                default => null,
            };
        }

        // /users/:id/api-keys
        if ($id !== null && $sub === 'api-keys') {
            return match (true) {
                $method === 'GET'                          => $ctrl->listApiKeys((int)$id),
                $method === 'POST'                         => array_merge($ctrl->createApiKey((int)$id), ['_status' => 201]),
                $method === 'DELETE' && $subId !== null     => (function () use ($ctrl, $id, $subId) { $ctrl->deleteApiKey((int)$id, $subId); return ['message' => 'Deleted']; })(),
                default => null,
            };
        }

        // /users/:id/preferences
        if ($id !== null && $sub === 'preferences') {
            return match ($method) {
                'GET' => $ctrl->getPreferences((int)$id),
                'PUT', 'PATCH' => $ctrl->updatePreferences((int)$id, $payload),
                default => null,
            };
        }

        return match (true) {
            $method === 'GET' && $id === null     => $ctrl->list(),
            $method === 'GET' && $id !== null      => $ctrl->get((int)$id),
            $method === 'POST' && $id === null     => array_merge($ctrl->create($payload), ['_status' => 201]),
            $method === 'PUT' && $id !== null       => $ctrl->update((int)$id, $payload),
            $method === 'PATCH' && $id !== null     => $ctrl->update((int)$id, $payload),
            $method === 'DELETE' && $id !== null    => (function () use ($ctrl, $id) { $ctrl->delete((int)$id); return ['message' => 'Deleted']; })(),
            default => null,
        };
    }

    // --- System ---
    if ($resource === 'system') {
        $ctrl = new \Api\V3\Controllers\SystemController();
        return match ($id) {
            'health'     => $method === 'GET' ? $ctrl->health() : null,
            'version'    => $method === 'GET' ? $ctrl->version() : null,
            'db-stats'   => $method === 'GET' ? $ctrl->dbStats() : null,
            'cron'       => $method === 'GET' ? $ctrl->cronStatus() : null,
            'errors'     => $method === 'GET' ? $ctrl->errors($params) : null,
            'dataengine' => $method === 'GET' ? $ctrl->dataengineStatus() : null,
            default      => null,
        };
    }

    // --- Root: API documentation ---
    if ($resource === '' || $path === '/') {
        return [
            'api' => 'Prosper202 API v3',
            'endpoints' => [
                'campaigns'     => '/campaigns',
                'aff_networks'  => '/aff-networks',
                'ppc_networks'  => '/ppc-networks',
                'ppc_accounts'  => '/ppc-accounts',
                'trackers'      => '/trackers',
                'landing_pages' => '/landing-pages',
                'text_ads'      => '/text-ads',
                'clicks'        => '/clicks',
                'conversions'   => '/conversions',
                'reports'       => '/reports/{summary|breakdown|timeseries}',
                'rotators'      => '/rotators',
                'attribution'   => '/attribution/models',
                'users'         => '/users',
                'system'        => '/system/{health|version|db-stats|cron|errors|dataengine}',
            ],
            'auth' => 'Authorization: Bearer <api_key> or ?apikey=<api_key>',
        ];
    }

    return null;
}

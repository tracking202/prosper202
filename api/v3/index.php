<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Load config at file scope so DB globals ($dbhost, $dbuser, etc.) are
// available via the 'global' keyword in the DB class constructor.
// Including inside Bootstrap::init() scopes them locally and breaks DB.
require_once dirname(__DIR__, 2) . '/202-config.php';

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Bootstrap;
use Api\V3\HttpException;
use Api\V3\Router;
use Api\V3\Exception\ValidationException;

// ─── Security headers ────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ─── Bootstrap ───────────────────────────────────────────────────────
try {
    Bootstrap::init();
    $db = Bootstrap::db();
} catch (\Throwable $e) {
    Bootstrap::errorResponse('Service unavailable', 503);
    exit;
}

// ─── CORS (after init so config constants are available) ─────────────
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

// ─── Parse request ───────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$path    = $_SERVER['REQUEST_URI'];
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

$queryParams = $_GET;
$headers     = getallheaders() ?: [];

$payload = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input', false, null, 0, 1_048_576); // 1 MB limit
    if ($raw !== '' && $raw !== false) {
        $payload = json_decode($raw, true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            Bootstrap::errorResponse('Invalid JSON body', 400);
            exit;
        }
        $payload = $payload ?? [];
    }
}

// ─── Route definitions ───────────────────────────────────────────────
try {

    // Unauthenticated health probe
    if ($path === '/system/health' && $method === 'GET') {
        Bootstrap::jsonResponse([
            'data' => ['status' => 'healthy', 'timestamp' => time(), 'api_version' => 'v3'],
        ]);
        exit;
    }

    // Authenticate
    $auth = Auth::fromRequest($headers, $db);
    $userId = $auth->userId();

    // Controller factories — instantiated lazily by the router handlers.
    $crud = fn(string $class) => new $class($db, $userId);

    $router = new Router();

    // ── CRUD resources ───────────────────────────────────────────────
    $crudMap = [
        'campaigns'     => \Api\V3\Controllers\CampaignsController::class,
        'aff-networks'  => \Api\V3\Controllers\AffNetworksController::class,
        'ppc-networks'  => \Api\V3\Controllers\PpcNetworksController::class,
        'ppc-accounts'  => \Api\V3\Controllers\PpcAccountsController::class,
        'trackers'      => \Api\V3\Controllers\TrackersController::class,
        'landing-pages' => \Api\V3\Controllers\LandingPagesController::class,
        'text-ads'      => \Api\V3\Controllers\TextAdsController::class,
    ];

    foreach ($crudMap as $resource => $class) {
        $router->group("/$resource", function (Router $r) use ($class, $crud, &$queryParams, &$payload) {
            $r->get('',       fn() => $crud($class)->list($queryParams));
            $r->get('/{id}',  fn($ctx) => $crud($class)->get((int)$ctx['id']));
            $r->post('',      fn() => ['_status' => 201] + $crud($class)->create($payload));
            $r->put('/{id}',  fn($ctx) => $crud($class)->update((int)$ctx['id'], $payload));
            $r->delete('/{id}', fn($ctx) => tap($crud($class), fn($c) => $c->delete((int)$ctx['id'])));
        });
    }

    // Tracker sub-resource
    $router->get('/trackers/{id}/url', function ($ctx) use ($crud) {
        return $crud(\Api\V3\Controllers\TrackersController::class)->getTrackingUrl((int)$ctx['id']);
    });

    // ── Clicks (read-only) ───────────────────────────────────────────
    $router->get('/clicks', fn() => $crud(\Api\V3\Controllers\ClicksController::class)->list($queryParams));
    $router->get('/clicks/{id}', fn($ctx) => $crud(\Api\V3\Controllers\ClicksController::class)->get((int)$ctx['id']));

    // ── Conversions ──────────────────────────────────────────────────
    $router->group('/conversions', function (Router $r) use ($crud, &$queryParams, &$payload) {
        $cls = \Api\V3\Controllers\ConversionsController::class;
        $r->get('',        fn() => $crud($cls)->list($queryParams));
        $r->get('/{id}',   fn($ctx) => $crud($cls)->get((int)$ctx['id']));
        $r->post('',       fn() => ['_status' => 201] + $crud($cls)->create($payload));
        $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->delete((int)$ctx['id'])));
    });

    // ── Reports ──────────────────────────────────────────────────────
    $router->group('/reports', function (Router $r) use ($crud, &$queryParams) {
        $cls = \Api\V3\Controllers\ReportsController::class;
        $r->get('/summary',    fn() => $crud($cls)->summary($queryParams));
        $r->get('/breakdown',  fn() => $crud($cls)->breakdown($queryParams));
        $r->get('/timeseries', fn() => $crud($cls)->timeseries($queryParams));
        $r->get('/daypart',    fn() => $crud($cls)->daypart($queryParams));
        $r->get('/weekpart',   fn() => $crud($cls)->weekpart($queryParams));
    });

    // ── Rotators ─────────────────────────────────────────────────────
    $router->group('/rotators', function (Router $r) use ($crud, &$queryParams, &$payload) {
        $cls = \Api\V3\Controllers\RotatorsController::class;
        $r->get('',        fn() => $crud($cls)->list($queryParams));
        $r->get('/{id}',   fn($ctx) => $crud($cls)->get((int)$ctx['id']));
        $r->post('',       fn() => ['_status' => 201] + $crud($cls)->create($payload));
        $r->put('/{id}',   fn($ctx) => $crud($cls)->update((int)$ctx['id'], $payload));
        $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->delete((int)$ctx['id'])));

        // Sub-resource: rules
        $r->get('/{id}/rules',             fn($ctx) => $crud($cls)->listRules((int)$ctx['id']));
        $r->post('/{id}/rules',            fn($ctx) => $crud($cls)->createRule((int)$ctx['id'], $payload));
        $r->delete('/{id}/rules/{ruleId}', fn($ctx) => tap($crud($cls), fn($c) => $c->deleteRule((int)$ctx['id'], (int)$ctx['ruleId'])));
    });

    // ── Attribution ──────────────────────────────────────────────────
    $router->group('/attribution/models', function (Router $r) use ($crud, &$queryParams, &$payload) {
        $cls = \Api\V3\Controllers\AttributionController::class;
        $r->get('',        fn() => $crud($cls)->listModels($queryParams));
        $r->post('',       fn() => ['_status' => 201] + $crud($cls)->createModel($payload));
        $r->get('/{id}',   fn($ctx) => $crud($cls)->getModel((int)$ctx['id']));
        $r->put('/{id}',   fn($ctx) => $crud($cls)->updateModel((int)$ctx['id'], $payload));
        $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->deleteModel((int)$ctx['id'])));

        $r->get('/{id}/snapshots', fn($ctx) => $crud($cls)->listSnapshots((int)$ctx['id'], $queryParams));
        $r->get('/{id}/exports',   fn($ctx) => $crud($cls)->listExports((int)$ctx['id']));
        $r->post('/{id}/exports',  fn($ctx) => ['_status' => 201] + $crud($cls)->scheduleExport((int)$ctx['id'], $payload));
    });

    // ── Users (admin-gated writes, self-or-admin for reads) ──────────
    $router->group('/users', function (Router $r) use ($db, $userId, $auth, &$payload) {
        $make = fn() => new \Api\V3\Controllers\UsersController($db, $userId);

        $r->get('/roles', fn() => $make()->listRoles());

        $r->get('', function () use ($auth, $make) {
            $auth->requireAdmin();
            return $make()->list();
        });
        $r->post('', function () use ($auth, $make, &$payload) {
            $auth->requireAdmin();
            return ['_status' => 201] + $make()->create($payload);
        });
        $r->get('/{id}', function ($ctx) use ($auth, $make) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return $make()->get((int)$ctx['id']);
        });
        $r->put('/{id}', function ($ctx) use ($auth, $make, &$payload) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return $make()->update((int)$ctx['id'], $payload);
        });
        $r->delete('/{id}', function ($ctx) use ($auth, $make) {
            $auth->requireAdmin();
            $make()->delete((int)$ctx['id']);
            return null; // 204
        });

        // Roles sub-resource (admin only)
        $r->post('/{id}/roles', function ($ctx) use ($auth, $make, &$payload) {
            $auth->requireAdmin();
            return $make()->assignRole((int)$ctx['id'], $payload);
        });
        $r->delete('/{id}/roles/{roleId}', function ($ctx) use ($auth, $make) {
            $auth->requireAdmin();
            $make()->removeRole((int)$ctx['id'], (int)$ctx['roleId']);
            return null;
        });

        // API keys (self-or-admin)
        $r->get('/{id}/api-keys', function ($ctx) use ($auth, $make) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return $make()->listApiKeys((int)$ctx['id']);
        });
        $r->post('/{id}/api-keys', function ($ctx) use ($auth, $make) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return ['_status' => 201] + $make()->createApiKey((int)$ctx['id']);
        });
        $r->delete('/{id}/api-keys/{keyId}', function ($ctx) use ($auth, $make) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            $make()->deleteApiKey((int)$ctx['id'], $ctx['keyId']);
            return null;
        });

        // Preferences (self-or-admin)
        $r->get('/{id}/preferences', function ($ctx) use ($auth, $make) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return $make()->getPreferences((int)$ctx['id']);
        });
        $r->put('/{id}/preferences', function ($ctx) use ($auth, $make, &$payload) {
            $auth->requireSelfOrAdmin((int)$ctx['id']);
            return $make()->updatePreferences((int)$ctx['id'], $payload);
        });
    });

    // ── System (admin only; /health is handled above without auth) ─────
    $router->group('/system', function (Router $r) use ($db, $auth, &$queryParams) {
        $make = fn() => new \Api\V3\Controllers\SystemController($db);

        $r->get('/version',    fn() => $make()->version());
        $r->get('/db-stats',   fn() => $make()->dbStats());
        $r->get('/cron',       fn() => $make()->cronStatus());
        $r->get('/errors',     fn() => $make()->errors($queryParams));
        $r->get('/dataengine', fn() => $make()->dataengineStatus());
    }, [$auth->requireAdmin(...)]);

    // ── API root ─────────────────────────────────────────────────────
    $router->get('/', fn() => [
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
            'reports'       => '/reports/{summary|breakdown|timeseries|daypart|weekpart}',
            'rotators'      => '/rotators',
            'attribution'   => '/attribution/models',
            'users'         => '/users',
            'system'        => '/system/{health|version|db-stats|cron|errors|dataengine}',
        ],
        'auth' => 'Authorization: Bearer <api_key>',
    ]);

    // ─── Dispatch ────────────────────────────────────────────────────
    $match = $router->match($method, $path);

    if ($match === null) {
        Bootstrap::errorResponse('Not found', 404);
        exit;
    }

    // Run middleware stack
    foreach ($match['middleware'] as $mw) {
        $mw();
    }

    // Execute handler
    $response = ($match['handler'])($match['pathParams']);

    // ─── Send response ───────────────────────────────────────────────
    if ($response === null) {
        // DELETE — 204 No Content
        http_response_code(204);
    } else {
        $status = $response['_status'] ?? 200;
        unset($response['_status']);
        Bootstrap::jsonResponse($response, $status);
    }

} catch (AuthException $e) {
    Bootstrap::errorResponse($e->getMessage(), $e->getCode() ?: 401);
} catch (ValidationException $e) {
    Bootstrap::errorResponse($e->getMessage(), 422, $e->getFieldErrors() ? ['field_errors' => $e->getFieldErrors()] : []);
} catch (HttpException $e) {
    $code = $e->getHttpStatus();
    $message = $code >= 500 ? 'Internal server error' : $e->getMessage();
    Bootstrap::errorResponse($message, $code);
} catch (\Throwable $e) {
    Bootstrap::errorResponse('Internal server error', 500);
}

// ─── Helper ──────────────────────────────────────────────────────────
/**
 * Execute a side-effect on an object and return null (for DELETE handlers).
 */
function tap(object $obj, callable $fn): null
{
    $fn($obj);
    return null;
}

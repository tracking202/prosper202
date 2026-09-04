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
use Api\V3\RequestContext;
use Api\V3\Router;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\ServerStateStore;

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
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-P202-API-Version, Idempotency-Key, If-Match');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Parse request ───────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/v3')) {
    $path = substr($path, strlen('/api/v3'));
} elseif (str_starts_with($path, '/api/')) {
    $path = substr($path, strlen('/api'));
}
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

$queryParams = $_GET;
$headers     = getallheaders() ?: [];
RequestContext::setHeaders($headers);

$payload = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $raw = file_get_contents('php://input', false, null, 0, 1_048_576); // 1 MB limit
    if ($raw !== '' && $raw !== false) {
        $payload = json_decode($raw, true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            Bootstrap::errorResponse('Invalid JSON body', 400);
            exit;
        }
        if ($payload !== null && !is_array($payload)) {
            Bootstrap::errorResponse('Invalid JSON body', 400);
            exit;
        }
        $payload = $payload ?? [];
    }
}

$requestedVersion = strtolower(trim((string)($headers['X-P202-API-Version'] ?? $headers['x-p202-api-version'] ?? '')));
if ($requestedVersion !== '' && !in_array($requestedVersion, ['v3', '3'], true)) {
    Bootstrap::errorResponse(
        'Unsupported API version',
        400,
        ['supported_versions' => ['v3'], 'requested_version' => $requestedVersion]
    );
    exit;
}
RequestContext::setResolvedApiVersion('v3');
header('X-P202-API-Version-Resolved: v3');

$adoptionPct = (int)(getenv('P202_CAPABILITY_ADOPTION_PCT') ?: 0);
$deprecationThresholdPct = (int)(getenv('P202_DEPRECATION_THRESHOLD_PCT') ?: 95);
if ($adoptionPct >= $deprecationThresholdPct && shouldEmitDeprecationNotice($path, $requestedVersion)) {
    $sunset = (string)(getenv('P202_DEPRECATION_SUNSET') ?: gmdate('D, d M Y H:i:s \\G\\M\\T', strtotime('+180 days')));
    header('Deprecation: true');
    header('Sunset: ' . $sunset);
    header('Warning: 299 - "Legacy unversioned API requests are deprecated; use X-P202-API-Version: v3."');
}

// ─── Route definitions ───────────────────────────────────────────────
try {

    // Unauthenticated API version discovery
    if ($path === '/versions' && $method === 'GET') {
        $versions = new \Api\V3\Controllers\CapabilitiesController($db);
        Bootstrap::jsonResponse($versions->versions());
        exit;
    }

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
    RequestContext::setActorUserId($userId);

    // Lightweight fixed-window rate limits for high-impact operations.
    $stateStore = new ServerStateStore();
    $bucketKey = 'user:' . $userId;
    $limit = null;
    $windowSeconds = 60;
    if (str_starts_with($path, '/sync')) {
        $limit = 30;
        $bucketKey .= ':sync';
    } elseif (str_contains($path, '/bulk-upsert')) {
        $limit = 60;
        $bucketKey .= ':bulk-upsert';
    }
    if ($limit !== null) {
        $rate = $stateStore->consumeRateLimit($bucketKey, $limit, $windowSeconds);
        if (!$rate['allowed']) {
            $retryAfter = max(1, (int)$rate['reset_at'] - time());
            header('Retry-After: ' . $retryAfter);
            Bootstrap::errorResponse(
                'Rate limit exceeded',
                429,
                [
                    'rate_limit' => ['limit' => $limit, 'window_seconds' => $windowSeconds],
                    'retry_after_seconds' => $retryAfter,
                ]
            );
            exit;
        }
    }

    // ── CRUD resources ───────────────────────────────────────────────
    $crudMap = [
        'campaigns'     => \Api\V3\Controllers\CampaignsController::class,
        'aff-networks'  => \Api\V3\Controllers\AffNetworksController::class,
        'ppc-networks'  => \Api\V3\Controllers\PpcNetworksController::class,
        'ppc-accounts'  => \Api\V3\Controllers\PpcAccountsController::class,
        'trackers'      => \Api\V3\Controllers\TrackersController::class,
        'landing-pages' => \Api\V3\Controllers\LandingPagesController::class,
        'text-ads'          => \Api\V3\Controllers\TextAdsController::class,
        'forecast-events'   => \Api\V3\Controllers\ForecastEventsController::class,
    ];

    // ── Route definitions ────────────────────────────────────────────
    // Routes are built as a pure function of the request context: the
    // payload, the query string, and the user the write is performed as.
    // Applying a staged change rebuilds them around the recorded payload
    // and the proposer's id, so the applied write is exactly the reviewed
    // one. Nothing is swapped by reference: a handler written as an arrow
    // function captures by value at definition time and would otherwise
    // never see a later swap.
    $buildRouters = function (array $payload, array $queryParams, int $actorUserId)
        use ($db, $auth, $stateStore, $crudMap): array {
        // Controller factories — instantiated lazily by the router handlers.
        $crud = fn(string $class) => new $class($db, $actorUserId);

        // Single-create idempotency: when a POST create carries an
        // Idempotency-Key header, the response is recorded and replayed on a
        // retry with the same key and payload, so a retried create cannot
        // duplicate the row. Mirrors bulkUpsert(): the payload hash is part of
        // the storage scope (same key + different payload is a fresh create,
        // not a conflict) and replays carry idempotent_replay: true. API-key
        // creation is deliberately not wrapped — its response contains the
        // secret, which must never persist in the server-state store.
        $idempotent = static function (string $operation, array $requestPayload, callable $op) use ($actorUserId, $stateStore): array {
            $key = trim((string)(RequestContext::header('idempotency-key') ?? ''));
            if ($key === '') {
                return $op();
            }
            $scope = 'create:' . $operation . ':user:' . $actorUserId
                . ':request:' . ServerStateStore::canonicalHash($requestPayload);
            // Claim the key atomically: a plain read-then-write left a
            // window where two concurrent retries both missed the record and
            // both created a row.
            $reservation = $stateStore->reserveIdempotent($scope, $key);
            if ($reservation['state'] === 'replay') {
                $existing = $reservation['response'] ?? [];
                $existing['idempotent_replay'] = true;
                return $existing;
            }
            if ($reservation['state'] === 'in_flight') {
                throw new ConflictException(
                    'A request with this Idempotency-Key is still in flight; retry once it completes '
                    . 'to receive the recorded response.'
                );
            }
            if ($reservation['state'] === 'indeterminate') {
                // A previous holder died without recording a response, so
                // whether it created the record is unknowable. Replaying is
                // impossible and re-executing could duplicate exactly what
                // the key exists to prevent, so the key stays spent.
                throw new ConflictException(
                    'A previous request with this Idempotency-Key did not finish, so whether it created '
                    . 'the record is unknown and this key can be neither replayed nor reused. Check '
                    . 'whether the record exists, then retry with a new Idempotency-Key if it does not.'
                );
            }
            try {
                $response = $op();
            } catch (WriteCommittedException $e) {
                // The row exists; only the steps after it failed. Releasing
                // the claim would invite a retry that creates a second row,
                // so the key is marked spent — from the very next retry, not
                // once the claim ages out.
                $stateStore->markIdempotentIndeterminate($scope, $key);
                throw $e;
            } catch (\Throwable $e) {
                // Nothing was written, so free the key: the caller can
                // correct and retry rather than being told a request is in
                // flight.
                $stateStore->releaseIdempotent($scope, $key);
                throw $e;
            }
            $stateStore->putIdempotent($scope, $key, $response);
            return $response;
        };

        $router = new Router();


        foreach ($crudMap as $resource => $class) {
            $router->group("/$resource", function (Router $r) use ($resource, $class, $crud, $idempotent, $queryParams, $payload) {
                $r->get('',       fn() => $crud($class)->list($queryParams));
                $r->post('/bulk-upsert', fn() => $crud($class)->bulkUpsert($payload));
                $r->get('/{id}',  fn($ctx) => $crud($class)->get((int)$ctx['id']));
                $r->post('',      fn() => ['_status' => 201] + $idempotent($resource, $payload, fn() => $crud($class)->create($payload)));
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
        $router->group('/conversions', function (Router $r) use ($crud, $idempotent, $queryParams, $payload) {
            $cls = \Api\V3\Controllers\ConversionsController::class;
            $r->get('',        fn() => $crud($cls)->list($queryParams));
            $r->get('/{id}',   fn($ctx) => $crud($cls)->get((int)$ctx['id']));
            $r->post('',       fn() => ['_status' => 201] + $idempotent('conversions', $payload, fn() => $crud($cls)->create($payload)));
            $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->delete((int)$ctx['id'])));
        });

        // ── Reports ──────────────────────────────────────────────────────
        $router->group('/reports', function (Router $r) use ($crud, $queryParams) {
            $cls = \Api\V3\Controllers\ReportsController::class;
            $r->get('/summary',    fn() => $crud($cls)->summary($queryParams));
            $r->get('/breakdown',  fn() => $crud($cls)->breakdown($queryParams));
            $r->get('/timeseries', fn() => $crud($cls)->timeseries($queryParams));
            $r->get('/daypart',    fn() => $crud($cls)->daypart($queryParams));
            $r->get('/weekpart',   fn() => $crud($cls)->weekpart($queryParams));
        });

        // ── LTV: reads (ltv:read) ───────────────────────────────────────
        $router->group('/ltv', function (Router $r) use ($crud, $queryParams) {
            $cls = \Api\V3\Controllers\LtvController::class;
            $r->get('/summary',        fn() => $crud($cls)->summary($queryParams));
            $r->get('/customers',      fn() => $crud($cls)->customers($queryParams));
            $r->get('/customers/{id}', fn($ctx) => $crud($cls)->customerDetail((int)$ctx['id']));
            $r->get('/customers/{id}/engagement', fn($ctx) => $crud($cls)->customerEngagement((int)$ctx['id'], $queryParams));
            $r->get('/customers/{id}/next-offer', fn($ctx) => $crud($cls)->customerNextOffer((int)$ctx['id']));
            $r->get('/abm',            fn() => $crud($cls)->abm($queryParams));
            $r->get('/abm/company',    fn() => $crud($cls)->abmCompany($queryParams));
            $r->get('/breakdown',      fn() => $crud($cls)->breakdown($queryParams));
            $r->get('/cohorts',        fn() => $crud($cls)->cohorts($queryParams));
            $r->get('/mrr',            fn() => $crud($cls)->mrr());
            $r->get('/predict',        fn() => $crud($cls)->predict($queryParams));
            $r->get('/products',       fn() => $crud($cls)->products($queryParams));
            $r->get('/companies',      fn() => $crud($cls)->listCompanies($queryParams));
            $r->get('/subscriptions',  fn() => $crud($cls)->listSubscriptions($queryParams));
            $r->get('/fields',         fn() => $crud($cls)->fieldsList());
            $r->get('/webhooks',       fn() => $crud($cls)->listWebhooks());
            $r->get('/integrations',   fn() => $crud($cls)->listIntegrations());
        }, [
            static function () use ($auth): void {
                $auth->requireScope('ltv:read');
            },
        ]);

        // ── LTV: CRM + inbound integration writes (ltv:write) ──────────
        $router->group('/ltv', function (Router $r) use ($crud, $payload) {
            $cls = \Api\V3\Controllers\LtvController::class;
            $r->post('/customers',                fn() => ['_status' => 201] + $crud($cls)->upsertCustomer($payload));
            $r->patch('/customers/{id}',          fn($ctx) => $crud($cls)->patchCustomer((int)$ctx['id'], $payload));
            $r->post('/customers/{id}/merge',     fn($ctx) => $crud($cls)->mergeCustomer((int)$ctx['id'], $payload));
            $r->delete('/customers/{id}',         fn($ctx) => tap($crud($cls), fn($c) => $c->deleteCustomer((int)$ctx['id'])));
            $r->post('/customers/{id}/aliases',   fn($ctx) => ['_status' => 201] + $crud($cls)->addAlias((int)$ctx['id'], $payload));
            $r->post('/customers/{id}/next-offer/impression', fn($ctx) => $crud($cls)->recordNextOfferImpression((int)$ctx['id'], $payload));
            $r->delete('/customers/{id}/aliases/{aliasId}', fn($ctx) => tap($crud($cls), fn($c) => $c->deleteCustomerAlias((int)$ctx['id'], (int)$ctx['aliasId'])));
            $r->post('/companies',                fn() => ['_status' => 201] + $crud($cls)->createCompany($payload));
            $r->patch('/companies/{id}',          fn($ctx) => $crud($cls)->patchCompany((int)$ctx['id'], $payload));
            $r->post('/companies/{id}/merge',     fn($ctx) => $crud($cls)->mergeCompany((int)$ctx['id'], $payload));
            $r->delete('/companies/{id}',         fn($ctx) => tap($crud($cls), fn($c) => $c->deleteCompany((int)$ctx['id'])));
            $r->post('/revenue',                  fn() => $crud($cls)->recordRevenue($payload));
            $r->post('/events',                   fn() => $crud($cls)->recordEngagementEvent($payload));
            $r->post('/subscriptions',            fn() => ['_status' => 201] + $crud($cls)->upsertSubscription($payload));
            $r->post('/subscriptions/{ref}/events', fn($ctx) => $crud($cls)->subscriptionEvent((string)$ctx['ref'], $payload));
            $r->post('/products',                 fn() => ['_status' => 201] + $crud($cls)->upsertProduct($payload));
            $r->post('/fields',                   fn() => ['_status' => 201] + $crud($cls)->createField($payload));
            $r->patch('/fields/{id}',             fn($ctx) => $crud($cls)->updateField((int)$ctx['id'], $payload));
            $r->delete('/fields/{id}',            fn($ctx) => tap($crud($cls), fn($c) => $c->deleteField((int)$ctx['id'])));
            $r->post('/webhooks',                 fn() => ['_status' => 201] + $crud($cls)->createWebhook($payload));
            $r->delete('/webhooks/{id}',          fn($ctx) => tap($crud($cls), fn($c) => $c->deleteWebhook((int)$ctx['id'])));
            $r->post('/integrations',             fn() => ['_status' => 201] + $crud($cls)->createIntegration($payload));
            $r->delete('/integrations/{id}',      fn($ctx) => tap($crud($cls), fn($c) => $c->deleteIntegration((int)$ctx['id'])));
        }, [
            static function () use ($auth): void {
                $auth->requireScope('ltv:write');
            },
        ]);

        // ── API capabilities ────────────────────────────────────────────
        $router->get('/capabilities', fn() => (new \Api\V3\Controllers\CapabilitiesController($db, $actorUserId))->capabilities());

        // ── Server-side sync planning (admin + read scope) ─────────────
        $router->group('/sync', function (Router $r) use ($crud, $queryParams, $payload) {
            $cls = \Api\V3\Controllers\SyncController::class;
            $r->post('/plan', fn() => $crud($cls)->plan($payload));
            $r->get('/status', fn() => $crud($cls)->status($queryParams));
            $r->get('/history', fn() => $crud($cls)->history($queryParams));
        }, [
            static function () use ($auth): void {
                $auth->requireAdmin();
                $auth->requireScope('sync:read');
            },
        ]);

        // ── Server-side sync orchestration (admin + write scope) ───────
        $router->group('/sync', function (Router $r) use ($crud, $payload) {
            $cls = \Api\V3\Controllers\SyncController::class;
            $r->post('/jobs', fn() => ['_status' => 202] + $crud($cls)->createJob($payload));
            $r->post('/worker/run', fn() => $crud($cls)->runWorker($payload));
            $r->post('/jobs/{id}/run', fn($ctx) => $crud($cls)->runJob((string)$ctx['id']));
            $r->post('/jobs/{id}/cancel', fn($ctx) => $crud($cls)->cancelJob((string)$ctx['id']));
            $r->post('/re-sync', fn() => ['_status' => 202] + $crud($cls)->reSync($payload));
        }, [
            static function () use ($auth): void {
                $auth->requireAdmin();
                $auth->requireScope('sync:write');
            },
        ]);

        $router->group('/sync', function (Router $r) use ($crud, $queryParams) {
            $cls = \Api\V3\Controllers\SyncController::class;
            $r->get('/jobs/{id}', fn($ctx) => $crud($cls)->getJob((string)$ctx['id']));
            $r->get('/jobs/{id}/events', fn($ctx) => $crud($cls)->events((string)$ctx['id'], $queryParams));
        }, [
            static function () use ($auth): void {
                $auth->requireAdmin();
                $auth->requireScope('sync:read');
            },
        ]);

        // ── Incremental changes feed (admin only) ──────────────────────
        $router->group('/changes', function (Router $r) use ($crud, $queryParams) {
            $cls = \Api\V3\Controllers\SyncController::class;
            $r->get('/{entity}', fn($ctx) => $crud($cls)->listChanges((string)$ctx['entity'], $queryParams));
        }, [
            static function () use ($auth): void {
                $auth->requireAdmin();
                $auth->requireScope('sync:read');
            },
        ]);

        // ── Audit & provenance (admin only) ────────────────────────────
        $router->group('/audit', function (Router $r) use ($crud, $queryParams) {
            $cls = \Api\V3\Controllers\SyncController::class;
            $r->get('/sync-jobs', fn() => $crud($cls)->auditList($queryParams));
            $r->get('/sync-jobs/{id}', fn($ctx) => $crud($cls)->auditGet((string)$ctx['id'], $queryParams));
        }, [
            static function () use ($auth): void {
                $auth->requireAdmin();
                $auth->requireScope('sync:read');
            },
        ]);

        // ── Rotators ─────────────────────────────────────────────────────
        $router->group('/rotators', function (Router $r) use ($crud, $idempotent, $queryParams, $payload) {
            $cls = \Api\V3\Controllers\RotatorsController::class;
            $r->get('',        fn() => $crud($cls)->list($queryParams));
            $r->get('/{id}',   fn($ctx) => $crud($cls)->get((int)$ctx['id']));
            $r->post('',       fn() => ['_status' => 201] + $idempotent('rotators', $payload, fn() => $crud($cls)->create($payload)));
            $r->put('/{id}',   fn($ctx) => $crud($cls)->update((int)$ctx['id'], $payload));
            $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->delete((int)$ctx['id'])));

            // Sub-resource: rules
            $r->get('/{id}/rules',             fn($ctx) => $crud($cls)->listRules((int)$ctx['id']));
            $r->post('/{id}/rules',            fn($ctx) => $idempotent('rotators/' . (int)$ctx['id'] . '/rules', $payload, fn() => $crud($cls)->createRule((int)$ctx['id'], $payload)));
            $r->put('/{id}/rules/{ruleId}',    fn($ctx) => $crud($cls)->updateRule((int)$ctx['id'], (int)$ctx['ruleId'], $payload));
            $r->delete('/{id}/rules/{ruleId}', fn($ctx) => tap($crud($cls), fn($c) => $c->deleteRule((int)$ctx['id'], (int)$ctx['ruleId'])));
        });

        // ── Attribution ──────────────────────────────────────────────────
        $router->group('/attribution/models', function (Router $r) use ($crud, $idempotent, $queryParams, $payload) {
            $cls = \Api\V3\Controllers\AttributionController::class;
            $r->get('',        fn() => $crud($cls)->listModels($queryParams));
            $r->post('',       fn() => ['_status' => 201] + $idempotent('attribution/models', $payload, fn() => $crud($cls)->createModel($payload)));
            $r->get('/{id}',   fn($ctx) => $crud($cls)->getModel((int)$ctx['id']));
            $r->put('/{id}',   fn($ctx) => $crud($cls)->updateModel((int)$ctx['id'], $payload));
            $r->delete('/{id}', fn($ctx) => tap($crud($cls), fn($c) => $c->deleteModel((int)$ctx['id'])));

            $r->get('/{id}/snapshots', fn($ctx) => $crud($cls)->listSnapshots((int)$ctx['id'], $queryParams));
            $r->get('/{id}/exports',   fn($ctx) => $crud($cls)->listExports((int)$ctx['id']));
            $r->post('/{id}/exports',  fn($ctx) => ['_status' => 201] + $idempotent('attribution/models/' . (int)$ctx['id'] . '/exports', $payload, fn() => $crud($cls)->scheduleExport((int)$ctx['id'], $payload)));
        });

        // ── Users (admin-gated writes, self-or-admin for reads) ──────────
        $router->group('/users', function (Router $r) use ($db, $auth, $idempotent, $payload) {
            $make = fn() => new \Api\V3\Controllers\UsersController($db);

            $r->get('/roles', fn() => $make()->listRoles());

            $r->get('', function () use ($auth, $make) {
                $auth->requireAdmin();
                return $make()->list();
            });
            $r->post('', function () use ($auth, $make, $idempotent, $payload) {
                $auth->requireAdmin();
                return ['_status' => 201] + $idempotent('users', $payload, fn() => $make()->create($payload));
            });
            $r->get('/{id}', function ($ctx) use ($auth, $make) {
                $auth->requireSelfOrAdmin((int)$ctx['id']);
                return $make()->get((int)$ctx['id']);
            });
            $r->put('/{id}', function ($ctx) use ($auth, $make, $payload) {
                $auth->requireSelfOrAdmin((int)$ctx['id']);
                return $make()->update((int)$ctx['id'], $payload);
            });
            $r->delete('/{id}', function ($ctx) use ($auth, $make) {
                $auth->requireAdmin();
                $make()->delete((int)$ctx['id']);
                return null; // 204
            });

            // Roles sub-resource (admin only)
            $r->post('/{id}/roles', function ($ctx) use ($auth, $make, $payload) {
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
            $r->post('/{id}/api-keys', function ($ctx) use ($auth, $make, $payload) {
                $auth->requireSelfOrAdmin((int)$ctx['id']);
                return ['_status' => 201] + $make()->createApiKey((int)$ctx['id'], $payload, $auth);
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
            $r->put('/{id}/preferences', function ($ctx) use ($auth, $make, $payload) {
                $auth->requireSelfOrAdmin((int)$ctx['id']);
                return $make()->updatePreferences((int)$ctx['id'], $payload);
            });
        });

        // ── System (admin only; /health is handled above without auth) ─────
        $router->group('/system', function (Router $r) use ($db, $queryParams) {
            $make = fn() => new \Api\V3\Controllers\SystemController($db);

            $r->get('/version',    fn() => $make()->version());
            $r->get('/db-stats',   fn() => $make()->dbStats());
            $r->get('/cron',       fn() => $make()->cronStatus());
            $r->get('/errors',     fn() => $make()->errors($queryParams));
            $r->get('/dataengine', fn() => $make()->dataengineStatus());
            $r->get('/metrics',    fn() => $make()->metrics());
        }, [$auth->requireAdmin(...)]);

        // ── API root ─────────────────────────────────────────────────────
        $router->get('/', fn() => [
            'api' => 'Prosper202 API v3',
            'endpoints' => [
                'versions'      => '/versions',
                'capabilities'  => '/capabilities',
                'campaigns'     => '/campaigns',
                'aff_networks'  => '/aff-networks',
                'ppc_networks'  => '/ppc-networks',
                'ppc_accounts'  => '/ppc-accounts',
                'trackers'      => '/trackers',
                'landing_pages' => '/landing-pages',
                'text_ads'      => '/text-ads',
                'forecast_events' => '/forecast-events',
                'clicks'        => '/clicks',
                'conversions'   => '/conversions',
                'reports'       => '/reports/{summary|breakdown|timeseries|daypart|weekpart}',
                'ltv'           => '/ltv/{summary|customers|companies|breakdown|mrr|predict|products|fields|revenue|subscriptions|webhooks|integrations}',
                'rotators'      => '/rotators',
                'attribution'   => '/attribution/models',
                'users'         => '/users',
                'system'        => '/system/{health|version|db-stats|cron|errors|dataengine|metrics}',
                'sync'          => '/sync/{plan|jobs|status|history|re-sync}',
                'changes'       => '/changes/{entity}',
                'audit'         => '/audit/sync-jobs',
            ],
            'auth' => 'Authorization: Bearer <api_key>',
        ]);

        // ── DELETE dry-run previews ─────────────────────────────────────
        // `?dry_run=1` on a DELETE returns what the delete would remove without
        // removing anything. Fail-closed by construction: a dry-run DELETE is
        // only ever dispatched to a handler registered here, and a DELETE with
        // dry_run set whose path has no preview is rejected — it can never fall
        // through to the real delete. (LTV deletes have no previews yet, so
        // they reject.) Auth checks that live inside the main users handlers
        // are replicated on their previews below; group middleware still runs
        // from the main match before this router is consulted.
        $previewRouter = new Router();
        foreach ($crudMap as $resource => $class) {
            $previewRouter->delete("/$resource/{id}", fn($ctx) => $crud($class)->deletePreview((int)$ctx['id']));
        }
        $previewRouter->delete('/conversions/{id}', fn($ctx) => $crud(\Api\V3\Controllers\ConversionsController::class)->deletePreview((int)$ctx['id']));
        $previewRouter->delete('/rotators/{id}', fn($ctx) => $crud(\Api\V3\Controllers\RotatorsController::class)->deletePreview((int)$ctx['id']));
        $previewRouter->delete('/rotators/{id}/rules/{ruleId}', fn($ctx) => $crud(\Api\V3\Controllers\RotatorsController::class)->deleteRulePreview((int)$ctx['id'], (int)$ctx['ruleId']));
        $previewRouter->delete('/attribution/models/{id}', fn($ctx) => $crud(\Api\V3\Controllers\AttributionController::class)->deleteModelPreview((int)$ctx['id']));
        $previewRouter->group('/users', function (Router $r) use ($db, $auth) {
            $make = fn() => new \Api\V3\Controllers\UsersController($db);
            $r->delete('/{id}', function ($ctx) use ($auth, $make) {
                $auth->requireAdmin();
                return $make()->deletePreview((int)$ctx['id']);
            });
            $r->delete('/{id}/api-keys/{keyId}', function ($ctx) use ($auth, $make) {
                $auth->requireSelfOrAdmin((int)$ctx['id']);
                return $make()->deleteApiKeyPreview((int)$ctx['id'], (string)$ctx['keyId']);
            });
            $r->delete('/{id}/roles/{roleId}', function ($ctx) use ($auth, $make) {
                $auth->requireAdmin();
                return $make()->removeRolePreview((int)$ctx['id'], (int)$ctx['roleId']);
            });
        });


        return ['router' => $router, 'preview' => $previewRouter];
    };

    $built = $buildRouters($payload, $queryParams, $userId);
    $router = $built['router'];
    $previewRouter = $built['preview'];
    // ── Staged writes ───────────────────────────────────────────────
    // `?staged=1` on an operator-surface write records it as a proposal
    // (server-issued change id) instead of executing it; a person applies
    // or discards it through /staged-changes. Fail-closed like dry-run: a
    // write outside this allowlist rejects the parameter rather than
    // executing, and applying re-dispatches through the real route so
    // validation and authorization re-run against current state and the
    // applier's credentials.
    $stageableRouter = new Router();
    $stageable = static fn() => true;
    foreach ($crudMap as $resource => $class) {
        $stageableRouter->group("/$resource", function (Router $r) use ($stageable) {
            $r->post('', $stageable);
            $r->put('/{id}', $stageable);
            $r->delete('/{id}', $stageable);
        });
    }
    $stageableRouter->group('/conversions', function (Router $r) use ($stageable) {
        $r->post('', $stageable);
        $r->delete('/{id}', $stageable);
    });
    $stageableRouter->group('/rotators', function (Router $r) use ($stageable) {
        $r->post('', $stageable);
        $r->put('/{id}', $stageable);
        $r->delete('/{id}', $stageable);
        $r->post('/{id}/rules', $stageable);
        $r->put('/{id}/rules/{ruleId}', $stageable);
        $r->delete('/{id}/rules/{ruleId}', $stageable);
    });
    $stageableRouter->group('/attribution/models', function (Router $r) use ($stageable) {
        $r->post('', $stageable);
        $r->put('/{id}', $stageable);
        $r->delete('/{id}', $stageable);
        $r->post('/{id}/exports', $stageable);
    });
    $stageableRouter->group('/users', function (Router $r) use ($stageable) {
        $r->post('', $stageable);
        $r->put('/{id}', $stageable);
        $r->delete('/{id}', $stageable);
        $r->post('/{id}/roles', $stageable);
        $r->delete('/{id}/roles/{roleId}', $stageable);
        $r->delete('/{id}/api-keys/{keyId}', $stageable);
        $r->put('/{id}/preferences', $stageable);
    });

    $stagedChanges = fn() => new \Api\V3\Controllers\StagedChangesController($stateStore, $auth);

    $router->group('/staged-changes', function (Router $r) use ($stagedChanges, $buildRouters, $queryParams) {
        $r->get('', fn() => $stagedChanges()->list($queryParams));
        $r->get('/{id}', fn($ctx) => $stagedChanges()->get((string)$ctx['id']));
        $r->post('/{id}/discard', fn($ctx) => $stagedChanges()->discard((string)$ctx['id']));
        $r->post('/{id}/apply', function ($ctx) use ($stagedChanges, $buildRouters) {
            return $stagedChanges()->apply(
                (string)$ctx['id'],
                // Rebuild the routes around the recorded payload and the
                // PROPOSER's id: the write lands in the account that
                // proposed it, while authorization (requireAdmin, scope)
                // still runs against the applier's credentials below.
                function (string $m, string $p, array $body, int $proposerId) use ($buildRouters) {
                    $target = $buildRouters($body, [], $proposerId)['router']->match($m, $p);
                    if ($target === null) {
                        throw new ConflictException('The staged operation no longer matches a route on this server.');
                    }
                    foreach ($target['middleware'] as $mw) {
                        $mw();
                    }
                    return ($target['handler'])($target['pathParams']);
                }
            );
        });
    });

    // ─── Dispatch ────────────────────────────────────────────────────
    $match = $router->match($method, $path);

    if ($match === null) {
        Bootstrap::errorResponse('Not found', 404);
        exit;
    }

    // Central scope enforcement: every authenticated endpoint requires
    // `<area>:read` for GET/HEAD and `<area>:write` for anything else, so a
    // key scoped `read` can never write and a key scoped to one area can
    // never touch another. Keys without a stored scope parse to `*` and pass
    // everything, exactly as before scoping existed. `/capabilities` and the
    // API root stay readable by any valid key — clients probe them to decide
    // what they can do. Route-level checks (requireAdmin, the ltv/sync
    // requireScope middleware) still run below; this is the floor, not a
    // replacement.
    $stagedWrite = stagedWriteRequested($method, $queryParams);
    $dryRun = deleteDryRunRequested($method, $queryParams);
    if ($stagedWrite && $dryRun) {
        throw new ValidationException('staged and dry_run are mutually exclusive', [
            'staged' => 'Use dry_run=1 to preview a delete, or staged=1 to record it for approval — not both.',
        ]);
    }

    $scopeArea = scopeAreaForPath($path);
    if ($scopeArea === null && !Auth::isScopeExemptPath($path)) {
        // Default-deny. The route matched, so it exists; it just has no
        // scope area. Letting it through would hand every key unchecked
        // access to a whole route family — the failure mode of a mapping
        // list maintained separately from the routes themselves.
        // The 500 handler replaces the message with a generic one, so log
        // the specifics or the operator sees an unexplained failure.
        error_log(sprintf(
            'p202: refusing %s %s — its route family has no API key scope area. '
            . 'Add it to Auth::KNOWN_SCOPE_AREAS (or Auth::isScopeExemptPath if deliberately unscoped).',
            $method,
            $path
        ));
        throw new HttpException(
            'This endpoint is not mapped to an API key scope and cannot be served. '
            . 'Add its area to Auth::KNOWN_SCOPE_AREAS.',
            500
        );
    }
    if ($scopeArea !== null) {
        $scopeAction = in_array($method, ['GET', 'HEAD'], true) ? 'read' : 'write';
        if ($method === 'POST' && $path === '/sync/plan') {
            // Planning computes a diff without applying it; sync:read keys
            // could always call it through the group middleware.
            $scopeAction = 'read';
        }
        if ($stagedWrite) {
            // Staging proposes rather than performs, so a propose-only
            // (`stage`) key suffices; the write scope is required of the
            // applier instead.
            $scopeAction = 'stage';
        }
        if ($method === 'POST' && preg_match('#^/staged-changes/[^/]+/discard$#', $path) === 1) {
            // Discarding one's own proposal is part of proposing.
            $scopeAction = 'stage';
        }
        if ($method === 'POST' && preg_match('#^/staged-changes/[^/]+/apply$#', $path) === 1) {
            // Applying is gated on the area the change actually touches —
            // StagedChangesController::apply() requires `<area>:write` once
            // it has read the record. Demanding `staged-changes:write` here
            // as well would lock out the documented granular approver key
            // (e.g. `read,campaigns:write`), which no scope token satisfies.
            $scopeArea = null;
        }
    }
    if ($scopeArea !== null) {
        $auth->requireScope($scopeArea . ':' . $scopeAction);
    }

    if ($stagedWrite) {
        // Record the proposal instead of executing. Fail-closed: a write
        // outside the stageable allowlist rejects the parameter — it must
        // never fall through to the immediate write.
        if ($stageableRouter->match($method, $path) === null) {
            throw new ValidationException('staged is not supported for this endpoint', [
                'staged' => 'This write cannot be staged; remove staged to perform it directly.',
            ]);
        }
        $stagePreview = null;
        if ($method === 'DELETE') {
            $previewMatch = $previewRouter->match('DELETE', $path);
            if ($previewMatch !== null) {
                try {
                    // The preview embeds the full record in the staged
                    // change, so it is a read: a propose-only key without
                    // read scope must not obtain through staging what GET
                    // would refuse it.
                    if ($scopeArea !== null) {
                        $auth->requireScope($scopeArea . ':read');
                    }
                    $previewResponse = ($previewMatch['handler'])($previewMatch['pathParams']);
                    if (is_array($previewResponse)) {
                        $stagePreview = $previewResponse['data'] ?? null;
                    }
                } catch (AuthException) {
                    // The proposer may lack the preview's authorization (e.g.
                    // a non-admin staging a user delete for an admin to
                    // apply); stage without the embedded preview. Not-found
                    // and validation errors still propagate — a proposal
                    // that cannot name its target is rejected up front.
                    $stagePreview = null;
                }
            }
        }
        $response = ['_status' => 202] + $stagedChanges()->stage($method, $path, $payload, $stagePreview);
    } else {
        // Run middleware stack
        foreach ($match['middleware'] as $mw) {
            $mw();
        }

        // Execute handler (or the dry-run preview for a DELETE that asked for one)
        if ($dryRun) {
            $preview = $previewRouter->match('DELETE', $path);
            if ($preview === null) {
                throw new ValidationException('dry_run is not supported for this endpoint', [
                    'dry_run' => 'This DELETE has no preview; remove dry_run to perform the delete.',
                ]);
            }
            $response = ($preview['handler'])($preview['pathParams']);
        } else {
            $response = ($match['handler'])($match['pathParams']);
        }
    }

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
} catch (ConflictException $e) {
    Bootstrap::errorResponse($e->getMessage(), 409, $e->getDetails() ? ['details' => $e->getDetails()] : []);
} catch (WriteCommittedException $e) {
    error_log('p202: ' . $e->getMessage() . ' cause: ' . ($e->getPrevious()?->getMessage() ?? 'unknown'));
    Bootstrap::errorResponse($e->getMessage(), $e->getHttpStatus());
} catch (HttpException $e) {
    $code = $e->getHttpStatus();
    $message = $code >= 500 ? 'Internal server error' : $e->getMessage();
    Bootstrap::errorResponse($message, $code);
} catch (\Throwable) {
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

/**
 * Whether a DELETE request asked for a dry-run preview. The parameter is
 * strictly validated: an unrecognized value is an error, never a fall-through
 * to the real delete — a typo like dry_run=tru must not destroy data.
 */
function deleteDryRunRequested(string $method, array $queryParams): bool
{
    if ($method !== 'DELETE' || !array_key_exists('dry_run', $queryParams)) {
        return false;
    }
    $flag = strtolower(trim((string)$queryParams['dry_run']));
    if (in_array($flag, ['1', 'true', 'yes', ''], true)) {
        return true;
    }
    if (in_array($flag, ['0', 'false', 'no'], true)) {
        return false;
    }
    throw new ValidationException('Invalid dry_run value', [
        'dry_run' => "Use dry_run=1 to preview the delete, or omit the parameter to perform it (got '$flag').",
    ]);
}

/**
 * Map a request path to the scope area that governs it, or null for paths
 * exempt from scope checks (discovery metadata and the pre-auth endpoints).
 */
function scopeAreaForPath(string $path): ?string
{
    return Auth::scopeAreaForPath($path);
}

/**
 * Whether a mutating request asked to be staged for approval instead of
 * executed. Same strict validation as dry_run: an unrecognized value is an
 * error, never a fall-through to the immediate write.
 */
function stagedWriteRequested(string $method, array $queryParams): bool
{
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !array_key_exists('staged', $queryParams)) {
        return false;
    }
    $flag = strtolower(trim((string)$queryParams['staged']));
    if (in_array($flag, ['1', 'true', 'yes', ''], true)) {
        return true;
    }
    if (in_array($flag, ['0', 'false', 'no'], true)) {
        return false;
    }
    throw new ValidationException('Invalid staged value', [
        'staged' => "Use staged=1 to stage the write for approval, or omit the parameter to perform it (got '$flag').",
    ]);
}

function shouldEmitDeprecationNotice(string $path, string $requestedVersion): bool
{
    if ($requestedVersion !== '') {
        return false;
    }

    // Only emit for legacy v3 workload paths, never for health/version discovery.
    if ($path === '/versions' || $path === '/system/health') {
        return false;
    }

    return str_starts_with($path, '/sync')
        || str_starts_with($path, '/changes')
        || str_starts_with($path, '/audit')
        || str_contains($path, '/bulk-upsert');
}

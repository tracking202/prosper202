<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AdministrationController;
use PHPUnit\Framework\TestCase;

/**
 * The Administration routes ask for what the pages ask for (CLAUDE.md #5),
 * on top of the Admin role every /system route requires: access_to_settings
 * for what Account › Settings (202-account/administration.php) shows and
 * changes, access_to_api_integrations for the URLs Account › API integrations
 * hands out. The one-off deletion's `?dry_run=1` preview runs in the same
 * handler, so it is gated identically, and none of the writes is stageable.
 *
 * Read from api/v3/index.php through ReadsRouteRegistrations: each path is
 * matched in a real Router loaded with every registration in source order, so
 * the route checked is the one that answers. AdministrationController is
 * reached through these routes and nothing else.
 *
 * The behaviour — a Campaign manager's key answered 403 "Admin access
 * required.", an Admin whose role lacks access_to_settings answered 403 naming
 * it — was measured over HTTP against a running instance; the controller's
 * own rules are AdministrationControllerIntegrationTest's.
 */
final class AdministrationRoutesPermissionTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** "METHOD path" => [the controller method its handler calls, the page middleware]. */
    private const ROUTES = [
        'GET /system/info' => ['info', '$settingsPage'],
        'GET /system/login-log' => ['loginLog', '$settingsPage'],
        'GET /system/retention' => ['retention', '$settingsPage'],
        'PUT /system/retention' => ['setRetention', '$settingsPage'],
        'POST /system/retention/delete-before' => ['scheduleDeletion', '$settingsPage'],
        'GET /system/isp-lookup' => ['ispLookup', '$settingsPage'],
        'PUT /system/isp-lookup' => ['setIspLookup', '$settingsPage'],
        'GET /system/integrations' => ['integrations', '$integrationsPage'],
    ];

    public function testEveryAdministrationRouteAnswersBehindAdminAndItsPagesPermission(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $main = $this->router($registrations, '$router');
        self::assertGreaterThan(150, count(array_filter($registrations, static fn (array $r): bool => $r['router'] === '$router')), 'the scan reads the main router');

        foreach (self::ROUTES as $operation => [$method, $page]) {
            [$verb, $path] = explode(' ', $operation, 2);
            $match = $main->match($verb, $path);
            self::assertNotNull($match, "$operation is served");
            $route = $registrations[($match['handler'])()];
            self::assertSame(
                ['$auth->requireAdmin(...)', $page],
                $route['middleware'],
                "$operation (line {$route['line']}) runs requireAdmin and $page, and only them"
            );
            self::assertMatchesRegularExpression(
                '/->' . $method . '\(/',
                $route['handler'],
                "$operation (line {$route['line']}) calls AdministrationController::$method"
            );
            self::assertTrue(
                str_contains($route['handler'], 'AdministrationController::class')
                    || str_contains($route['group'], '$cls=\Api\V3\Controllers\AdministrationController::class;'),
                "$operation (line {$route['line']}) builds an AdministrationController"
            );
            self::assertStringNotContainsString('requirePermission', $route['handler'], "$operation asks for nothing beyond its group's middleware");
        }

        $dryRun = $registrations[($main->match('POST', '/system/retention/delete-before')['handler'])()];
        self::assertStringContainsString('->scheduleDeletion($payload,writeDryRunRequested($queryParams))', $dryRun['handler'], 'the preview is the strictly-read dry_run of the same handler');

        // AdministrationController is reached through these routes and nothing else.
        foreach ($registrations as $route) {
            $reaches = str_contains($route['handler'], 'AdministrationController')
                || (str_contains($route['group'], 'AdministrationController::class') && str_contains($route['handler'], '$crud($cls)'));
            if ($reaches) {
                self::assertArrayHasKey($route['method'] . ' ' . $route['path'], self::ROUTES, "line {$route['line']}: {$route['method']} {$route['path']} reaches AdministrationController without being checked here");
            }
        }
    }

    public function testThePageMiddlewaresAskForThePagesPermissions(): void
    {
        $src = self::indexSource();
        foreach (['$settingsPage' => 'access_to_settings', '$integrationsPage' => 'access_to_api_integrations'] as $var => $permission) {
            self::assertSame(1, preg_match_all('/' . preg_quote($var, '/') . '\s*=/', $src), "$var is assigned once");
            self::assertMatchesRegularExpression(
                '/' . preg_quote($var, '/') . ' = static function \(\) use \(\$auth, \$db\): void \{\s*'
                    . '\$auth->requirePermission\(\$db, \'' . $permission . '\'\);\s*\};/',
                $src,
                "$var asks for $permission and does nothing else"
            );
        }
    }

    public function testNoAdministrationWriteIsStageable(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $stageable = $this->router($registrations, '$stageableRouter');
        self::assertNotNull($stageable->match('POST', '/conversions'), 'the scan reads the stageable router (POST /conversions is stageable)');
        foreach (array_keys(self::ROUTES) as $operation) {
            [$verb, $path] = explode(' ', $operation, 2);
            self::assertNull($stageable->match($verb, $path), "$operation must not be stageable: ?staged=1 is refused, never recorded");
        }
    }

    /**
     * The tables the one-off deletion previews are the tables the cron job
     * deletes from (ClearOldClicks() in 202-cronjobs/index.php): a preview that
     * counts other tables answers a question nobody asked.
     */
    public function testTheDeletionPreviewCountsTheTablesTheCronJobDeletesFrom(): void
    {
        $cron = (string) file_get_contents(dirname(__DIR__, 3) . '/202-cronjobs/index.php');
        $start = strpos($cron, 'function ClearOldClicks(');
        self::assertNotFalse($start, 'ClearOldClicks() is in the cron job');
        self::assertSame(1, preg_match("/explode\\(',', '([^']+)'\\)/", substr($cron, $start), $m), 'ClearOldClicks() names its tables in one list');
        self::assertSame(explode(',', $m[1]), AdministrationController::CLICK_DATA_TABLES);
    }

    public function testTheScanSeesAMissingPermission(): void
    {
        $src = <<<'PHP'
<?php
$router = new Router();
$router->group('/system', function (Router $r) use ($crud) {
    $cls = \Api\V3\Controllers\AdministrationController::class;
    $r->get('/info', fn() => $crud($cls)->info());
}, [$auth->requireAdmin(...)]);
PHP;
        $registrations = $this->registrations($src);
        $route = $registrations[($this->router($registrations, '$router')->match('GET', '/system/info')['handler'])()];
        self::assertSame(['$auth->requireAdmin(...)'], $route['middleware'], 'a group without the page middleware reads as without it');
    }
}

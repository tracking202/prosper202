<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Router;
use PHPUnit\Framework\TestCase;

/**
 * The Update routes ask for what the Update pages ask for (CLAUDE.md #5):
 * access_to_update_section on every route, and delete_individual_subids as
 * well to delete subids by list (tracking202/update/delete-subids.php). Their
 * `?dry_run=1` preview runs in the same handler, so it is gated identically,
 * and none of them is stageable.
 *
 * Read from api/v3/index.php's tokens: every registration on `$router` and
 * `$stageableRouter`, with the prefixes and middleware of the groups that
 * enclose it, is loaded into a real Router in source order, and each Update
 * path is matched there — so the route that answers is the one checked, not
 * one the scan found somewhere (first match wins: an earlier route on the
 * same path would shadow it). What the scan cannot read — a registration
 * whose path is not a string literal, other than the CRUD loop's "/$resource"
 * — is refused by line rather than skipped (CLAUDE.md #20).
 *
 * The behaviour — a role without the permission answered 403 on the write and
 * on its dry run, and a role with access_to_update_section but not
 * delete_individual_subids refused only the delete — is
 * UpdateEndpointsInstanceTest's, over HTTP against a running instance.
 */
final class UpdateRoutesPermissionTest extends TestCase
{
    /** Path => the UpdateController method its handler calls, and the permissions its handler asks for first. */
    private const ROUTES = [
        '/clicks/cpc' => ['cpc', []],
        '/conversions/subids' => ['markSubids', []],
        '/conversions/subids/delete' => ['deleteSubids', ['delete_individual_subids']],
        '/conversions/subids/reset' => ['resetSubids', []],
        '/conversions/uploads' => ['uploadRevenue', []],
    ];

    use RouteRegistrationScan;

    public function testEveryUpdateRouteAnswersBehindTheUpdateSectionPermission(): void
    {
        $registrations = $this->registrations(self::source());
        $main = $this->router($registrations, '$router');
        self::assertGreaterThan(150, count(array_filter($registrations, static fn (array $r): bool => $r['router'] === '$router')), 'the scan reads the main router');

        foreach (self::ROUTES as $path => [$method, $permissions]) {
            $match = $main->match('POST', $path);
            self::assertNotNull($match, "POST $path is served");
            $route = $registrations[($match['handler'])()];
            self::assertSame(['$updateSection'], $route['middleware'], "POST $path (line {$route['line']}) runs the access_to_update_section middleware, and only it");
            self::assertStringContainsString(
                '->' . $method . '($payload,writeDryRunRequested($queryParams))',
                $route['handler'],
                "POST $path (line {$route['line']}) calls UpdateController::$method with the body and the strictly-read dry_run"
            );
            self::assertTrue(
                str_contains($route['handler'], 'UpdateController::class)->' . $method . '(')
                    || (str_contains($route['handler'], '$crud($cls)->' . $method . '(') && str_contains($route['group'], '$cls=\Api\V3\Controllers\UpdateController::class;')),
                "POST $path (line {$route['line']}) builds an UpdateController"
            );

            $first = $this->firstStatement($route['handler']);
            if ($permissions === []) {
                self::assertStringNotContainsString('requirePermission', $route['handler'], "POST $path asks for nothing beyond the group's permission");
                continue;
            }
            foreach ($permissions as $permission) {
                self::assertSame('$auth->requirePermission($db,\'' . $permission . '\');', $first, "POST $path (line {$route['line']}) asks for $permission before anything else");
            }
        }

        // UpdateController is reached through these routes and nothing else.
        $reached = array_filter($registrations, static fn (array $r): bool => str_contains($r['handler'], 'UpdateController') || str_contains($r['handler'], '$crud($cls)->' . 'markSubids'));
        foreach ($registrations as $route) {
            $callsUpdate = str_contains($route['handler'], 'UpdateController::class')
                || ((bool) preg_match('/->(cpc|markSubids|deleteSubids|resetSubids|uploadRevenue)\(/', $route['handler']));
            if ($callsUpdate) {
                self::assertArrayHasKey($route['path'], self::ROUTES, "line {$route['line']}: {$route['method']} {$route['path']} reaches UpdateController without being an Update route checked here");
            }
        }
        self::assertNotEmpty($reached);
    }

    public function testTheMiddlewareAsksForAccessToUpdateSection(): void
    {
        $src = self::source();
        self::assertSame(1, preg_match_all('/\$updateSection\s*=/', $src), '$updateSection is assigned once');
        self::assertMatchesRegularExpression(
            '/\$updateSection = static function \(\) use \(\$auth, \$db\): void \{\s*'
                . '\$auth->requirePermission\(\$db, \'access_to_update_section\'\);\s*\};/',
            $src,
            '$updateSection asks for access_to_update_section and does nothing else'
        );
    }

    public function testNoUpdateRouteIsStageable(): void
    {
        $registrations = $this->registrations(self::source());
        $stageable = $this->router($registrations, '$stageableRouter');
        self::assertNotNull($stageable->match('POST', '/conversions'), 'the scan reads the stageable router (POST /conversions is stageable)');
        foreach (array_keys(self::ROUTES) as $path) {
            self::assertNull($stageable->match('POST', $path), "POST $path must not be stageable: ?staged=1 is refused, never recorded");
        }
    }

    public function testTheScanSeesAMissingGateAndAnUnreadablePath(): void
    {
        $src = <<<'PHP'
<?php
$router = new Router();
$updateSection = static function () use ($auth, $db): void { $auth->requirePermission($db, 'access_to_update_section'); };
$router->group('/clicks', function (Router $r) use ($crud) {
    $r->post('/cpc', fn() => $crud(\Api\V3\Controllers\UpdateController::class)->cpc($payload, writeDryRunRequested($queryParams)));
});
$router->group('/conversions', function (Router $r) use ($crud) {
    $cls = \Api\V3\Controllers\UpdateController::class;
    $r->post('/subids/delete', function () use ($crud, $cls) {
        $x = 1;
        $auth->requirePermission($db, 'delete_individual_subids');
        return $crud($cls)->deleteSubids($payload, writeDryRunRequested($queryParams));
    });
}, [$updateSection]);
PHP;
        $registrations = $this->registrations($src);
        $router = $this->router($registrations, '$router');
        $cpc = $registrations[($router->match('POST', '/clicks/cpc')['handler'] ?? static fn () => -1)()] ?? null;
        self::assertNotNull($cpc);
        self::assertSame([], $cpc['middleware'], 'a group without the middleware reads as without it');
        $delete = $registrations[($router->match('POST', '/conversions/subids/delete')['handler'])()];
        self::assertSame(['$updateSection'], $delete['middleware']);
        self::assertSame('$x=1;', $this->firstStatement($delete['handler']), 'a check after other work is not the first statement');

        try {
            $this->registrations("<?php\n\$router->post(\$path, fn() => 1);\n");
            self::fail('a registration whose path is not a literal was accepted');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('line 2', $e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * The Setup routes ask for what the Setup pages ask for (CLAUDE.md #5):
 * access_to_setup_section, which every page under tracking202/setup/ checks
 * before anything else, on every route — the landing-page code, the
 * postback code, and a traffic source's variables and an account's pixels —
 * and, on the variables routes, remove_traffic_source first: Setup › Traffic
 * Sources renders the variables dialog (and the variables it edits) only for
 * a role that has it.
 *
 * Read from api/v3/index.php's tokens through ReadsRouteRegistrations: each
 * path is matched in a real Router loaded in source order, so the route
 * that answers is the one checked. That matters twice here: GET
 * /conversions/postback-code must be answered by the Setup route and not by
 * GET /conversions/{id}, registered for the conversions CRUD; and a Setup
 * write must be answered by a route in the gated group, not by an earlier
 * one on the same path.
 *
 * Their deletes have `?dry_run=1` previews (the preview router answers them,
 * with the controller's deletePreview()), and their writes are stageable:
 * staged=1 records them, and applying runs the real route, middleware
 * included, against the applier.
 *
 * The behaviour — a role without the permission refused 403, a role with it
 * served — is SetupEndpointsInstanceTest's, over HTTP.
 */
final class SetupRoutesPermissionTest extends TestCase
{
    use ReadsRouteRegistrations;

    private const CODE = 'SetupCodeController';
    private const VARS = 'PpcNetworkVariablesController';
    private const PIXELS = 'PpcAccountPixelsController';

    /** [method, path, controller, the method its handler calls]. */
    private const ROUTES = [
        ['GET', '/landing-pages/5/code', self::CODE, 'landingPageCode'],
        ['GET', '/conversions/postback-code', self::CODE, 'postbackCode'],
        ['GET', '/ppc-networks/5/variables', self::VARS, 'list'],
        ['POST', '/ppc-networks/5/variables', self::VARS, 'create'],
        ['PUT', '/ppc-networks/5/variables/6', self::VARS, 'update'],
        ['PATCH', '/ppc-networks/5/variables/6', self::VARS, 'update'],
        ['DELETE', '/ppc-networks/5/variables/6', self::VARS, 'delete'],
        ['GET', '/ppc-accounts/5/pixels', self::PIXELS, 'list'],
        ['POST', '/ppc-accounts/5/pixels', self::PIXELS, 'create'],
        ['PUT', '/ppc-accounts/5/pixels/6', self::PIXELS, 'update'],
        ['PATCH', '/ppc-accounts/5/pixels/6', self::PIXELS, 'update'],
        ['DELETE', '/ppc-accounts/5/pixels/6', self::PIXELS, 'delete'],
    ];

    /** The variable each controller is held in, in its Setup group. */
    private const CLASS_VARIABLES = [self::CODE => '$code', self::VARS => '$vars', self::PIXELS => '$pixels'];

    /** The middleware each controller's routes run, in order. */
    private const MIDDLEWARE = [
        self::CODE => ['$setupSection'],
        self::VARS => ["\$setupRemove['ppc-networks']", '$setupSection'],
        self::PIXELS => ['$setupSection'],
    ];

    public function testEverySetupRouteAnswersBehindTheSetupSectionPermission(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $main = $this->router($registrations, '$router');
        self::assertGreaterThan(150, count(array_filter($registrations, static fn (array $r): bool => $r['router'] === '$router')), 'the scan reads the main router');

        foreach (self::ROUTES as [$method, $path, $controller, $call]) {
            $match = $main->match($method, $path);
            self::assertNotNull($match, "$method $path is served");
            $route = $registrations[($match['handler'])()];
            self::assertSame(self::MIDDLEWARE[$controller], $route['middleware'], "$method $path (line {$route['line']}) runs the Setup page's permission middleware, and only it");
            $variable = self::CLASS_VARIABLES[$controller];
            self::assertStringContainsString($variable . '=\Api\V3\Controllers\\' . $controller . '::class;', $route['group'], "$method $path (line {$route['line']}): $variable is $controller");
            self::assertMatchesRegularExpression(
                '/\$crud\(' . preg_quote($variable, '/') . '\)\)?->' . $call . '\(|fn\(\$c\)=>\$c->' . $call . '\(/',
                $route['handler'],
                "$method $path (line {$route['line']}) calls $controller::$call"
            );
            if ($call === 'delete') {
                self::assertStringContainsString('tap($crud(' . $variable . ')', $route['handler'], "$method $path (line {$route['line']}) deletes through $controller");
            }
            self::assertStringNotContainsString('(int)', $route['handler'], "$method $path (line {$route['line']}) reads its ids with pathId, never a cast that reads '1e3' as 1000");
        }
    }

    public function testThePostbackCodeIsNotAnsweredByTheConversionsCrud(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $main = $this->router($registrations, '$router');
        $route = $registrations[($main->match('GET', '/conversions/postback-code')['handler'])()];
        self::assertStringContainsString('postbackCode', $route['handler'], 'GET /conversions/postback-code reaches the postback code');
        $byId = $registrations[($main->match('GET', '/conversions/77')['handler'])()];
        self::assertStringContainsString('->get(', $byId['handler'], 'GET /conversions/{id} still answers a conversion id');
    }

    public function testTheMiddlewareAsksForThePagesPermissions(): void
    {
        $src = self::indexSource();
        self::assertSame(1, preg_match_all('/\$setupSection\s*=/', $src), '$setupSection is assigned once');
        self::assertMatchesRegularExpression(
            '/\$setupSection = static function \(\) use \(\$auth, \$db\): void \{\s*'
                . '\$auth->requirePermission\(\$db, \'access_to_setup_section\'\);\s*\};/',
            $src,
            '$setupSection asks for access_to_setup_section and does nothing else'
        );
        self::assertSame(1, preg_match_all('/\$setupRemove\s*=/', $src), '$setupRemove is assigned once');
        self::assertMatchesRegularExpression(
            "/'ppc-networks'\\s*=> static function \\(\\) use \\(\\\$auth, \\\$db\\): void \\{ \\\$auth->requirePermission\\(\\\$db, 'remove_traffic_source'\\); \\},/",
            $src,
            "\$setupRemove['ppc-networks'] asks for remove_traffic_source and does nothing else"
        );
    }

    public function testTheControllersAreReachedThroughTheseRoutesOnly(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $listed = [];
        foreach (self::ROUTES as [$method, $path]) {
            $listed[] = $method . ' ' . preg_replace('#/\d+#', '/{}', $path);
        }
        $reached = 0;
        foreach ($registrations as $route) {
            $names = [self::CODE, self::VARS, self::PIXELS];
            $mentions = array_filter($names, static fn (string $n): bool => str_contains($route['handler'], $n));
            $viaVariable = (bool) preg_match('/\$crud\(\$(code|vars|pixels)\)/', $route['handler']) && $route['router'] === '$router';
            if ($mentions === [] && !$viaVariable) {
                continue;
            }
            $reached++;
            $key = $route['method'] . ' ' . preg_replace('#\{\w+\}#', '{}', $route['path']);
            if ($route['router'] === '$previewRouter') {
                self::assertSame('DELETE', $route['method'], "line {$route['line']}: the preview router only previews deletes");
                self::assertStringContainsString('->deletePreview(', $route['handler'], "line {$route['line']}: a preview calls deletePreview()");
                self::assertContains($key, $listed, "line {$route['line']}: a preview of a Setup delete this test checks");
                continue;
            }
            self::assertContains($key, $listed, "line {$route['line']}: $key reaches a Setup controller without being a Setup route checked here");
        }
        self::assertGreaterThanOrEqual(12, $reached);
    }

    public function testTheDeletesHavePreviewsAndTheWritesAreStageable(): void
    {
        $registrations = $this->registrations(self::indexSource());
        $preview = $this->router($registrations, '$previewRouter');
        $stageable = $this->router($registrations, '$stageableRouter');
        foreach ([['/ppc-networks/5/variables/6', self::VARS], ['/ppc-accounts/5/pixels/6', self::PIXELS]] as [$path, $controller]) {
            $match = $preview->match('DELETE', $path);
            self::assertNotNull($match, "DELETE $path has a dry-run preview");
            $route = $registrations[($match['handler'])()];
            self::assertStringContainsString($controller . '::class)->deletePreview(', $route['handler'], "DELETE $path previews through $controller");
            self::assertStringContainsString('GoalsController::pathId($ctx[', $route['handler'], "DELETE $path's preview reads its ids with pathId");
        }
        foreach (self::ROUTES as [$method, $path]) {
            $staged = $stageable->match($method, $path) !== null;
            self::assertSame($method !== 'GET', $staged, "$method $path " . ($method === 'GET' ? 'is a read' : 'is stageable'));
        }
    }

    public function testTheScanSeesARouteOutsideTheGate(): void
    {
        $src = <<<'PHP'
<?php
$router = new Router();
$setupSection = static function () use ($auth, $db): void { $auth->requirePermission($db, 'access_to_setup_section'); };
$router->get('/ppc-networks/{id}/variables', fn($ctx) => $crud(\Api\V3\Controllers\PpcNetworkVariablesController::class)->list((int)$ctx['id']));
$router->group('', function (Router $r) use ($crud) {
    $vars = \Api\V3\Controllers\PpcNetworkVariablesController::class;
    $r->get('/ppc-networks/{id}/variables', fn($ctx) => $crud($vars)->list($id($ctx)));
}, [$setupSection]);
PHP;
        $registrations = $this->registrations($src);
        $router = $this->router($registrations, '$router');
        $route = $registrations[($router->match('GET', '/ppc-networks/5/variables')['handler'])()];
        self::assertSame([], $route['middleware'], 'an earlier ungated route on the same path is the one that answers, and reads as ungated');
        self::assertSame(4, $route['line']);
    }
}

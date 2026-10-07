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

    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var list<array{0: int, 1: string, 2: int}> */
    private array $t = [];

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
    }

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

    /**
     * Every route registration on $router, $previewRouter and $stageableRouter
     * (and on a group's `$r`), in source order.
     *
     * @return list<array{router: string, method: string, path: string, middleware: list<string>, handler: string, group: string, line: int}>
     */
    private function registrations(string $src): array
    {
        $this->t = [];
        foreach (\PhpToken::tokenize($src) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }
            $this->t[] = [$token->id < 256 ? 0 : $token->id, $token->text, $token->line];
        }
        $n = count($this->t);
        $out = [];
        // Open groups: [router, prefix, middleware, index of the group call's closing parenthesis, body text].
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][3]) {
                array_pop($stack);
            }
            if ($this->t[$i][0] !== T_VARIABLE || ($this->t[$i + 1][1] ?? '') !== '->'
                || !in_array(strtolower($this->t[$i + 2][1] ?? ''), ['group', ...self::ROUTE_METHODS], true)
                || ($this->t[$i + 3][1] ?? '') !== '(') {
                continue;
            }
            $var = $this->t[$i][1];
            if (in_array($var, ['$router', '$previewRouter', '$stageableRouter'], true)) {
                [$router, $prefix, $middleware, $groupBody] = [$var, '', [], ''];
            } elseif ($var === '$r' && $stack !== []) {
                [$router, $prefix, $middleware, , $groupBody] = $stack[count($stack) - 1];
            } else {
                continue;
            }
            $call = strtolower($this->t[$i + 2][1]);
            $close = $this->closing($i + 3);
            $line = $this->t[$i][2];
            $path = $this->pathAt($i + 4, $line);

            if ($call === 'group') {
                $bodyOpen = $this->find('{', $i + 4, $close);
                $bodyClose = $this->closing($bodyOpen);
                $own = [];
                if (($this->t[$bodyClose + 1][1] ?? '') === ',' && ($this->t[$bodyClose + 2][1] ?? '') === '[') {
                    $listClose = $this->closing($bodyClose + 2);
                    $own = $this->items($bodyClose + 3, $listClose);
                }
                $stack[] = [$router, $prefix . $path, [...$middleware, ...$own], $close, $this->text($bodyOpen, $bodyClose)];
                continue;
            }
            $comma = $this->find(',', $i + 4, $close);
            $out[] = [
                'router' => $router,
                'method' => strtoupper($call),
                'path' => $prefix . $path,
                'middleware' => $middleware,
                'handler' => $this->text($comma + 1, $close - 1),
                'group' => $groupBody,
                'line' => $line,
            ];
        }

        return $out;
    }

    /**
     * The path a registration names: a single-quoted literal, or the CRUD
     * loop's double-quoted "/$resource…", whose variable is written {crud}
     * and expanded by router(). Anything else is refused by line.
     */
    private function pathAt(int $at, int $line): string
    {
        $token = $this->t[$at];
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_starts_with($token[1], "'")) {
            return substr($token[1], 1, -1);
        }
        if ($token[1] === '"') {
            $path = '';
            for ($i = $at + 1; ($this->t[$i][1] ?? '"') !== '"'; $i++) {
                if ($this->t[$i][0] === T_ENCAPSED_AND_WHITESPACE) {
                    $path .= $this->t[$i][1];
                } elseif ($this->t[$i][0] === T_VARIABLE && $this->t[$i][1] === '$resource') {
                    $path .= '{crud}';
                } else {
                    throw new \UnexpectedValueException("line $line: a route path interpolating {$this->t[$i][1]}; teach the scan to read it");
                }
            }
            return $path;
        }
        throw new \UnexpectedValueException("line $line: a route registration whose path is not a string literal; teach the scan to read it");
    }

    /**
     * The registrations of one router, in a real Router, each answering its
     * own index. A {crud} path is registered once per $crudMap resource, as
     * the loop registers it.
     *
     * @param list<array<string, mixed>> $registrations
     */
    private function router(array $registrations, string $name): Router
    {
        $resources = [];
        if (preg_match('/\$crudMap = \[(.*?)\];/s', self::source(), $map) === 1 && preg_match_all("/'([a-z\-]+)'\s*=>/", $map[1], $keys) > 0) {
            $resources = $keys[1];
        }
        self::assertContains('campaigns', $resources, 'the CRUD resources were read');
        $router = new Router();
        foreach ($registrations as $index => $route) {
            if ($route['router'] !== $name) {
                continue;
            }
            foreach (str_contains($route['path'], '{crud}') ? $resources : [''] as $resource) {
                $router->add($route['method'], str_replace('{crud}', $resource, $route['path']), static fn () => $index);
            }
        }
        return $router;
    }

    /** The first statement of a closure's body (through its ';'), or an arrow function's expression. */
    private function firstStatement(string $handler): string
    {
        if (str_starts_with($handler, 'fn(') || str_starts_with($handler, 'static fn(')) {
            return substr($handler, (int) strpos($handler, '=>') + 2);
        }
        $open = strpos($handler, '{');
        self::assertNotFalse($open, 'a closure handler has a body');
        $end = strpos($handler, ';', $open);
        self::assertNotFalse($end, 'its body has a statement');

        return substr($handler, $open + 1, $end - $open);
    }

    private function closing(int $open): int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $want = $pairs[$this->t[$open][1]] ?? null;
        self::assertNotNull($want, 'token ' . $open . ' opens a bracket');
        $depth = 0;
        $n = count($this->t);
        for ($i = $open; $i < $n; $i++) {
            $text = $this->t[$i][1];
            if (isset($pairs[$text]) || $text === '${' || $this->t[$i][0] === T_CURLY_OPEN || $this->t[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        self::fail('bracket at token ' . $open . ' never closes');
    }

    private function find(string $text, int $from, int $to): int
    {
        $depth = 0;
        for ($i = $from; $i <= $to; $i++) {
            if ($depth === 0 && $this->t[$i][1] === $text) {
                return $i;
            }
            if (in_array($this->t[$i][1], ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($this->t[$i][1], [')', ']', '}'], true)) {
                $depth--;
            }
        }
        self::fail('no "' . $text . '" between tokens ' . $from . ' and ' . $to);
    }

    /** The tokens from $from to $to, joined without whitespace. */
    private function text(int $from, int $to): string
    {
        return implode('', array_map(static fn (array $t): string => $t[1], array_slice($this->t, $from, $to - $from + 1)));
    }

    /**
     * The comma-separated items between $from and $to (exclusive), each as text.
     *
     * @return list<string>
     */
    private function items(int $from, int $to): array
    {
        $items = [];
        $start = $from;
        $depth = 0;
        for ($i = $from; $i < $to; $i++) {
            $text = $this->t[$i][1];
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $items[] = $this->text($start, $i - 1);
                $start = $i + 1;
            }
        }
        if ($start < $to) {
            $items[] = $this->text($start, $to - 1);
        }
        return $items;
    }
}

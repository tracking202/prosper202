<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Router;

/**
 * api/v3/index.php's route registrations, read from its tokens: every call
 * on `$router`, `$previewRouter` and `$stageableRouter` (and on a group's
 * `$r`), with the prefixes and middleware of the groups that enclose it,
 * loaded into a real Router in source order so the route that answers a
 * path is the one a test checks (first match wins: an earlier route on the
 * same path would shadow it). What the scan cannot read — a registration
 * whose path is not a string literal, other than the CRUD loop's
 * "/$resource" — is refused by line rather than skipped (CLAUDE.md #20).
 *
 * Shared by the section permission tests (UpdateRoutesPermissionTest,
 * SetupRoutesPermissionTest); used from a TestCase, whose assertions it
 * calls.
 */
trait RouteRegistrationScan
{
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var list<array{0: int, 1: string, 2: int}> */
    private array $t = [];

    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php');
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

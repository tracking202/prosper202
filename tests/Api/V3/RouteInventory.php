<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use RuntimeException;

/**
 * Every route api/v3/index.php serves, read from its source, and every
 * operation docs/openapi.yaml documents — the two sides
 * RoutesAreDocumentedTest holds equal.
 *
 * The routes are read by walking index.php's tokens, not by matching lines:
 * the router registers most paths inside groups (`$router->group('/apps',
 * function (Router $r) { $r->get('/{id}', …) })`), so a path is the
 * concatenation of every enclosing group's prefix, and only brace depth says
 * which groups enclose a call. A registration whose path is not a literal
 * this reader can follow is refused by line rather than skipped (CLAUDE.md
 * #20: a scanner that cannot see a construct must not answer "no such
 * construct"). The one computed prefix, the CRUD loop's "/$resource", is
 * expanded from $crudMap.
 *
 * Handled before the router, and so named here, are the unauthenticated
 * paths index.php answers itself; each must still be in the source, so one
 * removed there cannot stay "served" here.
 */
final class RouteInventory
{
    /**
     * Paths index.php answers before building the router, as the literal
     * that routes them => the operations. The literal is asserted to be in
     * index.php.
     */
    public const PRE_ROUTER = [
        "\$path === '/apps/installs'" => ['GET /apps/installs', 'POST /apps/installs'],
        "'#^/apps/installs/([^/]+)/events\$#D'" => ['POST /apps/installs/{}/events'],
        "\$path === '/versions' && \$method === 'GET'" => ['GET /versions'],
        "\$path === '/system/health' && \$method === 'GET'" => ['GET /system/health'],
        "\$path === '/apps/schema'" => ['GET /apps/schema'],
    ];

    private const ROUTE_METHODS = ['get' => 'GET', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'];

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string> "METHOD /path" with every path parameter written {}
     */
    public static function served(): array
    {
        $source = (string) file_get_contents(self::root() . '/api/v3/index.php');
        $operations = [];

        foreach (self::PRE_ROUTER as $literal => $ops) {
            if (!str_contains($source, $literal)) {
                throw new RuntimeException("index.php no longer routes $literal; update RouteInventory::PRE_ROUTER");
            }
            array_push($operations, ...$ops);
        }

        // The whole file: the main router, the dry-run and staging routers
        // (which register the same paths again, so they add nothing to the
        // set but are read for their groups' prefixes) and the
        // /staged-changes group registered after them.
        $tokens = token_get_all($source);

        $resources = self::crudResources($source);

        // Which variables are routers: every one assigned `new Router()`,
        // and every closure parameter typed Router (a group's). Derived, not listed,
        // so a group written with another parameter name is still read.
        $routerVariables = [];
        $range = $source;
        if (preg_match_all('/\bRouter\s+(\$\w+)/', $range, $params) > 0) {
            foreach ($params[1] as $name) {
                $routerVariables[$name] = true;
            }
        }
        if (preg_match_all('/(\$\w+)\s*=\s*new\s+Router\b/', $range, $made) > 0) {
            foreach ($made[1] as $name) {
                $routerVariables[$name] = true;
            }
        }
        $depth = 0;
        /** @var list<array{prefix: string, depth: int}> $groups */
        $groups = [];
        $pendingGroup = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;
            if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                if ($pendingGroup !== null) {
                    $groups[] = ['prefix' => $pendingGroup, 'depth' => $depth];
                    $pendingGroup = null;
                }
                continue;
            }
            if ($text === '}') {
                if ($groups !== [] && $groups[count($groups) - 1]['depth'] === $depth) {
                    array_pop($groups);
                }
                $depth--;
                continue;
            }
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            $j = self::next($tokens, $i);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $k = self::next($tokens, $j);
            if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
                continue;
            }
            $call = strtolower($tokens[$k][1]);
            $open = self::next($tokens, $k);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $line = (int) $t[2];

            // `$c->delete(…)` is a controller's; only a Router registers.
            if (!isset($routerVariables[$t[1]])) {
                continue;
            }
            if ($call === 'match') {
                continue; // dispatching, not registering
            }
            if ($call !== 'group' && !isset(self::ROUTE_METHODS[$call]) && $call !== 'add') {
                throw new RuntimeException("index.php:$line calls {$t[1]}->$call(), a router method this reader does not know");
            }

            $argAt = self::next($tokens, $open);
            $method = null;
            if ($call === 'add') {
                $method = self::literal($tokens, $argAt, $line);
                $comma = self::next($tokens, (int) $argAt);
                $argAt = $comma === null ? null : self::next($tokens, $comma);
            } elseif ($call !== 'group') {
                $method = self::ROUTE_METHODS[$call];
            }
            $path = self::pathArgument($tokens, $argAt, $line);

            if ($call === 'group') {
                // The prefix applies to the closure's braces, which is how its
                // extent is found; an arrow function has none to follow.
                for ($n = (int) $argAt + 1; $n < $count; $n++) {
                    if (is_array($tokens[$n]) && $tokens[$n][0] === T_FN) {
                        throw new RuntimeException("index.php:$line defines a route group with an arrow function, which this reader cannot bound");
                    }
                    if (is_array($tokens[$n]) && $tokens[$n][0] === T_FUNCTION) {
                        break;
                    }
                }
                $pendingGroup = $path;
                continue;
            }
            $full = implode('', array_column($groups, 'prefix')) . $path;
            $operations[] = strtoupper((string) $method) . ' ' . ($full === '' ? '/' : $full);
        }
        if ($groups !== [] || $pendingGroup !== null) {
            throw new RuntimeException('unbalanced groups while reading api/v3/index.php');
        }

        // The CRUD loop's computed prefix, once per resource.
        $expanded = [];
        foreach ($operations as $op) {
            if (str_contains($op, '{resource}')) {
                foreach ($resources as $resource) {
                    $expanded[] = str_replace('{resource}', $resource, $op);
                }
                continue;
            }
            $expanded[] = $op;
        }

        return self::normalise($expanded);
    }

    /**
     * @return list<string> "METHOD /path" for every operation under `paths:`
     *         whose path is the v3 API's (the /.well-known/ receivers are not)
     */
    public static function documented(): array
    {
        $lines = file(self::root() . '/docs/openapi.yaml', FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('cannot read docs/openapi.yaml');
        }
        $operations = [];
        $inPaths = false;
        $path = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\w[\w-]*):/', $line, $m) === 1) {
                $inPaths = $m[1] === 'paths';
                $path = null;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('/^  (\/\S*):\s*$/', $line, $m) === 1) {
                $path = str_starts_with($m[1], '/.well-known/') ? null : $m[1];
                continue;
            }
            if ($path !== null && preg_match('/^    (get|put|post|patch|delete|head|options):\s*$/', $line, $m) === 1) {
                $operations[] = strtoupper($m[1]) . ' ' . $path;
            }
        }

        return self::normalise($operations);
    }

    /** @param list<string> $operations */
    private static function normalise(array $operations): array
    {
        $out = [];
        foreach ($operations as $op) {
            $op = (string) preg_replace('/\{[^}]*\}/', '{}', $op);
            $out[$op] = true;
        }
        $out = array_keys($out);
        sort($out, SORT_STRING);

        return $out;
    }

    /** @return list<string> */
    private static function crudResources(string $source): array
    {
        if (preg_match('/\$crudMap = \[(.*?)\];/s', $source, $m) !== 1
            || preg_match_all("/'([a-z-]+)'\s*=>/", $m[1], $keys) < 5) {
            throw new RuntimeException('cannot read $crudMap in api/v3/index.php');
        }

        return $keys[1];
    }

    /** @param list<mixed> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        for ($n = $i + 1, $c = count($tokens); $n < $c; $n++) {
            if (is_array($tokens[$n]) && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $n;
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function literal(array $tokens, ?int $at, int $line): string
    {
        if ($at === null || !is_array($tokens[$at]) || $tokens[$at][0] !== T_CONSTANT_ENCAPSED_STRING) {
            throw new RuntimeException("index.php:$line registers a route whose method or path is not a string literal");
        }

        return substr($tokens[$at][1], 1, -1);
    }

    /**
     * A path argument: a string literal, or a double-quoted one whose only
     * interpolation is the CRUD loop's $resource.
     *
     * @param list<mixed> $tokens
     */
    private static function pathArgument(array $tokens, ?int $at, int $line): string
    {
        if ($at !== null && $tokens[$at] === '"') {
            // Literal text and the CRUD loop's $resource, nothing else.
            $path = '';
            for ($n = $at + 1; $n < count($tokens) && $tokens[$n] !== '"'; $n++) {
                $part = $tokens[$n];
                if (is_array($part) && $part[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $path .= $part[1];
                } elseif (is_array($part) && $part[0] === T_VARIABLE && $part[1] === '$resource') {
                    $path .= '{resource}';
                } else {
                    throw new RuntimeException("index.php:$line interpolates something other than \$resource into a route path");
                }
            }

            return $path;
        }

        return self::literal($tokens, $at, $line);
    }
}

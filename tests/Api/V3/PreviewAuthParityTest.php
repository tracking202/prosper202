<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * A DELETE preview asks for everything its DELETE asks for.
 *
 * A preview (`?dry_run=1`, and the record embedded in a `?staged=1`
 * proposal) returns the full row the delete would remove, so it is a read of
 * that row and must be refused to whoever the delete refuses. The route's
 * group middleware is shared by construction: the dispatcher runs the real
 * match's middleware before a dry run and before a staged write. What is not
 * shared is an authorization call made *inside* a DELETE handler — the users
 * routes' requireAdmin(), the model routes' manage_attribution_models — and
 * each of those has to be repeated in the preview's handler by hand. This
 * test reads api/v3/index.php and holds that: for every preview route, each
 * authorization call in the real DELETE handler at the same path is also in
 * the preview handler.
 *
 * What it reads: `$router`, `$previewRouter` and a group's `$r` calling
 * `->group('prefix', function … { … }, [middleware])` and
 * `->delete('path', <closure or arrow fn>)`. An authorization call is
 * `->requireAdmin(`, `->requireSelfOrAdmin(`, `->requirePermission(…, 'name')`
 * (by permission name), or a call to a local closure variable (`$manage()`)
 * whose own body makes one of those. What it refuses rather than guesses at:
 * a DELETE registered through `->add(` or with a handler that is not a
 * closure literal, a path or prefix that is not a string literal, and a
 * permission given as anything but a string literal.
 */
final class PreviewAuthParityTest extends TestCase
{
    private const ROUTERS = ['$router' => 'main', '$previewRouter' => 'preview', '$stageableRouter' => 'other'];

    /** @var list<array{0:int|string,1:string,2:int}> */
    private array $t = [];

    public function testEveryPreviewAsksForWhatItsDeleteAsksFor(): void
    {
        [$real, $preview] = $this->deletes((string) file_get_contents(dirname(__DIR__, 3) . '/api/v3/index.php'));

        self::assertNotEmpty($preview, 'no preview routes were read: the scan is blind, not the file clean');
        self::assertArrayHasKey('/attribution/models/{id}', $real, 'the scan reads grouped routes');
        foreach ($preview as $path => [$line, $checks]) {
            self::assertArrayHasKey($path, $real, "the preview at line $line ($path) has no DELETE route of its own");
            $missing = array_diff($real[$path][1], $checks);
            self::assertSame(
                [],
                array_values($missing),
                "DELETE $path (line {$real[$path][0]}) calls " . implode(', ', $missing)
                . " in its handler; its preview (line $line) must call the same, or a key the delete refuses reads the row through dry_run/staged"
            );
        }
    }

    public function testTheScanSeesAMissingCheck(): void
    {
        $src = <<<'PHP'
<?php
$build = function () use ($auth, $db) {
    $router = new Router();
    $router->group('/things', function (Router $r) use ($auth, $db) {
        $gate = static function () use ($auth, $db): void {
            $auth->requirePermission($db, 'manage_things');
        };
        $r->delete('/{id}', function ($ctx) use ($gate) { $gate(); return null; });
        $r->delete('/{id}/parts/{p}', function ($ctx) use ($auth) { $auth->requireAdmin(); return null; });
    }, [static function () {}]);
    $previewRouter = new Router();
    $previewRouter->delete('/things/{id}', fn($ctx) => 1);
    $previewRouter->group('/things', function (Router $r) use ($auth) {
        $r->delete('/{id}/parts/{p}', function ($ctx) use ($auth) { $auth->requireAdmin(); return 1; });
    });
};
PHP;
        [$real, $preview] = $this->deletes($src);
        self::assertSame(['permission:manage_things'], $real['/things/{id}'][1]);
        self::assertSame([], $preview['/things/{id}'][1]);
        self::assertSame(['requireAdmin'], $preview['/things/{id}/parts/{p}'][1]);
    }

    public function testWhatTheScanCannotReadIsRefused(): void
    {
        foreach ([
            '$previewRouter->add(\'DELETE\', \'/x\', fn() => 1);' => 'add(',
            '$router->delete(\'/x\', $handler);' => 'not a closure',
            '$router->delete($path, fn() => 1);' => 'not a string literal',
            '$router->group(\'/g\', function (Router $r) use ($auth, $db, $p) { $r->delete(\'/x\', function () use ($auth, $db, $p) { $auth->requirePermission($db, $p); }); });' => 'permission',
        ] as $code => $why) {
            try {
                $this->deletes("<?php\n" . $code . "\n");
                self::fail("accepted: $code");
            } catch (\UnexpectedValueException $e) {
                self::assertStringContainsString($why, $e->getMessage(), $code);
            }
        }
    }

    /**
     * @return array{0: array<string, array{0:int, 1:list<string>}>, 1: array<string, array{0:int, 1:list<string>}>}
     */
    private function deletes(string $src): array
    {
        $this->t = [];
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $this->t[] = is_array($tok) ? $tok : [0, $tok, 0];
        }
        $closures = $this->closureChecks();

        $out = ['main' => [], 'preview' => [], 'other' => []];
        // Stack of open groups: [router, prefix, index of the closing brace].
        $stack = [];
        $n = count($this->t);
        for ($i = 0; $i < $n; $i++) {
            while ($stack !== [] && $i > $stack[count($stack) - 1][2]) {
                array_pop($stack);
            }
            if ($this->t[$i][0] !== T_VARIABLE || ($this->t[$i + 1][1] ?? '') !== '->' || !isset($this->t[$i + 2])) {
                continue;
            }
            $var = $this->t[$i][1];
            $method = strtolower($this->t[$i + 2][1]);
            if (isset(self::ROUTERS[$var])) {
                $router = self::ROUTERS[$var];
                $prefix = '';
            } elseif ($var === '$r' && $stack !== []) {
                [$router, $prefix] = $stack[count($stack) - 1];
            } else {
                continue;
            }
            if (($this->t[$i + 3][1] ?? '') !== '(') {
                continue;
            }
            $line = (int) $this->t[$i][2];
            if ($method === 'add') {
                throw new \UnexpectedValueException("line $line: a route registered through ->add( cannot be read; use ->delete(");
            }
            if ($method !== 'group' && $method !== 'delete') {
                continue;
            }
            $close = $this->matching($i + 3);
            [$first, $afterFirst] = $this->stringArg($i + 4, $line);
            if ($method === 'group') {
                $body = $this->closureBody($afterFirst, $line);
                $stack[] = [$router, $prefix . $first, $body[1]];
                continue;
            }
            if ($router === 'other') {
                continue;
            }
            $h = $afterFirst;
            $bodyStart = $this->closureStart($h, $line);
            $out[$router][$prefix . $first] = [$line, $this->checksIn($bodyStart, $close, $closures, $line)];
        }

        return [$out['main'], $out['preview']];
    }

    /** A string-literal argument's text (a double-quoted string keeps its interpolation as written). */
    private function stringArg(int $i, int $line): array
    {
        if ($this->t[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
            return [substr($this->t[$i][1], 1, -1), $i + 1];
        }
        if ($this->t[$i][1] === '"') {
            $text = '';
            for ($j = $i + 1; $this->t[$j][1] !== '"'; $j++) {
                $text .= $this->t[$j][1];
            }
            return [$text, $j + 1];
        }
        throw new \UnexpectedValueException("line $line: a route path or prefix that is not a string literal cannot be read");
    }

    /** Index of the first token of a closure or arrow fn starting at $i (after the comma). */
    private function closureStart(int $i, int $line): int
    {
        if (($this->t[$i][1] ?? '') !== ',') {
            throw new \UnexpectedValueException("line $line: expected a handler argument");
        }
        $j = $i + 1;
        if ($this->t[$j][0] === T_STATIC) {
            $j++;
        }
        if (!in_array($this->t[$j][0], [T_FUNCTION, T_FN], true)) {
            throw new \UnexpectedValueException("line $line: a handler that is not a closure literal cannot be read");
        }

        return $j;
    }

    /** [open brace, close brace] of a group's definition closure. */
    private function closureBody(int $i, int $line): array
    {
        $j = $this->closureStart($i, $line);
        while ($this->t[$j][1] !== '{') {
            $j++;
        }

        return [$j, $this->matching($j)];
    }

    /** Authorization calls between two token indexes, closure variables expanded. */
    private function checksIn(int $from, int $to, array $closures, int $line): array
    {
        $found = [];
        for ($j = $from; $j < $to; $j++) {
            $v = $this->t[$j][1];
            if ($v === '->' && isset($this->t[$j + 1])) {
                $name = $this->t[$j + 1][1];
                if ($name === 'requireAdmin' || $name === 'requireSelfOrAdmin') {
                    $found[] = $name;
                } elseif ($name === 'requirePermission') {
                    $found[] = 'permission:' . $this->permissionName($j + 2, $line);
                }
            } elseif ($this->t[$j][0] === T_VARIABLE && ($this->t[$j + 1][1] ?? '') === '(' && isset($closures[$v])) {
                array_push($found, ...$closures[$v]);
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    private function permissionName(int $open, int $line): string
    {
        $close = $this->matching($open);
        $last = $this->t[$close - 1];
        if ($last[0] !== T_CONSTANT_ENCAPSED_STRING || ($this->t[$close - 2][1] ?? '') !== ',') {
            throw new \UnexpectedValueException("line $line: a requirePermission whose permission is not a string literal cannot be read");
        }

        return substr($last[1], 1, -1);
    }

    /**
     * `$name = [static] function … { … };` and `$name = [static] fn … => …;`
     * → the authorization calls in its body. The map is file-wide, not
     * scoped, so one name bound to two closures that check different things
     * is refused rather than resolved to either.
     */
    private function closureChecks(): array
    {
        $map = [];
        $n = count($this->t);
        for ($i = 0; $i + 2 < $n; $i++) {
            if ($this->t[$i][0] !== T_VARIABLE || $this->t[$i + 1][1] !== '=') {
                continue;
            }
            $j = $i + 2;
            if ($this->t[$j][0] === T_STATIC) {
                $j++;
            }
            if ($this->t[$j][0] === T_FUNCTION) {
                $k = $j;
                while ($this->t[$k][1] !== '{') {
                    $k++;
                }
                $end = $this->matching($k);
            } elseif ($this->t[$j][0] === T_FN) {
                $k = $j;
                $depth = 0;
                for ($end = $j; $end < $n; $end++) {
                    $v = $this->t[$end][1];
                    if ($v === '(' || $v === '[' || $v === '{') {
                        $depth++;
                    } elseif ($v === ')' || $v === ']' || $v === '}') {
                        $depth--;
                    } elseif ($v === ';' && $depth === 0) {
                        break;
                    }
                }
            } else {
                continue;
            }
            $name = $this->t[$i][1];
            $checks = $this->checksIn($k, $end, [], (int) $this->t[$i][2]);
            if (isset($map[$name]) && $map[$name] !== $checks) {
                throw new \UnexpectedValueException('line ' . $this->t[$i][2] . ": $name is bound to closures that check different things; give them different names");
            }
            if ($checks !== [] || isset($map[$name])) {
                $map[$name] = $checks;
            }
        }

        return $map;
    }

    private function matching(int $open): int
    {
        $pairs = ['(' => ')', '{' => '}', '[' => ']'];
        $o = $this->t[$open][1];
        $c = $pairs[$o];
        $depth = 0;
        $n = count($this->t);
        for ($j = $open; $j < $n; $j++) {
            $v = $this->t[$j][1];
            if ($v === $o || ($o === '{' && ($this->t[$j][0] === T_CURLY_OPEN || $this->t[$j][0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
                $depth++;
            } elseif ($v === $c) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        throw new \UnexpectedValueException('unbalanced ' . $o);
    }
}

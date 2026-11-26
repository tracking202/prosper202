<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * Every route under /users/{id}/ asks that the user is there, right after
 * its authorization: `$auth->…;` statements, then
 * `$liveUser(PathId::of($ctx));`, before anything else in the handler.
 * GET /users/{id} answered 404 for a removed
 * user while its preferences, API keys and click filter answered 200, and
 * POST /users/{id}/api-keys minted a key for a removed user or for an id no
 * user ever had (measured live). Authorization comes first so a caller
 * refused there learns nothing of which users exist.
 *
 * The floor only: it reads the handlers' text. That the guard refuses is
 * executed by RemovedUserRoutesInstanceTest, over every route this reads.
 */
final class RemovedUserRoutesTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** The guard each /users group defines, as the scan's whitespace-free text. */
    private const GUARD = '$liveUser=staticfn(int$id)=>$make()->requireUser($id);';

    private const MAKE = '$make=fn()=>new\Api\V3\Controllers\UsersController($db);';

    /** @return list<string> */
    private function problems(string $src): array
    {
        $problems = [];
        $seen = 0;
        foreach ($this->registrations($src) as $route) {
            if (!str_starts_with($route['path'], '/users/{id}/')) {
                continue;
            }
            $where = "{$route['router']} {$route['method']} {$route['path']} (line {$route['line']})";
            if ($route['router'] === '$stageableRouter') {
                // An allowlist, only ever matched: a staged write is applied
                // through the real route, which asks.
                continue;
            }
            $seen++;
            $group = self::statements($route['group']);
            if (!in_array(self::MAKE, $group, true) || !in_array(self::GUARD, $group, true)) {
                $problems[] = "$where: its group does not define \$make and \$liveUser as "
                    . self::MAKE . ' ' . self::GUARD;
                continue;
            }
            $handler = $route['handler'];
            $head = substr($handler, 0, (int) strpos($handler, '{'));
            if (!str_starts_with($handler, 'function($ctx)use(') || !str_contains($head, '$liveUser')) {
                $problems[] = "$where: not a closure that captures \$liveUser";
                continue;
            }
            $body = self::statements(substr($handler, (int) strpos($handler, '{')));
            $i = 0;
            while (isset($body[$i]) && str_starts_with($body[$i], '$auth->')) {
                $i++;
            }
            if ($i === 0) {
                $problems[] = "$where: does not begin with its authorization";
            } elseif (($body[$i] ?? '') !== '$liveUser(PathId::of($ctx));') {
                $problems[] = "$where: the statement after its authorization is not \$liveUser(PathId::of(\$ctx));";
            }
        }
        if ($seen < 19) {
            $problems[] = "read $seen routes under /users/{id}/, fewer than the 19 there are";
        }

        return $problems;
    }

    /**
     * The top-level statements inside a `{…}` block of whitespace-free text,
     * each through its ';'.
     *
     * @return list<string>
     */
    private static function statements(string $block): array
    {
        $out = [];
        $depth = 0;
        $start = 1;
        $len = strlen($block);
        for ($i = 0; $i < $len; $i++) {
            $c = $block[$i];
            if ($c === "'" || $c === '"') {
                for ($i++; $i < $len && $block[$i] !== $c; $i++) {
                    if ($block[$i] === '\\') {
                        $i++;
                    }
                }
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
                if ($depth === 1 && $c === '}') {
                    // A block statement (if, a closure's body) ends here when no ';' follows.
                    if (!in_array($block[$i + 1] ?? '', [')', ';', ','], true)) {
                        $out[] = substr($block, $start, $i - $start + 1);
                        $start = $i + 1;
                    }
                }
            } elseif ($c === ';' && $depth === 1) {
                $out[] = substr($block, $start, $i - $start + 1);
                $start = $i + 1;
            }
        }

        return $out;
    }

    public function testEveryRouteUnderAUserAsksThatTheUserIsThere(): void
    {
        self::assertSame([], $this->problems(self::indexSource()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function plants(): iterable
    {
        $auth = "\$auth->requirePersonalSettingsOf(\$db, PathId::of(\$ctx));\n                ";
        $guard = "\$liveUser(PathId::of(\$ctx));\n                ";
        $get = 'return $make()->getPreferences(';
        $update = "return \$make()->updatePreferences(\n"
            . "                    PathId::of(\$ctx),\n"
            . "                    \$payload,\n"
            . "                    null,\n"
            . "                    // The LTV › Settings values ask what the LTV Settings writes ask.\n"
            . "                    static fn () => \\Api\\V3\\Support\\LtvAccess::require(\$auth, \$db, 'settings_write')\n"
            . '                );';
        yield 'the guard removed' => [$auth . $guard . $get, $auth . $get];
        yield 'the guard before the authorization' => [$auth . $guard . $get, $guard . $auth . $get];
        yield 'the guard after the work' => [
            $guard . $update,
            '$x = ' . substr($update, strlen('return ')) . "\n                " . $guard . 'return $x;',
        ];
        $filterGet = 'return $filter()->get(';
        yield 'the guard made conditional' => [$guard . $filterGet, 'if ($x) ' . $guard . $filterGet];
        yield 'the guard redefined' => [
            '$liveUser = static fn(int $id) => $make()->requireUser($id);',
            '$liveUser = static fn(int $id) => null;',
        ];
    }

    /** @dataProvider plants */
    public function testAPlantedDefectIsReported(string $from, string $to): void
    {
        $src = self::indexSource();
        self::assertStringContainsString($from, $src, 'the plant lands');
        $planted = preg_replace('/' . preg_quote($from, '/') . '/', addcslashes($to, '\\$'), $src, 1);
        self::assertNotSame($src, $planted);
        self::assertNotSame([], $this->problems((string) $planted));
    }
}

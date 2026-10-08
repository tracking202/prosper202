<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\NotFoundException;
use Api\V3\Support\PathId;
use PHPUnit\Framework\TestCase;

/**
 * Every route handler in api/v3/index.php reads an {id} in its path with
 * PathId::of() -- digits the int cast leaves unchanged, or a 404 -- and the
 * few string parameters (listed below, with why) as strings. Nothing else
 * may touch `$ctx`.
 *
 * The handlers read ids as `(int) $ctx['id']`, and `(int) '2e0'` is 2:
 * measured live, DELETE /campaigns/2e0 deleted campaign 2, PUT
 * /aff-networks/2x renamed category 2 and GET /aff-networks/2abc read it,
 * each a 2xx for a path that named no record. The scan reads the routes
 * through ReadsRouteRegistrations, so a route it cannot read is refused by
 * line, and a planted cast of every spelling is shown to be reported below.
 */
final class PathIdsAreReadStrictlyTest extends TestCase
{
    use ReadsRouteRegistrations;

    /** [path pattern, parameter, why it is a string] */
    private const STRING_PARAMS = [
        ['#^/sync/jobs/\{id\}(/|$)#', 'id', 'a sync job id is a generated string'],
        ['#^/audit/sync-jobs/\{id\}$#', 'id', 'the audit record of a sync job, by its string id'],
        ['#^/staged-changes/\{id\}(/|$)#', 'id', 'a staged change id is a server-issued string'],
        ['#^/changes/\{entity\}$#', 'entity', 'an entity name (SyncController checks it against the list)'],
        ['#^/ltv/subscriptions/\{ref\}/events$#', 'ref', "the caller's own external subscription id"],
        ['#^/apps/\{id\}/installs/\{uuid\}$#', 'uuid', "an Android install's uuid"],
        ['#^/users/\{id\}/api-keys/\{keyId\}$#', 'keyId', 'an API key is a string'],
    ];

    /**
     * What is wrong with how each route reads its path parameters.
     *
     * @return list<string>
     */
    private function problems(string $src): array
    {
        $problems = [];
        $reads = 0;
        foreach ($this->registrations($src) as $route) {
            if ($route['router'] === '$stageableRouter') {
                continue; // an allowlist: its handlers read nothing
            }
            $where = "{$route['method']} {$route['path']} (line {$route['line']})";
            $handler = $route['handler'];
            // The scan's handler text has its whitespace removed. What is
            // left after the vouched reads and the parameter's own
            // declaration must not name $ctx at all: a cast, intval(), a
            // raw read, a local helper or another class's parser is not
            // read here, so it is refused rather than guessed at.
            $rest = preg_replace('/(?<![\w\\\\])PathId::of\(\$ctx(,\'\w+\')?\)/', '', $handler);
            $rest = preg_replace("/\\(string\\)\\\$ctx\\['\\w+'\\]/", '', (string) $rest);
            $rest = preg_replace('/\b(fn|function)\(\$ctx\)/', '', (string) $rest);
            if (preg_match('/\$ctx\b/', (string) $rest) === 1) {
                $problems[] = "$where reads \$ctx other than through PathId::of() or (string): $handler";
            }
            preg_match_all('/\{(\w+)\}/', $route['path'], $params);
            foreach ($params[1] as $param) {
                if ($param === 'crud') {
                    continue; // the CRUD loop's resource name, not a parameter
                }
                $reads++;
                $string = false;
                foreach (self::STRING_PARAMS as [$pattern, $name]) {
                    $string = $string || ($name === $param && preg_match($pattern, $route['path']) === 1);
                }
                if ($string) {
                    if (!str_contains($handler, "(string)\$ctx['$param']")) {
                        $problems[] = "$where does not read its string parameter {$param} as (string)\$ctx['$param']";
                    }
                    continue;
                }
                $strict = $param === 'id'
                    ? preg_match('/(?<![\w\\\\])PathId::of\(\$ctx(,\'id\')?\)/', $handler) === 1
                    : str_contains($handler, "PathId::of(\$ctx,'$param')");
                if (!$strict) {
                    $problems[] = "$where does not read {{$param}} with PathId::of(\$ctx" . ($param === 'id' ? '' : ", '$param'") . ')';
                }
            }
        }
        if ($reads === 0) {
            $problems[] = 'no path parameter was read: the scan saw nothing';
        }

        return $problems;
    }

    public function testEveryPathParameterIsReadStrictly(): void
    {
        $src = self::indexSource();
        self::assertSame([], $this->problems($src));
        self::assertStringContainsString("\nuse Api\\V3\\Support\\PathId;\n", $src, 'PathId is the Support class, imported by name');
        self::assertDoesNotMatchRegularExpression('/^namespace\s/m', $src, 'index.php declares no namespace, so the imported PathId is the one read');
        self::assertDoesNotMatchRegularExpression('/\b(class|function)\s+PathId\b/i', $src, 'nothing in index.php redeclares PathId');
    }

    /** Every spelling the scan claims to refuse, planted, is reported; the vouched ones are not. */
    public function testThePlantedShapesAreReported(): void
    {
        $good = <<<'PHP'
<?php
$router->get('/campaigns/{id}', fn($ctx) => $c->get(PathId::of($ctx)));
$router->delete('/rotators/{id}/rules/{ruleId}', fn($ctx) => $c->deleteRule(PathId::of($ctx), PathId::of($ctx, 'ruleId')));
$router->get('/staged-changes/{id}', fn($ctx) => $s->get((string)$ctx['id']));
$router->get('/users/{id}', function ($ctx) use ($auth) {
    $auth->requireSelfOrAdmin(PathId::of($ctx));
    return $make()->get(PathId::of($ctx));
});
PHP;
        self::assertSame([], $this->problems($good), 'the vouched shapes pass');

        $bad = [
            "fn(\$ctx) => \$c->get((int)\$ctx['id'])",
            "fn(\$ctx) => \$c->get((int) \$ctx['id'])",
            "fn(\$ctx) => \$c->get(intval(\$ctx['id']))",
            "fn(\$ctx) => \$c->get(\$ctx['id'])",
            "fn(\$ctx) => \$c->get((float)\$ctx['id'])",
            "fn(\$ctx) => \$c->get(\\Api\\V3\\Controllers\\GoalsController::pathId(\$ctx['id']))",
            "fn(\$ctx) => \$c->get(\$id(\$ctx))",
            "fn(\$ctx) => \$c->get(MyPathId::of(\$ctx))",
            "fn(\$ctx) => \$c->get(\\Other\\PathId::of(\$ctx))",
            "fn(\$ctx) => \$c->get(PathId::of(\$ctx) + (int)\$ctx['id'])",
            "fn() => \$c->get(7)",
        ];
        foreach ($bad as $handler) {
            $src = "<?php\n\$router->get('/campaigns/{id}', $handler);\n";
            self::assertNotSame([], $this->problems($src), "planted: $handler");
        }
        $src = "<?php\n\$router->delete('/rotators/{id}/rules/{ruleId}', fn(\$ctx) => \$c->deleteRule(PathId::of(\$ctx), (int)\$ctx['ruleId']));\n";
        self::assertNotSame([], $this->problems($src), 'a second parameter cast is reported');
        $src = "<?php\n\$router->get('/staged-changes/{id}', fn(\$ctx) => \$s->get(\$ctx['id']));\n";
        self::assertNotSame([], $this->problems($src), 'a string parameter read without (string) is reported');
    }

    /** @return iterable<string, array{0: string}> */
    public static function notIds(): iterable
    {
        foreach (['1e3', '2e0', '12x', '12abc', '0', '00', '01', '-1', '+1', ' 1', '1 ', '1.0', '0x1A', '', '99999999999999999999', '9223372036854775808', '%31'] as $segment) {
            yield var_export($segment, true) => [$segment];
        }
    }

    /** @dataProvider notIds */
    public function testASegmentThatIsNotAnIdIsANotFound(string $segment): void
    {
        try {
            PathId::of(['id' => $segment]);
            self::fail(var_export($segment, true) . ' was read as an id');
        } catch (NotFoundException $e) {
            self::assertSame('Not found: ' . json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' is not an id', $e->getMessage());
        }
    }

    public function testAnIdIsReadAsItsNumber(): void
    {
        self::assertSame(1, PathId::of(['id' => '1']));
        self::assertSame(1000, PathId::of(['id' => '1000']));
        self::assertSame(42, PathId::of(['ruleId' => '42'], 'ruleId'));
        self::assertSame(PHP_INT_MAX, PathId::of(['id' => (string) PHP_INT_MAX]), 'a BIGINT id (LTV, attribution) is read whole');
    }
}

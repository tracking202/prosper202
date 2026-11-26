<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\PageRows;
use PHPUnit\Framework\TestCase;

/**
 * Every v3 route that takes `offset` says whether rows remain after its
 * page: GET /ltv/breakdown paged with limit and offset and answered nothing
 * about the rows after the page, so an agent totalling it undercounted
 * without knowing (CLAUDE.md #1: a page of exactly the remaining rows read
 * the same as a page with rows after it).
 *
 * Driven from the controllers, not from a list: every method of every class
 * under api/v3/ is read from its tokens, with the methods it calls on itself
 * (`$this->`, `self::`, `static::`, `parent::`, through the class chain) and
 * the class constants it names. A method takes `offset` when that reach
 * holds the string 'offset' (a request key, an allowed-parameter list, an
 * answer key); every public one reads through both halves of PageRows
 * (fetchLimit() bound as the LIMIT, cut() for the rows answered) and answers
 * a `truncated` key.
 *
 * What the scan cannot follow is refused, not skipped (CLAUDE.md #20): a
 * call by a computed name on $this, 'offset' outside a method or a constant,
 * and 'offset' in any other api/v3 file than the ones listed with why. The
 * name is matched as PHP's string tokens hold it; one assembled from pieces
 * ('off' . 'set') is not seen. The scan is a floor: that the bound LIMIT is
 * fetchLimit()'s and the answered rows cut()'s is held per route family by
 * the integration tests (PagedAnswersIntegrationTest,
 * BreakdownTruncationIntegrationTest).
 */
final class PagedAnswersSayWhetherRowsRemainTest extends TestCase
{
    /**
     * api/v3 files outside the controllers that name 'offset', with why
     * they are not routes that take it.
     */
    private const NOT_ROUTES = [
        'api/v3/Support/RemoteApiClient.php' => 'the sync client: it sends offset to another install and reads that install\'s truncated',
        'api/v3/Support/ServerStateStore.php' => 'listJobEvents() answers the events from an offset; SyncController::events() takes the request\'s and is held here',
    ];

    private const PAGE_ROWS = 'Api\\V3\\Support\\PageRows';

    /** @return array<string, string> path relative to the repository => source */
    private static function apiSources(): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];
        $it = new \RecursiveIteratorIterator(\Tests\Support\SourceScan::tree($root . '/api/v3'));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, 'read ' . $file->getPathname());
            $out[substr($file->getPathname(), strlen($root) + 1)] = $source;
        }
        ksort($out);
        self::assertGreaterThan(100, count($out), 'the api/v3 tree was read');

        return $out;
    }

    public function testEveryRouteTakingOffsetSaysWhetherRowsRemain(): void
    {
        [$takers, $problems] = self::scan(self::apiSources(), self::NOT_ROUTES);

        self::assertSame([], $problems, "paged answers that do not say whether rows remain:\n  " . implode("\n  ", $problems));
        // A floor, so a scan that lost its way cannot pass on nothing; and
        // the routes the ticket and #284 named are among what it found.
        self::assertGreaterThanOrEqual(19, count($takers), implode(', ', $takers));
        foreach ([
            'Api\\V3\\Controller::list', 'Api\\V3\\Controllers\\LtvController::breakdown',
            'Api\\V3\\Controllers\\ReportsController::breakdown', 'Api\\V3\\Controllers\\GoalsController::outcomes',
            'Api\\V3\\Controllers\\SyncController::events', 'Api\\V3\\Controllers\\AttributionController::breakdown',
        ] as $method) {
            self::assertContains($method, $takers);
        }
    }

    /** Each shape the scan claims to read is planted, and reported. */
    public function testEachShapeOfAnUnsaidPageIsSeen(): void
    {
        $base = <<<'PHP'
            <?php
            namespace Api\V3;
            use Api\V3\Support\PageRows;
            abstract class Controller {
                public function list(array $params): array {
                    $limit = 5;
                    $offset = QueryInt::param($params, 'offset', 0, 0, 9);
                    [$rows, $truncated] = PageRows::cut($this->read(PageRows::fetchLimit($limit), $offset), $limit);
                    return ['data' => $rows, 'truncated' => $truncated];
                }
            }
            PHP;
        $clean = ['api/v3/Controller.php' => $base];
        [$takers, $problems] = self::scan($clean, []);
        self::assertSame([], $problems, 'the clean shape is clean');
        self::assertSame(['Api\\V3\\Controller::list'], $takers);

        $controller = static fn (string $body, string $head = 'use Api\V3\Support\PageRows;'): array => $clean + ['api/v3/Controllers/X.php' => "<?php\nnamespace Api\\V3\\Controllers;\n$head\nfinal class X extends \\Api\\V3\\Controller {\n$body\n}\n"];
        $planted = [
            'no PageRows at all' => [$controller('public function a(array $p): array { $o = $p["offset"]; return ["data" => [], "truncated" => false]; }'), 'X::a', 'PageRows::fetchLimit()'],
            'no cut' => [$controller('public function a(array $p): array { $l = PageRows::fetchLimit(1); return ["offset" => 0, "truncated" => false]; }'), 'X::a', 'PageRows::cut()'],
            'no truncated key' => [$controller('public function a(array $p): array { [$r] = PageRows::cut([], PageRows::fetchLimit(1)); return ["offset" => 0]; }'), 'X::a', "'truncated'"],
            'offset read in a private helper' => [$controller('public function a(array $p): array { return $this->paging($p); } private function paging(array $p): array { return [$p[\'offset\']]; }'), 'X::a', 'PageRows::fetchLimit()'],
            'offset read through a static helper' => [$controller('public function a(array $p): array { return self::paging($p); } private static function paging(array $p): array { return [$p[\'offset\']]; }'), 'X::a', 'PageRows::fetchLimit()'],
            'offset named by a constant' => [$controller('private const K = \'offset\'; public function a(array $p): array { return [$p[self::K]]; }'), 'X::a', 'PageRows::fetchLimit()'],
            'offset only in a constant list' => [$controller('private const P = [\'limit\', \'offset\']; public function a(array $p): array { foreach (self::P as $k) { $v[] = $p[$k]; } return $v; }'), 'X::P', 'no method of'],
            'an inherited list overridden' => [$controller('public function list(array $p): array { return parent::list($p) + ["offset" => 1]; } public function b(array $p): array { $o = $p["offset"]; return []; }'), 'X::b', 'PageRows::fetchLimit()'],
            'PageRows of another namespace' => [$controller('public function a(array $p): array { [$r, $t] = PageRows::cut([], PageRows::fetchLimit(1)); return ["offset" => 0, "truncated" => $t]; }', 'use Other\PageRows;'), 'X::a', 'PageRows::fetchLimit()'],
            'a call by a computed name' => [$controller('public function a(array $p): array { $m = "x"; return $this->$m($p); }'), 'X.php', 'computed name'],
            'offset outside a method' => [$controller('public $o = "offset";'), 'X.php', 'outside a method'],
            'offset in another file' => [$clean + ['api/v3/Support/Y.php' => "<?php\nfunction y(array \$p) { return \$p['offset']; }\n"], 'Y.php', 'not a controller'],
        ];
        foreach ($planted as $shape => [$sources, $where, $says]) {
            [, $problems] = self::scan($sources, []);
            $hit = array_filter($problems, static fn (string $p): bool => str_contains($p, $where) && str_contains($p, $says));
            self::assertNotSame([], $hit, "$shape: expected a report naming $where and $says, got:\n  " . implode("\n  ", $problems));
        }

        // A public method reaching a clean one through $this-> is clean: it
        // answers what the clean one answered.
        [, $problems] = self::scan($controller('public function a(array $p): array { return $this->list($p); }'), []);
        self::assertSame([], $problems);
    }

    public function testPageRowsSaysWhetherTheRowPastThePageCame(): void
    {
        self::assertSame(51, PageRows::fetchLimit(50));
        self::assertSame([[1, 2], true], PageRows::cut([1, 2, 3], 2));
        self::assertSame([[1, 2], false], PageRows::cut([1, 2], 2), 'a page of exactly the rows that remain');
        self::assertSame([[1], false], PageRows::cut([1], 2));
        self::assertSame([[], false], PageRows::cut([], 2));
        self::assertSame([['a'], true], PageRows::cut(['x' => 'a', 'y' => 'b'], 1), 'rows come back as a list');
        foreach ([static fn () => PageRows::cut([1, 2, 3, 4], 2), static fn () => PageRows::fetchLimit(0), static fn () => PageRows::cut([], 0)] as $refused) {
            try {
                $refused();
                self::fail('refused');
            } catch (\LogicException $e) {
                self::assertStringContainsString('PageRows', $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $sources path => source
     * @param array<string, string> $notRoutes path => why
     * @return array{0: list<string>, 1: list<string>} [public methods taking offset, problems]
     */
    private static function scan(array $sources, array $notRoutes): array
    {
        /** @var array<string, array{extends: ?string, methods: array<string, array{public: bool, offset: bool, calls: list<array{0: string, 1: string}>, consts: list<string>, pageFetch: bool, pageCut: bool, truncatedKey: bool, line: int, file: string}>, consts: array<string, bool>}> $classes */
        $classes = [];
        $problems = [];
        foreach ($sources as $path => $source) {
            $inController = $path === 'api/v3/Controller.php' || str_starts_with($path, 'api/v3/Controllers/');
            self::read($path, $source, $inController && !isset($notRoutes[$path]), $classes, $problems);
            if (!$inController && !isset($notRoutes[$path]) && self::namesOffset($source)) {
                $problems[] = "$path names 'offset' and is not a controller: read the request's through a controller and PageRows, or list the file in NOT_ROUTES with why";
            }
        }

        foreach ($classes as $class => $info) {
            $reads = array_filter($info['methods'], static fn (array $m): bool => $m['offset']);
            foreach ($info['lists'] as $const => $at) {
                if ($reads === []) {
                    $problems[] = "$at $class::$const lists 'offset' and no method of $class names it, so this scan cannot see where it is read";
                }
            }
        }

        $takers = [];
        foreach ($classes as $class => $info) {
            foreach ($info['methods'] as $name => $method) {
                if (!$method['public']) {
                    continue;
                }
                $reach = self::reach($classes, $class, $name);
                $takes = false;
                $fetch = $cut = $truncated = false;
                foreach ($reach as [$owner, $m]) {
                    $body = $classes[$owner]['methods'][$m];
                    $takes = $takes || $body['offset'];
                    foreach ($body['consts'] as $const) {
                        $takes = $takes || self::constNamesOffset($classes, $owner, $const);
                    }
                    $fetch = $fetch || $body['pageFetch'];
                    $cut = $cut || $body['pageCut'];
                    $truncated = $truncated || $body['truncatedKey'];
                }
                if (!$takes) {
                    continue;
                }
                $takers[] = "$class::$name";
                $at = "{$method['file']}:{$method['line']} $class::$name";
                if (!$fetch) {
                    $problems[] = "$at takes offset and binds no PageRows::fetchLimit(): read one row past the page";
                }
                if (!$cut) {
                    $problems[] = "$at takes offset and answers rows not cut by PageRows::cut()";
                }
                if (!$truncated) {
                    $problems[] = "$at takes offset and answers no 'truncated'";
                }
            }
        }
        sort($takers);

        return [$takers, $problems];
    }

    /**
     * Every method $class::$name reaches through calls on itself, as
     * [declaring class, method].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function reach(array $classes, string $class, string $name): array
    {
        $seen = [];
        $queue = [[$class, $name, $class]];
        while ($queue !== []) {
            [$lookup, $method, $self] = array_shift($queue);
            $owner = self::resolve($classes, $lookup, $method);
            if ($owner === null || isset($seen["$owner::$method"])) {
                continue;
            }
            $seen["$owner::$method"] = [$owner, $method];
            foreach ($classes[$owner]['methods'][$method]['calls'] as [$kind, $callee]) {
                if ($kind === 'parent') {
                    $parent = $classes[$owner]['extends'];
                    if ($parent !== null) {
                        $queue[] = [$parent, $callee, $self];
                    }
                } else {
                    // $this-> and static:: resolve from the object's class;
                    // self:: from the declaring one.
                    $queue[] = [$kind === 'self' ? $owner : $self, $callee, $self];
                }
            }
        }

        return array_values($seen);
    }

    private static function resolve(array $classes, string $class, string $method): ?string
    {
        for ($c = $class; $c !== null && isset($classes[$c]); $c = $classes[$c]['extends']) {
            if (isset($classes[$c]['methods'][$method])) {
                return $c;
            }
        }

        return null;
    }

    private static function constNamesOffset(array $classes, string $class, string $const): bool
    {
        for ($c = $class; $c !== null && isset($classes[$c]); $c = $classes[$c]['extends']) {
            if (isset($classes[$c]['consts'][$const])) {
                return $classes[$c]['consts'][$const];
            }
        }

        return false;
    }

    private static function isOffset(\PhpToken $t): bool
    {
        if ($t->id === T_CONSTANT_ENCAPSED_STRING) {
            return strtolower(substr($t->text, 1, -1)) === 'offset';
        }

        return $t->id === T_ENCAPSED_AND_WHITESPACE && strtolower($t->text) === 'offset';
    }

    private static function namesOffset(string $source): bool
    {
        foreach (\PhpToken::tokenize($source) as $t) {
            if (self::isOffset($t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The classes of one file: each method's body, read for 'offset', the
     * calls it makes on itself, the constants it names and PageRows.
     */
    private static function read(string $path, string $source, bool $held, array &$classes, array &$problems): void
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $n = count($tokens);
        $namespace = '';
        $uses = [];
        $class = null;
        $classDepth = -1;
        $method = null;
        $methodDepth = -1;
        $depth = 0;
        $pendingMethod = null;
        $constName = null;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $text = $t->text;
            if ($t->id === T_NAMESPACE && $depth === 0) {
                $namespace = $tokens[$i + 1]->text;
                continue;
            }
            if ($t->id === T_USE && $depth === 0 && $class === null) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $alias = substr($name, (int) strrpos('\\' . $name, '\\'));
                if (($tokens[$i + 2]->id ?? null) === T_AS) {
                    $alias = $tokens[$i + 3]->text;
                }
                $uses[$alias] = $name;
                continue;
            }
            if (in_array($t->id, [T_CLASS, T_TRAIT], true) && ($tokens[$i - 1]->id ?? null) !== T_DOUBLE_COLON && ($tokens[$i + 1]->id ?? null) === T_STRING) {
                $class = ltrim($namespace . '\\' . $tokens[$i + 1]->text, '\\');
                $extends = null;
                if (($tokens[$i + 2]->id ?? null) === T_EXTENDS) {
                    $extends = self::fullName($tokens[$i + 3]->text, $namespace, $uses);
                }
                $classes[$class] = ['extends' => $extends, 'methods' => [], 'consts' => [], 'lists' => []];
                $classDepth = $depth + 1;
                continue;
            }
            if ($text === '{' || $t->id === T_CURLY_OPEN || $t->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($pendingMethod !== null && $text === '{') {
                    $method = $pendingMethod;
                    $methodDepth = $depth;
                    $pendingMethod = null;
                }
                continue;
            }
            if ($text === '}') {
                if ($method !== null && $depth === $methodDepth) {
                    $method = null;
                }
                if ($class !== null && $depth === $classDepth) {
                    $class = null;
                }
                $depth--;
                continue;
            }
            if ($class !== null && $method === null && $depth === $classDepth) {
                if ($t->id === T_FUNCTION) {
                    $name = $tokens[$i + 1]->text;
                    $public = true;
                    for ($k = $i - 1; $k >= 0 && in_array($tokens[$k]->id, [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT], true); $k--) {
                        if (in_array($tokens[$k]->id, [T_PRIVATE, T_PROTECTED], true)) {
                            $public = false;
                        }
                    }
                    $classes[$class]['methods'][$name] = ['public' => $public, 'offset' => false, 'calls' => [], 'consts' => [],
                        'pageFetch' => false, 'pageCut' => false, 'truncatedKey' => false, 'line' => $t->line, 'file' => $path];
                    $pendingMethod = $name;
                    // An abstract method has no body.
                    for ($k = $i + 2; $k < $n && $tokens[$k]->text !== '{' && $tokens[$k]->text !== ';'; $k++) {
                    }
                    if (($tokens[$k]->text ?? '') === ';') {
                        $pendingMethod = null;
                    }
                    continue;
                }
                if ($t->id === T_CONST) {
                    // `const NAME =` or `const TYPE NAME =`
                    $k = $i + 1;
                    while (($tokens[$k + 1]->text ?? '') !== '=') {
                        $k++;
                    }
                    $constName = $tokens[$k]->text;
                    $classes[$class]['consts'][$constName] = false;
                    continue;
                }
                if ($text === ';') {
                    $constName = null;
                }
            }
            if (!self::isOffset($t) && !($method !== null && in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true))) {
                continue;
            }
            if (self::isOffset($t)) {
                if (!$held) {
                    continue;
                }
                if ($method !== null) {
                    $classes[$class]['methods'][$method]['offset'] = true;
                } elseif ($class !== null && $constName !== null) {
                    // `const KEY = 'offset'` is the key under another name;
                    // a list holding it is a list of parameter names, read
                    // where a method names the key (held below).
                    if (($tokens[$i - 1]->text ?? '') === '=' && ($tokens[$i + 1]->text ?? '') === ';') {
                        $classes[$class]['consts'][$constName] = true;
                    } else {
                        $classes[$class]['lists'][$constName] = "$path:{$t->line}";
                    }
                } else {
                    $problems[] = "$path:{$t->line} names 'offset' outside a method or a class constant, where this scan cannot tell who reads it";
                }
                continue;
            }
            // Inside a method body from here on.
            $m = &$classes[$class]['methods'][$method];
            if ($t->id === T_VARIABLE && $text === '$this' && in_array($tokens[$i + 1]->id ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $next = $tokens[$i + 2] ?? null;
                if ($next !== null && $next->id === T_STRING && ($tokens[$i + 3]->text ?? '') === '(') {
                    $m['calls'][] = ['this', $next->text];
                } elseif ($next !== null && $next->id !== T_STRING) {
                    $problems[] = "$path:{$t->line} calls a method on \$this by a computed name, which this scan cannot follow";
                }
            } elseif ($t->id === T_STRING && in_array(strtolower($text), ['self', 'static', 'parent'], true) && ($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON) {
                $next = $tokens[$i + 2] ?? null;
                if ($next !== null && $next->id === T_STRING && ($tokens[$i + 3]->text ?? '') === '(') {
                    $m['calls'][] = [strtolower($text) === 'parent' ? 'parent' : (strtolower($text) === 'self' ? 'self' : 'this'), $next->text];
                } elseif ($next !== null && $next->id === T_STRING) {
                    $m['consts'][] = $next->text;
                } elseif ($next !== null && $next->id === T_VARIABLE && ($tokens[$i + 3]->text ?? '') === '(') {
                    $problems[] = "$path:{$t->line} calls a method on " . $text . ':: by a computed name, which this scan cannot follow';
                }
            } elseif (in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON
                && ($tokens[$i - 1]->id ?? null) !== T_OBJECT_OPERATOR
                && self::fullName($text, $namespace, $uses) === self::PAGE_ROWS && ($tokens[$i + 3]->text ?? '') === '(') {
                $callee = $tokens[$i + 2]->text;
                if ($callee === 'fetchLimit') {
                    $m['pageFetch'] = true;
                } elseif ($callee === 'cut') {
                    $m['pageCut'] = true;
                }
            } elseif ($t->id === T_CONSTANT_ENCAPSED_STRING && substr($text, 1, -1) === 'truncated' && ($tokens[$i + 1]->id ?? null) === T_DOUBLE_ARROW) {
                $m['truncatedKey'] = true;
            }
            unset($m);
        }
    }

    /** A class name as PHP resolves it in $namespace with $uses. */
    private static function fullName(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name)[0];
        if (isset($uses[$first])) {
            return $uses[$first] . substr($name, strlen($first));
        }

        return ltrim($namespace . '\\' . $name, '\\');
    }
}

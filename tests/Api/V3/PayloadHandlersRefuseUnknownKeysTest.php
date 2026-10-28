<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;

/**
 * Every API handler that takes a request body refuses the keys it does not
 * read (CLAUDE.md #4).
 *
 * The CRUD base used to `continue` past any key that was not a writable
 * field, and a dozen hand-written handlers read the keys they knew and never
 * looked at the rest, so a typo'd field, a field the handler does not write,
 * or a body meant for another endpoint answered 200/201 having done less
 * than asked. Each now hands its body, before anything else reads it, to
 * one of the two checks that refuse by name:
 *
 *  - Api\V3\Support\PayloadKeys::refuseUnknown($payload, [...accepted]) for
 *    a handler that reads its body itself;
 *  - $this->validatePayload($payload, ...) for the CRUD base, which reads
 *    fields() (unknown keys, read-only keys that differ, nulls and numbers
 *    are all checked there).
 *
 * A handler here is a public method of a class under api/v3/Controllers (or
 * the base Api\V3\Controller) with a parameter named $payload; and every
 * method api/v3/index.php hands `$payload` to must be one of them, so a
 * handler whose body parameter is named something else is not missed.
 *
 * What "before anything else reads it" means, read from the tokens: the
 * first `$payload` in the method body is the first argument of the check;
 * the check is a statement of its own directly in the method body (brace
 * depth 1, not inside an if, a loop or a closure) — alone, or assigned to a
 * variable. A subclass of the CRUD base that overrides create() or update()
 * reads the keys it handles itself (a store link, a campaign's links) and
 * must hand the rest to parent::create()/parent::update(), whose
 * validatePayload() refuses anything it does not write — so for those the
 * check is that the parent call is made. That is weaker than the rule for
 * the others, and it is the one place the base's check is the guard.
 *
 * A handler that cannot start with a check is listed in EXEMPT with why, and
 * with text its body must still contain, so an exemption that stops being
 * true fails here rather than going on excusing a handler.
 */
final class PayloadHandlersRefuseUnknownKeysTest extends TestCase
{
    /**
     * class::method => [why it is not held to the first-statement rule,
     * text the method body must contain].
     */
    private const EXEMPT = [
        'AppPostbacksController::verify' => [
            'The body is the postback Apple (or an ad network) sent, verified as it arrived: its fields are '
            . 'Apple\'s to add to, and the signature covers the whole body. verify() reads the fields it '
            . 'checks and reports the rest as the postback\'s, never as a write.',
            "array_key_exists('jws-string', \$payload)",
        ],
        'StagedChangesController::stage' => [
            'Records the body of another route for review. The route it is applied to refuses unknown keys '
            . 'when the change is applied (index.php rebuilds the routes around the recorded body), so '
            . 'repeating every route\'s accepted keys here would be a second list to drift.',
            "'payload' => \$payload,",
        ],
        'Controller::bulkUpsert' => [
            'The body is a list of rows, or {"rows": [...]}. A list has no keys to check; an object is held '
            . 'to `rows`. Each row then goes through update() or create(), whose validatePayload() refuses '
            . 'its unknown keys.',
            'PayloadKeys::refuseUnknown($payload, [\'rows\']',
        ],
        'UsersController::roleIdFrom' => [
            'Not a handler: index.php reads the role id with it for the permission check before calling '
            . 'assignRole(), which refuses every key but role_id.',
            '$payload[\'role_id\']',
        ],
    ];

    /** The base's own handlers and the subclass overrides held to "calls the parent". */
    private const PARENT_ROUTED = ['create', 'update'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{file: string, class: string, method: string, crudSubclass: bool, body: list<array{0:int|string,1:string,2:int}|string>, line: int, imports: array<string, string>}>
     */
    private static function handlers(): array
    {
        $files = glob(self::root() . '/api/v3/Controllers/*.php') ?: [];
        $files[] = self::root() . '/api/v3/Controller.php';
        $handlers = [];
        foreach ($files as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $imports = self::imports($tokens);
            $class = null;
            $crudSubclass = false;
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!is_array($tokens[$i])) {
                    continue;
                }
                $before = self::prev($tokens, $i);
                if ($tokens[$i][0] === T_CLASS && $class === null && !($before !== null && is_array($tokens[$before]) && $tokens[$before][0] === T_DOUBLE_COLON)) {
                    $class = self::nextName($tokens, $i);
                    $j = self::next($tokens, $i);
                    $j = self::next($tokens, $j);
                    if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS) {
                        $parent = self::nextName($tokens, $j);
                        $crudSubclass = ($imports[$parent] ?? $parent) === 'Api\\V3\\Controller' || $parent === '\\Api\\V3\\Controller';
                    }
                    continue;
                }
                if ($tokens[$i][0] !== T_FUNCTION || $class === null) {
                    continue;
                }
                // The modifiers before `function`: a method with none is public.
                $public = true;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_PRIVATE, T_PROTECTED], true)) {
                        $public = false;
                    }
                    break;
                }
                $name = self::nextName($tokens, $i);
                $open = self::find($tokens, $i, '(');
                $close = self::matching($tokens, $open);
                $takesPayload = false;
                for ($k = $open; $k < $close; $k++) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE && $tokens[$k][1] === '$payload') {
                        $takesPayload = true;
                    }
                }
                $bodyOpen = $close + 1;
                while ($bodyOpen < $count && $tokens[$bodyOpen] !== '{' && $tokens[$bodyOpen] !== ';') {
                    $bodyOpen++;
                }
                if ($bodyOpen >= $count || $tokens[$bodyOpen] === ';') {
                    continue; // abstract
                }
                $bodyClose = self::matching($tokens, $bodyOpen);
                if ($public && $takesPayload) {
                    $handlers[$class . '::' . $name] = [
                        'file' => substr($file, strlen(self::root()) + 1),
                        'class' => $class,
                        'method' => $name,
                        'crudSubclass' => $crudSubclass,
                        'body' => array_slice($tokens, $bodyOpen, $bodyClose - $bodyOpen + 1),
                        'line' => (int) $tokens[$i][2],
                        'imports' => $imports,
                    ];
                }
                $i = $bodyClose;
            }
        }
        ksort($handlers);

        return $handlers;
    }

    public function testEveryHandlerThatTakesABodyRefusesTheKeysItDoesNotRead(): void
    {
        $handlers = self::handlers();
        self::assertGreaterThan(50, count($handlers), 'the scan found the handlers (a reader that finds none proves nothing)');

        $failures = [];
        foreach ($handlers as $id => $handler) {
            if (isset(self::EXEMPT[$id])) {
                continue;
            }
            $problem = $handler['crudSubclass'] && in_array($handler['method'], self::PARENT_ROUTED, true)
                ? self::parentProblem($handler)
                : self::firstUseProblem($handler);
            if ($problem !== null) {
                $failures[] = "$id ({$handler['file']}:{$handler['line']}): $problem";
            }
        }

        self::assertSame([], $failures, "A handler that takes a request body must refuse the keys it does not read: start it with\n"
            . "    PayloadKeys::refuseUnknown(\$payload, [...the keys it reads], 'what the body is');\n"
            . "(use Api\\V3\\Support\\PayloadKeys), before anything else reads \$payload. See this test's docblock.\n"
            . implode("\n", $failures));
    }

    public function testEveryMethodIndexPhpHandsTheBodyToIsAHandlerThisTestReads(): void
    {
        $handlerNames = [];
        foreach (self::handlers() as $handler) {
            $handlerNames[$handler['method']] = true;
        }
        $tokens = token_get_all((string) file_get_contents(self::root() . '/api/v3/index.php'));
        $called = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $n = self::next($tokens, $i);
            if ($n === null || !is_array($tokens[$n]) || $tokens[$n][0] !== T_STRING) {
                continue;
            }
            $open = self::next($tokens, $n);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $close = self::matching($tokens, $open);
            $depth = 0;
            for ($k = $open; $k <= $close; $k++) {
                if (in_array($tokens[$k], ['(', '[', '{'], true) || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($tokens[$k], [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($depth === 1 && is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE && $tokens[$k][1] === '$payload') {
                    $called[$tokens[$n][1]] = (int) $tokens[$n][2];
                }
            }
        }
        self::assertGreaterThan(40, count($called), 'the scan found index.php\'s handler calls');

        $missing = [];
        foreach ($called as $method => $line) {
            if (!isset($handlerNames[$method])) {
                $missing[] = "$method (api/v3/index.php:$line)";
            }
        }
        self::assertSame([], $missing, 'index.php hands the request body to these methods, but no controller method of that name '
            . 'takes a parameter named $payload, so the handler test above does not read them. Name the body parameter $payload.');
    }

    public function testTheExemptionsStillDescribeTheCode(): void
    {
        $handlers = self::handlers();
        foreach (self::EXEMPT as $id => [$why, $needle]) {
            self::assertArrayHasKey($id, $handlers, "EXEMPT names $id, which is no longer a handler that takes \$payload; remove it");
            self::assertTrue(str_contains(self::text($handlers[$id]['body']), $needle), "$id is exempt because: $why\nIts body no longer contains: $needle");
        }
    }

    /**
     * @param array{body: list<array{0:int|string,1:string,2:int}|string>, imports: array<string, string>, method: string} $handler
     */
    private static function firstUseProblem(array $handler): ?string
    {
        $body = $handler['body'];
        $first = null;
        foreach ($body as $k => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$payload') {
                $first = $k;
                break;
            }
        }
        if ($first === null) {
            return 'never reads $payload, so nothing checks its keys';
        }

        // The tokens before it: `(` and the callee.
        $open = self::prev($body, $first);
        if ($open === null || $body[$open] !== '(') {
            return 'the first use of $payload (line ' . $body[$first][2] . ') is not the first argument of a key check';
        }
        $after = self::next($body, $first);
        if ($after === null || !in_array($body[$after], [',', ')'], true)) {
            return 'the first use of $payload (line ' . $body[$first][2] . ') is an expression on it, not $payload itself';
        }
        $name = self::prev($body, $open);
        $op = $name === null ? null : self::prev($body, $name);
        $target = $op === null ? null : self::prev($body, $op);
        if ($name === null || $op === null || $target === null || !is_array($body[$name]) || !is_array($body[$op]) || !is_array($body[$target])) {
            return 'the first use of $payload (line ' . $body[$first][2] . ') is not passed to a key check';
        }
        $calleeName = $body[$name][1];
        $isCheck = false;
        if ($body[$op][0] === T_DOUBLE_COLON && $calleeName === 'refuseUnknown') {
            $class = $body[$target][1];
            $resolved = $body[$target][0] === T_NAME_FULLY_QUALIFIED ? ltrim($class, '\\') : ($handler['imports'][$class] ?? null);
            $isCheck = $resolved === 'Api\\V3\\Support\\PayloadKeys';
        } elseif ($body[$op][0] === T_OBJECT_OPERATOR && $calleeName === 'validatePayload') {
            $isCheck = $body[$target][0] === T_VARIABLE && $body[$target][1] === '$this';
        }
        if (!$isCheck) {
            return 'the first use of $payload (line ' . $body[$first][2] . ') is passed to ' . self::text(array_slice($body, $target, $first - $target)) . '…, not to PayloadKeys::refuseUnknown() or $this->validatePayload()';
        }

        // A statement of its own, directly in the method body.
        $before = self::prev($body, $target);
        if ($before !== null && $body[$before] === '=') {
            $var = self::prev($body, $before);
            $before = $var === null ? null : self::prev($body, $var);
        }
        if ($before === null || !in_array($body[$before], [';', '{', '}'], true)) {
            return 'the key check (line ' . $body[$first][2] . ') is part of a larger expression, so it may not run';
        }
        $depth = 0;
        for ($k = 0; $k < $target; $k++) {
            if ($body[$k] === '{' || (is_array($body[$k]) && in_array($body[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($body[$k] === '}') {
                $depth--;
            }
        }
        if ($depth !== 1) {
            return 'the key check (line ' . $body[$first][2] . ') is inside a block (an if, a loop or a closure), so it does not run on every call';
        }

        return null;
    }

    /**
     * @param array{body: list<array{0:int|string,1:string,2:int}|string>, method: string} $handler
     */
    private static function parentProblem(array $handler): ?string
    {
        $body = $handler['body'];
        foreach ($body as $k => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'parent') {
                continue;
            }
            $op = self::next($body, $k);
            $name = $op === null ? null : self::next($body, $op);
            $open = $name === null ? null : self::next($body, $name);
            if ($op !== null && $name !== null && $open !== null && is_array($body[$op]) && $body[$op][0] === T_DOUBLE_COLON
                && is_array($body[$name]) && $body[$name][1] === $handler['method'] && $body[$open] === '(') {
                return null;
            }
        }

        return 'overrides the CRUD base\'s ' . $handler['method'] . '() without calling parent::' . $handler['method']
            . '(), whose validatePayload() is what refuses the keys this override does not read';
    }

    /**
     * use statements: short name => fully qualified.
     *
     * @param list<array{0:int|string,1:string,2:int}|string> $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }
            $n = self::next($tokens, $i);
            if ($n === null || !is_array($tokens[$n]) || !in_array($tokens[$n][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                continue;
            }
            $full = ltrim($tokens[$n][1], '\\');
            $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
            $as = self::next($tokens, $n);
            if ($as !== null && is_array($tokens[$as]) && $tokens[$as][0] === T_AS) {
                $aliasAt = self::next($tokens, $as);
                if ($aliasAt !== null && is_array($tokens[$aliasAt])) {
                    $alias = $tokens[$aliasAt][1];
                }
            }
            $imports[$alias] = $full;
        }

        return $imports;
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        for ($k = $i + 1, $n = count($tokens); $k < $n; $k++) {
            if (!(is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return $k;
            }
        }

        return null;
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function prev(array $tokens, int $i): ?int
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (!(is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return $k;
            }
        }

        return null;
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function nextName(array $tokens, int $i): string
    {
        $n = self::next($tokens, $i);
        if ($n !== null && $tokens[$n] === '&') {
            $n = self::next($tokens, $n);
        }

        return $n !== null && is_array($tokens[$n]) ? $tokens[$n][1] : '';
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function find(array $tokens, int $from, string $char): int
    {
        for ($k = $from, $n = count($tokens); $k < $n; $k++) {
            if ($tokens[$k] === $char) {
                return $k;
            }
        }
        throw new \RuntimeException("no $char after token $from");
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $pairs = ['(' => ')', '{' => '}', '[' => ']'];
        $depth = 0;
        for ($k = $open, $n = count($tokens); $k < $n; $k++) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (is_string($t) && isset($pairs[$t])) {
                $depth++;
            } elseif (is_string($t) && in_array($t, $pairs, true)) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        throw new \RuntimeException('unbalanced brackets at token ' . $open);
    }

    /** @param list<array{0:int|string,1:string,2:int}|string> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));
    }
}

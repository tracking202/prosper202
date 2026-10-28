<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * Every write of a redirector's rules goes through the one check of a rule,
 * RotatorsController::planRule(), or is listed with why it needs none
 * (CLAUDE.md #5).
 *
 * Setup › Redirectors and PUT /rotators/{id}/rules refused a rule with no
 * criterion; POST and PUT of one rule checked only the entries they were
 * sent, and answered 200 for one. The redirects read a rule with no
 * criterion as every visitor, so one API call sent all of a redirector's
 * traffic to that rule's destination. RotatorRuleSetIntegrationTest holds
 * the three API writes to the same refusals in the same words; this holds a
 * fourth write to the same check.
 *
 * Two questions, from the tokens:
 *
 *  - Which files write a rule's rows: a string that PHP assembles (literals
 *    and interpolated strings, joined across `.`) beginning with INSERT,
 *    REPLACE, UPDATE or DELETE and naming 202_rotator_rules,
 *    202_rotator_rules_criteria or 202_rotator_rules_redirects. Each is
 *    listed with what makes its writes safe (WRITERS); a listed file with no
 *    write left fails too.
 *  - In RotatorsController, which public methods reach such a write (its own
 *    statements, or a method of the class it calls, through any number of
 *    calls). Each is listed (ENTRY_POINTS): one that writes a rule has the
 *    write inside its transaction() closure, with a call that reaches
 *    planRule() as a statement directly in that closure (not in an if, a
 *    loop or a try) above the first write; one that only removes a rule
 *    whole says so.
 *
 * What this does not see: a table name assembled at runtime (none of the
 * tree's writes with an unread table reaches these tables:
 * RollupWritersAreMarkedTest lists them), a write in another class that the
 * controller calls, and whether the listed files' own checks are right
 * (the page's are held by tests/live/setup-pages.sh).
 */
final class RotatorRuleWritesAreCheckedTest extends TestCase
{
    private const CONTROLLER = 'api/v3/Controllers/RotatorsController.php';

    private const TABLE = '/(?<![\w])202_rotator_rules(?:_criteria|_redirects)?(?![\w])/';

    private const WRITE_HEAD = '/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/i';

    /** @var array<string, string> file => why its writes of a rule's rows are safe */
    private const WRITERS = [
        self::CONTROLLER => 'the API: each public method that reaches a write is held to ENTRY_POINTS',
        'tracking202/ajax/rotator.php' => 'Setup › Redirectors\' save, which refuses a rule with no name, criterion '
            . 'or destination with checks of its own (tests/live/setup-pages.sh)',
        'tracking202/setup/rotator.php' => 'removes a redirector with every rule, criterion and destination it has',
        '202-config/functions-upgrade.php' => 'the upgrade ladder, moving stored destinations into their own table',
    ];

    /** The check of a rule. */
    private const CHECK = 'planRule';

    /** @var array<string, string> public method => 'checked', or why it needs no check */
    private const ENTRY_POINTS = [
        'createRule' => 'checked',
        'updateRule' => 'checked',
        'setRules' => 'checked',
        'delete' => 'removes the redirector with its rules, criteria and destinations',
        'deleteRule' => 'removes one rule with its criteria and destinations',
    ];

    public function testEveryFileThatWritesARuleIsListed(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (self::writes($source) !== []) {
                $found[] = $file;
            }
        }
        $listed = array_keys(self::WRITERS);
        sort($listed);
        self::assertSame(
            $listed,
            $found,
            "Files that write a redirector rule's rows. A new one goes through RotatorsController (planRule()), "
                . 'or is listed in WRITERS with what makes its writes safe; a listed file with none left is removed.'
        );
    }

    public function testEveryControllerMethodThatWritesARuleChecksItFirst(): void
    {
        $source = SourceScan::phpFiles()[self::CONTROLLER] ?? null;
        self::assertIsString($source, self::CONTROLLER . ' was not read');
        self::assertSame([], self::controllerProblems($source));
    }

    /** A controller to plant into: %CREATE% is createRule()'s closure body, %EXTRA% more methods. */
    private const PLANTED = <<<'PHP'
        <?php
        class C
        {
            public function createRule(int $id, array $payload): array
            {
                return $this->transaction(function () use ($payload): array {
                    %CREATE%
                    return [];
                });
            }
            public function updateRule(): void
            {
                $this->transaction(function (): void {
                    $this->planRule(1, [], null, 1);
                    $this->insert();
                });
            }
            public function setRules(): void
            {
                $this->transaction(function (): void {
                    $this->plan();
                    $this->insert();
                });
            }
            public function delete(): void
            {
                $this->db->query('DELETE FROM 202_rotator_rules WHERE rotator_id = 1');
            }
            public function deleteRule(): void
            {
                $this->db->query('DELETE FROM 202_rotator_rules WHERE id = 1');
            }
            private function plan(): void
            {
                $this->planRule(1, [], null, 1);
            }
            private function insert(): void
            {
                $this->db->query('INSERT INTO ' . '202_rotator_rules SET x = 1');
            }
            private function planRule(): array
            {
                return [];
            }
            %EXTRA%
        }
        PHP;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function plantedControllers(): iterable
    {
        $check = '$rule = $this->planRule(1, $payload, null, 1);';
        $write = '$this->db->query("INSERT INTO 202_rotator_rules_criteria SET rule_id = 1");';
        $body = static fn (string $create, string $extra = ''): string
            => str_replace(['%CREATE%', '%EXTRA%'], [$create, $extra], self::PLANTED);
        $before = 'createRule writes before';

        yield 'the check above the write' => [$body("$check\n$write"), ''];
        yield 'no check' => [$body($write), $before];
        yield 'the check below the write' => [$body("$write\n$check"), $before];
        yield 'the check in an if' => [$body("if (\$payload !== []) {\n$check\n}\n$write"), $before];
        yield 'the check in a try' => [$body("try {\n$check\n} catch (\\Throwable) {}\n$write"), $before];
        $opening = 'return $this->transaction(function () use ($payload): array {';
        yield 'the write outside the transaction' => [
            str_replace($opening, $write . "\n" . $opening, $body($check)),
            'createRule writes outside its transaction() closure',
        ];
        yield 'a new public writer' => [
            $body("$check\n$write", 'public function importRules(): void { $this->insert(); }'),
            'importRules writes a rule and is not listed',
        ];
        $deleteRule = "\$this->db->query('DELETE FROM 202_rotator_rules WHERE id = 1');";
        yield 'a listed method that no longer writes' => [
            str_replace($deleteRule, '', $body("$check\n$write")),
            'deleteRule is listed and writes no rule',
        ];
    }

    /** @dataProvider plantedControllers */
    public function testTheControllerScanCatchesEachPlant(string $source, string $problem): void
    {
        $problems = self::controllerProblems($source);
        if ($problem === '') {
            self::assertSame([], $problems);
            return;
        }
        $named = array_filter($problems, static fn (string $p): bool => str_contains($p, $problem));
        self::assertNotSame([], $named, implode("\n", $problems));
    }

    /** @return iterable<string, array{0: string}> */
    public static function plantedFileWrites(): iterable
    {
        yield 'a literal' => ["<?php \$db->query('INSERT INTO 202_rotator_rules_criteria (rule_id) VALUES (1)');"];
        yield 'an interpolated string' => [
            "<?php \$db->query(\"UPDATE 202_rotator_rules SET status = 1 WHERE id = \$id\");",
        ];
        yield 'a string joined across a dot' => [
            "<?php \$db->query('DELETE FROM ' . '202_rotator_rules_criteria WHERE rule_id = ' . \$id);",
        ];
        yield 'a backquoted table' => ["<?php \$sql = 'REPLACE INTO `202_rotator_rules_redirects` SET id = 1';"];
    }

    /** @dataProvider plantedFileWrites */
    public function testTheFileScanReadsEachSpelling(string $source): void
    {
        self::assertNotSame([], self::writes($source));
    }

    public function testTheFileScanLeavesReadsAndOtherTablesAlone(): void
    {
        self::assertSame([], self::writes("<?php \$db->query('SELECT * FROM 202_rotator_rules WHERE id = 1');"));
        self::assertSame([], self::writes("<?php \$db->query('UPDATE 202_rotators SET name = 1');"));
        self::assertSame([], self::writes("<?php // INSERT INTO 202_rotator_rules\n"));
    }

    /**
     * The write statements a source holds, by the token index each string
     * starts at.
     *
     * @return array<int, string>
     */
    private static function writes(string $source): array
    {
        $tokens = self::tokens($source);
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $string = self::stringAt($tokens, $i);
            if ($string === null) {
                continue;
            }
            [$text, $next] = $string;
            $start = $i;
            while (($tokens[$next] ?? null) === '.') {
                $more = self::stringAt($tokens, $next + 1);
                if ($more === null) {
                    // A variable or call: the rest is not read, and a table
                    // it would name is not seen.
                    break;
                }
                $text .= $more[0];
                $next = $more[1];
            }
            if (preg_match(self::WRITE_HEAD, $text) === 1 && preg_match(self::TABLE, $text) === 1) {
                $out[$start] = $text;
            }
            $i = $next - 1;
        }

        return $out;
    }

    /**
     * The text of the string at $i and the index after it: a literal, or an
     * interpolated string whose interpolations read as a space.
     *
     * @param list<mixed> $tokens
     * @return array{0: string, 1: int}|null
     */
    private static function stringAt(array $tokens, int $i): ?array
    {
        $t = $tokens[$i] ?? null;
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            return [substr($t[1], 1, -1), $i + 1];
        }
        if ($t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC)) {
            $end = $t === '"' ? '"' : T_END_HEREDOC;
            $text = '';
            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                $u = $tokens[$j];
                if ($u === $end || (is_array($u) && $u[0] === $end)) {
                    break;
                }
                $text .= is_array($u) && $u[0] === T_ENCAPSED_AND_WHITESPACE ? $u[1] : ' ';
            }

            return [$text, $j + 1];
        }

        return null;
    }

    /**
     * What is wrong with the controller's writes of a rule, one line each.
     *
     * @return list<string>
     */
    private static function controllerProblems(string $source): array
    {
        $tokens = self::tokens($source);
        $methods = self::methods($tokens);
        $writes = self::writes($source);

        // Each method's own writes and the class's methods it calls.
        $ownWrites = [];
        $calls = [];
        foreach ($methods as $name => $m) {
            $inside = static fn (int $at): bool => $at > $m['open'] && $at < $m['close'];
            $ownWrites[$name] = array_values(array_filter(array_keys($writes), $inside));
            $calls[$name] = array_keys(self::callsIn($tokens, $m['open'], $m['close'], $methods));
        }
        $reaches = static function (string $from, callable $is) use ($calls): bool {
            $seen = [];
            $queue = [$from];
            while ($queue !== []) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                if ($is($name)) {
                    return true;
                }
                array_push($queue, ...($calls[$name] ?? []));
            }

            return false;
        };
        $hasOwnWrite = static fn (string $n): bool => ($ownWrites[$n] ?? []) !== [];
        $writer = static fn (string $name): bool => $reaches($name, $hasOwnWrite);
        $checker = static fn (string $name): bool => $reaches($name, static fn (string $n): bool => $n === self::CHECK);

        $problems = [];
        foreach ($methods as $name => $m) {
            if (!$m['public']) {
                continue;
            }
            $listed = self::ENTRY_POINTS[$name] ?? null;
            if (!$writer($name)) {
                if ($listed !== null) {
                    $problems[] = "$name is listed and writes no rule: remove it from ENTRY_POINTS";
                }
                continue;
            }
            if ($listed === null) {
                $problems[] = "$name writes a rule and is not listed: "
                    . 'give it planRule() as createRule() has it, and list it';
                continue;
            }
            if ($listed !== 'checked') {
                continue;
            }
            $closure = self::transactionClosure($tokens, $m['open'], $m['close']);
            if ($closure === null) {
                $problems[] = "$name writes a rule outside a transaction() closure";
                continue;
            }
            // Every write it makes, its own or a call's, is in the closure.
            $firstWrite = null;
            foreach ($ownWrites[$name] as $at) {
                if ($at < $closure[0] || $at > $closure[1]) {
                    $problems[] = "$name writes outside its transaction() closure";
                }
                $firstWrite = min($firstWrite ?? $at, $at);
            }
            foreach (self::callsIn($tokens, $m['open'], $m['close'], $methods) as $callee => $positions) {
                if (!$writer($callee)) {
                    continue;
                }
                foreach ($positions as $at) {
                    if ($at < $closure[0] || $at > $closure[1]) {
                        if (!$checker($callee)) {
                            $problems[] = "$name writes outside its transaction() closure (through $callee())";
                        }
                        continue;
                    }
                    $firstWrite = min($firstWrite ?? $at, $at);
                }
            }
            $check = null;
            foreach (self::callsIn($tokens, $closure[0], $closure[1], $methods) as $callee => $positions) {
                if (!$checker($callee) || $writer($callee)) {
                    continue;
                }
                foreach ($positions as $at) {
                    if (self::depthAt($tokens, $closure[0], $at) === 1 && self::startsStatement($tokens, $at)) {
                        $check = min($check ?? $at, $at);
                    }
                }
            }
            if ($check === null || $firstWrite === null || $check > $firstWrite) {
                $problems[] = "$name writes before a statement of its transaction() closure calls planRule()";
            }
        }
        foreach (array_keys(self::ENTRY_POINTS) as $name) {
            if (!isset($methods[$name])) {
                $problems[] = "$name is listed and writes no rule: remove it from ENTRY_POINTS";
            }
        }

        return $problems;
    }

    /**
     * The class's methods: name => [body open brace, its close, public].
     *
     * @param list<mixed> $tokens
     * @return array<string, array{open: int, close: int, public: bool}>
     */
    private static function methods(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $named = is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING;
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION || !$named) {
                continue;
            }
            $public = true;
            $modifiers = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT];
            for ($j = $i - 1; $j >= 0 && is_array($tokens[$j]) && in_array($tokens[$j][0], $modifiers, true); $j--) {
                if (in_array($tokens[$j][0], [T_PRIVATE, T_PROTECTED], true)) {
                    $public = false;
                }
            }
            $open = $i + 2;
            while (isset($tokens[$open]) && $tokens[$open] !== '{' && $tokens[$open] !== ';') {
                $open++;
            }
            if (($tokens[$open] ?? null) !== '{') {
                continue;
            }
            $close = self::matching($tokens, $open);
            $out[$tokens[$i + 1][1]] = ['open' => $open, 'close' => $close, 'public' => $public];
        }

        return $out;
    }

    /**
     * The class's methods called between $from and $to, `$this->m(`,
     * `self::m(` or `static::m(`, each with where its receiver is.
     *
     * @param list<mixed> $tokens
     * @param array<string, mixed> $methods
     * @return array<string, list<int>>
     */
    private static function callsIn(array $tokens, int $from, int $to, array $methods): array
    {
        $out = [];
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(' || !isset($methods[$t[1]])) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $receiver = $tokens[$i - 2] ?? null;
            $member = is_array($before) && $before[0] === T_OBJECT_OPERATOR
                && is_array($receiver) && $receiver[0] === T_VARIABLE && $receiver[1] === '$this';
            $static = is_array($before) && $before[0] === T_DOUBLE_COLON && is_array($receiver)
                && ($receiver[0] === T_STATIC || ($receiver[0] === T_STRING && strtolower($receiver[1]) === 'self'));
            if ($member || $static) {
                $out[$t[1]][] = $i - 2;
            }
        }

        return $out;
    }

    /**
     * The body of the closure handed to `$this->transaction(` in a method:
     * [its open brace, its close].
     *
     * @param list<mixed> $tokens
     * @return array{0: int, 1: int}|null
     */
    private static function transactionClosure(array $tokens, int $from, int $to): ?array
    {
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            $member = is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_OBJECT_OPERATOR;
            $called = is_array($t) && $t[0] === T_STRING && $t[1] === 'transaction'
                && ($tokens[$i + 1] ?? null) === '(';
            if (!$member || !$called) {
                continue;
            }
            for ($j = $i + 2; $j < $to; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_FUNCTION) {
                    $open = $j;
                    while ($tokens[$open] !== '{') {
                        $open++;
                    }

                    return [$open, self::matching($tokens, $open)];
                }
            }
        }

        return null;
    }

    /**
     * How many braces deep $at is inside the block opened at $open: 1 is a
     * statement of the block itself.
     *
     * @param list<mixed> $tokens
     */
    private static function depthAt(array $tokens, int $open, int $at): int
    {
        $depth = 0;
        for ($i = $open; $i < $at; $i++) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
        }

        return $depth;
    }

    /**
     * Whether the call at $at (its receiver) begins its statement, alone or
     * as the value assigned to a variable: `$this->m(…);` or `$x = $this->m(…);`.
     *
     * @param list<mixed> $tokens
     */
    private static function startsStatement(array $tokens, int $at): bool
    {
        $i = $at - 1;
        if (($tokens[$i] ?? null) === '=' && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_VARIABLE) {
            $i -= 2;
        }

        return in_array($tokens[$i] ?? null, [';', '{', '}'], true);
    }

    /** @param list<mixed> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $n - 1;
    }

    /** @return list<mixed> the tokens, less whitespace and comments */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }
}

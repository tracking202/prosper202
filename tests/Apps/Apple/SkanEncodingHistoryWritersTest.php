<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use PHPUnit\Framework\TestCase;

/**
 * The encoding history is only as complete as the list of paths that
 * replace or remove an encoding (plan §5.5, SkanEncodingTimeline): a path
 * that edits 202_app_skan_encodings without first copying the meaning into
 * 202_app_skan_encoding_history makes the report decode postbacks set under
 * the old meaning as the new one, silently — no error anywhere, just a
 * different answer. So the writers are held here:
 *
 *  - every write to 202_app_skan_encodings (UPDATE, DELETE, REPLACE,
 *    upsert) in runtime PHP is preceded, in its own function, by a
 *    SkanEncodingHistory retire call that runs whenever the write does:
 *    directly in the function body, in a statement no condition or
 *    short-circuit can skip, before the write — except the user purge,
 *    which deletes the history in the same transaction;
 *  - the encodings controller (whose writes are the base Controller's
 *    generic UPDATE and DELETE, which name no table) retires the same way
 *    in beforeUpdate() and beforeDelete(), and the base Controller calls
 *    each hook before it builds its write.
 *
 * "Contains a retire call" was the first version of this test, and it held
 * with the retire moved below the DELETE or wrapped in an `if`: a question
 * about order answered by a lookup that had thrown the order away
 * (CLAUDE.md #22). The call is anchored on, not the file.
 */
final class SkanEncodingHistoryWritersTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Files allowed to remove encodings without keeping them, and why. */
    private const EXEMPT = [
        'api/v3/Apps/AppDataPurge.php' => 'the user purge deletes the history too',
    ];

    private const WRITE = '/\b(?:UPDATE|DELETE\s+FROM|REPLACE\s+INTO|INSERT\s+INTO)\s+`?202_app_skan_encodings`?\b(?![_a-z])/i';

    /** @return list<string> */
    private static function runtimeFiles(): array
    {
        $out = [];
        foreach (['api', '202-config', '202-account', '202-cronjobs', 'tracking202', 'cli', 'bin'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $out[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
                }
            }
        }
        sort($out);

        return $out;
    }

    public function testEveryPathThatReplacesAnEncodingKeepsItsMeaning(): void
    {
        $writers = [];
        foreach (self::runtimeFiles() as $file) {
            $source = (string) file_get_contents(self::ROOT . '/' . $file);
            if (preg_match_all(self::WRITE, $source, $m) === 0) {
                continue;
            }
            $statements = array_map('strtoupper', array_map(static fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s), $m[0]));
            $onlyInserts = array_filter($statements, static fn (string $s): bool => !str_starts_with($s, 'INSERT')) === [];
            if ($onlyInserts && !preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $source)) {
                continue; // a plain insert replaces nothing
            }
            $writers[] = $file;
            if (isset(self::EXEMPT[$file])) {
                self::assertMatchesRegularExpression('/DELETE\s+FROM\s+202_app_skan_encoding_history/i', $source, $file . ' is exempt because ' . self::EXEMPT[$file]);
                continue;
            }
            foreach (self::writeSites($source) as [$line, $function, $problem]) {
                self::assertNull(
                    $problem,
                    $file . ':' . $line . ' (' . $function . '()) replaces or removes SKAN encodings; ' . ($problem ?? '')
                        . ' Copy their meaning to the history first (SkanEncodingHistory), unconditionally, above the write.'
                );
            }
        }
        // The census, so a writer that stops matching the pattern is noticed.
        self::assertSame(['api/v3/Apps/AppDataPurge.php', 'api/v3/Controllers/AppRegistrationsController.php'], $writers);
    }

    public function testTheEncodingsControllerRetiresBeforeItsGenericUpdateAndDelete(): void
    {
        $source = (string) file_get_contents(self::ROOT . '/api/v3/Controllers/AppSkanEncodingsController.php');
        foreach (['beforeUpdate', 'beforeDelete'] as $hook) {
            self::assertSame(1, preg_match('/function ' . $hook . '\(.*?\n    \}\n/s', $source, $m), $hook . ' is missing');
            self::assertMatchesRegularExpression('/->retireEncoding\(\$this->userId, \(int\)\$id,/', $m[0], $hook . ' must keep the meaning it replaces');
            $retires = array_values(array_filter(
                self::retireCalls(self::tokens($source)),
                static fn (array $r): bool => $r['function'] === $hook
            ));
            self::assertNotSame([], $retires, $hook);
            self::assertTrue(
                $retires[0]['unconditional'],
                $hook . '\'s retire must run whenever the hook does: directly in its body, in a statement nothing can skip'
            );
        }
        self::assertStringContainsString("'effective_at' => ['type' => 'i', 'value' => \$now]", $source, 'every write restarts effective_at');

        // The hooks run before the base Controller's writes, which is what
        // makes a retire in the hook a retire before the write.
        $base = (string) file_get_contents(self::ROOT . '/api/v3/Controller.php');
        // delete() is recordDeleted(deleteRecord($id)) since #167: the hook
        // and the write live in deleteRecord(), and delete() must reach it.
        self::assertSame(1, preg_match('/    public function delete\(int\|string \$id.*?\n    \}\n/s', $base, $d), 'delete');
        self::assertStringContainsString('$this->deleteRecord($id)', $d[0], 'Controller::delete() runs deleteRecord()');
        foreach ([
            'update' => ['$this->beforeUpdate(', "'UPDATE %s SET %s WHERE %s'", 'public function update'],
            'deleteRecord' => ['$this->beforeDelete(', "'DELETE FROM %s WHERE %s'", 'protected function deleteRecord'],
        ] as $method => [$hook, $write, $signature]) {
            self::assertSame(1, preg_match('/    ' . preg_quote($signature, '/') . '\(int\|string \$id.*?\n    \}\n/s', $base, $m), $method);
            $h = strpos($m[0], $hook);
            $w = strpos($m[0], $write);
            self::assertIsInt($h, $method . ' calls ' . $hook);
            self::assertIsInt($w, $method . ' writes ' . $write);
            self::assertLessThan($w, $h, 'Controller::' . $method . '() calls its hook before it writes');
        }
    }

    /** A source whose SKAN encoding writes are checked for their retire, to plant against. */
    public function testTheOrderCheckRefusesEachShapeThatSkipsOrFollowsTheRetire(): void
    {
        $ok = <<<'PHP'
<?php
function ok($db) {
    (new SkanEncodingHistory($db))->retireRegistration(1, 2, 3);
    $stmt = $db->prepare('DELETE FROM 202_app_skan_encodings WHERE registration_id = ?');
}
PHP;
        self::assertSame([null], array_column(self::writeSites($ok), 2));
        foreach ([
            'after the write' => "function f(\$db) {\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3);\n}",
            'inside an if' => "function f(\$db, \$c) {\n if (\$c) { (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3); }\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'braceless if' => "function f(\$db, \$c) {\n if (\$c) (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3);\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'short-circuit' => "function f(\$db, \$c) {\n \$c && (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3);\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'ternary' => "function f(\$db, \$c) {\n \$c ? (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3) : null;\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'another function' => "function g(\$db) { (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3); }\nfunction f(\$db) {\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'in a loop' => "function f(\$db, \$xs) {\n foreach (\$xs as \$x) { (new SkanEncodingHistory(\$db))->retireRegistration(1, 2, 3); }\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
            'a lookalike callee' => "function f(\$db) {\n retireRegistration(1, 2, 3);\n \$s = \$db->prepare('DELETE FROM 202_app_skan_encodings WHERE x = 1');\n}",
        ] as $shape => $body) {
            $problems = array_column(self::writeSites("<?php\n" . $body), 2);
            self::assertNotSame([], $problems, $shape);
            self::assertNotNull($problems[count($problems) - 1], $shape . ' must be refused');
        }
    }

    /** @return list<array{0: int|string, 1: string}|string> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * Each function's body as [name, open index, close index] over tokens().
     *
     * @param list<array{0: int|string, 1: string}|string> $tokens
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function functions(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING ? $tokens[$i + 1][1] : '{closure}';
            // The body's brace: the first `{` after the parameter list (or a
            // `;` for an abstract or interface method, which has none).
            $j = $i + 1;
            $paren = 0;
            for (; $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t === '(') {
                    $paren++;
                } elseif ($t === ')') {
                    $paren--;
                } elseif ($paren === 0 && ($t === '{' || $t === ';')) {
                    break;
                }
            }
            if (($tokens[$j] ?? null) !== '{') {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $t = $tokens[$k];
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}' && --$depth === 0) {
                    break;
                }
            }
            $out[] = [$name, $j, $k];
        }

        return $out;
    }

    /**
     * Every `->retireEncoding(` / `->retireRegistration(` method call, with
     * its function and whether it runs whenever that function's body runs:
     * at the body's own brace depth, and in a statement with no control
     * keyword, `?`, `&&`, `||`, `and`, `or` or `??` before the call.
     *
     * @param list<array{0: int|string, 1: string}|string> $tokens
     * @return list<array{index: int, function: string, unconditional: bool}>
     */
    private static function retireCalls(array $tokens): array
    {
        $out = [];
        foreach (self::functions($tokens) as [$name, $open, $close]) {
            $depth = 0;
            $statementStart = $open + 1;
            for ($i = $open; $i <= $close; $i++) {
                $t = $tokens[$i];
                if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    $statementStart = $i + 1;
                    continue;
                }
                if ($t === '}') {
                    $depth--;
                    $statementStart = $i + 1;
                    continue;
                }
                if ($t === ';') {
                    $statementStart = $i + 1;
                    continue;
                }
                if (is_array($t) && $t[0] === T_STRING && in_array($t[1], ['retireEncoding', 'retireRegistration'], true)
                    && is_array($tokens[$i - 1]) && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    && ($tokens[$i + 1] ?? null) === '(') {
                    $guarded = false;
                    for ($k = $statementStart; $k < $i; $k++) {
                        $u = $tokens[$k];
                        if ($u === '?' || (is_array($u) && in_array($u[0], [
                            T_IF, T_ELSEIF, T_ELSE, T_WHILE, T_FOR, T_FOREACH, T_DO, T_SWITCH, T_MATCH, T_CASE,
                            T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE, T_FN, T_FUNCTION,
                        ], true))) {
                            $guarded = true;
                        }
                    }
                    // A braceless head (`if ($c) $h->retire…;`, `else …`)
                    // is in the same statement, so its keyword is caught above.
                    $out[] = ['index' => $i, 'function' => $name, 'unconditional' => $depth === 1 && !$guarded];
                }
            }
        }

        return $out;
    }

    /**
     * Every SKAN encoding write literal in a source, with its line, its
     * function, and what is wrong with its retire (null when a retire runs
     * unconditionally before it in the same function).
     *
     * @return list<array{0: int, 1: string, 2: string|null}>
     */
    private static function writeSites(string $source): array
    {
        $tokens = self::tokens($source);
        $functions = self::functions($tokens);
        $retires = self::retireCalls($tokens);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) || preg_match(self::WRITE, $t[1]) !== 1) {
                continue;
            }
            if (preg_match('/^\W*INSERT/i', trim($t[1], "'\" ")) === 1 && preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $t[1]) !== 1) {
                continue; // a plain insert replaces nothing
            }
            $owner = null;
            foreach ($functions as [$name, $open, $close]) {
                if ($i > $open && $i < $close && ($owner === null || $open > $owner[1])) {
                    $owner = [$name, $open, $close];
                }
            }
            if ($owner === null) {
                $out[] = [$t[2], '(file scope)', 'The write is outside any function, where no retire can be shown to precede it.'];
                continue;
            }
            $before = array_filter($retires, static fn (array $r): bool => $r['function'] === $owner[0] && $r['index'] > $owner[1] && $r['index'] < $i);
            if ($before === []) {
                $out[] = [$t[2], $owner[0], 'No retire call precedes the write in its function.'];
            } elseif (array_filter($before, static fn (array $r): bool => $r['unconditional']) === []) {
                $out[] = [$t[2], $owner[0], 'The retire above it can be skipped (it sits in a branch, a loop or behind a condition).'];
            } else {
                $out[] = [$t[2], $owner[0], null];
            }
        }

        return $out;
    }
}

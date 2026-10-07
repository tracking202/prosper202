<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * An Account page must not change state on a GET that carries no token.
 *
 * AccountPostRequiresTokenTest covers every handler that reads $_POST. This is
 * the half it cannot see: a handler keyed on $_GET. account.php's
 * customer-dashboard hand-back (`?customers_api_key=<base64>`) validated the
 * key against the dashboard and UPDATEd 202_users on a plain GET, so any page
 * the signed-in person visited could plant
 * `<img src=".../202-account/account.php?customers_api_key=...">` and replace
 * their customer key. It was fixed once -- the GET now only fills the form
 * that saves through the token-checked POST -- and the 1.9.76 rewrite of the
 * page put the write back, with every test green, because nothing looked at
 * GET handlers. This one does.
 *
 * What it checks: every `if`/`elseif` whose condition reads $_GET or $_REQUEST
 * (a GET reaches both), in 202-account/ and 202-account/ajax/. If the braced
 * body contains a database write (an UPDATE / INSERT INTO / DELETE FROM /
 * REPLACE INTO in any string, executeUpdate()/executeInsert(), UserDataPurge)
 * or the outbound key validation (validateCustomersApiKey()), the body must
 * also call AUTH::check_csrf_token() or AUTH::csrf_token_matches() (a GET
 * carries its token in the query string), read as calls: the class named
 * exactly AUTH before `::`, not `MyAUTH::`, outside comments. An inline
 * hash_equals() used to count as well; with an empty session token it
 * compared '' with '' and passed, and SessionTokenComparedOnlyByAuthTest
 * now refuses it anywhere in the tree.
 *
 * What it does not check, stated so nobody reads more into a green run:
 *   - It tests for the token call's presence in the body, not that it runs
 *     before the write or decides whether it runs (CLAUDE.md #21, #22). The
 *     handlers it passes today all check first and exit; review a new one.
 *   - It sees writes only through the markers above. A write through a helper
 *     function that hides the SQL is invisible to it.
 *   - A condition that reads $_GET through a variable assigned elsewhere is
 *     invisible. A braceless or alternative-syntax body after a $_GET
 *     condition is not read at all, so it is refused by name instead
 *     (CLAUDE.md #20: a checker that cannot read a construct must not answer
 *     "no such construct").
 */
final class AccountGetDoesNotWriteTest extends TestCase
{
    private const WRITE = '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM|REPLACE\s+INTO)\b|->(?:executeUpdate|executeInsert)\(|validateCustomersApiKey\(|UserDataPurge/i';

    /** @return list<array{file: string, line: int, condition: string, body: ?string}> */
    private static function getGuardedBlocks(string $file, string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $blocks = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_IF, T_ELSEIF], true)) {
                continue;
            }
            $line = $tokens[$i][2];

            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '(') {
                $open++;
            }
            $condition = '';
            $depth = 0;
            for ($k = $open; $k < $count; $k++) {
                $tok = $tokens[$k];
                $condition .= is_array($tok) ? $tok[1] : $tok;
                if ($tok === '(') {
                    $depth++;
                } elseif ($tok === ')' && --$depth === 0) {
                    break;
                }
            }
            if (!str_contains($condition, '$_GET') && !str_contains($condition, '$_REQUEST')) {
                continue;
            }

            $b = $k + 1;
            while ($b < $count && is_array($tokens[$b]) && in_array($tokens[$b][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $b++;
            }
            if (($tokens[$b] ?? null) !== '{') {
                $blocks[] = ['file' => $file, 'line' => $line, 'condition' => $condition, 'body' => null];
                continue;
            }

            $body = '';
            $depth = 0;
            for ($k = $b; $k < $count; $k++) {
                $tok = $tokens[$k];
                $body .= is_array($tok) ? $tok[1] : $tok;
                if ($tok === '{' || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($tok === '}' && --$depth === 0) {
                    break;
                }
            }
            $blocks[] = ['file' => $file, 'line' => $line, 'condition' => $condition, 'body' => $body];
        }

        return $blocks;
    }

    /** @return array<string, string> repo-relative path => source */
    private static function accountFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge(glob($root . '/202-account/*.php') ?: [], glob($root . '/202-account/ajax/*.php') ?: []);
        self::assertNotEmpty($paths, 'found no Account pages to scan');

        $files = [];
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, "could not read $path");
            $files[substr($path, strlen($root) + 1)] = $source;
        }

        return $files;
    }

    /**
     * Whether code calls AUTH::check_csrf_token() or AUTH::csrf_token_matches(),
     * by token: `AUTH` (or `\AUTH`) exactly, then `::`, the name and `(`.
     * A substring would credit `MyAUTH::check_csrf_token(` and a comment that
     * quotes the call (CLAUDE.md #21: a name is not a call site).
     */
    private static function callsATokenCheck(string $code): bool
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize('<?php ' . $code), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        foreach ($tokens as $i => $token) {
            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || ltrim($token->text, '\\') !== 'AUTH') {
                continue;
            }
            if (($tokens[$i + 1]->text ?? '') === '::'
                && in_array($tokens[$i + 2]->text ?? '', ['check_csrf_token', 'csrf_token_matches'], true)
                && ($tokens[$i + 3]->text ?? '') === '(') {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> one line per GET-guarded block that writes without a token check */
    private static function violations(string $file, string $source): array
    {
        $out = [];
        foreach (self::getGuardedBlocks($file, $source) as $block) {
            if ($block['body'] === null) {
                $out[] = sprintf('%s:%d has a braceless or alternative-syntax body after a $_GET condition; this test cannot read it -- use braces', $file, $block['line']);
                continue;
            }
            if (preg_match(self::WRITE, $block['body']) !== 1) {
                continue;
            }
            if (self::callsATokenCheck($block['body'])) {
                continue;
            }
            $out[] = sprintf('%s:%d writes on a GET without checking the session token: if %s', $file, $block['line'], preg_replace('/\s+/', ' ', $block['condition']));
        }

        return $out;
    }

    public function testNoAccountPageWritesOnAGetWithoutAToken(): void
    {
        $violations = [];
        foreach (self::accountFiles() as $file => $source) {
            array_push($violations, ...self::violations($file, $source));
        }

        self::assertSame([], $violations, "A GET is reachable from any page the signed-in person visits (an <img> tag is enough), so it must not change state:\n  "
            . implode("\n  ", $violations)
            . "\nMove the write behind a POST that calls AUTH::check_csrf_token(), and have the GET only fill that form.");
    }

    public function testTheScanStillSeesTheHandlersItClaimsTo(): void
    {
        // Without this a broken scan -- no blocks found -- would make the test
        // above vacuous. account.php's token-checked GET removals and the
        // customer-key hand-back are the known GET handlers.
        $conditions = array_map(
            static fn (array $b): string => $b['condition'],
            self::getGuardedBlocks('account.php', self::accountFiles()['202-account/account.php'])
        );
        $joined = implode("\n", $conditions);
        foreach (["'customers_api_key'", "'remove_user_stats202_app_key'", "'remove_user_api_key'"] as $key) {
            self::assertStringContainsString($key, $joined, "the scan no longer finds the GET handler for $key in account.php");
        }
    }

    public function testItRefusesTheShapeThatShipped(): void
    {
        // The customer-key hand-back as the 1.9.76 rewrite shipped it.
        $shipped = <<<'PHP_SAMPLE'
            <?php
            if (!empty($_GET['customers_api_key'])) {
                $mysql['p202_customer_api_key'] = $db->real_escape_string(base64_decode((string) $_GET['customers_api_key']));
                $validate = validateCustomersApiKey($mysql['p202_customer_api_key']);
                if (!$keyErrors) {
                    $db->query("UPDATE 202_users SET p202_customer_api_key = '" . $mysql['p202_customer_api_key'] . "' WHERE user_id = '1'");
                }
            }
            PHP_SAMPLE;
        self::assertCount(1, self::violations('sample.php', $shipped));

        // Each marker on its own, and the shapes that must pass.
        foreach ([
            '$db->query("DELETE FROM 202_api_keys WHERE api_key = \'x\'");',
            '$conn->executeUpdate($stmt);',
            '(new \Prosper202\User\UserDataPurge($db))->deleteUser(3);',
            'validateCustomersApiKey($k);',
        ] as $write) {
            self::assertCount(1, self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { $write }\n"), $write);
            self::assertCount(1, self::violations('s.php', "<?php\nif (isset(\$_REQUEST['x'])) { $write }\n"), "\$_REQUEST: $write");
            self::assertSame([], self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { if (!AUTH::check_csrf_token()) { exit; } $write }\n"), "guarded: $write");
            self::assertSame([], self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { if (!AUTH::csrf_token_matches(\$_GET['token'] ?? null)) { exit; } $write }\n"), "guarded by the query-string token: $write");
            self::assertSame([], self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { if (!\\AUTH::csrf_token_matches(\$_REQUEST['token'] ?? null)) { exit; } $write }\n"), "guarded through \\AUTH: $write");
            // Not a guard: the inline comparison that passed '' against '',
            // another class whose name ends the same way, and a comment.
            foreach ([
                "if (!hash_equals((string) (\$_SESSION['token'] ?? ''), (string) (\$_GET['token'] ?? ''))) { exit; }",
                'if (!MyAUTH::check_csrf_token()) { exit; }',
                'if (!Other\\AUTH::csrf_token_matches($_GET[\'token\'] ?? null)) { exit; }',
                '// AUTH::check_csrf_token() is called by the page that links here',
                '/* AUTH::csrf_token_matches($_GET[\'token\']) */',
            ] as $notAGuard) {
                self::assertCount(1, self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { $notAGuard\n $write }\n"), "not a guard ($notAGuard): $write");
            }
        }
        self::assertSame([], self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) { \$y = \$db->query('SELECT 1'); }\n"));
        self::assertCount(1, self::violations('s.php', "<?php\nif (isset(\$_GET['x'])) \$db->query('UPDATE t SET a = 1');\n"), 'a braceless body is refused, not skipped');
        self::assertCount(1, self::violations('s.php', "<?php\nif (isset(\$_GET['x'])): \$db->query('UPDATE t SET a = 1'); endif;\n"), 'alternative syntax is refused, not skipped');
    }
}

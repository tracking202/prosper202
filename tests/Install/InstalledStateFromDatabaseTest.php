<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * is_installed() decides whether the installer, the setup wizard and the
 * fetch-a-key page run, and answers from the database alone: an account
 * exists. Its body reads none of the request superglobals.
 */
final class InstalledStateFromDatabaseTest extends TestCase
{
    /** The calls is_installed() makes; any other call could read the request. */
    private const ALLOWED_CALLS = ['getInstance', 'getConnection', 'query', 'getCode', 'fetch_assoc', 'free', 'is_array'];

    private const REQUEST_VARIABLES = ['$_SERVER', '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_ENV', '$_FILES', '$_SESSION', '$GLOBALS'];

    /** @return list<\PhpToken> the tokens of is_installed()'s body, braces included */
    private function functionBodyTokens(): array
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/202-config/functions.php');
        self::assertIsString($source, 'could not read 202-config/functions.php');

        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable()
        ));

        // Find `function is_installed`.
        $start = null;
        for ($i = 0, $n = count($tokens); $i < $n - 1; $i++) {
            if (
                $tokens[$i]->is(T_FUNCTION)
                && $tokens[$i + 1]->is(T_STRING)
                && $tokens[$i + 1]->text === 'is_installed'
            ) {
                $start = $i;
                break;
            }
        }
        self::assertNotNull($start, 'is_installed() not found in functions.php');

        // Walk to the opening brace, then capture to its match.
        $open = null;
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '{') {
                $open = $i;
                break;
            }
        }
        self::assertNotNull($open, 'is_installed() has no body');

        $depth = 0;
        $body = [];
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $body[] = $tokens[$i];
            if ($tokens[$i]->text === '{') {
                $depth++;
            } elseif ($tokens[$i]->text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
        }

        self::fail('is_installed() body braces are unbalanced');
    }

    public function testIsInstalledReadsNothingFromTheRequest(): void
    {
        $body = $this->functionBodyTokens();
        $count = count($body);
        for ($i = 0; $i < $count; $i++) {
            $token = $body[$i];
            if ($token->is(T_VARIABLE) && in_array($token->text, self::REQUEST_VARIABLES, true)) {
                self::fail("is_installed() reads {$token->text}; it must answer from the database alone.");
            }
            if ($token->text === '$' || $token->is([T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_STRING_VARNAME])) {
                self::fail("is_installed() uses {$token->text}, which this test cannot read; it must answer from the database alone.");
            }
            $next = $body[$i + 1] ?? null;
            if ($next !== null && $next->text === '(' && $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                self::assertContains(
                    $token->text,
                    self::ALLOWED_CALLS,
                    "is_installed() calls {$token->text}(); list it here only if it cannot read the request."
                );
            }
        }
    }

    public function testIsInstalledQueriesTheUsersTable(): void
    {
        $body = $this->functionBodyTokens();
        $text = implode(' ', array_map(static fn (\PhpToken $t): string => $t->text, $body));
        self::assertStringContainsString('202_users', $text, 'is_installed() no longer reads 202_users');
    }
}

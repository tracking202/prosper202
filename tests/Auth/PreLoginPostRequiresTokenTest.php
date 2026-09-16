<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Every page that takes a POST before there is a login checks the session
 * token before it does any work.
 *
 * Three pages answer a POST with nobody logged in: the installer, the
 * upgrader and the login form. For them the token connect.php mints on every
 * request is the only thing between a cross-site form and the work the page
 * does, because there is no user session to require yet. install.php and
 * 202-login.php made the check; upgrade.php did not, and the repair
 * RELEASING.md gives for a stranded branch deployment — wind 202_version back
 * and open that page — is exactly when the gap was open.
 * tests/live/upgrade-csrf.sh proves the same over HTTP against a running
 * instance; this is the part that runs in CI.
 *
 * Scoped to the pre-login pages on purpose: of the 74 files in the tree that
 * read $_POST, 27 check a token, so the tree-wide invariant cannot land as one
 * change. Those are a sweep of their own.
 */
final class PreLoginPostRequiresTokenTest extends TestCase
{
    /** The guard spellings the tree uses. Each is held to a real comparison below. */
    private const GUARDS = ['install_csrf_ok(', 'AUTH::check_csrf_token('];

    /**
     * Each pre-login page with the call its guard must precede. Order in the
     * source is the assertion: a check that runs after the work is decoration.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'installer' => ['202-config/install.php', 'new INSTALL('],
            'upgrader'  => ['202-config/upgrade.php', 'UPGRADE::upgrade_databases('],
            'login'     => ['202-login.php', 'AUTH::authenticate('],
        ];
    }

    /** @dataProvider pages */
    public function testThePostIsGuardedBeforeTheWork(string $file, string $work): void
    {
        $code = $this->codeOf($file);

        $post = strpos($code, "\$_SERVER['REQUEST_METHOD'] == 'POST'");
        $this->assertNotFalse($post, "$file no longer branches on a POST; this test's subject has moved");

        $workAt = strpos($code, $work);
        $this->assertNotFalse($workAt, "$file no longer calls $work; the work this guard protects has moved");

        $guardAt = false;
        foreach (self::GUARDS as $guard) {
            $at = strpos($code, $guard, $post);
            if ($at !== false && ($guardAt === false || $at < $guardAt)) {
                $guardAt = $at;
            }
        }
        $this->assertNotFalse(
            $guardAt,
            "$file takes a POST without checking the session token (" . implode(' or ', self::GUARDS) . ')'
        );
        $this->assertLessThan($workAt, $guardAt, "$file checks the token only after $work has already run");
    }

    /** @dataProvider pages */
    public function testTheFormCarriesTheToken(string $file): void
    {
        $this->assertStringContainsString(
            'name="token"',
            $this->codeOf($file),
            "$file renders a form with no token field, so its own submissions would fail the check"
        );
    }

    /**
     * A guard is only a guard if its name resolves to a real comparison. Both
     * spellings must fail closed on an empty token and compare with
     * hash_equals — asserted on the definitions, so "guarded" above is a
     * statement about code rather than about a function name.
     */
    public function testEveryGuardSpellingFailsClosedAndUsesHashEquals(): void
    {
        $definitions = [
            '202-config/functions-install-helpers.php' => 'install_csrf_ok',
            '202-config/functions-auth.php' => 'check_csrf_token',
        ];

        foreach ($definitions as $file => $name) {
            $body = $this->functionBody($file, $name);

            $this->assertStringContainsString('hash_equals(', $body, "$name() no longer compares with hash_equals()");
            $this->assertMatchesRegularExpression(
                "/[!=]== *''/",
                $body,
                "$name() no longer refuses an empty token; hash_equals('', '') is true"
            );
        }
    }

    /** The file with comments removed, so prose cannot satisfy a scan. */
    private function codeOf(string $file): string
    {
        return implode('', array_column($this->tokensOf($file), 'text'));
    }

    /**
     * @return list<array{id: int|null, text: string}>
     */
    private function tokensOf(string $file): array
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        $this->assertNotSame('', $source, "$file is empty or missing");

        $out = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out[] = ['id' => null, 'text' => $token];
                continue;
            }
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out[] = ['id' => $token[0], 'text' => $token[1]];
        }

        return $out;
    }

    /** The body of `function $name`, bounded at its closing brace. */
    private function functionBody(string $file, string $name): string
    {
        $tokens = $this->tokensOf($file);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count && $tokens[$j]['id'] === T_WHITESPACE; $j++) {
            }
            if ($j >= $count || $tokens[$j]['id'] !== T_STRING || $tokens[$j]['text'] !== $name) {
                continue;
            }

            $depth = 0;
            $body = '';
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]['text'];
                if ($text === '{' || $text === '${') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body . $text;
                    }
                }
                $body .= $text;
            }
            $this->fail("$name() in $file has no closing brace");
        }

        $this->fail("$name() is not defined in $file");
    }
}

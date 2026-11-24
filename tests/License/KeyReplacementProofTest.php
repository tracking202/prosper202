<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\KeyReplacementProof;

/**
 * The license-key page (api-key-required.php) answers with nobody signed in,
 * and its session token is one every visitor holds, so any key the license
 * service accepted replaced the install's: whoever held the new key could
 * later revoke it and lock the install. A saved key is now replaced only by
 * a session whose user may change Settings, or with such a user's password,
 * and the license service is asked nothing before that is shown. While no
 * key is saved, anyone may still save one: that is the install.
 *
 * The page itself is held here by its tokens, and run end to end, against a
 * database, by tests/Auth/LicenceKeyPageIntegrationTest.php.
 */
final class KeyReplacementProofTest extends TestCase
{
    private const PAGE = 'api-key-required.php';

    /** @var list<string> */
    private array $calls = [];

    /**
     * @param array{session?: bool, limited?: bool, user?: ?int, may?: bool, throws?: string} $world
     */
    private function proof(array $world): KeyReplacementProof
    {
        return new KeyReplacementProof(
            $world['session'] ?? false,
            function (string $user, string $address) use ($world): bool {
                $this->calls[] = "limited:$user@$address";
                if (($world['throws'] ?? '') === 'limited') {
                    throw new \RuntimeException('throttle read failed');
                }
                return $world['limited'] ?? false;
            },
            function (string $user, string $password) use ($world): ?int {
                $this->calls[] = "authenticate:$user:$password";
                if (($world['throws'] ?? '') === 'authenticate') {
                    throw new \RuntimeException('sign-in read failed');
                }
                return $world['user'] ?? null;
            },
            function (string $user, string $address) use ($world): void {
                $this->calls[] = "failure:$user@$address";
                if (($world['throws'] ?? '') === 'failure') {
                    throw new \RuntimeException('log write failed');
                }
            },
            function (int $userId) use ($world): bool {
                $this->calls[] = "may:$userId";
                return $world['may'] ?? false;
            },
        );
    }

    public function testWithNoKeySavedAnyoneMaySaveOne(): void
    {
        self::assertNull($this->proof([])->refusal(false, '', '', '203.0.113.9'));
        self::assertSame([], $this->calls, 'nothing is asked of anyone');
    }

    public function testASavedKeyIsReplacedBySessionThatMayChangeSettings(): void
    {
        self::assertNull($this->proof(['session' => true])->refusal(true, '', '', '203.0.113.9'));
        self::assertSame([], $this->calls);
    }

    public function testASavedKeyIsNotReplacedByAVisitorWhoShowsNothing(): void
    {
        foreach ([['', ''], ['owner', ''], ['', 'pw'], ['   ', 'pw']] as [$user, $password]) {
            self::assertSame(
                KeyReplacementProof::NEEDS_PROOF,
                $this->proof([])->refusal(true, $user, $password, '203.0.113.9')
            );
        }
        self::assertSame([], $this->calls, 'no password is checked and no guess counted');
    }

    public function testTheRightPasswordOfAUserWhoMayChangeSettingsReplacesIt(): void
    {
        self::assertNull(
            $this->proof(['user' => 7, 'may' => true])->refusal(true, ' owner ', 'pw', '203.0.113.9')
        );
        self::assertSame(['limited:owner@203.0.113.9', 'authenticate:owner:pw', 'may:7'], $this->calls);
    }

    public function testAWrongPasswordIsRefusedAndCountedByTheThrottle(): void
    {
        self::assertSame(
            KeyReplacementProof::WRONG_PASSWORD,
            $this->proof(['user' => null])->refusal(true, 'owner', 'guess', '203.0.113.9')
        );
        self::assertSame(
            ['limited:owner@203.0.113.9', 'authenticate:owner:guess', 'failure:owner@203.0.113.9'],
            $this->calls
        );
    }

    public function testTheRightPasswordOfAUserWhoMayNotChangeSettingsIsRefused(): void
    {
        self::assertSame(
            KeyReplacementProof::NOT_PERMITTED,
            $this->proof(['user' => 9, 'may' => false])->refusal(true, 'viewer', 'pw', '203.0.113.9')
        );
        self::assertNotContains('failure:viewer@203.0.113.9', $this->calls, 'a right password is not a guess');
    }

    public function testAThrottledVisitorIsRefusedBeforeThePasswordIsChecked(): void
    {
        self::assertSame(
            KeyReplacementProof::THROTTLED,
            $this->proof(['limited' => true, 'user' => 7, 'may' => true])->refusal(true, 'owner', 'pw', '203.0.113.9')
        );
        self::assertSame(['limited:owner@203.0.113.9'], $this->calls);
    }

    /** A check that cannot be made refuses; it never reads as proof. */
    public function testACheckThatFailsRefuses(): void
    {
        foreach (['limited', 'authenticate', 'failure'] as $failing) {
            $this->calls = [];
            $previous = ini_set('error_log', '/dev/null');
            try {
                $answer = $this->proof(['throws' => $failing, 'user' => null])
                    ->refusal(true, 'owner', 'pw', '203.0.113.9');
            } finally {
                ini_set('error_log', (string) $previous);
            }
            self::assertSame(KeyReplacementProof::UNCHECKED, $answer, $failing);
        }
    }

    // ── the page ───────────────────────────────────────────────────────

    /** @return list<\PhpToken> */
    private static function tokens(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PAGE);

        return array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable()
        ));
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return array<int, int> each bracket's index => its partner's
     */
    private static function pairs(array $tokens): array
    {
        $pairs = [];
        $open = [];
        foreach ($tokens as $i => $t) {
            if (in_array($t->text, ['{', '(', '['], true) || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $open[] = $i;
            } elseif (in_array($t->text, ['}', ')', ']'], true)) {
                $o = array_pop($open);
                self::assertNotNull($o, 'unbalanced bracket at line ' . $t->line);
                $pairs[$o] = $i;
                $pairs[$i] = $o;
            }
        }
        self::assertSame([], $open, 'unbalanced brackets in ' . self::PAGE);

        return $pairs;
    }

    /**
     * $count significant tokens from $from on, joined by one space.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function spelled(array $tokens, int $from, int $count): string
    {
        $texts = array_map(static fn (\PhpToken $t): string => $t->text, array_slice($tokens, $from, $count));

        return implode(' ', $texts);
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<int>
     */
    private static function indexesOf(array $tokens, string $text): array
    {
        $at = [];
        foreach ($tokens as $i => $t) {
            if ($t->text === $text) {
                $at[] = $i;
            }
        }

        return $at;
    }

    /**
     * The page asks the license service only in the branch where the
     * ownership check said yes, and the check runs on every request that
     * reaches the ask.
     *
     * Read by token, and refused for any shape but this one: the refusal is
     * assigned once, as the first statement directly inside the block the
     * token guard opens; the very next statement is `if ($key_owner_refusal
     * !== null) { … } else { … }`; every ask sits in that else; and the
     * variable is named nowhere else in the file, so nothing can change it
     * between the check and the branch, and no default stands in for a
     * check that did not run.
     */
    public function testTheLicenceServiceIsAskedOnlyOnceTheOwnerIsShown(): void
    {
        $tokens = self::tokens();
        $pairs = self::pairs($tokens);
        $uses = self::indexesOf($tokens, '$key_owner_refusal');
        self::assertCount(3, $uses, 'the refusal is assigned, tested and shown, and named nowhere else');
        [$assigned, $tested, $shown] = $uses;

        $call = '$key_owner_refusal = \\Prosper202\\License\\KeyReplacementProof :: forPage ( $db ,'
            . ' $session_may_change_settings ) -> refusal ( $has_existing_key ,';
        self::assertSame(
            $call,
            self::spelled($tokens, $assigned, 15),
            'the check is the class\'s, on whether a key is saved'
        );

        $brace = $assigned - 1;
        self::assertSame('{', $tokens[$brace]->text, 'the check is the first statement of its block');
        $close = $brace - 1;
        self::assertSame(')', $tokens[$close]->text);
        $open = $pairs[$close];
        self::assertSame('if', $tokens[$open - 1]->text);
        self::assertSame(
            '$csrf_ok && $api_key !== \'\'',
            self::spelled($tokens, $open + 1, $close - $open - 1),
            "the check runs in the token guard's block, whenever a key was posted"
        );

        $end = $pairs[$assigned + 12];
        self::assertSame(')', $tokens[$end]->text);
        self::assertSame(';', $tokens[$end + 1]->text, 'the call is the whole statement');
        self::assertSame($tested, $end + 4, 'the branch on the answer is the next statement');
        self::assertSame('if ( $key_owner_refusal !== null ) {', self::spelled($tokens, $end + 2, 7));
        $refusedOpen = $end + 8;
        $refusedClose = $pairs[$refusedOpen];
        self::assertTrue($shown > $refusedOpen && $shown < $refusedClose, 'the refusal is shown in its own branch');
        self::assertSame('$error = $key_owner_refusal ;', self::spelled($tokens, $shown - 2, 4));
        self::assertSame('else {', self::spelled($tokens, $refusedClose + 1, 2), 'the ask has an else of its own');
        $elseOpen = $refusedClose + 2;
        $elseClose = $pairs[$elseOpen];

        $asks = self::indexesOf($tokens, 'api_key_validate');
        self::assertNotSame([], $asks, 'the page still asks the license service');
        foreach ($asks as $ask) {
            self::assertTrue(
                $ask > $elseOpen && $ask < $elseClose,
                'api_key_validate() at line ' . $tokens[$ask]->line . ' is outside the branch the ownership check opens'
            );
        }

        self::assertSame(
            '$has_existing_key = p202FetchRow ( $existing_key_check , $existing_key_sql ) !== null ;',
            self::spelled($tokens, self::indexesOf($tokens, '$has_existing_key')[0], 11),
            'whether a key is saved is read once, before anything uses it'
        );
        self::assertSame(
            '$session_may_change_settings = AUTH :: logged_in ( ) && isset ( $userObj ) && $userObj instanceof User'
                . ' && $userObj -> hasPermission ( \\Prosper202\\License\\KeyReplacementProof :: PERMISSION ) ;',
            self::spelled($tokens, self::indexesOf($tokens, '$session_may_change_settings')[0], 26),
            'the session counts only when it is signed in and its user may change Settings'
        );
        foreach (['$has_existing_key', '$session_may_change_settings'] as $name) {
            foreach (array_slice(self::indexesOf($tokens, $name), 1) as $use) {
                $next = $tokens[$use + 1]->text;
                self::assertNotContains(
                    $next,
                    ['=', '.=', '??=', '|=', '&=', '++', '--'],
                    "$name is written again at line " . $tokens[$use]->line
                );
                self::assertNotSame('&', $tokens[$use - 1]->text, "$name is referenced at line " . $tokens[$use]->line);
            }
        }
    }

    /**
     * No part of the saved key reaches the page: the read asks only whether
     * one is saved, so a visitor cannot be shown any of its characters. The
     * page printed its first 8 and last 4 to anyone.
     */
    public function testThePageNeverHoldsTheSavedKey(): void
    {
        $tokens = self::tokens();
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::PAGE);
        self::assertStringContainsString(
            "\$existing_key_sql = \"SELECT 1 AS has_key FROM 202_users WHERE user_id='1'\"\n"
                . "    . \" AND p202_customer_api_key IS NOT NULL AND p202_customer_api_key != ''\";",
            $source,
            'the one read of the saved key asks only whether there is one'
        );
        foreach ($tokens as $t) {
            self::assertNotContains(
                strtolower($t->text),
                ['substr', 'mb_substr', 'str_split', '$existing_api_key', '$existing_key'],
                'line ' . $t->line . ' reads or cuts a key on the page'
            );
        }
    }
}

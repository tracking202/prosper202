<?php

declare(strict_types=1);

namespace Tests\Standalone;

use PHPUnit\Framework\TestCase;

/**
 * The standalone family (U7) — every page that renders through info_top(),
 * the three feed sections and the retired 202-Mobile addresses — checks the
 * session token wherever it takes a POST, and every form it renders that
 * posts carries the token inside it.
 *
 * Error pattern #5 found two writes in this family that asked for none: the
 * license-key page (api-key-required.php), which saved the install's license
 * key for anyone who posted one, and the setup wizard (setup-config.php),
 * which rewrote 202-config.php — on an installed instance too — for any POST
 * to step 2. Both check a token now, and the wizard also refuses outright
 * once the install is done.
 *
 * This is a floor, as AccountPostRequiresTokenTest is: a file that takes a
 * POST calls a guard this test recognises, by token (`AUTH::` directly
 * before the name, or the global function), in a file that declares no
 * namespace. It does not prove the guard decides the work; for sign-in, the
 * installer, the upgrader and the license-key page that is
 * PreLoginPostRequiresTokenTest, at length, and for the rest
 * tests/live/prelogin-pages.sh posts each form without its token and asserts
 * the refusal sentence and an unchanged row.
 */
final class StandalonePostsRequireTokenTest extends TestCase
{
    /** The family, by path; a new standalone page is a line here. */
    private const FILES = [
        '202-login.php', '202-lost-pass.php', '202-pass-reset.php', '202-404.php', 'api-key-required.php', 'index.php',
        '202-config/install.php', '202-config/upgrade.php', '202-config/setup-config.php', '202-config/requirements.php',
        '202-config/get_apikey.php', '202-config/setup.php',
        '202-tv/index.php', '202-resources/index.php', '202-appstore/index.php',
        '202-Mobile/index.php', '202-Mobile/202-login.php', '202-Mobile/mini-stats/index.php',
    ];

    /** The guards, as calls: [class or null, function]. */
    private const GUARDS = [
        ['AUTH', 'check_csrf_token'],
        [null, 'install_csrf_ok'],
        [null, 'p202_standalone_wizard_token_ok'],
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<\PhpToken> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
    }

    /** @param list<\PhpToken> $tokens */
    private static function takesPost(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if ($t->is(T_VARIABLE) && in_array($t->text, ['$_POST', '$_REQUEST'], true)) {
                return true;
            }
            if ($t->is(T_CONSTANT_ENCAPSED_STRING) && in_array($t->text, ["'POST'", '"POST"'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function guardCalls(array $tokens): int
    {
        $found = 0;
        foreach ($tokens as $i => $t) {
            if (!$t->is(T_STRING) || ($tokens[$i + 1]->text ?? '') !== '(') {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            foreach (self::GUARDS as [$class, $function]) {
                if ($t->text !== $function) {
                    continue;
                }
                if ($class === null) {
                    if ($before === null || !$before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR])) {
                        $found++;
                    }
                } elseif ($before !== null && $before->text === '::') {
                    $owner = $tokens[$i - 2] ?? null;
                    if ($owner !== null && $owner->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ltrim($owner->text, '\\') === $class) {
                        $found++;
                    }
                }
            }
        }
        return $found;
    }

    public function testEveryStandaloneFileThatTakesAPostChecksTheToken(): void
    {
        $missing = [];
        $checked = 0;
        foreach (self::FILES as $relative) {
            $path = self::root() . '/' . $relative;
            self::assertFileExists($path, "$relative is listed but gone; update the list");
            $tokens = self::tokens((string) file_get_contents($path));
            foreach ($tokens as $t) {
                if ($t->is(T_NAMESPACE)) {
                    $missing[] = "$relative declares a namespace, so the guard's name is not read as the global one";
                }
            }
            if (!self::takesPost($tokens)) {
                continue;
            }
            $checked++;
            if (self::guardCalls($tokens) === 0) {
                $missing[] = "$relative takes a POST and calls no token guard";
            }
        }
        // sign-in, lost-pass, pass-reset, the license key, install, upgrade, the wizard, the App Store.
        self::assertGreaterThanOrEqual(8, $checked, 'fewer standalone files take a POST than the family has writes; the scan is broken');
        self::assertSame([], $missing, implode("\n", $missing));
    }

    public function testEveryPostFormCarriesTheToken(): void
    {
        $problems = [];
        $forms = 0;
        foreach (self::FILES as $relative) {
            $source = (string) file_get_contents(self::root() . '/' . $relative);
            $markup = '';
            foreach (\PhpToken::tokenize($source) as $token) {
                if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && stripos($token->text, '<form') !== false) {
                    $problems[] = "$relative line {$token->line}: a <form> built in a PHP string cannot be read by this test";
                }
                if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $markup .= $token->text;
                }
            }
            $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);
            // An echo block inside the tag ends in a question mark and a
            // bracket that do not end the tag; read past it.
            if (!preg_match_all('/<form\b((?:<\?php.*?\?>|<\?=.*?\?>|[^>])*)>(.*?)<\/form>/is', $markup, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $match[1])) {
                    continue;
                }
                $forms++;
                $body = $match[2];
                $carries = preg_match('/\bp202_account_token_field\(\s*\)/', $body)
                    || preg_match('/<input\b[^>]*type\s*=\s*["\']hidden["\'][^>]*name\s*=\s*["\']token["\']/i', $body)
                    || preg_match('/<input\b[^>]*name\s*=\s*["\']token["\'][^>]*type\s*=\s*["\']hidden["\']/i', $body);
                if (!$carries) {
                    $problems[] = "$relative: a POST form without the session token inside it: " . trim(substr($match[0], 0, 120));
                }
            }
        }
        // sign-in, lost-pass, pass-reset, the license key, install, upgrade, the wizard, the App Store key.
        self::assertGreaterThanOrEqual(8, $forms, 'fewer POST forms than the standalone pages render; the scan is broken');
        self::assertSame([], $problems, implode("\n", $problems));
    }
}

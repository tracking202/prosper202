<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Api\V3\Apps\Android\InstallToken;
use PHPUnit\Framework\TestCase;

/**
 * What the redirect writes for `[[p202_install_token]]` (plan §5.1): the
 * signed token for a canonical click id, and otherwise nothing — never the
 * bare, guessable click id, whatever went wrong.
 */
final class InstallTokenExpandTest extends TestCase
{
    public function testACanonicalClickIsSigned(): void
    {
        $key = str_repeat("\x01", 32);
        self::assertSame(InstallToken::forClick(42, $key), InstallToken::expand('42', static fn (): string => $key));
        self::assertSame(InstallToken::forClick(42, $key), InstallToken::expand(42, static fn (): string => $key));
    }

    public function testEverythingElseExpandsEmpty(): void
    {
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-install-token-test.log');
        try {
            $key = static fn (): string => str_repeat("\x01", 32);
            foreach (['p202', '', '0', '042', '-42', '42.0', ' 42', '99999999999999999999', null, 4.2, true] as $click) {
                self::assertSame('', InstallToken::expand($click, $key), var_export($click, true));
            }
            self::assertSame('', InstallToken::expand('42', static fn (): ?string => null), 'no key');
            self::assertSame('', InstallToken::expand('42', static function (): string {
                throw new \RuntimeException('the lookup failed');
            }), 'an unreadable key');
            self::assertSame('', InstallToken::expand('42', static fn (): string => 'short'), 'a corrupt key');
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
    }

    public function testTheRedirectComputesTheTokenFromTheRawClickIdThroughExpand(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/202-config/connect2.php');
        $start = strpos($src, 'function replaceTokens(');
        self::assertIsInt($start);
        $body = substr($src, $start, (int) strpos($src, "\n}\n", $start) - $start);
        $token = strpos($body, "p202InstallToken(\$tokens['subid'])");
        $encode = strpos($body, 'array_map(rawurlencode202(...), $tokens)');
        self::assertIsInt($token, 'replaceTokens() expands the token from the subid');
        self::assertIsInt($encode);
        self::assertLessThan($encode, $token, 'before the tokens are encoded');
        self::assertMatchesRegularExpression('/function p202InstallToken\(\$clickId\): string\s*\{.*?InstallToken::expand\(\$clickId,/s', $src);
    }
}

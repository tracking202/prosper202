<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;

/**
 * Every postback and pixel endpoint that refuses an amount a conversion
 * cannot hold names the refusal amount_out_of_range on the Inbound Log.
 * gpb.php and pb.php answered 422 without naming it, so the row read as a
 * plain refusal and carried that outcome's fix: a subid naming none of your
 * clicks, the wrong thing to check (found in a browser pass of 1.9.80).
 */
final class AmountRefusalIsNamedTest extends TestCase
{
    private const CATCH = '/catch\s*\(\s*\\\\?Prosper202\\\\Conversion\\\\Ledger\\\\'
        . 'AmountOutOfRange\s+\$\w+\s*\)\s*\{/';

    public function testEveryCatchOfAnAmountOutOfRangeNamesTheOutcome(): void
    {
        $root = dirname(__DIR__, 2);
        $catches = 0;
        foreach (glob($root . '/tracking202/static/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            $offset = 0;
            while (preg_match(self::CATCH, $src, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $open = $m[0][1] + strlen($m[0][0]);
                $body = self::block($src, $open);
                $catches++;
                self::assertMatchesRegularExpression(
                    "/RequestLog::current\(\)\?->refuse\(\s*'amount_out_of_range'/",
                    $body,
                    basename($file) . ': the catch of AmountOutOfRange must name amount_out_of_range on the Inbound Log'
                );
                $offset = $open;
            }
        }
        // gpb, pb, px, gpx, upx: a scan that found none read nothing.
        self::assertGreaterThanOrEqual(5, $catches, 'the endpoints\' catches were not found where this test looks');
    }

    /** The text of the block whose opening brace ends just before $open. */
    private static function block(string $src, int $open): string
    {
        $depth = 1;
        $tokens = \PhpToken::tokenize('<?php ' . substr($src, $open));
        $body = '';
        foreach (array_slice($tokens, 1) as $token) {
            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                if (--$depth === 0) {
                    return $body;
                }
            }
            $body .= $token->text;
        }
        self::fail('an unclosed catch block');
    }
}

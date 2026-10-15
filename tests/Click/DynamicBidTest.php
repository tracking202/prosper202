<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\DynamicBid;

/**
 * The t202b bid, read once for the four click scripts. Three of them handed
 * the string to number_format() under strict_types: a TypeError for every
 * bid sent, and the click was lost.
 */
final class DynamicBidTest extends TestCase
{
    /** @return iterable<string, array{mixed, ?string}> */
    public static function bids(): iterable
    {
        yield 'a decimal' => ['1.25', '1.25000'];
        yield 'a whole number' => ['3', '3.00000'];
        yield 'a dollar sign' => ['$0.35', '0.35000'];
        yield 'no leading digit' => ['.5', '0.50000'];
        yield 'a trailing point' => ['2.', '2.00000'];
        yield 'past five decimals, rounded' => ['0.123456', '0.12346'];
        yield 'zero' => ['0', '0.00000'];
        yield 'the largest the spy row holds' => ['99.99', '99.99000'];
        // Refused: the click costs its tracker's CPC.
        yield 'absent' => [null, null];
        yield 'empty' => ['', null];
        yield 'an unfilled token' => ['{bid}', null];
        yield 'an exponent' => ['1e1', null];
        yield 'negative' => ['-1', null];
        yield 'a space' => [' 2', null];
        yield 'a comma' => ['1,5', null];
        yield 'more than the spy row holds' => ['99.995', null];
        yield 'past a float' => ['1' . str_repeat('0', 400), null];
        yield 'a list' => [['1.25'], null];
        yield 'two dollar signs, then a number' => ['$$1', '1.00000'];
    }

    /** @dataProvider bids */
    public function testTheBidIsWhatTheColumnsHoldOrNone(mixed $sent, ?string $expected): void
    {
        self::assertSame($expected, DynamicBid::fromRequest($sent));
    }

    /**
     * The four scripts read it here and nowhere else: every string naming
     * t202b, in either quoting, is the one read or (on the landing pages) the
     * request's default.
     */
    public function testEveryClickScriptReadsTheBidHere(): void
    {
        $root = dirname(__DIR__, 2);
        $names = [
            'tracking202/redirect/dl.php' => 1, 'tracking202/redirect/rtr.php' => 1,
            // and $_GET += ['t202b' => '', …]
            'tracking202/static/record_simple.php' => 2, 'tracking202/static/record_adv.php' => 2,
        ];
        foreach ($names as $file => $count) {
            $source = (string) file_get_contents("$root/$file");
            self::assertSame(1, substr_count($source, "DynamicBid::fromRequest(\$_GET['t202b'] ?? null)"), $file);
            $strings = array_filter(
                \PhpToken::tokenize($source),
                static fn (\PhpToken $t): bool => $t->is(T_CONSTANT_ENCAPSED_STRING)
                    && substr($t->text, 1, -1) === 't202b'
            );
            self::assertCount($count, $strings, "$file names t202b outside the one read");
        }
    }

    /** A number refused is said, where an unfilled token never was. */
    public function testARefusedNumberIsLoggedAndAnUnfilledTokenIsNot(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'bid');
        self::assertIsString($log);
        $old = ini_set('error_log', $log);
        try {
            DynamicBid::fromRequest('{bid}');
            DynamicBid::fromRequest('$150');
        } finally {
            ini_set('error_log', (string) $old);
        }
        $written = (string) file_get_contents($log);
        unlink($log);

        self::assertSame(1, substr_count($written, 'p202 click: the bid'), $written);
        self::assertStringContainsString('the bid "150" (t202b) is not a plain decimal from 0 to 99.99', $written);
    }
}

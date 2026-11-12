<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * gpb.php and upx.php fire the traffic source's browser pixels for the
 * conversion they just recorded with that conversion's DestinationEventId
 * (TrafficSourcePixels::EVENT_ID), the id the destinations send it under, so
 * a Meta Purchase and a server event name one sale alike.
 *
 * Held by reading each script's tokens: every p202FireTrafficSourcePixels()
 * is fired with $tokens; the statement before it that sets $tokens is a
 * whole statement (not under a braceless if or else) assigning
 * withConversionEventId()'s answer for exactly (int) $conversionId; between
 * the two nothing names $tokens and no block closes; and nothing in the
 * script can reach $tokens without naming it (a reference, extract(), a
 * variable variable). A shape the scan cannot read is refused (CLAUDE.md #20,
 * #22).
 */
final class ConversionPixelsCarryTheEventIdTest extends TestCase
{
    private const SCRIPTS = ['tracking202/static/gpb.php', 'tracking202/static/upx.php'];
    private const STAMP = 'Prosper202\Conversion\TrafficSourcePixels::withConversionEventId';

    public function testEachScriptFiresItsPixelsWithTheConversionsEventId(): void
    {
        foreach (self::SCRIPTS as $script) {
            self::assertSame([], self::problems((string) file_get_contents(dirname(__DIR__, 2) . '/' . $script)), $script);
        }
    }

    public function testTheScanRefusesEachWayTheWiringCanBreak(): void
    {
        $stamp = '$tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t");';
        $fire = ' p202FireTrafficSourcePixels($db, 3, $tokens, null);';
        self::assertSame([], self::problems('<?php $tokens["payout"] = 1; ' . $stamp . $fire), 'the positive control');
        self::assertSame([], self::problems('<?php if ($ok) { $tokens["payout"] = 1; ' . $stamp . ' // markup only' . "\n" . $fire . ' }'), 'both in one block');
        $planted = [
            'no event id' => '<?php' . $fire,
            'the result dropped' => '<?php ' . str_replace('$tokens = ', '', $stamp) . $fire,
            'after the fire' => '<?php' . $fire . ' ' . $stamp,
            'reassigned between' => '<?php ' . $stamp . ' $tokens = [];' . $fire,
            'another conversion' => '<?php ' . str_replace('(int) $conversionId', '7', $stamp) . $fire,
            'the conversion plus one' => '<?php ' . str_replace('(int) $conversionId', '(int) $conversionId + 1', $stamp) . $fire,
            'another variable' => '<?php ' . str_replace('$conversionId', '$conversionIdOld', $stamp) . $fire,
            'other tokens fired' => '<?php ' . $stamp . str_replace('3, $tokens', '3, $other', $fire),
            'an entry unset between' => '<?php ' . $stamp . ' unset($tokens["p202_event_id"]);' . $fire,
            'an entry written between' => '<?php ' . $stamp . ' $tokens["p202_event_id"] = "x";' . $fire,
            'array_pop between' => '<?php ' . $stamp . ' array_pop($tokens);' . $fire,
            'array_splice between' => '<?php ' . $stamp . ' array_splice($tokens, 0, 1);' . $fire,
            'destructured between' => '<?php ' . $stamp . ' [$tokens] = [[]];' . $fire,
            'null-coalescing assignment between' => '<?php ' . $stamp . ' $tokens ??= [];' . $fire,
            'a reference taken before' => '<?php $t = &$tokens; ' . $stamp . ' $t["p202_event_id"] = "x";' . $fire,
            'foreach by reference between' => '<?php ' . $stamp . ' foreach ($tokens as &$v) { $v = ""; }' . $fire,
            'foreach into a reference elsewhere' => '<?php ' . $stamp . ' foreach ($a as &$v) {}' . $fire,
            'extract()' => '<?php ' . $stamp . ' extract($a);' . $fire,
            'a variable variable' => '<?php ' . $stamp . ' $$name = [];' . $fire,
            'stamped in one branch' => '<?php if ($a) { ' . $stamp . ' }' . $fire,
            'stamped under a braceless if' => '<?php if ($a) ' . $stamp . $fire,
            'stamped under a braceless else' => '<?php if ($a) {} else ' . $stamp . $fire,
        ];
        $expected = [
            'no event id' => 'not the ones withConversionEventId',
            'the result dropped' => 'not the ones withConversionEventId',
            'after the fire' => 'not the ones withConversionEventId',
            'reassigned between' => 'not the ones withConversionEventId',
            'another conversion' => '(int) $conversionId',
            'the conversion plus one' => '(int) $conversionId',
            'another variable' => '(int) $conversionId',
            'other tokens fired' => 'not fired with $tokens',
            'an entry unset between' => 'not the ones withConversionEventId',
            'an entry written between' => 'not the ones withConversionEventId',
            'array_pop between' => 'not the ones withConversionEventId',
            'array_splice between' => 'not the ones withConversionEventId',
            'destructured between' => 'not the ones withConversionEventId',
            'null-coalescing assignment between' => 'not the ones withConversionEventId',
            'a reference taken before' => 'a reference',
            'foreach by reference between' => 'a reference',
            'foreach into a reference elsewhere' => 'a reference',
            'extract()' => 'extract()',
            'a variable variable' => 'a variable variable',
            'stamped in one branch' => 'block that ends',
            'stamped under a braceless if' => 'not the ones withConversionEventId',
            'stamped under a braceless else' => 'not the ones withConversionEventId',
        ];
        foreach ($planted as $why => $code) {
            $found = self::problems($code);
            self::assertNotSame([], $found, $why);
            self::assertStringContainsString($expected[$why], implode(' | ', $found), $why . ' is refused by the check that names it');
        }
    }

    /** @return list<string> */
    private static function problems(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)
        ));
        $text = static fn (int $i): string => isset($tokens[$i]) ? (is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i]) : '';
        $out = [];
        // What can reach $tokens without naming it: refused anywhere in the script.
        foreach ($tokens as $i => $t) {
            $word = strtolower($text($i));
            if ($word === '&' && str_starts_with($text($i + 1), '$')) {
                $out[] = 'a reference (&' . $text($i + 1) . ') can change $tokens without naming it';
            } elseif ($word === 'extract' && $text($i + 1) === '(') {
                $out[] = 'extract() can change $tokens without naming it';
            } elseif ($word === '$' && ($text($i + 1) === '$' || str_starts_with($text($i + 1), '$') || $text($i + 1) === '{')) {
                $out[] = 'a variable variable can change $tokens without naming it';
            }
        }
        $fires = [];
        foreach ($tokens as $i => $t) {
            if (ltrim($text($i), '\\') === 'p202FireTrafficSourcePixels' && $text($i + 1) === '(') {
                $fires[] = $i;
            }
        }
        if ($fires === []) {
            $out[] = 'no p202FireTrafficSourcePixels() call';
        }
        foreach ($fires as $fire) {
            if (self::argument($tokens, $fire + 1, 2, $text) !== '$tokens') {
                $out[] = 'the pixels are not fired with $tokens';
                continue;
            }
            // The last statement before the fire that names $tokens must be the stamp, whole: a
            // statement that starts after ; { or }, so not the body of a braceless if or else.
            $last = null;
            for ($i = $fire - 1; $i >= 0; $i--) {
                if ($text($i) === '$tokens') {
                    $last = $i;
                    break;
                }
            }
            $start = $last;
            while ($start !== null && $start > 0 && !in_array($text($start - 1), [';', '{', '}'], true)) {
                $start--;
            }
            $call = $start === null ? '' : self::statementPrefix($tokens, $start, $text);
            if ($start === null || !str_starts_with($call, '$tokens=' . self::STAMP . '(') && !str_starts_with($call, '$tokens=\\' . self::STAMP . '(')) {
                $out[] = 'the tokens fired are not the ones withConversionEventId() returned, assigned in a statement of its own';
                continue;
            }
            $open = $start;
            while ($text($open) !== '(') {
                $open++;
            }
            if (self::argument($tokens, $open, 4, $text) !== '(int)$conversionId') {
                $out[] = 'withConversionEventId() is not asked for the recorded conversion, (int) $conversionId';
            }
            // Between the stamp's end and the fire no block closes (nothing names $tokens there: the stamp is the last that does).
            $end = $open;
            for ($depth = 0; $end < $fire; $end++) {
                $t = $text($end);
                $depth += $t === '(' ? 1 : ($t === ')' ? -1 : 0);
                if ($depth === 0 && $t === ';') {
                    break;
                }
            }
            for ($i = $end + 1, $depth = 0; $i < $fire; $i++) {
                $t = $text($i);
                $depth += $t === '{' ? 1 : ($t === '}' ? -1 : 0);
                if ($depth < 0) {
                    $out[] = 'the stamp is inside a block that ends before the pixels are fired';
                    break;
                }
            }
        }

        return $out;
    }

    /** The statement from $start, its tokens joined, up to the stamp's open paren and a little past. */
    private static function statementPrefix(array $tokens, int $start, \Closure $text): string
    {
        $s = '';
        for ($i = $start; $i < $start + 12 && isset($tokens[$i]); $i++) {
            $s .= $text($i);
        }

        return $s;
    }

    /**
     * The text of argument $n (0-based) of the call whose `(` is $open,
     * whitespace dropped.
     *
     * @param list<mixed> $tokens
     */
    private static function argument(array $tokens, int $open, int $n, \Closure $text): string
    {
        $depth = 0;
        $arg = 0;
        $out = '';
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $text($i);
            if (in_array($t, ['(', '[', '{'], true)) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($t, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($t === ',' && $depth === 1) {
                $arg++;
                continue;
            }
            if ($arg === $n) {
                $out .= $t;
            }
        }

        return $out;
    }
}

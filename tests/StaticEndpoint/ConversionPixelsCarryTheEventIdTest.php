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
 * (in any letter case) is fired with $tokens, in the statement directly
 * after the stamp; the stamp is a whole statement (not under a braceless if
 * or else) that is `$tokens = withConversionEventId(...);` and nothing more,
 * asked for exactly (int) $conversionId; $conversionId is written once, as
 * `$conversionId = $conversionResult['conv_id'];`, before the stamp; and
 * nothing in the script can reach either variable without naming it (a
 * reference, extract(), a variable variable, $GLOBALS, goto, the function
 * named in a string, and include or eval once the id is set). A shape the
 * scan cannot read is refused (CLAUDE.md #20, #21, #22).
 *
 * Not read here: that $conversionResult is what the recorder answered, and a
 * call through a name built at run time.
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
        $id = " \$conversionId = \$conversionResult['conv_id'];";
        $control = '<?php' . $id . ' $tokens["payout"] = 1; ' . $stamp . $fire;
        self::assertSame([], self::problems($control), 'the positive control');
        $block = '<?php' . $id . ' if ($ok) { $tokens["payout"] = 1; ' . $stamp . ' // markup only' . "\n"
            . ' $fired =' . $fire . ' }';
        self::assertSame([], self::problems($block), 'both in one block, the answer kept');
        self::assertSame([], self::problems('<?php' . $id . ' ' . $stamp . ' echo' . $fire), 'the answer echoed');
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
            'a reference taken before' => '<?php $t = &$tokens; ' . $stamp . $fire,
            'foreach by reference between' => '<?php ' . $stamp . $fire . ' foreach ($tokens as &$v) { $v = ""; }',
            'foreach into a reference elsewhere' => '<?php foreach ($a as &$v) {} ' . $stamp . $fire,
            'extract()' => '<?php extract($a); ' . $stamp . $fire,
            'a variable variable' => '<?php $$name = []; ' . $stamp . $fire,
            'stamped in one branch' => '<?php if ($a) { ' . $stamp . ' }' . $fire,
            'stamped under a braceless if' => '<?php if ($a) ' . $stamp . $fire,
            'stamped under a braceless else' => '<?php if ($a) {} else ' . $stamp . $fire,
            'extract() by its full name' => '<?php \extract($a); ' . $stamp . $fire,
            '$GLOBALS' => '<?php ' . $stamp . $fire . ' $GLOBALS["x"] = 1;',
            'goto' => '<?php goto done; ' . $stamp . $fire . ' done:',
            'eval once the id is set' => '<?php eval($code); ' . $stamp . $fire,
            'include once the id is set' => '<?php include $file; ' . $stamp . $fire,
            'an index after the stamp' => '<?php ' . str_replace('"t");', '"t")["x"] ?? [];', $stamp) . $fire,
            'a condition after the stamp' => '<?php ' . str_replace('"t");', '"t") && false;', $stamp) . $fire,
            'a sum after the stamp' => '<?php ' . str_replace('"t");', '"t") + [];', $stamp) . $fire,
            'a ternary after the stamp' => '<?php ' . str_replace('"t");', '"t") ? [] : [];', $stamp) . $fire,
            'the id written again' => '<?php $conversionId = 7; ' . $stamp . $fire,
            'the id incremented' => '<?php $conversionId++; ' . $stamp . $fire,
            'the id set after the stamp' => '<?php ' . $stamp . $fire . ' $conversionId = 7;',
            'a statement between' => '<?php ' . $stamp . ' $x = 1;' . $fire,
            'fired inside another call' => '<?php ' . $stamp . ' other(1,' . $fire,
            'the function named in a string' => '<?php ' . $stamp . $fire
                . ' call_user_func("p202FireTrafficSourcePixels", $db, 3, $other, null);',
            'a second fire in another case' => '<?php ' . $stamp . $fire
                . ' P202FIRETRAFFICSOURCEPIXELS($db, 3, $other, null);',
        ];
        // Every case sets the id as the scripts do, so each trips only the check it names.
        foreach ($planted as $why => $code) {
            $planted[$why] = '<?php' . $id . substr($code, 5);
        }
        $planted['the id never set'] = '<?php ' . $stamp . $fire;
        $planted['the id set from something else'] = '<?php $conversionId = $other["conv_id"]; ' . $stamp . $fire;
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
            'extract() by its full name' => 'extract()',
            '$GLOBALS' => '$GLOBALS',
            'goto' => 'goto',
            'eval once the id is set' => 'eval',
            'include once the id is set' => 'include',
            'an index after the stamp' => 'not the ones withConversionEventId',
            'a condition after the stamp' => 'not the ones withConversionEventId',
            'a sum after the stamp' => 'not the ones withConversionEventId',
            'a ternary after the stamp' => 'not the ones withConversionEventId',
            'the id written again' => 'written once',
            'the id incremented' => 'written once',
            'the id set after the stamp' => 'written once',
            'a statement between' => 'directly after',
            'fired inside another call' => 'directly after',
            'the function named in a string' => 'named in a string',
            'a second fire in another case' => 'not fired with $tokens',
            'the id never set' => 'written once',
            'the id set from something else' => 'written once',
        ];
        self::assertSame(array_keys($planted), array_keys($expected), 'every case names its check');
        foreach ($planted as $why => $code) {
            $found = self::problems($code);
            self::assertCount(1, $found, $why . ' trips one check: ' . implode(' | ', $found));
            self::assertStringContainsString($expected[$why], $found[0], $why . ' is refused by its own check');
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
            } elseif (ltrim($word, '\\') === 'extract' && $text($i + 1) === '(') {
                $out[] = 'extract() can change $tokens without naming it';
            } elseif ($word === '$' && ($text($i + 1) === '$' || str_starts_with($text($i + 1), '$') || $text($i + 1) === '{')) {
                $out[] = 'a variable variable can change $tokens without naming it';
            } elseif ($text($i) === '$GLOBALS') {
                $out[] = '$GLOBALS can change $tokens without naming it';
            } elseif ($word === 'goto') {
                $out[] = 'a goto can step over the stamp';
            } elseif (
                is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && stripos($t[1], 'p202FireTrafficSourcePixels') !== false
            ) {
                $out[] = 'p202FireTrafficSourcePixels is named in a string, where a call through it is not read';
            }
        }
        // $conversionId is written once, whole, from the recorder's answer; after it nothing is included or evaluated.
        $idSet = null;
        $idWrites = 0;
        foreach ($tokens as $i => $t) {
            if ($text($i) !== '$conversionId') {
                continue;
            }
            $after = $text($i + 1);
            $writes = in_array($text($i - 1), ['++', '--'], true) || in_array($after, ['++', '--'], true)
                || (str_ends_with($after, '=') && !in_array($after, ['==', '===', '!=', '!==', '<=', '>='], true));
            if (!$writes) {
                continue;
            }
            $idWrites++;
            $whole = in_array($text($i - 1), [';', '{', '}', ''], true) || $i === 0;
            $rest = '';
            for ($j = $i + 1; $j <= $i + 6; $j++) {
                $rest .= $text($j);
            }
            $asTheScriptsDo = ["=\$conversionResult['conv_id'];", '=$conversionResult["conv_id"];'];
            if ($whole && in_array($rest, $asTheScriptsDo, true)) {
                $idSet = $i;
            }
        }
        $fires = [];
        foreach ($tokens as $i => $t) {
            if (strcasecmp(ltrim($text($i), '\\'), 'p202FireTrafficSourcePixels') === 0 && $text($i + 1) === '(') {
                $fires[] = $i;
            }
        }
        if ($idSet === null || $idWrites !== 1 || ($fires !== [] && $idSet > min($fires))) {
            $out[] = '$conversionId is not written once, as $conversionId = $conversionResult[\'conv_id\'];,'
                . ' before the pixels are fired';
        } else {
            foreach ($tokens as $i => $t) {
                $loads = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL];
                if ($i > $idSet && is_array($t) && in_array($t[0], $loads, true)) {
                    $out[] = 'an ' . (($t[0] === T_EVAL) ? 'eval' : 'include')
                        . ' after $conversionId is set can change it or $tokens without naming them';
                }
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
            $open = $start;
            while ($open !== null && $text($open) !== '(' && $text($open) !== '') {
                $open++;
            }
            // The statement is the assignment of the call and nothing more: the call's closing paren, then `;`.
            $close = $open;
            for ($depth = 0; $close !== null && $text($close) !== ''; $close++) {
                $depth += $text($close) === '(' ? 1 : ($text($close) === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            if (
                $start === null
                || !in_array(strstr($call, '(', true), ['$tokens=' . self::STAMP, '$tokens=\\' . self::STAMP], true)
                || $text($close + 1) !== ';'
            ) {
                $out[] = 'the tokens fired are not the ones withConversionEventId() returned, assigned in a statement of its own';
                continue;
            }
            if (self::argument($tokens, $open, 4, $text) !== '(int)$conversionId') {
                $out[] = 'withConversionEventId() is not asked for the recorded conversion, (int) $conversionId';
            }
            if ($idSet !== null && $idSet > $start) {
                $out[] = '$conversionId is not written once, as $conversionId = $conversionResult[\'conv_id\'];,'
                    . ' before the stamp';
            }
            // The fire is the statement directly after the stamp: nothing between but what takes its answer.
            $lead = '';
            for ($i = $close + 2; $i < $fire; $i++) {
                $lead .= $text($i);
            }
            if (str_contains($lead, '}')) {
                $out[] = 'the stamp is inside a block that ends before the pixels are fired';
            } elseif (
                !in_array($lead, ['', 'echo', 'return'], true)
                && (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*=$/', $lead) !== 1 || $lead === '$tokens=')
            ) {
                $out[] = 'the pixels are not fired in the statement directly after the stamp';
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

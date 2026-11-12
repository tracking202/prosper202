<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;

/**
 * gpb.php and upx.php fire the traffic source's browser pixels for the
 * conversion they just recorded with that conversion's DestinationEventId
 * (TrafficSourcePixels::EVENT_ID), the id the destinations send it under, so
 * a Meta Purchase and a server event name one sale alike. Held here by
 * reading each script's statements in order: the tokens the pixels are fired
 * with are the ones withConversionEventId() returned, for the recorded
 * conversion, with nothing reassigning them in between.
 */
final class ConversionPixelsCarryTheEventIdTest extends TestCase
{
    private const SCRIPTS = ['tracking202/static/gpb.php', 'tracking202/static/upx.php'];

    public function testEachScriptFiresItsPixelsWithTheConversionsEventId(): void
    {
        foreach (self::SCRIPTS as $script) {
            self::assertSame([], self::problems((string) file_get_contents(dirname(__DIR__, 2) . '/' . $script)), $script);
        }
    }

    public function testTheScanRefusesEachWayTheWiringCanBreak(): void
    {
        $good = '<?php $tokens["payout"] = 1;'
            . ' $tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t");'
            . ' $fired = p202FireTrafficSourcePixels($db, 3, $tokens, null);';
        self::assertSame([], self::problems($good), 'the positive control');
        $planted = [
            'no event id' => '<?php $fired = p202FireTrafficSourcePixels($db, 3, $tokens, null);',
            'the result dropped' => '<?php \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t"); p202FireTrafficSourcePixels($db, 3, $tokens, null);',
            'after the fire' => '<?php p202FireTrafficSourcePixels($db, 3, $tokens, null); $tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t");',
            'reassigned between' => '<?php $tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t"); $tokens = []; p202FireTrafficSourcePixels($db, 3, $tokens, null);',
            'another conversion' => '<?php $tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, 7, "t"); p202FireTrafficSourcePixels($db, 3, $tokens, null);',
            'other tokens fired' => '<?php $tokens = \Prosper202\Conversion\TrafficSourcePixels::withConversionEventId($c, $tokens, 1, 2, (int) $conversionId, "t"); p202FireTrafficSourcePixels($db, 3, $other, null);',
        ];
        foreach ($planted as $why => $code) {
            self::assertNotSame([], self::problems($code), $why);
        }
    }

    /** @return list<string> */
    private static function problems(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $text = static fn ($t): string => is_array($t) ? $t[1] : $t;
        $fires = [];
        foreach ($tokens as $i => $t) {
            $word = ltrim($text($t), '\\');
            if ($word === 'p202FireTrafficSourcePixels' && $text($tokens[$i + 1] ?? '') === '(') {
                $fires[] = $i;
            }
        }
        if ($fires === []) {
            return ['no p202FireTrafficSourcePixels() call'];
        }
        $out = [];
        foreach ($fires as $fire) {
            // The third argument is $tokens, read as the tokens between the second and third top-level comma.
            if (self::argument($tokens, $fire + 1, 2, $text) !== '$tokens') {
                $out[] = 'the pixels are not fired with $tokens';
                continue;
            }
            $stamped = null;
            for ($i = $fire - 1; $i >= 0; $i--) {
                if ($text($tokens[$i]) === '$tokens' && $text($tokens[$i + 1] ?? '') === '=') {
                    $call = '';
                    for ($j = $i + 2; $j < count($tokens) && $text($tokens[$j]) !== '('; $j++) {
                        $call .= $text($tokens[$j]);
                    }
                    $stamped = ltrim($call, '\\') === 'Prosper202\Conversion\TrafficSourcePixels::withConversionEventId' ? $j : false;
                    break;
                }
            }
            if ($stamped === null || $stamped === false) {
                $out[] = 'the tokens fired are not the ones withConversionEventId() returned';
                continue;
            }
            if (!str_contains(self::argument($tokens, $stamped, 4, $text), '$conversionId')) {
                $out[] = 'withConversionEventId() is not asked for the recorded conversion ($conversionId)';
            }
        }

        return $out;
    }

    /**
     * The text of argument $n (0-based) of the call whose `(` is $open.
     *
     * @param list<mixed> $tokens
     */
    private static function argument(array $tokens, int $open, int $n, \Closure $text): string
    {
        $depth = 0;
        $arg = 0;
        $out = '';
        for ($i = $open; $i < count($tokens); $i++) {
            $t = $text($tokens[$i]);
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

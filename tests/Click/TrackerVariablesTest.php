<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\TrackerVariables;
use Tests\Support\SourceScan;

/**
 * The traffic source's variables a click reads, from a tracker row that may
 * not have them: a landing-page click with no tracker has neither key
 * (record_simple.php and record_adv.php read them directly — two "Undefined
 * array key" warnings on every such click, measured in the server log), and
 * a source with no variables has NULL in both (the LEFT JOIN's GROUP_CONCAT).
 */
final class TrackerVariablesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, list<array{string, int}>}> */
    public static function rows(): iterable
    {
        yield 'no tracker' => [['user_id' => '2', 'aff_campaign_id' => '2'], []];
        yield 'a source with no variables' => [['parameters' => null, 'ppc_variable_ids' => null], []];
        yield 'an empty list' => [['parameters' => '', 'ppc_variable_ids' => ''], []];
        yield 'two variables' => [
            ['parameters' => 'kw,gid', 'ppc_variable_ids' => '7,9'],
            [['kw', 7], ['gid', 9]],
        ];
        // dl.php's `!empty()` read this one as none.
        yield 'a variable named 0' => [['parameters' => '0', 'ppc_variable_ids' => '4'], [['0', 4]]];
        yield 'an empty name in the list is skipped, the ids stay aligned' => [
            ['parameters' => 'a,,c', 'ppc_variable_ids' => '1,2,3'],
            [['a', 1], ['c', 3]],
        ];
    }

    /**
     * @dataProvider rows
     * @param array<string, mixed> $row
     * @param list<array{string, int}> $expected
     */
    public function testThePairs(array $row, array $expected): void
    {
        self::assertSame($expected, TrackerVariables::pairs($row));
    }

    /**
     * No click endpoint reads the two columns off a row itself: `$row['parameters']`
     * or `$row['ppc_variable_ids']` in code (an SQL string naming the columns
     * is not a read).
     */
    public function testTheEndpointsAskTheHelper(): void
    {
        $found = [];
        $insignificant = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_starts_with($path, 'tracking202/redirect/') && !str_starts_with($path, 'tracking202/static/')) {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn ($t): bool => !is_array($t) || !in_array($t[0], $insignificant, true)
            ));
            foreach ($tokens as $i => $token) {
                $key = $tokens[$i + 2] ?? null;
                if (
                    is_array($token) && $token[0] === T_VARIABLE && ($tokens[$i + 1] ?? null) === '['
                    && is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(substr($key[1], 1, -1), ['parameters', 'ppc_variable_ids'], true)
                ) {
                    $found[] = $path . ':' . $token[2] . '  ' . $token[1] . '[' . $key[1] . ']';
                }
            }
        }
        self::assertSame([], $found, 'read a tracker row\'s variables with TrackerVariables::pairs():'
            . ' a landing-page click with no tracker has neither key');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A refused entry of a list in a request body is named the one way the API
 * names it everywhere: its field, a dot and its position — `items.0.unit_price`
 * (PayloadKeys::listErrors()), `events.1.event_id`, `subids.3`.
 *
 * POST /events named the same thing `events[1].event_id`, POST /goals/evaluate
 * `goals[0].versions[1]` and `events[0].revenue`, POST /conversions/subids
 * `subids[3]`: a client that reads field_errors to find the field it sent
 * (an agent fixing its body, a form putting the message under the input) had
 * two grammars to parse, and the one it learned from the conversion and LTV
 * bodies did not find these.
 *
 * A key built with a bracketed index is a string literal ending in `name[`
 * that is concatenated with something (`'events[' . $i . ']'`), or the
 * literal part of an interpolated string ending in `name[` (`"offers[$n]"`,
 * `"x[{$i}]"`), in any served PHP file. Comments are not read. A key built
 * by sprintf() or from a variable holding the `[` is not seen.
 */
final class FieldErrorPathsAreDottedTest extends TestCase
{
    /**
     * Repo-relative file => how many such keys it builds, and why each is
     * not a body list's field error the API names with a dot.
     *
     * @var array<string, array{int, string}>
     */
    private const ALLOWED = [
        'api/v3/Controller.php' => [
            1,
            'filter[<field>]: the name of the query parameter the caller sent, as it sent it',
        ],
        'api/v3/Controllers/SetupCodeController.php' => [
            3,
            'offers[<n>]: an offer by its place in the comma-separated `offers` query value, numbered from 1 as'
            . ' the Setup page numbers them; a query value, not a body list',
        ],
        '202-config/Report/OverviewChart.php' => [
            2,
            'levels[<i>][id], types[<i>][type]: the Overview chart builder\'s own form field names, as'
            . ' tracking202/ajax/charts.php receives them from the page; a form post, not an API body',
        ],
        'api/v3/Apps/Android/InstallEventsIntake.php' => [
            1,
            "events[<i>]: the Android SDK's events contract (tests/fixtures/app-sdk-contract/android names"
            . ' `events[0].…`), which the SDK is held to; changing it is an SDK contract change',
        ],
        '202-config/Goals/GoalDefinition.php' => [
            5,
            'after[<i>], trigger.where[<i>], .value[<j>]: the goal definition grammar, shared with the iOS and'
            . ' Android SDKs through tests/fixtures/app-sdk-contract/goals/definitions.json, whose vectors name'
            . ' refusals this way',
        ],
        'api/v3/Controllers/GoalsController.php' => [
            2,
            "definition.after[<i>]: GoalDefinition's after[<i>] under `definition.`, so a prerequisite that"
            . ' names no live goal is named as a malformed one is',
        ],
        '202-config/PHPStan/Rules/VacuousAssertionRule.php' => [
            1,
            'array[…]: a PHP array type in a rule message, not a field',
        ],
    ];

    /**
     * The lines that build a key with a bracketed index.
     *
     * @return list<int>
     */
    private static function bracketedKeys(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                if (preg_match('/\w\[$/D', substr($token[1], 1, -1)) !== 1) {
                    continue;
                }
                $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
                $next = $i + 1;
                while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], $skip, true)) {
                    $next++;
                }
                if (($tokens[$next] ?? null) === '.') {
                    $lines[] = $token[2];
                }
            } elseif ($token[0] === T_ENCAPSED_AND_WHITESPACE && preg_match('/\w\[$/D', $token[1]) === 1) {
                $lines[] = $token[2];
            }
        }

        return $lines;
    }

    public function testNoBodyListEntryIsNamedWithABracket(): void
    {
        $unlisted = [];
        $miscounted = [];
        $seen = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $lines = self::bracketedKeys($source);
            if ($lines === []) {
                continue;
            }
            $seen += count($lines);
            if (!isset(self::ALLOWED[$path])) {
                $unlisted[] = $path . ' line ' . implode(', ', $lines);
            } elseif (self::ALLOWED[$path][0] !== count($lines)) {
                $miscounted[] = $path . ': ' . count($lines) . ' (lines ' . implode(', ', $lines) . '), '
                    . self::ALLOWED[$path][0] . ' listed';
            }
        }
        self::assertSame([], $unlisted, "name a list entry `field.<position>` ('events.' . \$i), as "
            . 'PayloadKeys::listErrors() does; a bracket that is not a body field error goes in ALLOWED with'
            . ' its reason');
        self::assertSame([], $miscounted, 'a listed file gained or lost a bracketed key: dot it, or update ALLOWED');
        $listed = array_sum(array_map(static fn (array $entry): int => $entry[0], self::ALLOWED));
        self::assertSame($listed, $seen, 'a listed file no longer exists or builds none');
    }

    /** @return iterable<string, array{string, int}> */
    public static function shapes(): iterable
    {
        yield 'concatenated' => ['<?php $e["events[" . $i . "].name"] = "x";', 1];
        yield 'single quotes, path variable' => ["<?php \$path = 'events[' . \$i . ']';", 1];
        yield 'a prefix before the bracket' => ["<?php \$vp = \$path . '.versions[' . \$j . ']';", 1];
        yield 'interpolated' => ['<?php $e["offers[$n]"] = "x";', 1];
        yield 'interpolated in braces' => ['<?php $e["x[{$i}]"] = "x";', 1];
        yield 'a comment between' => ["<?php \$p = 'a[' /* index */ . \$i . ']';", 1];
        yield 'the dotted form' => ["<?php \$path = 'events.' . \$i;", 0];
        yield 'an array index is not a key' => ['<?php $a = $b[$i]; $c = "$d[0]";', 0];
        yield 'a whole literal is not built' => ["<?php \$k = 'filter[name]'; \$j = 'a[';", 0];
        yield 'a comment is not read' => ["<?php // 'events[' . \$i\n\$x = 1;", 0];
    }

    /** @dataProvider shapes */
    public function testTheShapesTheScanReads(string $source, int $expected): void
    {
        self::assertCount($expected, self::bracketedKeys($source));
    }
}

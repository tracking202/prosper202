<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlLiteralText;

/**
 * Two invariants over the tree for web events (plan §2.2, §5.5):
 *
 * 1. Every static endpoint that records a conversion asks
 *    p202RecordWebEvent() first. An endpoint that recorded its plain
 *    conversion without asking would turn an `event=` hit on a goal
 *    campaign into a plain conversion — money the goals never decided —
 *    and the next endpoint added would do the same. The one exemption is
 *    named here with its reason; a new recording endpoint fails until it
 *    is placed in one list or the other.
 * 2. Every GoalEngine built outside the tests is given a notifier. The
 *    engine decides which outcomes a traffic source is told about; one
 *    built without a notifier writes payable outcomes on a campaign that
 *    notifies and tells nobody, silently. The PR 5 intake will build one
 *    too, and this is what makes it decide.
 *
 * Calls are found as calls (CLAUDE.md #21): the name as a T_STRING that is
 * not a method (`->`, `?->`, `::`), a declaration (`function`), a
 * constructor (`new`) or another namespace's function, followed by `(`.
 */
final class WebEventPathsTest extends TestCase
{
    private const EVENT_ENDPOINTS = [
        'tracking202/static/gpb.php',
        'tracking202/static/gpx.php',
        'tracking202/static/upx.php',
        'tracking202/static/pb.php',
        'tracking202/static/px.php',
    ];

    private const EXEMPT = [
        // ClickBank's INS carries a receipt and an order, never an event:
        // its payload is ClickBank's, not something an operator templates.
        'tracking202/static/cb202.php' => 'ClickBank INS has no event parameter',
    ];

    private const RECORDERS = ['p202RecordConversion', 'p202RecordLegacyConversion'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Positions (token index) of calls to a global function in a file.
     *
     * @param list<mixed> $tokens
     * @return list<int>
     */
    public static function callsTo(array $tokens, string $name): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || $t[0] !== T_STRING || strcasecmp($t[1], $name) !== 0) {
                continue;
            }
            $prev = self::neighbour($tokens, $i, -1);
            $next = self::neighbour($tokens, $i, 1);
            if ($next !== '(') {
                continue;
            }
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NS_SEPARATOR], true)) {
                continue;
            }
            $out[] = $i;
        }

        return $out;
    }

    /** @param list<mixed> $tokens */
    private static function neighbour(array $tokens, int $i, int $step): mixed
    {
        for ($j = $i + $step; isset($tokens[$j]); $j += $step) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    /** @return list<mixed> */
    private static function tokens(string $relative): array
    {
        $source = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($source, $relative);

        return token_get_all($source);
    }

    public function testEveryRecordingEndpointAsksTheEventPathFirst(): void
    {
        $recording = [];
        foreach (glob(self::root() . '/tracking202/static/*.php') ?: [] as $path) {
            $relative = substr($path, strlen(self::root()) + 1);
            $tokens = self::tokens($relative);
            $first = null;
            foreach (self::RECORDERS as $recorder) {
                foreach (self::callsTo($tokens, $recorder) as $at) {
                    $first = $first === null ? $at : min($first, $at);
                }
            }
            if ($first !== null) {
                $recording[$relative] = [$tokens, $first];
            }
        }
        self::assertNotEmpty($recording, 'the scan found no recording endpoint at all: it is reading the wrong tree');

        foreach ($recording as $relative => [$tokens, $first]) {
            if (isset(self::EXEMPT[$relative])) {
                self::assertSame([], self::callsTo($tokens, 'p202RecordWebEvent'), $relative . ' is exempt and takes no event');
                continue;
            }
            self::assertContains($relative, self::EVENT_ENDPOINTS, $relative . ' records a conversion but is not an event endpoint: '
                . 'call p202RecordWebEvent() before recording (and list it in EVENT_ENDPOINTS), or exempt it with a reason');
            $asks = self::callsTo($tokens, 'p202RecordWebEvent');
            self::assertCount(1, $asks, $relative . ' asks the event path exactly once');
            self::assertLessThan($first, $asks[0], $relative . ' asks the event path before it records a plain conversion');
        }
        foreach (self::EVENT_ENDPOINTS as $relative) {
            self::assertArrayHasKey($relative, $recording, $relative . ' is listed as an event endpoint but records nothing');
        }
    }

    public function testEveryGoalEngineBuiltOutsideTheTestsHasANotifier(): void
    {
        // Everything but tests/, vendor/ and the other non-PHP trees; the tests
        // build engines without notifiers on purpose.
        $built = 0;
        foreach (SqlLiteralText::phpFiles(self::root()) as $path) {
            $relative = substr($path, strlen(self::root()) + 1);
            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'GoalEngine')) {
                continue;
            }
            $tokens = token_get_all($source);
            foreach ($tokens as $i => $t) {
                if (!is_array($t) || $t[0] !== T_NEW) {
                    continue;
                }
                $class = self::neighbour($tokens, $i, 1);
                if (!is_array($class) || !preg_match('/(^|\\\\)GoalEngine$/', $class[1])) {
                    continue;
                }
                $built++;
                [$args, $named] = self::arguments($tokens, $i);
                self::assertTrue($args >= 5 || in_array('notifier', $named, true),
                    $relative . ' builds a GoalEngine without a notifier: payable goals the campaign notifies for would be told to no one. '
                    . 'Pass a TrafficSourceNotifier.');
            }
        }
        // The event endpoints' helper, event.php, POST /events, and re-evaluation.
        self::assertGreaterThanOrEqual(4, $built, 'the scan found too few engines to be reading the tree');
    }

    /**
     * The argument count and named arguments of the `new X(...)` at $new.
     *
     * @param list<mixed> $tokens
     * @return array{0: int, 1: list<string>}
     */
    private static function arguments(array $tokens, int $new): array
    {
        $j = $new;
        while (isset($tokens[$j]) && $tokens[$j] !== '(') {
            $j++;
        }
        $depth = 0;
        $args = 0;
        $named = [];
        $seen = false;
        for (; isset($tokens[$j]); $j++) {
            $t = $tokens[$j];
            if (in_array($t, ['(', '[', '{'], true) || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if (in_array($t, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth === 1 && $t === ',') {
                $args++;
                $seen = false;
                continue;
            }
            if ($depth === 1 && is_array($t) && !in_array($t[0], [T_WHITESPACE, T_COMMENT], true)) {
                if (!$seen && $t[0] === T_STRING && self::neighbour($tokens, $j, 1) === ':') {
                    $named[] = $t[1];
                }
                $seen = true;
            } elseif ($depth === 1 && $t !== ',' && !is_array($t)) {
                $seen = true;
            }
        }

        return [$args + ($seen ? 1 : 0), $named];
    }
}

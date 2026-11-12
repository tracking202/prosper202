<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\InboundFix;
use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\OutcomeKind;

/**
 * Every outcome a refused request can be logged with says what to do about
 * it (InboundFix), or is listed in InboundFix::NO_FIX with why: the outcomes
 * the endpoints and the request log name, the event recorder's refusals as
 * the pixels log them ('event_' and its status), and a payment
 * notification's (OutcomeKind).
 */
final class InboundFixCoversEveryRefusalTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> */
    private static function loggedOutcomes(): array
    {
        $files = [
            self::ROOT . '/202-config/static-endpoint-helpers.php',
            self::ROOT . '/202-config/InboundLog/RequestLog.php',
            ...(glob(self::ROOT . '/tracking202/static/*.php') ?: []),
        ];
        $found = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/->(?:refuse|legacyResult|converted)\\(\\s*'([a-z_]+)'/", $source, $m);
            array_push($found, ...$m[1]);
            preg_match_all("/p202PostbackAuthNoteRefusal\\([^,]+,\\s*'([a-z_]+)'/", $source, $m);
            array_push($found, ...$m[1]);
        }

        // RequestLog::entry()'s own outcomes for a request nothing named one for.
        $log = (string) file_get_contents(self::ROOT . '/202-config/InboundLog/RequestLog.php');
        self::assertSame(1, preg_match("/\\? '([a-z_]+)' : \\(\\\$status >= 500 \\? '([a-z_]+)' : '([a-z_]+)'\\)/", $log, $m),
            'RequestLog::entry() names its default outcomes where this test reads them');
        array_push($found, $m[1], $m[2], $m[3]);
        $found = [...$found, ...array_keys(\Prosper202\InboundLog\RequestLog::LEGACY_REASONS)];

        // The pixels log an event's refusal as 'event_' and the status p202RecordWebEvent() answered.
        $helpers = (string) file_get_contents(self::ROOT . '/202-config/static-endpoint-helpers.php');
        $start = strpos($helpers, 'function p202RecordWebEvent(');
        $end = strpos($helpers, "\nif (!function_exists(", (int) $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        preg_match_all("/'status' => '([a-z_]+)'/", substr($helpers, $start, $end - $start), $m);
        $statuses = array_diff(array_unique($m[1]), ['recorded', 'no_goals']);
        self::assertContains('invalid', $statuses, 'the scan read the event recorder\'s statuses');
        $pixels = 0;
        foreach (['px', 'gpx'] as $endpoint) {
            $pixels += substr_count((string) file_get_contents(self::ROOT . "/tracking202/static/$endpoint.php"), "refuse('event_' . \$webEvent['status']");
        }
        self::assertSame(2, $pixels, 'px.php and gpx.php log an event refusal as event_<status>');
        foreach ($statuses as $status) {
            $found[] = 'event_' . $status;
        }

        foreach (OutcomeKind::cases() as $kind) {
            $found[] = $kind->value;
        }

        return array_values(array_unique(array_diff($found, InboundLog::ACCEPTED_OUTCOMES, ['event_'])));
    }

    public function testEveryRefusalSaysWhatToDoOrWhyNot(): void
    {
        $missing = [];
        foreach (self::loggedOutcomes() as $outcome) {
            $fix = InboundFix::of(['accepted' => false, 'outcome' => $outcome]);
            if ($fix === null && !array_key_exists($outcome, InboundFix::NO_FIX)) {
                $missing[] = $outcome;
            }
            if ($fix !== null && $fix['page'] !== null) {
                self::assertFileExists(self::ROOT . '/' . rtrim($fix['page'], '/') . (str_ends_with($fix['page'], '/') ? '/index.php' : ''),
                    $outcome . ' links a page that exists');
            }
        }
        sort($missing);

        self::assertSame([], $missing, 'a refusal with no line saying what to do: add it to InboundFix, or to NO_FIX with why');
    }

    public function testEveryFixAndExceptionIsAnOutcomeThatIsLogged(): void
    {
        $logged = self::loggedOutcomes();
        $stale = array_values(array_diff([...InboundFix::outcomes(), ...array_keys(InboundFix::NO_FIX)], $logged));

        self::assertSame([], $stale, 'a fix line or exception for an outcome nothing logs: remove it');
    }
}

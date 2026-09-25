<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Apps\Apple\SkanEncodingTimeline;
use PHPUnit\Framework\TestCase;

/**
 * Encoding versions with the 35-day horizon (plan §5.5): a postback decodes
 * under every meaning its value had in the 35 days before it arrived, and a
 * disagreement is ambiguous_encoding, credited to neither.
 */
final class SkanEncodingTimelineTest extends TestCase
{
    private const H = SkanEncodingTimeline::HORIZON_SECONDS;
    private const EDIT = 1_700_000_000;

    /** @return array<string, mixed> */
    private static function meaning(int $registration, int $goal, int $from, ?int $until = null, ?int $fine = 10, ?string $coarse = null, ?string $override = null): array
    {
        return [
            'registration_id' => $registration,
            'fine_value' => $fine,
            'coarse_value' => $coarse,
            'goal_id' => $goal,
            'revenue_override' => $override,
            'effective_at' => $from,
            'retired_at' => $until,
        ];
    }

    private static function status(SkanEncodingTimeline $t, int $at, int $registration = 7, ?int $fine = 10, ?string $coarse = null): string
    {
        $d = $t->decode($registration, $fine, $coarse, $at);

        return $d['status'] . ($d['goal_id'] !== null ? ':' . $d['goal_id'] : '');
    }

    public function testTheHorizonIsThirtyFiveDays(): void
    {
        self::assertSame(35 * 86400, self::H);
    }

    public function testAnEditIsAmbiguousForExactlyTheHorizonAfterIt(): void
    {
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, 1, self::EDIT),
            self::meaning(7, 2, self::EDIT),
        ]);
        self::assertSame('decoded:1', self::status($t, self::EDIT - 1), 'before the edit: only the old meaning');
        self::assertSame('ambiguous', self::status($t, self::EDIT), 'from the edit on, a device may hold either document');
        self::assertSame('ambiguous', self::status($t, self::EDIT + self::H - 1));
        self::assertSame('decoded:2', self::status($t, self::EDIT + self::H), 'exact again 35 days later');
        self::assertSame(2, $t->decode(7, 10, null, self::EDIT)['meanings']);
    }

    public function testAnEditThatKeepsTheMeaningIsNotAmbiguous(): void
    {
        // Re-saving the same goal and override (or a failed update that left
        // a copy of the current meaning in the history) changes nothing.
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, 1, self::EDIT, override: '4.99'),
            self::meaning(7, 1, self::EDIT, override: '4.99000'),
        ]);
        self::assertSame('decoded:1', self::status($t, self::EDIT + 5));
    }

    public function testAChangedRevenueOverrideIsAnotherMeaning(): void
    {
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, 1, self::EDIT, override: '4.99'),
            self::meaning(7, 1, self::EDIT, override: '9.99'),
        ]);
        self::assertSame('ambiguous', self::status($t, self::EDIT + 5));
        self::assertSame('9.99000', $t->decode(7, 10, null, self::EDIT + self::H)['revenue_override']);
    }

    public function testADeletedEncodingStillDecodesWhatDevicesSetBeforeIt(): void
    {
        $t = new SkanEncodingTimeline([self::meaning(7, 1, 1, self::EDIT)]);
        self::assertSame('decoded:1', self::status($t, self::EDIT + self::H - 1));
        self::assertSame('undecoded', self::status($t, self::EDIT + self::H));
    }

    public function testAnEncodingAddedLaterStillReadsEarlierPostbacks(): void
    {
        // Nothing meant the value inside the horizon: the first meaning it
        // was given afterwards decodes it (the report always has).
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, self::EDIT, self::EDIT + 100),
            self::meaning(7, 2, self::EDIT + 100),
        ]);
        self::assertSame('decoded:1', self::status($t, self::EDIT - 10 * self::H), 'the first meaning, not the current one');
        self::assertSame('undecoded', self::status($t, self::EDIT - 10, fine: 11), 'a value never given a meaning');
    }

    public function testAnAppEncodingOverAnAccountOneIsAnEditForThatAppOnly(): void
    {
        $t = new SkanEncodingTimeline([
            self::meaning(0, 1, 1),
            self::meaning(7, 2, self::EDIT),
        ]);
        self::assertSame('decoded:1', self::status($t, self::EDIT - 1));
        self::assertSame('ambiguous', self::status($t, self::EDIT + 1), 'devices of app 7 may still set 10 for the account goal');
        self::assertSame('decoded:2', self::status($t, self::EDIT + self::H));
        self::assertSame('decoded:1', self::status($t, self::EDIT + 1, registration: 8), 'another app never had its own meaning');
        self::assertSame('decoded:1', self::status($t, self::EDIT + 1, registration: 0), 'an unclaimed postback reads the account-wide set');
    }

    public function testAFineValueNeverFallsBackToACoarseOne(): void
    {
        $t = new SkanEncodingTimeline([self::meaning(7, 1, 1, fine: null, coarse: 'high')]);
        self::assertSame('undecoded', self::status($t, self::EDIT, fine: 3, coarse: 'high'));
        self::assertSame('decoded:1', self::status($t, self::EDIT, fine: null, coarse: 'high'));
    }

    public function testAMeaningReplacedInTheSecondItBeganNeverApplied(): void
    {
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, 1, self::EDIT),
            self::meaning(7, 3, self::EDIT, self::EDIT),
            self::meaning(7, 2, self::EDIT),
        ]);
        self::assertSame(2, $t->decode(7, 10, null, self::EDIT + 1)['meanings'], 'goal 3 applied at no instant');
    }

    /**
     * The report does not decode each postback: it groups rows by the
     * segment between two breakpoints (MySQL's INTERVAL()) and decodes each
     * segment at representativeTime(). That is only an optimisation if it
     * returns the answer decoding every postback at its own time would, so
     * this checks it on dense random timelines at every breakpoint, one
     * second either side, and random times between.
     */
    public function testDecodingASegmentOnceEqualsDecodingEveryPostback(): void
    {
        mt_srand(8);
        for ($round = 0; $round < 40; $round++) {
            $meanings = [];
            foreach ([0, 7] as $registration) {
                foreach ([10, 11] as $fine) {
                    $t = mt_rand(0, 50) * 86400;
                    $n = mt_rand(0, 4);
                    for ($i = 0; $i < $n; $i++) {
                        $next = $t + mt_rand(0, 60) * 86400 + mt_rand(0, 3);
                        $retire = $i === $n - 1 && mt_rand(0, 1) === 0 ? null : $next;
                        $meanings[] = self::meaning($registration, mt_rand(1, 3), $t, $retire, $fine, override: mt_rand(0, 1) ? '1.5' : null);
                        if ($retire === null) {
                            break;
                        }
                        $t = $next + (mt_rand(0, 3) === 0 ? mt_rand(1, 90) * 86400 : 0); // sometimes a gap
                    }
                }
            }
            $timeline = new SkanEncodingTimeline($meanings);
            $points = $timeline->breakpoints();
            $times = [];
            foreach ($points as $b) {
                array_push($times, $b - 1, $b, $b + 1);
            }
            for ($i = 0; $i < 60; $i++) {
                $times[] = mt_rand(-10, 400) * 86400 + mt_rand(0, 86399);
            }
            foreach ($times as $at) {
                $segment = count(array_filter($points, static fn (int $b): bool => $b <= $at)); // INTERVAL()
                foreach ([0, 7, 8] as $registration) {
                    foreach ([10, 11] as $fine) {
                        self::assertSame(
                            $timeline->decode($registration, $fine, null, $at),
                            $timeline->decode($registration, $fine, null, $timeline->representativeTime($segment)),
                            "round $round, received $at, registration $registration, value $fine"
                        );
                    }
                }
            }
        }
    }
}

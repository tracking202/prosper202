<?php

declare(strict_types=1);

namespace Tests\Apps\Apple;

use Api\V3\Apps\Apple\SkanEncodingTimeline;
use PHPUnit\Framework\TestCase;

/**
 * Encoding versions with the postback horizon (plan §5.5, §5.8): a postback
 * decodes under every meaning its value had in the horizon before it
 * arrived, and a disagreement is ambiguous_encoding, credited to neither.
 * Meanings and postbacks are keyed by app (App Store id; 0 = account-wide).
 */
final class SkanEncodingTimelineTest extends TestCase
{
    private const H = SkanEncodingTimeline::HORIZON_SECONDS;
    private const EDIT = 1_700_000_000;

    /** @return array<string, mixed> */
    private static function meaning(int $app, int $goal, int $from, ?int $until = null, ?int $fine = 10, ?string $coarse = null, ?string $override = null): array
    {
        return [
            'app_id' => $app,
            'fine_value' => $fine,
            'coarse_value' => $coarse,
            'goal_id' => $goal,
            'revenue_override' => $override,
            'effective_at' => $from,
            'retired_at' => $until,
        ];
    }

    private static function status(SkanEncodingTimeline $t, int $at, int $app = 7, ?int $fine = 10, ?string $coarse = null): string
    {
        $d = $t->decode($app, $fine, $coarse, $at);

        return $d['status'] . ($d['goal_id'] !== null ? ':' . $d['goal_id'] : '');
    }

    /**
     * The horizon is the longest a device's document can predate the
     * postback's arrival: the third conversion window's close (day 35),
     * Apple's longest random delivery delay after it (144 hours), and the
     * age past which the iOS SDK stops encoding with a cached document.
     */
    public function testTheHorizonCoversTheWindowsTheDeliveryDelayAndTheSchemaAge(): void
    {
        self::assertSame(35, SkanEncodingTimeline::CONVERSION_WINDOW_DAYS);
        self::assertSame(144 * 3600, SkanEncodingTimeline::DELIVERY_DELAY_DAYS * 86400);
        self::assertSame(48 * 86400, self::H);
    }

    /**
     * The SDK's bound is half of the horizon's third term: if the Swift
     * constant moved without this one, devices would encode with documents
     * older than the report looks back for.
     */
    public function testTheSchemaAgeIsTheSdksOwnBound(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/sdk/ios-attribution/Sources/P202Attribution/P202Attribution.swift');
        self::assertSame(1, preg_match('/public static let maxSchemaAge: TimeInterval = (\d+) \* 24 \* 60 \* 60\n/', $source, $m), 'P202Attribution.maxSchemaAge is declared in days');
        self::assertSame(SkanEncodingTimeline::SCHEMA_MAX_AGE_DAYS, (int) $m[1]);
    }

    /**
     * The finding this horizon exists for: a device holding the pre-edit
     * document sets the value on day 35 of the third window, and Apple
     * delivers the postback 144 hours after the window closes. Its arrival
     * is 41 days after the device's document was fetched — an old 35-day
     * horizon ends after the retirement and credits the new meaning.
     */
    public function testAThirdWindowPostbackDeliveredLateStillSeesTheMeaningItWasSetUnder(): void
    {
        $fetched = self::EDIT - 1; // the device's last fetch, just before the edit
        $t = new SkanEncodingTimeline([
            self::meaning(7, 1, 1, self::EDIT),
            self::meaning(7, 2, self::EDIT),
        ]);
        $arrival = $fetched + 35 * 86400 + 144 * 3600;
        self::assertSame('ambiguous', self::status($t, $arrival), 'the pre-edit meaning is still possible');
        // And a re-engagement whose windows began up to the SDK's schema age
        // after that fetch.
        self::assertSame('ambiguous', self::status($t, $arrival + 7 * 86400));
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
        self::assertSame('decoded:2', self::status($t, self::EDIT + self::H), 'exact again a horizon later');
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
        self::assertSame('decoded:1', self::status($t, self::EDIT + 1, app: 8), 'another app never had its own meaning');
        self::assertSame('decoded:1', self::status($t, self::EDIT + 1, app: 0), 'app 0 reads the account-wide set only');
    }

    public function testAnUnresolvableAppMatchesNoPostback(): void
    {
        // The report hands a meaning whose app it could not read a negative
        // id: it must neither decode a postback nor count as account-wide.
        $t = new SkanEncodingTimeline([
            self::meaning(0, 1, 1),
            self::meaning(-5, 2, 1),
        ]);
        self::assertSame('decoded:1', self::status($t, self::EDIT));
        self::assertSame('decoded:1', self::status($t, self::EDIT, app: 0));
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
            foreach ([0, 7] as $app) {
                foreach ([10, 11] as $fine) {
                    $t = mt_rand(0, 50) * 86400;
                    $n = mt_rand(0, 4);
                    for ($i = 0; $i < $n; $i++) {
                        $next = $t + mt_rand(0, 60) * 86400 + mt_rand(0, 3);
                        $retire = $i === $n - 1 && mt_rand(0, 1) === 0 ? null : $next;
                        $meanings[] = self::meaning($app, mt_rand(1, 3), $t, $retire, $fine, override: mt_rand(0, 1) ? '1.5' : null);
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
                foreach ([0, 7, 8] as $app) {
                    foreach ([10, 11] as $fine) {
                        self::assertSame(
                            $timeline->decode($app, $fine, null, $at),
                            $timeline->decode($app, $fine, null, $timeline->representativeTime($segment)),
                            "round $round, received $at, app $app, value $fine"
                        );
                    }
                }
            }
        }
    }
}

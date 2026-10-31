<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ModelType;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * A stored journey is a superset of every model's window, and says so
 * (plan §6.3 Storage).
 *
 * A conversion's journey is built at 30 days with a 45-day-old click from
 * the same visitor outside it. Activating a 60-day model cannot be served
 * from that journey, so the conversion is re-queued with reason
 * rebuild_journey and its journey rebuilt from 202_clicks_visitor: the old
 * click appears in the journey and in the 60-day model's credits. A 7-day
 * model over the same journey reads it without a rebuild.
 *
 * @group integration
 */
final class JourneyLookbackTest extends TestCase
{
    use AttributionDatabase;

    public function testAWiderModelRebuildsTheJourneyAndANarrowerOneDoesNot(): void
    {
        $now = time();
        $this->campaign(1);
        foreach ([700 => 45 * 86400, 701 => 10 * 86400, 702 => 3600] as $click => $age) {
            $this->click($click, 1, $now - $age);
            $this->visit($click, $now - $age, self::cookie('lookback'));
        }
        $conv = $this->convert(702, '6');
        $this->work();
        self::assertSame([701, 702], self::journeyClicks($conv), 'built at 30 days: the 45-day-old click is outside');
        self::assertSame('30', (string) self::scalar("SELECT built_lookback_days FROM 202_attribution_journey_meta WHERE conv_id=$conv"));

        $wide = $this->addModel('Linear 60', ModelType::LINEAR, [], 60);
        (new \Prosper202\Attribution\AttributionWorker($this->conn))->fanOutModelRecomputes();
        self::assertSame('rebuild_journey', self::scalar("SELECT reason FROM 202_attribution_pending WHERE conv_id=$conv"), 'the log says why');
        $report = $this->work();
        self::assertSame(1, $report->outcomes['credited'] ?? 0, $report->summary());
        self::assertSame('60', (string) self::scalar("SELECT built_lookback_days FROM 202_attribution_journey_meta WHERE conv_id=$conv"));
        self::assertSame([700, 701, 702], self::journeyClicks($conv), 'rebuilt from the raw click identity data');
        self::assertSame([700 => '0.33333333', 701 => '0.33333333', 702 => '0.33333334'], self::credits($conv, $wide));
        self::assertSame([702 => '1.00000000'], self::credits($conv, $this->defaultModelId()), 'the 30-day default still reads its own window');

        // Mark the journey so a rebuild would show, then add a 7-day model.
        self::$db->query("UPDATE 202_attribution_journey_meta SET built_at = 1 WHERE conv_id=$conv");
        $narrow = $this->addModel('Linear 7', ModelType::LINEAR, [], 7);
        $this->work();
        self::assertSame('1', (string) self::scalar("SELECT built_at FROM 202_attribution_journey_meta WHERE conv_id=$conv"), 'read from the stored journey, not rebuilt');
        self::assertSame([702 => '1.00000000'], self::credits($conv, $narrow), 'only the converting click is inside 7 days');
        self::assertSame([700 => '0.33333333', 701 => '0.33333333', 702 => '0.33333334'], self::credits($conv, $wide), 'the other models are untouched');
    }

    public function testARebuildQueuedBehindAWeakReasonStillRebuilds(): void
    {
        $now = time();
        $this->campaign(1);
        foreach ([800 => 40 * 86400, 801 => 3600] as $click => $age) {
            $this->click($click, 1, $now - $age);
            $this->visit($click, $now - $age, self::cookie('weak'));
        }
        $conv = $this->convert(801, '2');
        $this->work();
        self::assertSame([801], self::journeyClicks($conv));

        // A model change queued first (weak), then the lookback widening: the
        // row must not keep the weak reason and reuse the narrow journey.
        (new \Prosper202\Attribution\AttributionWorker($this->conn))->enqueue([$conv], 'model_changed', false);
        $this->addModel('Linear 45', ModelType::LINEAR, [], 45);
        $this->work();
        self::assertSame([800, 801], self::journeyClicks($conv));
    }

    public function testNoReasonLetsAModelReadAJourneyNarrowerThanItsWindow(): void
    {
        $now = time();
        $this->campaign(1);
        foreach ([900 => 50 * 86400, 901 => 3600] as $click => $age) {
            $this->click($click, 1, $now - $age);
            $this->visit($click, $now - $age, self::cookie('guard'));
        }
        $conv = $this->convert(901, '2');
        $this->work();
        self::assertSame([901], self::journeyClicks($conv));

        // A model widened behind the fan-out's back (no recompute request),
        // and the conversion queued with the one reason that may reuse a
        // stored journey: the worker still sees the journey is too narrow.
        $wide = $this->addModel('Linear 60', ModelType::LINEAR, [], 60);
        self::$db->query('UPDATE 202_attribution_models SET recompute_requested_at = NULL');
        (new \Prosper202\Attribution\AttributionWorker($this->conn))->enqueue([$conv], 'model_changed', false);
        $this->work();
        self::assertSame([900, 901], self::journeyClicks($conv));
        self::assertSame([900 => '0.50000000', 901 => '0.50000000'], self::credits($conv, $wide));
    }
}

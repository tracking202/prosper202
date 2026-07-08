<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlEngagementRepository;
use Prosper202\Ltv\MysqlPersonalizationRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Manually instrumented ABM engagement events: name normalization, recording
 * writes + recency touch, and token-authenticated resolution for the public
 * site beacon.
 */
final class EngagementEventTest extends TestCase
{
    public function testEventNameNormalization(): void
    {
        self::assertSame('pricing_viewed', MysqlEngagementRepository::normalizeEventName('Pricing Viewed'));
        self::assertSame('demo.requested-v2', MysqlEngagementRepository::normalizeEventName('demo.requested-v2'));

        foreach (['', str_repeat('a', 65), 'has<script>', 'naïve-event', 'semi;colon'] as $bad) {
            try {
                MysqlEngagementRepository::normalizeEventName($bad);
                self::fail('Expected rejection for ' . var_export($bad, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRecordEventWritesRowAndTouchesRecency(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsInsertId('INSERT INTO 202_engagement_events', 42);

        $repo = new MysqlEngagementRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->recordEvent(7, 501, 'Demo Requested', 'site', 12345, 1700000000);

        $inserts = $write->statementsContaining('INSERT INTO 202_engagement_events');
        self::assertCount(1, $inserts);
        self::assertSame('iissidii', $inserts[0]->boundTypes);
        self::assertContains('demo_requested', $inserts[0]->boundValues, 'name must be normalized');
        self::assertContains('site', $inserts[0]->boundValues);
        self::assertContains(12345, $inserts[0]->boundValues);
        self::assertContains(1700000000, $inserts[0]->boundValues);

        $touches = $write->statementsContaining('UPDATE 202_customers SET last_activity_time');
        self::assertCount(1, $touches, 'manual events must count as customer activity');
    }

    public function testRecordEventStoresAndClampsDepthValues(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlEngagementRepository(new Connection($write, new FakeMysqliConnection()));

        // Normal depth value passes through.
        $repo->recordEvent(7, 501, 'scroll_depth', 'site', null, null, 75.0);
        // Client-supplied garbage is clamped, never rejected (the beacon must
        // stay a silent no-oracle endpoint) and never negative.
        $repo->recordEvent(7, 501, 'time_on_page', 'site', null, null, -50.0);
        $repo->recordEvent(7, 501, 'video_viewed', 'site', null, null, 9e12);

        $inserts = $write->statementsContaining('INSERT INTO 202_engagement_events');
        self::assertCount(3, $inserts);
        self::assertContains(75.0, $inserts[0]->boundValues);
        self::assertContains(0.0, $inserts[1]->boundValues, 'negative values clamp to zero');
        self::assertContains(999999999.999, $inserts[2]->boundValues, 'oversized values clamp to the column range');
    }

    public function testRecordEventRejectsUnknownSource(): void
    {
        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), new FakeMysqliConnection()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source');
        $repo->recordEvent(7, 501, 'ok_event', 'webhook');
    }

    public function testCustomerForTokenResolvesWithinReplayWindowRegardlessOfSeal(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'user_id' => 7, 'customer_id' => 501, 'click_id' => 12345, 'replay_until' => 1702592000,
        ]]);
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));

        // Sealed or not is irrelevant for the write side: only the replay
        // window gates event attribution.
        $resolved = $repo->customerForToken(str_repeat('a', 43), 1700100000);
        self::assertNotNull($resolved);
        self::assertSame(7, $resolved['userId']);
        self::assertSame(501, $resolved['customerId']);
        self::assertSame(12345, $resolved['clickId']);

        // Past the replay window: dead.
        self::assertNull($repo->customerForToken(str_repeat('a', 43), 1702592001));
        // Malformed: rejected before the database.
        self::assertNull($repo->customerForToken('nope', 1700100000));
    }

    public function testCustomerEventsScopedAndOrdered(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_engagement_events', [
            ['event_name' => 'pricing_viewed', 'source' => 'site', 'occurred_at' => 1700000200, 'click_id' => null],
        ]);
        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));

        $rows = $repo->customerEvents(7, 501, 90, 25);
        self::assertCount(1, $rows);

        $queries = $read->statementsContaining('FROM 202_engagement_events');
        self::assertCount(1, $queries);
        self::assertSame('iiii', $queries[0]->boundTypes);
        self::assertContains(7, $queries[0]->boundValues);
        self::assertContains(501, $queries[0]->boundValues);
        self::assertStringContainsString('ORDER BY occurred_at DESC', $queries[0]->sql);
    }

    public function testEngagementScoreIsDeterministicAndBounded(): void
    {
        $now = 1700000000;

        // No activity at all -> 0.
        self::assertSame(0, MysqlEngagementRepository::engagementScore([], $now));

        // Everything maxed (10 engagements/contact, 5-min avg time, full
        // scroll + video, activity today) -> exactly 100, never above.
        self::assertSame(100, MysqlEngagementRepository::engagementScore([
            'engagements' => 50, 'contacts' => 2,
            'avg_time_on_page' => 900.0, 'avg_scroll_depth' => 100.0, 'avg_video_pct' => 100.0,
            'last_activity' => $now,
        ], $now));

        // Component math: 5 engagements / 1 contact = 20 volume pts,
        // 150s avg time = 10 pts, 50% scroll = 7.5 pts, no video,
        // last activity 10 days ago = 5 recency pts -> round(42.5) = 43.
        self::assertSame(43, MysqlEngagementRepository::engagementScore([
            'engagements' => 5, 'contacts' => 1,
            'avg_time_on_page' => 150.0, 'avg_scroll_depth' => 50.0,
            'last_activity' => $now - 10 * 86400,
        ], $now));

        // Recency tiers: within 7 days adds 10, past 30 days adds nothing.
        self::assertSame(10, MysqlEngagementRepository::engagementScore(['last_activity' => $now - 86400], $now));
        self::assertSame(0, MysqlEngagementRepository::engagementScore(['last_activity' => $now - 31 * 86400], $now));

        // Same inputs, same output — the score is a pure function.
        $aggregates = ['engagements' => 7, 'contacts' => 3, 'avg_time_on_page' => 60.0, 'last_activity' => $now - 3 * 86400];
        self::assertSame(
            MysqlEngagementRepository::engagementScore($aggregates, $now),
            MysqlEngagementRepository::engagementScore($aggregates, $now)
        );
    }

    public function testParseScoreWeightsStrictContract(): void
    {
        self::assertSame(
            MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS,
            MysqlEngagementRepository::parseScoreWeights(''),
            'empty pref means account defaults'
        );
        self::assertSame(
            ['volume' => 60, 'time' => 10, 'scroll' => 10, 'video' => 10, 'recency' => 10],
            MysqlEngagementRepository::parseScoreWeights('volume:60, time:10, scroll:10, video:10, recency:10')
        );

        $invalid = [
            'volume:60',                                            // missing components
            'volume:60,time:10,scroll:10,video:10,recency:20',      // sums to 110
            'volume:40,time:20,scroll:15,video:15,recency:10,bogus:0', // unknown key
            'volume:40,volume:0,time:20,scroll:15,video:15,recency:10', // duplicate
            'volume:abc,time:20,scroll:15,video:15,recency:10',     // non-integer
        ];
        foreach ($invalid as $raw) {
            try {
                MysqlEngagementRepository::parseScoreWeights($raw);
                self::fail('Expected rejection for ' . var_export($raw, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEngagementScoreHonorsCustomWeights(): void
    {
        $now = 1700000000;
        $volumeOnly = ['volume' => 100, 'time' => 0, 'scroll' => 0, 'video' => 0, 'recency' => 0];

        // 5 engagements/contact = half the 10-per-contact saturation point.
        self::assertSame(50, MysqlEngagementRepository::engagementScore(
            ['engagements' => 5, 'contacts' => 1],
            $now,
            $volumeOnly
        ));
        // Fully weighted on recency: within 7 days = full points, within 30 = half.
        $recencyOnly = ['volume' => 0, 'time' => 0, 'scroll' => 0, 'video' => 0, 'recency' => 100];
        self::assertSame(100, MysqlEngagementRepository::engagementScore(['last_activity' => $now - 86400], $now, $recencyOnly));
        self::assertSame(50, MysqlEngagementRepository::engagementScore(['last_activity' => $now - 20 * 86400], $now, $recencyOnly));
    }

    public function testScoreWeightsReadDefaultsOnInvalidPref(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('user_ltv_score_weights', [[
            'user_ltv_score_weights' => 'garbage-not-a-weights-string',
        ]]);
        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));

        self::assertSame(
            MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS,
            $repo->scoreWeights(7),
            'a corrupted pref must not take reports down'
        );
    }

    public function testCustomerEngagementAggregatesCombinesClicksAndEvents(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [
            ['clicks' => 4, 'last_click' => 1700000100],
        ]);
        $read->whenQueryContainsReturnRows('FROM 202_engagement_events', [
            ['events' => 3, 'last_event' => 1700000500,
             'avg_time_on_page' => 120.0, 'avg_scroll_depth' => 80.0, 'avg_video_pct' => null],
        ]);
        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));

        $aggregates = $repo->customerEngagementAggregates(7, 501, 90);

        self::assertSame(7.0, (float) $aggregates['engagements'], 'clicks + events both count as engagement volume');
        self::assertSame(1, (int) $aggregates['contacts'], 'a single customer is one contact');
        self::assertSame(1700000500, (int) $aggregates['last_activity'], 'most recent of last click / last event');
        self::assertSame(120.0, (float) $aggregates['avg_time_on_page']);
        self::assertSame(80.0, (float) $aggregates['avg_scroll_depth']);
        self::assertSame(0.0, (float) ($aggregates['avg_video_pct'] ?? 0));
    }

    public function testAbmBreakdownCountsCustomEventsInEngagements(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('GROUP BY cu.company', [
            ['company' => 'Acme Corp', 'contacts' => 3, 'total_revenue' => 1200.0, 'mrr' => 99.0,
             'last_activity' => 1700000000, 'engagements' => 20, 'custom_events' => 6,
             'top_campaign_name' => 'Offer X', 'top_event_name' => 'pricing_viewed'],
        ]);
        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));

        $rows = $repo->abmBreakdown(7, 90, 50, 0);
        self::assertSame(6, (int) $rows[0]['custom_events']);

        $queries = $read->statementsContaining('GROUP BY cu.company');
        self::assertCount(1, $queries);
        self::assertStringContainsString('202_engagement_events', $queries[0]->sql, 'ABM engagements must include instrumented events');
    }
}

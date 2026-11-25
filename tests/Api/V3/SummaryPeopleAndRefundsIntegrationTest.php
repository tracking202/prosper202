<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\InboundLog;
use Tests\Report\ScratchReportDatabase;

/**
 * The summary's unique visitors, conversions and refunds, and its unmatched
 * conversions, run on a real server against fixtures counted by hand: a
 * person merged from two visitor keys, a person on two campaigns, clicks
 * with no visitor key, a superseded sale, a deleted reversal, a reversal of
 * a sale that does not count, and another account's rows of each kind.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_*); it writes
 * users 8140 and 8141's rows and removes them at both ends.
 *
 * @group integration
 */
final class SummaryPeopleAndRefundsIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 8140;
    private const OTHER = 8141;
    private const FIRST_CLICK = 81400001;
    private const KEY = 8140000;
    private const INSERT_LOG = 'INSERT INTO 202_inbound_log'
        . ' (user_id, received_at, endpoint, method, http_status, outcome, accepted) VALUES ';

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $now = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (
                ['202_dataengine', '202_aff_campaigns', '202_clicks_visitor', '202_identity_visitors',
                    '202_conversion_logs', '202_inbound_log', '202_users'] as $table
            ) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
        self::$ids = [];
    }

    private static function seed(): void
    {
        self::q("SET SESSION sql_mode=''");
        self::user(self::USER);
        $campaign = static fn (int $user, string $name): int => self::insert(
            "INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1, aff_campaign_name = "
            . self::str($name) . ", aff_campaign_url = 'https://o.example', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0'
        );
        $a = self::$ids['campA'] = $campaign(self::USER, 'sp A');
        $b = self::$ids['campB'] = $campaign(self::USER, 'sp B');
        $x = $campaign(self::OTHER, 'sp X');

        self::$now = time() - 7200;
        // click => [account, campaign]
        $clicks = [1 => [self::USER, $a], 2 => [self::USER, $a], 3 => [self::USER, $b], 4 => [self::USER, $b],
            5 => [self::USER, $a], 6 => [self::USER, $a], 7 => [self::OTHER, $x], 8 => [self::USER, $b]];
        foreach ($clicks as $n => [$user, $camp]) {
            self::row('202_dataengine', [
                'user_id' => $user, 'click_id' => self::FIRST_CLICK + $n, 'click_time' => self::$now - 60 * $n,
                'aff_campaign_id' => $camp, 'ppc_account_id' => 1, 'landing_page_id' => 0,
                'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
            ]);
        }

        // Keys 1 and 3 are canonical; key 2 was merged into key 1. Key 9 is
        // the other account's, merged there into a key numbered as this
        // account's key 1: click 8's row names it, and it is a person of its own.
        foreach ([1 => null, 2 => 1, 3 => null] as $k => $alias) {
            self::row('202_identity_visitors', ['visitor_key' => self::KEY + $k, 'user_id' => self::USER,
                'alias_of' => $alias === null ? null : self::KEY + $alias, 'created_at' => self::$now]);
        }
        self::row('202_identity_visitors', ['visitor_key' => self::KEY + 9, 'user_id' => self::OTHER,
            'alias_of' => self::KEY + 1, 'created_at' => self::$now]);
        // Clicks 1 and 2 are one person (keys 1 and 2), clicks 3 and 6 another
        // (key 3, on both campaigns). Click 4 has no key; click 5's row is the
        // other account's, so it has none here.
        $keys = [1 => [self::USER, 1], 2 => [self::USER, 2], 3 => [self::USER, 3], 6 => [self::USER, 3],
            5 => [self::OTHER, 9], 7 => [self::OTHER, 9], 8 => [self::USER, 9]];
        foreach ($keys as $n => [$user, $k]) {
            self::row('202_clicks_visitor', ['click_id' => self::FIRST_CLICK + $n, 'user_id' => $user,
                'visitor_key' => self::KEY + $k, 'click_time' => self::$now - 60 * $n]);
        }

        $conversion = static function (int $n, string $amount, array $more = [], int $user = self::USER): int {
            static $seq = 0;
            $seq++;

            return self::insert('INSERT INTO 202_conversion_logs SET ' . implode(', ', array_map(
                static fn (string $k, mixed $v): string => "`$k` = "
                    . ($v === null ? 'NULL' : (is_string($v) ? self::str($v) : $v)),
                array_keys($row = $more + [
                    'click_id' => self::FIRST_CLICK + $n, 'user_id' => $user, 'campaign_id' => 0,
                    'click_payout' => $amount, 'click_time' => self::$now - 60 * $n, 'conv_time' => self::$now,
                    'time_difference' => '', 'pixel_type' => 0, 'user_agent' => '', 'source' => 'postback',
                    'dedupe_key' => "sp:$seq", 'payable' => 1, 'deleted' => 0,
                ]),
                $row
            )));
        };
        $s1 = $conversion(1, '10.00000');
        $conversion(1, '-10.00000', ['reverses_conv_id' => $s1]);
        $conversion(2, '5.00000');
        $s3 = $conversion(3, '7.00000');
        $conversion(3, '-2.00000', ['reverses_conv_id' => $s3]);
        $conversion(3, '-7.00000', ['reverses_conv_id' => $s3, 'deleted' => 1]);
        $replaced = $conversion(3, '4.00000', ['superseded_reason' => 'replace']);
        $conversion(3, '-4.00000', ['reverses_conv_id' => $replaced]);
        $conversion(3, '3.00000', ['payable' => 0]);
        $s7 = $conversion(7, '9.00000', [], self::OTHER);
        $conversion(7, '-9.00000', ['reverses_conv_id' => $s7], self::OTHER);
        // The other account's rows naming this account's click 4.
        $foreign = $conversion(4, '6.00000', [], self::OTHER);
        $conversion(4, '-6.00000', ['reverses_conv_id' => $foreign], self::OTHER);

        $log = static function (int $user, string $outcome, int $at): void {
            self::row('202_inbound_log', ['user_id' => $user, 'received_at' => $at, 'endpoint' => 'pb',
                'method' => 'GET', 'http_status' => 200, 'outcome' => $outcome,
                'accepted' => in_array($outcome, InboundLog::BACKED_OUTCOMES, true) ? 1 : 0]);
        };
        $log(self::USER, 'unknown_click', self::$now - 600);
        $log(self::USER, 'no_click', self::$now - 500);
        $log(self::USER, 'malformed_click', self::$now - 400);
        $log(self::USER, 'unknown_click', self::$now - 86400 * 5);
        $log(self::USER, 'unauthenticated', self::$now - 300);
        $log(self::USER, 'duplicate', self::$now - 200);
        $log(self::USER, 'recorded', self::$now - 100);
        $log(self::OTHER, 'unknown_click', self::$now - 300);
        self::q(self::$strict);
    }

    private function reports(): ReportsController
    {
        return new ReportsController(self::$db, self::USER);
    }

    /** @return array<string, string> */
    private function window(): array
    {
        return ['time_from' => (string) (self::$now - 86400), 'time_to' => (string) (self::$now + 60)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function summary(array $params = []): array
    {
        $answer = $this->reports()->summary($params + $this->window());

        return [$answer['data'], $answer['unmatched_conversions']];
    }

    /** @param array<string, mixed> $data */
    private static function people(array $data): array
    {
        return [
            $data['unique_visitors'], $data['unidentified_clicks'], $data['total_conversions'],
            $data['total_refunds'], $data['total_refund_amount'], $data['refund_rate'],
        ];
    }

    public function testTheSummaryCountsPeopleConversionsAndRefundsByHand(): void
    {
        [$all] = $this->summary();
        // People: keys 1+2, key 3, key 9, and clicks 4 and 5 with no key here.
        // Conversions: the sales of clicks 1, 2 and 3; refunds: click 1's
        // and click 3's partial one (not the deleted one, nor the reversal of
        // the replaced sale).
        self::assertSame([5, 2, 3, 2, 12.0, 66.6667], self::people($all));
        self::assertSame(7, $all['total_clicks']);
    }

    /** Each campaign's figures are its own clicks'; a person on both is one person of each, and one of the whole. */
    public function testFiltersNarrowThemAndAPersonIsCountedOnce(): void
    {
        [$a] = $this->summary(['aff_campaign_id' => (string) self::$ids['campA']]);
        self::assertSame([3, 1, 2, 1, 10.0, 50.0], self::people($a), 'keys 1+2, key 3 and click 5');
        [$b] = $this->summary(['aff_campaign_id' => (string) self::$ids['campB']]);
        self::assertSame([3, 1, 1, 1, 2.0, 100.0], self::people($b), 'key 3, key 9 and click 4');
        [$all] = $this->summary();
        self::assertSame(5, $all['unique_visitors'], 'key 3 is on both campaigns');
        self::assertSame($a['unidentified_clicks'] + $b['unidentified_clicks'], $all['unidentified_clicks']);
        self::assertSame($a['total_refunds'] + $b['total_refunds'], $all['total_refunds']);
    }

    public function testAWindowWithNoClicksIsZero(): void
    {
        [$none] = $this->summary(['time_from' => '1000', 'time_to' => '2000']);
        self::assertSame([0, 0, 0, 0, 0.0, 0.0], self::people($none));
    }

    public function testUnmatchedConversionsAreTheAccountsRefusalsThatNamedNoClick(): void
    {
        [, $unmatched] = $this->summary();
        self::assertSame(['conversions' => 3, 'complete' => true], $unmatched);
        [, $filtered] = $this->summary(['aff_campaign_id' => (string) self::$ids['campB']]);
        self::assertSame($unmatched, $filtered, 'a refusal names no campaign: the figure is the account\'s');
        [, $week] = $this->summary(['time_from' => (string) (self::$now - 86400 * 6)]);
        self::assertSame(4, $week['conversions'], 'the older refusal is in a wider window');
    }

    /** Sixty unbacked requests within a minute: the cap may have refused one, so the count is a floor. */
    public function testAMinuteAtTheCapIsNotComplete(): void
    {
        $values = [];
        for ($i = 0; $i < InboundLog::UNBACKED_PER_MINUTE; $i++) {
            $values[] = '(' . self::USER . ', ' . (self::$now - 1000 + intdiv($i, 2))
                . ", 'pb', 'GET', 200, 'duplicate', 0)";
        }
        self::q(self::INSERT_LOG
            . implode(', ', $values));
        try {
            [, $unmatched] = $this->summary();
            self::assertSame(['conversions' => 3, 'complete' => false], $unmatched);
            [, $before] = $this->summary(['time_to' => (string) (self::$now - 1100)]);
            self::assertTrue($before['complete'], 'a window ending a minute before the run is whole');
        } finally {
            self::q('DELETE FROM 202_inbound_log WHERE user_id = ' . self::USER
                . " AND outcome = 'duplicate' AND received_at < " . (self::$now - 900));
        }
    }

    /** A bucket the prune has filled is whole only for a window that starts after its oldest row. */
    public function testAPrunedBucketIsCompleteOnlyBeforeItsOldestRow(): void
    {
        $base = self::$now - 86400 * 10 - InboundLog::KEEP_REFUSED * 61;
        $values = [];
        for ($i = 0; $i < InboundLog::KEEP_REFUSED; $i++) {
            $values[] = '(' . self::USER . ', ' . ($base + 61 * $i) . ", 'pb', 'GET', 200, 'unknown_click', 0)";
        }
        foreach (array_chunk($values, 1000) as $chunk) {
            self::q(self::INSERT_LOG
                . implode(', ', $chunk));
        }
        try {
            // The prune took what was older than the oldest row kept, so a
            // window from before it may have lost some; one inside it has not.
            $before = ['time_from' => (string) ($base - 100), 'time_to' => (string) ($base + 61 * 20)];
            [, $pruned] = $this->summary($before);
            self::assertSame(['conversions' => 21, 'complete' => false], $pruned);
            $inside = ['time_from' => (string) ($base + 61 * 10), 'time_to' => (string) ($base + 61 * 20)];
            [, $whole] = $this->summary($inside);
            self::assertSame(['conversions' => 11, 'complete' => true], $whole);
            [, $atTheOldest] = $this->summary(['time_from' => (string) $base] + $inside);
            self::assertFalse($atTheOldest['complete'], 'a pruned row may share the oldest kept row\'s second');
        } finally {
            self::q('DELETE FROM 202_inbound_log WHERE user_id = ' . self::USER
                . ' AND received_at < ' . ($base + 61 * InboundLog::KEEP_REFUSED));
        }
    }
}

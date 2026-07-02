<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;
use Prosper202\Ltv\MysqlWebhookRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Pure-logic guards: predictive-LTV caps, MRR normalization, period math,
 * webhook SSRF validation, and custom-field filter limits. No database.
 */
final class LtvGuardsTest extends TestCase
{
    public function testPredictCapsUnboundedSubscriberValueAndEchoesInputs(): void
    {
        $read = new FakeMysqliConnection();
        // summary(): high repeat rate (should cap at 0.95).
        $read->whenQueryContainsReturnRows('FROM 202_customers c', [[
            'customers' => 100,
            'total_revenue' => 5000.0,
            'refunded_amount' => 0.0,
            'total_orders' => 500,
            'avg_ltv' => 50.0,
            'aov' => 10.0,
            'repeat_customers' => 99,
            'purchasing_customers' => 100,
            'repeat_rate' => 0.99,
            'mrr' => 1000.0,
            'active_subscriptions' => 40,
        ]]);
        // mrr(): zero churn (should floor at 1%/mo and then cap at 60x MRR).
        $read->whenQueryContainsReturnRows('FROM 202_subscriptions', [[
            'mrr' => 1000.0,
            'active' => 40,
            'trialing' => 0,
            'past_due' => 0,
            'paused' => 0,
            'canceled_total' => 0,
            'canceled_90d' => 0,
        ]]);

        $repo = new MysqlLtvRepository(new Connection(new FakeMysqliConnection(), $read));
        $result = $repo->predict(new LtvQuery(7));

        self::assertSame('account', $result['basis']);
        // aov 10 / (1 - 0.95) = 200 (capped repeat rate).
        self::assertEqualsWithDelta(200.0, $result['predicted_ltv_per_customer'], 0.001);
        // mrr 1000 / floor(0.01) = 100000 -> capped at 60 * 1000 = 60000.
        self::assertEqualsWithDelta(60000.0, $result['predicted_subscriber_pool_value'], 0.001);
        self::assertContains('repeat_rate_capped_at_0.95', $result['caps_applied']);
        self::assertContains('churn_floored_at_1pct_monthly', $result['caps_applied']);
        self::assertContains('subscriber_ltv_capped_at_60_months_mrr', $result['caps_applied']);
        self::assertSame(100, $result['inputs']['customers']);
    }

    public function testLtvQueryRejectsMoreThanThreeCustomFieldFilters(): void
    {
        $filter = ['fieldId' => 1, 'column' => 'value_number', 'op' => '>=', 'value' => 1];

        $this->expectException(\RuntimeException::class);
        new LtvQuery(7, null, null, [$filter, $filter, $filter, $filter]);
    }

    public function testLtvQueryRejectsUnknownFilterColumnsAndOperators(): void
    {
        $this->expectException(\RuntimeException::class);
        new LtvQuery(7, null, null, [
            ['fieldId' => 1, 'column' => 'value_text; DROP TABLE x', 'op' => '=', 'value' => 'a'],
        ]);
    }

    public function testMrrNormalization(): void
    {
        self::assertEqualsWithDelta(10.0, MysqlSubscriptionRepository::normalizeMrr(10.0, 'month', 1), 0.001);
        self::assertEqualsWithDelta(10.0, MysqlSubscriptionRepository::normalizeMrr(120.0, 'year', 1), 0.001);
        self::assertEqualsWithDelta(5.0, MysqlSubscriptionRepository::normalizeMrr(10.0, 'month', 2), 0.001);
        // Weekly: 10 / (7/30.4375) ≈ 43.48/mo.
        self::assertEqualsWithDelta(43.482, MysqlSubscriptionRepository::normalizeMrr(10.0, 'week', 1), 0.01);
    }

    public function testAdvancePeriodUsesCalendarMonths(): void
    {
        $jan31 = (int) mktime(0, 0, 0, 1, 15, 2026);
        $advanced = MysqlSubscriptionRepository::advancePeriod($jan31, 'month', 1);
        self::assertSame((int) mktime(0, 0, 0, 2, 15, 2026), $advanced);
    }

    public function testWebhookGuardRejectsNonHttpsAndPrivateAddresses(): void
    {
        foreach ([
            'http://example.com/hook',          // not https
            'https://127.0.0.1/hook',           // loopback
            'https://10.0.0.5/hook',            // RFC1918
            'https://192.168.1.1/hook',         // RFC1918
            'https://169.254.169.254/hook',     // link-local / metadata
            'https://example.com:8080/hook',    // disallowed port
            'not a url',
        ] as $url) {
            try {
                MysqlWebhookRepository::assertUrlAllowed($url);
                self::fail('Expected rejection for ' . $url);
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWebhookSignatureIsHmacSha256(): void
    {
        $body = '{"event":"revenue.recorded"}';
        $expected = 'sha256=' . hash_hmac('sha256', $body, 'secret-1');
        self::assertSame($expected, MysqlWebhookRepository::signature($body, 'secret-1'));
    }
}

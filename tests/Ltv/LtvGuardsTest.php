<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlCustomerRepository;
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
            'mrr' => 500.0,
            'active_subscriptions' => 40,
        ]]);
        // mrr(): zero churn (should floor at 1%/mo and then cap at 60x MRR).
        // The account-wide MRR is DOUBLE the scoped summary's — the pool
        // projection must use the scoped figure, only churn comes from here.
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
        // SCOPED mrr 500 / floor(0.01) = 50000 -> capped at 60 * 500 = 30000.
        // (60 * account mrr would be 60000 — the scoped figure must win.)
        self::assertEqualsWithDelta(30000.0, $result['predicted_subscriber_pool_value'], 0.001);
        self::assertContains('repeat_rate_capped_at_0.95', $result['caps_applied']);
        self::assertContains('churn_floored_at_1pct_monthly', $result['caps_applied']);
        self::assertContains('subscriber_ltv_capped_at_60_months_mrr', $result['caps_applied']);
        self::assertSame(100, $result['inputs']['customers']);
    }

    public function testCustomerSearchEscapesLikeAndSegmentsAreAllowlisted(): void
    {
        $read = new FakeMysqliConnection();
        $repo = new MysqlLtvRepository(new Connection(new FakeMysqliConnection(), $read));

        $repo->customers(new LtvQuery(7), 'total_revenue', 'DESC', 50, 0, '50%_off', 'subscribers');

        $queries = $read->statementsContaining('ORDER BY c.total_revenue');
        self::assertCount(1, $queries);
        self::assertStringContainsString('c.primary_ref LIKE ?', $queries[0]->sql);
        self::assertStringContainsString('c.active_subscription_count > 0', $queries[0]->sql);
        self::assertContains('%50\%\_off%', $queries[0]->boundValues, 'LIKE metacharacters must be escaped');
        self::assertSame('issssii', $queries[0]->boundTypes, 'user + 4 search terms + limit/offset');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('segment');
        $repo->customers(new LtvQuery(7), 'total_revenue', 'DESC', 50, 0, null, 'bogus');
    }

    public function testBreakdownJoinsAdSpendForCacInWindow(): void
    {
        $read = new FakeMysqliConnection();
        $repo = new MysqlLtvRepository(new Connection(new FakeMysqliConnection(), $read));

        $repo->breakdown(new LtvQuery(7, 1700000000, 1700086400), 'campaign', 25, 0);

        $queries = $read->statementsContaining('SUM(click_cpc)');
        self::assertCount(1, $queries);
        self::assertStringContainsString('ltv_cac', $queries[0]->sql);
        // Bind order follows SQL text: spend subquery (user, from, to), then
        // the customer scope (user, from, to), then limit/offset.
        self::assertSame('iiiiiiii', $queries[0]->boundTypes);
        self::assertSame([7, 1700000000, 1700086400, 7, 1700000000, 1700086400, 25, 0], $queries[0]->boundValues);
    }

    public function testCohortsBucketByMonthsSinceAcquisition(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('cohort_month', [[
            'cohort_month' => '2026-06', 'customers' => 2,
            'm0' => 30.0, 'm1' => 15.0, 'm2' => 0.0, 'm3' => 0.0, 'm4' => 0.0, 'm5_plus' => 0.0,
            'total_revenue' => 45.0,
        ]]);
        $repo = new MysqlLtvRepository(new Connection(new FakeMysqliConnection(), $read));

        $rows = $repo->cohorts(7, 6, 1767225600);
        self::assertCount(1, $rows);
        self::assertSame(22.5, $rows[0]['ltv_per_customer'], 'per-customer LTV is derived from the cohort totals');

        $queries = $read->statementsContaining('TIMESTAMPDIFF(MONTH');
        self::assertCount(1, $queries);
        self::assertStringContainsString('merged_into_customer_id IS NULL', $queries[0]->sql);
        self::assertSame('ii', $queries[0]->boundTypes);
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

    public function testReservedIdempotencyPrefixesAreRejected(): void
    {
        // Ordinary caller keys pass; only the LEADING internal namespaces are
        // reserved ('subscribe-...' is fine, 'sub:...' is not).
        MysqlCustomerRepository::assertExternalIdempotencyKey('order-123');
        MysqlCustomerRepository::assertExternalIdempotencyKey('subscribe-2024');
        $this->addToAssertionCount(2);

        foreach (['void:conv:5', 'void-nc:conv:5', 'backfill:conv:9', 'sub:1:renewal:tx'] as $key) {
            try {
                MysqlCustomerRepository::assertExternalIdempotencyKey($key);
                self::fail($key . ' must be rejected — it squats an internal namespace');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function testXlsCellsNeutralizeSpreadsheetFormulas(): void
    {
        require_once __DIR__ . '/../../tracking202/ajax/ltv_helpers.php';

        self::assertSame("'=HYPERLINK(\"http://evil\")", p202_ltv_xls_cell('=HYPERLINK("http://evil")'));
        self::assertSame("'+1+2", p202_ltv_xls_cell('+1+2'));
        self::assertSame("'-2+3", p202_ltv_xls_cell('-2+3'));
        self::assertSame("'@SUM(A1)", p202_ltv_xls_cell('@SUM(A1)'));
        self::assertSame("'\tleading-tab", p202_ltv_xls_cell("\tleading-tab"));
        self::assertSame('Acme Inc', p202_ltv_xls_cell('Acme Inc'), 'ordinary values pass through untouched');
        self::assertSame('jane@example.com', p202_ltv_xls_cell('jane@example.com'), 'only a LEADING @ is dangerous');
        self::assertSame('', p202_ltv_xls_cell(''));
    }

    public function testWebhookSignatureIsHmacSha256(): void
    {
        $body = '{"event":"revenue.recorded"}';
        $expected = 'sha256=' . hash_hmac('sha256', $body, 'secret-1');
        self::assertSame($expected, MysqlWebhookRepository::signature($body, 'secret-1'));
    }
}

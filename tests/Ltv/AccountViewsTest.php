<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCustomerRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;
use Prosper202\Ltv\MysqlWebhookRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Account-wide list surfaces behind the new views: the cross-customer
 * subscription list (status filtering + tenant scoping) and the webhook
 * delivery log.
 */
final class AccountViewsTest extends TestCase
{
    public function testSubscriptionListScopedAndJoinedToCustomers(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('LEFT JOIN 202_customers c', [
            ['subscription_id' => 1, 'plan_name' => 'Pro', 'status' => 'active',
             'amount' => 49.0, 'mrr' => 49.0, 'customer_id' => 501,
             'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.com', 'company' => 'Acme'],
        ]);
        $read->whenQueryContainsReturnRows('SELECT COUNT(*) AS total FROM 202_subscriptions', [
            ['total' => 12],
        ]);
        $repo = new MysqlSubscriptionRepository(
            new Connection(new FakeMysqliConnection(), $read),
            new MysqlCustomerRepository(new Connection(new FakeMysqliConnection(), new FakeMysqliConnection()))
        );

        $result = $repo->listForUser(7, null, 50, 0);
        self::assertSame(12, $result['total']);
        self::assertCount(1, $result['rows']);

        $queries = $read->statementsContaining('LEFT JOIN 202_customers c');
        self::assertCount(1, $queries);
        self::assertSame('iii', $queries[0]->boundTypes, 'no status filter: user, limit, offset');
        self::assertContains(7, $queries[0]->boundValues);
    }

    public function testSubscriptionListStatusFilterValidatedAndBound(): void
    {
        $read = new FakeMysqliConnection();
        $repo = new MysqlSubscriptionRepository(
            new Connection(new FakeMysqliConnection(), $read),
            new MysqlCustomerRepository(new Connection(new FakeMysqliConnection(), new FakeMysqliConnection()))
        );

        $repo->listForUser(7, 'past_due', 25, 50);
        $queries = $read->statementsContaining('s.status = ?');
        self::assertCount(2, $queries, 'rows query and count query both filter');
        self::assertSame('isii', $queries[0]->boundTypes);
        self::assertContains('past_due', $queries[0]->boundValues);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('status');
        $repo->listForUser(7, 'bogus');
    }

    public function testIntegrationListFlagsUndecodableConfig(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_ltv_integrations', [
            ['integration_id' => 1, 'provider' => 'shopify', 'name' => 'Store', 'config' => '{"key":"v"}',
             'status' => 'active', 'created_at' => 1, 'updated_at' => 1],
            ['integration_id' => 2, 'provider' => 'aweber', 'name' => 'List', 'config' => '{broken',
             'status' => 'active', 'created_at' => 1, 'updated_at' => 1],
        ]);
        $repo = new \Prosper202\Ltv\MysqlIntegrationRepository(new Connection(new FakeMysqliConnection(), $read));

        $rows = $repo->list(7);

        self::assertSame(['key' => 'v'], $rows[0]['config']);
        self::assertFalse(array_key_exists('config_invalid', $rows[0]), 'valid config carries no flag');
        self::assertNull($rows[1]['config']);
        self::assertTrue((bool) ($rows[1]['config_invalid'] ?? false), 'corrupt JSON must be flagged, not silently null');
    }

    public function testConnectionLockErrorDetectionCoversErrnoTagsAndMessages(): void
    {
        self::assertTrue(Connection::isRetryableLockError(
            new \RuntimeException('MySQL execute failed: quelque chose [errno 1213]')
        ), 'errno tag detection must be locale-independent');
        self::assertTrue(Connection::isRetryableLockError(
            new \RuntimeException('MySQL execute failed: Lock wait timeout exceeded')
        ));
        self::assertTrue(Connection::isRetryableLockError(
            new \RuntimeException('outer', 0, new \RuntimeException('inner [errno 1205]'))
        ), 'previous-chain must be walked');
        self::assertFalse(Connection::isRetryableLockError(
            new \RuntimeException('MySQL execute failed: Duplicate entry [errno 1062]')
        ));
    }

    public function testScoreWeightsRethrowsNonSchemaDbFailures(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsExecuteReturns('user_ltv_score_weights', false);
        $repo = new \Prosper202\Ltv\MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));

        // A generic DB failure (not the missing-column deploy window) must
        // surface — silently-wrong default scores would mask an outage.
        $this->expectException(\RuntimeException::class);
        $repo->scoreWeights(7);
    }

    public function testClaimDeliveryIsAtomicConditionalUpdate(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlWebhookRepository(new Connection($write, new FakeMysqliConnection()));

        // The fake reports 0 affected rows — the lost-claim case — so the
        // dispatcher must be told NOT to post this delivery.
        self::assertFalse($repo->claimDelivery(9, 1700000000));

        $claims = $write->statementsContaining('SET next_attempt_at = ?');
        self::assertCount(1, $claims);
        self::assertSame('iii', $claims[0]->boundTypes);
        self::assertSame([1700000300, 9, 1700000000], $claims[0]->boundValues, 'claim window, delivery, now');
        self::assertStringContainsString("status = 'pending' AND next_attempt_at <= ?", $claims[0]->sql, 'only due pending rows are claimable');
    }

    public function testWebhookDeliveryLogScopedNewestFirst(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhook_deliveries', [
            ['delivery_id' => 9, 'event_name' => 'revenue.recorded', 'status' => 'delivered',
             'attempts' => 1, 'last_status_code' => 200, 'next_attempt_at' => 0,
             'created_at' => 1700000000, 'updated_at' => 1700000100],
        ]);
        $repo = new MysqlWebhookRepository(new Connection(new FakeMysqliConnection(), $read));

        $rows = $repo->recentDeliveries(7, 3, 25);
        self::assertCount(1, $rows);

        $queries = $read->statementsContaining('FROM 202_ltv_webhook_deliveries');
        self::assertCount(1, $queries);
        self::assertSame('iii', $queries[0]->boundTypes);
        self::assertSame([3, 7, 25], $queries[0]->boundValues, 'webhook, user, limit — tenant scoping is not optional');
        self::assertStringContainsString('ORDER BY delivery_id DESC', $queries[0]->sql);
    }
}

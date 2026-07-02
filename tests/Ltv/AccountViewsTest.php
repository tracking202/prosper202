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

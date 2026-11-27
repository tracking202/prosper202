<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\LtvTables;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Lifecycle\UnknownState;
use Prosper202\Ltv\DeliveryStatus;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\SubscriptionStatus;
use Prosper202\Ltv\WebhookDispatcher;
use Prosper202\Ltv\WebhookStatus;
use Tests\Support\LifecycleContract;

/**
 * The LTV lifecycles' transition tables, and the lists other surfaces copy
 * from them: the columns' ENUMs and docs/openapi.yaml (the Go CLI's are held
 * by TestLtvListsAreTheServers).
 */
final class LtvLifecyclesTest extends TestCase
{
    public function testEachHoldsTheContract(): void
    {
        foreach ([SubscriptionStatus::class, WebhookStatus::class, DeliveryStatus::class] as $lifecycle) {
            self::assertSame([], LifecycleContract::violations($lifecycle));
        }
    }

    public function testADeliveredDeliveryIsFinalAndAFailedOneOnlyRequeues(): void
    {
        self::assertTrue(DeliveryStatus::Delivered->isTerminal());
        self::assertSame([DeliveryStatus::Pending], DeliveryStatus::Failed->next());
        self::assertSame([DeliveryStatus::Delivered, DeliveryStatus::Failed], DeliveryStatus::Pending->next());
        self::assertSame([DeliveryStatus::Pending], DeliveryStatus::initial());
        self::assertSame([DeliveryStatus::Pending], DeliveryStatus::sources(DeliveryStatus::Delivered));
        self::assertSame([DeliveryStatus::Pending], DeliveryStatus::sources(DeliveryStatus::Failed));

        foreach ([
            [DeliveryStatus::Delivered, DeliveryStatus::Pending],
            [DeliveryStatus::Delivered, DeliveryStatus::Failed],
            [DeliveryStatus::Failed, DeliveryStatus::Delivered],
        ] as [$from, $to]) {
            try {
                $from->moveTo($to);
                self::fail($from->value . ' became ' . $to->value);
            } catch (IllegalTransition $e) {
                self::assertStringContainsString($from->value . ' may not become ' . $to->value, $e->getMessage());
            }
        }
    }

    public function testAWebhookIsActiveOrDeadAndDisabledIsNotAState(): void
    {
        self::assertSame([WebhookStatus::Active], WebhookStatus::initial());
        self::assertSame([WebhookStatus::Dead], WebhookStatus::Active->next());
        self::assertSame([WebhookStatus::Active], WebhookStatus::Dead->next());
        self::assertNull(WebhookStatus::tryFrom('disabled'));
        $this->expectException(UnknownState::class);
        $this->expectExceptionMessage('webhook 7 holds the state "disabled"');
        WebhookStatus::ofStored('disabled', 'webhook 7');
    }

    public function testTheBillingSystemMayStateAnySubscriptionStatusAndTheSweepOnlyLapses(): void
    {
        foreach (SubscriptionStatus::cases() as $from) {
            self::assertFalse($from->isTerminal(), $from->value . ': an upsert or a renewal reactivates it');
            self::assertCount(4, $from->next());
            foreach ($from->lapsesTo() as $to) {
                self::assertTrue($from->canMoveTo($to), 'a lapse is a move of the table');
            }
        }
        self::assertSame(SubscriptionStatus::cases(), SubscriptionStatus::initial());
        self::assertTrue(SubscriptionStatus::Canceled->canMoveTo(SubscriptionStatus::Active));

        self::assertSame([], SubscriptionStatus::Canceled->lapsesTo(), 'the sweep never leaves canceled');
        self::assertSame([SubscriptionStatus::Canceled], SubscriptionStatus::PastDue->lapsesTo());
        foreach ([SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::Paused] as $from) {
            self::assertSame([SubscriptionStatus::PastDue, SubscriptionStatus::Canceled], $from->lapsesTo());
            self::assertNotContains(SubscriptionStatus::Active, $from->lapsesTo(), 'nor makes one active');
        }
        $lapsing = array_values(array_filter(
            SubscriptionStatus::cases(),
            static fn (SubscriptionStatus $s): bool => $s->lapsesTo() !== []
        ));
        self::assertEqualsCanonicalizing($lapsing, SubscriptionStatus::lapsing());
        self::assertSame(SubscriptionStatus::PastDue, SubscriptionStatus::lapsing()[3], 'last: what lapsed this run'
            . ' is not canceled by it too');
    }

    public function testTheReasonsADeliveryWasSetAsideAreTheLifecycles(): void
    {
        $reasons = [
            DeliveryStatus::HELD, DeliveryStatus::HELD_ON_REVIVAL, DeliveryStatus::EXPIRED,
            DeliveryStatus::WEBHOOK_NOT_ACTIVE,
        ];
        self::assertCount(4, array_unique($reasons), 'told apart by identity');
        self::assertSame(DeliveryStatus::HELD, MysqlWebhookRepository::HELD_REASON);
        self::assertSame(DeliveryStatus::HELD_ON_REVIVAL, MysqlWebhookRepository::REVIVED_HELD_REASON);
        self::assertSame(DeliveryStatus::EXPIRED, WebhookDispatcher::EXPIRED_REASON);
        foreach ($reasons as $reason) {
            self::assertLessThanOrEqual(1000, strlen($reason), 'last_response_body is varchar(1000)');
        }
    }

    public function testTheColumnsHoldTheStates(): void
    {
        self::assertSame(SubscriptionStatus::values(), self::columnEnum(LtvTables::subscriptions()->createStatement));
        self::assertSame(DeliveryStatus::values(), self::columnEnum(LtvTables::ltvWebhookDeliveries()->createStatement));
        // `disabled` stays in the column, which is not changed for a value nothing writes.
        self::assertSame(
            ['active', 'disabled', 'dead'],
            self::columnEnum(LtvTables::ltvWebhooks()->createStatement)
        );
        self::assertSame(['active', 'dead'], array_map(
            static fn (WebhookStatus $s): string => $s->value,
            WebhookStatus::cases()
        ));
    }

    /** @return iterable<string, array{string, list<string>}> a pattern whose first group is the list, the states */
    public static function documented(): iterable
    {
        $inline = static fn (string $schema): string => '/\n    ' . $schema . ':\n(?:(?!\n    \w).)*?\n        status: \{type:'
            . ' string, enum: \[([^\]]*)\]\}/s';
        $param = static fn (string $operation): string => '/operationId: ' . $operation . '\n(?:(?!operationId:).)*?'
            . '- name: status\n\s+in: query\n\s+schema:\n\s+type: string\n\s+enum:\n((?:\s+- \w+\n)+)/s';
        yield 'LtvSubscription' => [$inline('LtvSubscription'), SubscriptionStatus::values()];
        yield 'LtvCustomerSubscription' => [$inline('LtvCustomerSubscription'), SubscriptionStatus::values()];
        yield 'GET /ltv/subscriptions ?status' => [$param('ltvListSubscriptions'), SubscriptionStatus::values()];
        yield 'LtvWebhook' => [$inline('LtvWebhook'), ['active', 'dead']];
        yield 'LtvWebhookDelivery' => [$inline('LtvWebhookDelivery'), DeliveryStatus::values()];
        yield 'GET /ltv/webhooks/{id}/deliveries ?status' => [$param('ltvWebhookDeliveries'), DeliveryStatus::values()];
    }

    /**
     * @dataProvider documented
     * @param list<string> $states
     */
    public function testTheApiDocumentListsTheStates(string $pattern, array $states): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertSame(1, preg_match($pattern, $spec, $m), 'docs/openapi.yaml no longer has the list this reads');
        preg_match_all('/[a-z_]+/', $m[1], $listed);
        self::assertSame($states, $listed[0]);
    }

    /** @return list<string> */
    private static function columnEnum(string $createTable): array
    {
        self::assertSame(1, preg_match('/`status` enum\(([^)]*)\)/', $createTable, $m), 'no status ENUM');
        preg_match_all("/'([^']*)'/", $m[1], $values);

        return $values[1];
    }
}

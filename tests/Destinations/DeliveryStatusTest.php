<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Destinations\DeliveryRows;
use Prosper202\Destinations\DeliveryStatus;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\WithheldFor;
use Prosper202\Lifecycle\IllegalTransition;
use Tests\Support\LifecycleContract;

/**
 * The delivery lifecycle's table, whole, and the surfaces that copy it: the
 * OpenAPI enums and the Go CLI's two lists.
 */
final class DeliveryStatusTest extends TestCase
{
    public function testTheTableIsExactlyThis(): void
    {
        self::assertSame([], LifecycleContract::violations(DeliveryStatus::class));
        $table = [];
        foreach (DeliveryStatus::cases() as $from) {
            $table[$from->value] = DeliveryStatus::values($from->next());
        }
        self::assertSame([
            'pending' => ['sent', 'failed', 'withheld', 'cancelled'],
            'sent' => [],
            'failed' => ['pending', 'sent'],
            'withheld' => ['pending', 'sent'],
            'cancelled' => ['sent'],
        ], $table);
        self::assertSame(['pending', 'sent', 'failed'], DeliveryStatus::values(DeliveryStatus::initial()));
    }

    public function testSentIsTheOnlyTerminalState(): void
    {
        self::assertSame(['sent'], DeliveryStatus::values(array_values(array_filter(DeliveryStatus::cases(), static fn (DeliveryStatus $s): bool => $s->isTerminal()))));
    }

    /** @return array<string, array{0: DeliveryStatus, 1: DeliveryStatus}> */
    public static function movesNoWriterMakes(): array
    {
        return [
            'a cancelled row is not queued again' => [DeliveryStatus::Cancelled, DeliveryStatus::Pending],
            'a cancelled row does not fail' => [DeliveryStatus::Cancelled, DeliveryStatus::Failed],
            'a cancelled row is not withheld' => [DeliveryStatus::Cancelled, DeliveryStatus::Withheld],
            'a sent row is not queued again' => [DeliveryStatus::Sent, DeliveryStatus::Pending],
            'a sent row does not fail' => [DeliveryStatus::Sent, DeliveryStatus::Failed],
            'a sent row is not withheld' => [DeliveryStatus::Sent, DeliveryStatus::Withheld],
            'a sent row is not cancelled' => [DeliveryStatus::Sent, DeliveryStatus::Cancelled],
            'a failed row is not cancelled' => [DeliveryStatus::Failed, DeliveryStatus::Cancelled],
            'a withheld row does not fail' => [DeliveryStatus::Withheld, DeliveryStatus::Failed],
        ];
    }

    /** @dataProvider movesNoWriterMakes */
    public function testTheTableRefuses(DeliveryStatus $from, DeliveryStatus $to): void
    {
        self::assertFalse($from->canMoveTo($to));
        self::assertNotContains($from->value, DeliveryStatus::whereMayMoveTo($to, 'status')['binds'], 'so no UPDATE to it matches such a row');
        $this->expectException(IllegalTransition::class);
        $from->moveTo($to);
    }

    public function testTheOperatorSendsAgainFromFailedOrWithheld(): void
    {
        self::assertSame(['failed', 'withheld'], DeliveryStatus::values(DeliveryStatus::resendable()));
    }

    public function testEveryStateHasItsLabelAndReadsBackFromItsValue(): void
    {
        self::assertSame(['waiting', 'sent', 'failed', 'withheld', 'cancelled'], array_map(static fn (DeliveryStatus $s): string => $s->label(), DeliveryStatus::cases()));
        self::assertSame("'failed','withheld'", DeliveryStatus::quotedList(DeliveryStatus::resendable()));
        foreach (DeliveryStatus::cases() as $case) {
            self::assertMatchesRegularExpression('/\A[a-z]{1,16}\z/', $case->value, 'a word the column holds, safe to quote');
        }
    }

    /**
     * A reason something acts on later is compared with what was stored, so
     * the stored form must be the reason itself, not a cut of it (#30).
     */
    public function testAReasonRowsAreReleasedByIsStoredWhole(): void
    {
        foreach (WithheldFor::cases() as $reason) {
            self::assertSame($reason->value, DeliveryRows::clip($reason->value), $reason->name);
            self::assertStringStartsWith('withheld: ', $reason->value, 'the pages cut this prefix off a reason');
        }
        self::assertSame(WithheldFor::NoCurrency->value, DestinationQueue::NO_CURRENCY);
        self::assertSame(1, substr_count((string) file_get_contents(dirname(__DIR__, 2) . '/202-config/Destinations/DestinationQueue.php'), 'WithheldFor::NoCurrency->value)'), 'the send writes the case');
    }

    public function testTheOpenApiEnumsAreTheStates(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $all = '[' . implode(', ', DeliveryStatus::values()) . ']';
        self::assertStringContainsString('- {name: status, in: query, schema: {type: string, enum: ' . $all . '}}', self::after($spec, 'operationId: listDestinationDeliveries'), 'the log\'s filter');
        self::assertStringContainsString('status: {type: string, enum: ' . $all . '}', self::after($spec, "\n    DestinationDelivery:\n"), 'a delivery\'s status');
        $resendable = '[' . implode(', ', DeliveryStatus::values(DeliveryStatus::resendable())) . ']';
        self::assertStringContainsString('status: {type: string, enum: ' . $resendable . ', description: Every delivery of this status; not with delivery_id}', $spec, 'a resend\'s status');
    }

    public function testTheGoCliListsAreTheStates(): void
    {
        $go = (string) file_get_contents(dirname(__DIR__, 2) . '/go-cli/cmd/destination.go');
        self::assertSame(DeliveryStatus::values(), self::goList($go, 'destinationDeliveryStatuses'));
        self::assertSame(DeliveryStatus::values(DeliveryStatus::resendable()), self::goList($go, 'destinationResendStatuses'));
        self::assertStringContainsString('enumFlag(destinationDeliveriesCmd, "status", destinationDeliveryStatuses)', $go);
        self::assertStringContainsString('enumFlag(destinationResendCmd, "status", destinationResendStatuses)', $go);
        self::assertStringContainsString('--status ' . implode('|', DeliveryStatus::values(DeliveryStatus::resendable())) . ')', $go, 'the resend command\'s usage line');
    }

    /** The next 600 bytes after $anchor. */
    private static function after(string $spec, string $anchor): string
    {
        $at = strpos($spec, $anchor);
        self::assertNotFalse($at, $anchor . ' is not in docs/openapi.yaml');

        return substr($spec, $at, 600);
    }

    /** @return list<string> */
    private static function goList(string $go, string $name): array
    {
        self::assertSame(1, preg_match('/^var ' . $name . ' = newEnum\(\[\]string\{([^}]*)\}\)$/m', $go, $m), $name . ' is not a plain newEnum list in destination.go');
        preg_match_all('/"([^"]*)"/', $m[1], $values);

        return $values[1];
    }
}

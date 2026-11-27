<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Notifications\NotificationStatus;
use Tests\Support\LifecycleContract;

/**
 * The notification outbox's transition table, whole: a move that is not
 * listed here is not one the outbox may make.
 */
final class NotificationStatusTest extends TestCase
{
    private const TABLE = [
        'pending' => ['sent', 'failed', 'cancelled', 'withheld'],
        'sent' => [],
        'failed' => [],
        'cancelled' => ['pending', 'sent'],
        'suppressed' => ['cancelled'],
        'withheld' => [],
    ];

    public function testTheTableIsExactlyThis(): void
    {
        $table = [];
        foreach (NotificationStatus::cases() as $state) {
            $table[$state->value] = array_map(static fn (NotificationStatus $s): string => $s->value, $state->next());
        }
        self::assertSame(self::TABLE, $table);
        self::assertSame([], LifecycleContract::violations(NotificationStatus::class));
        self::assertSame(['pending', 'suppressed', 'withheld'], array_map(static fn (NotificationStatus $s): string => $s->value, NotificationStatus::initial()));
        self::assertSame(array_keys(self::TABLE), NotificationStatus::values());
    }

    public function testSentFailedAndWithheldAreTerminal(): void
    {
        foreach (NotificationStatus::cases() as $state) {
            self::assertSame(
                in_array($state, [NotificationStatus::Sent, NotificationStatus::Failed, NotificationStatus::Withheld], true),
                $state->isTerminal(),
                $state->value
            );
        }
    }

    /** @return iterable<string, array{NotificationStatus, NotificationStatus}> */
    public static function closedMoves(): iterable
    {
        // The worker's unguarded marks made each of these when a cancel landed mid-send.
        yield 'a cancelled row is not failed by a send that lost the race' => [NotificationStatus::Cancelled, NotificationStatus::Failed];
        yield 'a cancelled row is not withheld' => [NotificationStatus::Cancelled, NotificationStatus::Withheld];
        yield 'a withheld row is never sent' => [NotificationStatus::Withheld, NotificationStatus::Sent];
        yield 'a withheld row does not wait again' => [NotificationStatus::Withheld, NotificationStatus::Pending];
        yield 'a sent row is not cancelled' => [NotificationStatus::Sent, NotificationStatus::Cancelled];
        yield 'a sent row does not wait again' => [NotificationStatus::Sent, NotificationStatus::Pending];
        yield 'a failed row is not retried' => [NotificationStatus::Failed, NotificationStatus::Pending];
        yield 'a failed row is not cancelled' => [NotificationStatus::Failed, NotificationStatus::Cancelled];
        yield 'a suppressed row is never queued' => [NotificationStatus::Suppressed, NotificationStatus::Pending];
        yield 'a suppressed row is never sent' => [NotificationStatus::Suppressed, NotificationStatus::Sent];
    }

    /** @dataProvider closedMoves */
    public function testAMoveTheTableDoesNotHoldIsRefused(NotificationStatus $from, NotificationStatus $to): void
    {
        self::assertFalse($from->canMoveTo($to));
        $this->expectException(IllegalTransition::class);
        $from->moveTo($to);
    }

    public function testAnUpdateToSentNamesPendingAndCancelledOnly(): void
    {
        self::assertSame(
            ['sql' => 'status IN (?, ?)', 'binds' => ['pending', 'cancelled'], 'types' => 'ss'],
            NotificationStatus::whereMayMoveTo(NotificationStatus::Sent, 'status')
        );
        self::assertSame([NotificationStatus::Pending], NotificationStatus::sources(NotificationStatus::Failed));
        self::assertSame([NotificationStatus::Pending], NotificationStatus::sources(NotificationStatus::Withheld));
        self::assertSame([NotificationStatus::Cancelled], NotificationStatus::sources(NotificationStatus::Pending));
    }

    public function testWhetherARowMayHaveGoneOutCountsItsAttempts(): void
    {
        $went = [];
        foreach (NotificationStatus::cases() as $state) {
            $went[$state->value] = [$state->mayHaveGoneOut(0), $state->mayHaveGoneOut(1)];
        }
        self::assertSame([
            'pending' => [false, true],
            'sent' => [true, true],
            'failed' => [true, true],
            'cancelled' => [false, true],
            'suppressed' => [false, true],
            'withheld' => [false, true],
        ], $went);
    }

    public function testWhatRetiringAnOutcomeDoesWithItsReachedRow(): void
    {
        $fate = [];
        foreach (NotificationStatus::cases() as $state) {
            $fate[$state->value] = [$state->reachedOnRetirement(0), $state->reachedOnRetirement(2)];
        }
        self::assertSame([
            'pending' => ['cancel', 'retract'],
            'sent' => ['retract', 'retract'],
            'failed' => ['retract', 'retract'],
            'cancelled' => ['nothing', 'nothing'],
            'suppressed' => ['retract', 'retract'],
            'withheld' => ['nothing', 'retract'],
        ], $fate);
    }

    public function testTheCountsTakeAnAliasAndNothingElse(): void
    {
        self::assertStringContainsString('n.status', NotificationStatus::retirementCountsSql('n.'));
        self::assertStringNotContainsString('.', NotificationStatus::retirementCountsSql());
        $this->expectException(\InvalidArgumentException::class);
        NotificationStatus::retirementCountsSql('n.status = 1 OR n.');
    }
}

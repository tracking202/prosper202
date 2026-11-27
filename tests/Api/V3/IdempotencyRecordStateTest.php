<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\IdempotencyAnswer;
use Api\V3\Support\IdempotencyRecordState as S;
use Api\V3\Support\IdempotencyReply;
use PHPUnit\Framework\TestCase;
use Tests\Support\LifecycleContract;

/** The Idempotency-Key record's transition table and its one reader of the fields. */
final class IdempotencyRecordStateTest extends TestCase
{
    public function testTheTableIsExactlyThis(): void
    {
        self::assertSame([], LifecycleContract::violations(S::class));
        self::assertSame([S::Free], S::initial());
        $table = [];
        foreach (S::cases() as $from) {
            $table[$from->value] = array_map(static fn (S $to): string => $to->value, $from->next());
        }
        self::assertSame([
            'free' => ['claimed', 'committed'],
            'claimed' => ['free', 'indeterminate', 'committed'],
            'indeterminate' => [],
            'committed' => [],
        ], $table);
    }

    public function testAnUnconfirmedWriteIsNeverRunAgain(): void
    {
        // CLAUDE.md #34: unknown is spent, never retryable.
        self::assertTrue(S::Indeterminate->isTerminal());
        self::assertTrue(S::Committed->isTerminal());
        self::assertSame([S::Claimed], S::sources(S::Free), 'only the request holding a claim frees it');
        self::assertSame([S::Claimed], S::sources(S::Indeterminate));
    }

    public function testEachBuilderWritesTheStateItNames(): void
    {
        $claim = S::claim('fp', 1000);
        self::assertSame(S::Claimed, S::ofRecord($claim));
        self::assertSame(1000, S::claimedAt($claim));
        self::assertSame('fp', S::fingerprintOf($claim));
        self::assertSame(S::Indeterminate, S::ofRecord(S::marked($claim)));
        self::assertSame('fp', S::fingerprintOf(S::marked($claim)));
        $committed = S::committed(['data' => []], 'fp', 1000);
        self::assertSame(S::Committed, S::ofRecord($committed));
        self::assertSame(['data' => []], S::responseOf($committed));
        self::assertSame(S::Committed, S::ofRecord(S::marked($committed)), 'a response is the answer whatever else is set');
    }

    public function testARecordThatIsThereAndUnreadableIsSpentNotFree(): void
    {
        self::assertSame(S::Free, S::ofRecord(null));
        $unreadable = [
            [], ['stored_at' => 5], ['claimed_at' => 0], ['claimed_at' => '17'], ['response' => 'x'],
            ['indeterminate' => 1], 'claimed', 7, false,
        ];
        foreach ($unreadable as $record) {
            self::assertSame(S::Indeterminate, S::ofRecord($record), json_encode($record));
        }
        self::assertSame('', S::fingerprintOf(['fingerprint' => 5]));
        self::assertSame('', S::fingerprintOf('x'));
    }

    public function testAReplyReadsAsTheArrayItsOlderReadersTake(): void
    {
        self::assertSame(
            ['state' => 'replay', 'response' => ['data' => 1]],
            (new IdempotencyReply(IdempotencyAnswer::Replay, ['data' => 1]))->toArray()
        );
        self::assertSame(
            ['miss', 'claimed', 'replay', 'in_flight', 'indeterminate', 'mismatch'],
            array_map(static fn (IdempotencyAnswer $a): string => $a->value, IdempotencyAnswer::cases())
        );
    }
}

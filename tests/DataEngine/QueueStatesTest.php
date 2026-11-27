<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\DirtyHourState;
use Prosper202\DataEngine\RebuildWindowState;
use Prosper202\Lifecycle\IllegalTransition;
use Prosper202\Lifecycle\UnknownState;
use Tests\Support\LifecycleContract;

/**
 * The transition tables of the data engine's two queues, and the one
 * reading of each table's flag pair.
 */
final class QueueStatesTest extends TestCase
{
    public function testBothHoldTheLifecycleContract(): void
    {
        self::assertSame([], LifecycleContract::violations(RebuildWindowState::class));
        self::assertSame([], LifecycleContract::violations(DirtyHourState::class));
    }

    public function testARebuildWindowIsClaimedThenMarkedOrReleased(): void
    {
        self::assertSame([RebuildWindowState::Pending], RebuildWindowState::initial());
        self::assertSame(
            ['pending' => ['processing'], 'processing' => ['completed', 'pending'], 'completed' => []],
            self::table(RebuildWindowState::cases())
        );
        self::assertTrue(RebuildWindowState::Completed->isTerminal());
        self::assertFalse(RebuildWindowState::Processing->isTerminal());
    }

    /**
     * @dataProvider movesNoWindowMakes
     */
    public function testAWindowRefuses(RebuildWindowState $from, RebuildWindowState $to): void
    {
        $this->expectException(IllegalTransition::class);
        $from->moveTo($to);
    }

    /** @return iterable<string, array{RebuildWindowState, RebuildWindowState}> */
    public static function movesNoWindowMakes(): iterable
    {
        yield 'done without a claim' => [RebuildWindowState::Pending, RebuildWindowState::Completed];
        yield 'a done window claimed again' => [RebuildWindowState::Completed, RebuildWindowState::Processing];
        yield 'a done window queued again' => [RebuildWindowState::Completed, RebuildWindowState::Pending];
    }

    public function testADirtyHourIsTakenThenMarkedAndAnyStateIsQueuedAgain(): void
    {
        self::assertSame([DirtyHourState::Queued], DirtyHourState::initial());
        self::assertSame(['queued' => ['taken'], 'taken' => ['queued']], self::table(DirtyHourState::cases()));
        // DirtyHours' upsert names no from-state: every other state may become Queued.
        self::assertSame([DirtyHourState::Taken], DirtyHourState::sources(DirtyHourState::Queued));
        // A range rolled up is a row deleted, so nothing is terminal.
        self::assertFalse(DirtyHourState::Queued->isTerminal());
        self::assertFalse(DirtyHourState::Taken->isTerminal());
    }

    /**
     * @dataProvider windowFlags
     */
    public function testAWindowsFlagsReadAs(mixed $processing, mixed $processed, RebuildWindowState $state): void
    {
        self::assertSame($state, RebuildWindowState::ofRow($processing, $processed));
    }

    /** @return iterable<string, array{mixed, mixed, RebuildWindowState}> */
    public static function windowFlags(): iterable
    {
        yield 'neither' => ['0', '0', RebuildWindowState::Pending];
        yield 'processing' => ['1', '0', RebuildWindowState::Processing];
        yield 'processed' => ['0', '1', RebuildWindowState::Completed];
        yield 'both, which no writer leaves' => ['1', '1', RebuildWindowState::Completed];
        yield 'native integers' => [1, 0, RebuildWindowState::Processing];
        yield 'any non-zero tinyint is set' => ['2', '0', RebuildWindowState::Processing];
    }

    /**
     * @dataProvider dirtyHourFlags
     */
    public function testADirtyHoursFlagReadsAs(mixed $processed, DirtyHourState $state): void
    {
        self::assertSame($state, DirtyHourState::ofRow($processed));
    }

    /** @return iterable<string, array{mixed, DirtyHourState}> */
    public static function dirtyHourFlags(): iterable
    {
        yield 'not processed' => ['0', DirtyHourState::Queued];
        yield 'processed' => ['1', DirtyHourState::Taken];
        yield 'native integers' => [1, DirtyHourState::Taken];
    }

    /**
     * @dataProvider unreadableFlags
     */
    public function testAFlagThatIsNotANumberIsRefusedNotReadAsUnset(mixed $flag): void
    {
        try {
            RebuildWindowState::ofRow($flag, '0', 'window 5-9');
            self::fail('read as a state');
        } catch (UnknownState $e) {
            self::assertStringStartsWith('window 5-9.processing holds the state ', $e->getMessage());
        }
        $this->expectException(UnknownState::class);
        DirtyHourState::ofRow($flag);
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableFlags(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'a word' => ['yes'];
        yield 'a bool' => [true];
        yield 'a raw byte' => ["\x01"];
    }

    /** What a state is written with reads back as that state. */
    public function testEachStatesAssignmentReadsAsThatState(): void
    {
        foreach (RebuildWindowState::cases() as $state) {
            $flags = self::flags($state->assignment());
            self::assertSame(['processing', 'processed'], array_keys($flags), $state->name);
            self::assertSame($state, RebuildWindowState::ofRow($flags['processing'], $flags['processed']));
        }
        foreach (DirtyHourState::cases() as $state) {
            $flags = self::flags($state->assignment());
            self::assertSame(['processed', 'deleted'], array_keys($flags), $state->name);
            self::assertSame($state, DirtyHourState::ofRow($flags['processed']));
            self::assertSame($flags['processed'], $flags['deleted'], 'the two flags are written together');
        }
    }

    /** GET /system/dataengine answers a window's state by name; the document lists the names. */
    public function testTheApiDocumentListsAWindowsStates(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $found = preg_match(
            '/^ +recent_jobs:\n(?:.*\n){1,30}? +status:\n +type: string\n +enum: \[([^\]\n]*)\]/m',
            $spec,
            $m
        );
        self::assertSame(1, $found, 'the status of a recent job is not where this test looks');
        self::assertSame(
            array_column(RebuildWindowState::cases(), 'value'),
            array_map('trim', explode(',', $m[1]))
        );
    }

    /**
     * @param list<\Prosper202\Lifecycle\State> $cases
     * @return array<string, list<string>>
     */
    private static function table(array $cases): array
    {
        $table = [];
        foreach ($cases as $case) {
            $table[(string) $case->value] = array_map(static fn ($to): string => (string) $to->value, $case->next());
        }

        return $table;
    }

    /** @return array<string, string> a SET list of `column = digit` as column => digit */
    private static function flags(string $assignment): array
    {
        $flags = [];
        foreach (explode(', ', $assignment) as $part) {
            self::assertMatchesRegularExpression('/\A[a-z]+ = [01]\z/', $part);
            [$column, $value] = explode(' = ', $part);
            $flags[$column] = $value;
        }

        return $flags;
    }
}

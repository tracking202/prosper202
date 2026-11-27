<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ExportStore;
use Prosper202\Attribution\Model;
use Prosper202\Attribution\ModelStatus;
use Prosper202\Lifecycle\IllegalTransition;
use Tests\Support\LifecycleContract;

/**
 * The transition tables of an attribution model's status and an export
 * job's, move by move: what each state may become, and the moves that are
 * refused.
 */
final class StatusLifecyclesTest extends TestCase
{
    /**
     * @param class-string<ModelStatus|ExportStatus> $enum
     * @return array<string, list<string>>
     */
    private static function table(string $enum): array
    {
        $table = [];
        foreach ($enum::cases() as $case) {
            $table[$case->value] = array_map(static fn ($to): string => $to->value, $case->next());
        }

        return $table;
    }

    public function testAModelsStatusMovesOnlyAsListed(): void
    {
        self::assertSame([], LifecycleContract::violations(ModelStatus::class));
        self::assertSame([
            'active' => ['inactive', 'invalid'],
            'inactive' => ['active'],
            'invalid' => ['active', 'inactive'],
        ], self::table(ModelStatus::class));
        self::assertSame([ModelStatus::Active, ModelStatus::Inactive], ModelStatus::initial(), 'no model is created invalid');
        self::assertSame([ModelStatus::Active, ModelStatus::Inactive], ModelStatus::requestable(), 'and no request asks for it');
        self::assertSame([ModelStatus::Active], ModelStatus::sources(ModelStatus::Invalid), 'only a computed model is found invalid');
        foreach (ModelStatus::cases() as $case) {
            self::assertFalse($case->isTerminal(), $case->value);
            self::assertSame($case === ModelStatus::Active, $case->computes(), $case->value);
        }
    }

    public function testASwitchedOffModelIsNotFoundInvalid(): void
    {
        $this->expectException(IllegalTransition::class);
        ModelStatus::Inactive->moveTo(ModelStatus::Invalid);
    }

    public function testTheModelConstantsAreTheStates(): void
    {
        self::assertSame(ModelStatus::values(), [Model::STATUS_ACTIVE, Model::STATUS_INACTIVE, Model::STATUS_INVALID]);
    }

    public function testAnExportsStatusMovesOnlyAsListed(): void
    {
        self::assertSame([], LifecycleContract::violations(ExportStatus::class));
        self::assertSame([
            'pending' => ['running'],
            'running' => ['completed', 'pending', 'failed'],
            'completed' => [],
            'failed' => ['pending'],
        ], self::table(ExportStatus::class));
        self::assertSame([ExportStatus::Pending], ExportStatus::initial());
        self::assertSame([ExportStatus::Completed], array_values(array_filter(ExportStatus::cases(), static fn (ExportStatus $s): bool => $s->isTerminal())));
        self::assertSame(ExportStatus::values(), ExportStore::STATUSES);
    }

    /** @dataProvider refusedExportMoves */
    public function testAnExportMoveOutsideTheTableIsRefused(ExportStatus $from, ExportStatus $to): void
    {
        self::assertFalse($from->canMoveTo($to));
        $this->expectException(IllegalTransition::class);
        $from->moveTo($to);
    }

    /** @return iterable<string, array{ExportStatus, ExportStatus}> */
    public static function refusedExportMoves(): iterable
    {
        yield 'a job is ended only by the run that holds it' => [ExportStatus::Pending, ExportStatus::Completed];
        yield 'or failed by it' => [ExportStatus::Pending, ExportStatus::Failed];
        yield 'a completed job is not run again' => [ExportStatus::Completed, ExportStatus::Pending];
        yield 'nor claimed' => [ExportStatus::Completed, ExportStatus::Running];
        yield 'nor failed afterwards' => [ExportStatus::Completed, ExportStatus::Failed];
        yield 'a failed job is queued, not claimed' => [ExportStatus::Failed, ExportStatus::Running];
        yield 'and does not complete' => [ExportStatus::Failed, ExportStatus::Completed];
    }
}

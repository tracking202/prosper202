<?php

declare(strict_types=1);

namespace Tests\Goals;

use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * GoalsController reads every id it is given (registration_id, scope_id,
 * goal ids in a body) through one parser, and a refusal names the range of
 * ids that exist whatever was wrong with the value. A 20-digit id used to
 * be answered "must be a whole number", which it is.
 */
final class GoalIdParamTest extends TestCase
{
    private static function id(mixed $value, bool $allowZero = false): int
    {
        $m = new \ReflectionMethod(GoalsController::class, 'id');

        return $m->invoke(null, $value, 'goal_id', $allowZero);
    }

    public function testAnIdInRangeIsRead(): void
    {
        self::assertSame(7, self::id('7'));
        self::assertSame(4294967295, self::id(4294967295));
        self::assertSame(0, self::id('0', allowZero: true));
    }

    /** @return iterable<string, array{mixed, bool, string}> */
    public static function refused(): iterable
    {
        yield '20 digits' => ['99999999999999999999', false, 'must be a whole number from 1 to 4294967295'];
        yield 'past the column' => [4294967296, false, 'must be a whole number from 1 to 4294967295'];
        yield 'zero where an id is needed' => [0, false, 'must be a whole number from 1 to 4294967295'];
        yield 'fraction' => ['1.5', false, 'must be a whole number from 1 to 4294967295'];
        yield 'text, zero allowed' => ['abc', true, 'must be a whole number from 0 to 4294967295'];
        yield 'negative, zero allowed' => [-1, true, 'must be a whole number from 0 to 4294967295'];
    }

    /** @dataProvider refused */
    public function testARefusalNamesTheRange(mixed $value, bool $allowZero, string $message): void
    {
        try {
            self::id($value, $allowZero);
            self::fail('expected ' . var_export($value, true) . ' to be refused');
        } catch (ValidationException $e) {
            self::assertSame(['goal_id' => $message], $e->getFieldErrors());
        }
    }
}

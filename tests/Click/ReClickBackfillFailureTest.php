<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ReClickBackfill;
use Prosper202\Database\Exceptions\QueryException;

/**
 * Which failures count towards stepping a click over for good: a server
 * error that is not the moment's, or a value of the click's rows that read
 * wrong. ReClickBackfillIntegrationTest raises errnos through the real walk;
 * these are the chains no trigger can raise.
 */
final class ReClickBackfillFailureTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, bool}> */
    public static function failures(): iterable
    {
        yield 'a server error, tagged' => [new QueryException('MySQL execute failed: x [errno 1644]'), true];
        yield 'a server error, raised' => [new \mysqli_sql_exception('x', 1062), true];
        yield 'a value the click holds' => [new \UnexpectedValueException('202_clicks.click_cpc read as string'), true];
        yield 'a value, wrapped' => [new \RuntimeException('repair', 0, new \UnexpectedValueException('v')), true];
        yield 'a server error, wrapped with no errno' => [
            new \RuntimeException('repair', 0, new \mysqli_sql_exception('x', 1264)),
            true,
        ];
        yield 'a lock wait, tagged' => [new QueryException('MySQL execute failed: x [errno 1205]'), false];
        yield 'a lock wait, wrapped' => [
            new \RuntimeException('repair', 0, new \mysqli_sql_exception('x', 1205)),
            false,
        ];
        yield 'a lost connection under a value error' => [
            new \UnexpectedValueException('v', 0, new QueryException('MySQL execute failed: gone [errno 2006]')),
            false,
        ];
        yield 'a storage engine error' => [new \mysqli_sql_exception('x', 1030), false];
        yield 'a network write' => [new QueryException('MySQL execute failed: x [errno 1160]'), false];
        yield 'a limit reached' => [new \mysqli_sql_exception('x', 1226), false];
        yield 'a lock table the click\'s own rows fill' => [
            new QueryException('MySQL execute failed: x [errno 1206]'),
            true,
        ];
        yield 'a statement to prepare again' => [new QueryException('MySQL execute failed: x [errno 1615]'), false];
        yield 'a permission refused' => [new QueryException('MySQL execute failed: x [errno 1142]'), false];
        yield 'a column missing, wrapped' => [
            new \RuntimeException('repair', 0, new \mysqli_sql_exception('x', 1054)),
            false,
        ];
        yield 'a type error' => [new \TypeError('int expected'), false];
        yield 'a logic error' => [new \LogicException('Click 9 has no first visit under its lock'), false];
        yield 'a runtime error with no errno' => [new QueryException('MySQL execute failed: x'), false];
        yield 'an errno anywhere but last' => [new QueryException('x [errno 1062] and more'), false];
    }

    /** @dataProvider failures */
    public function testWhatCountsTowardsAStepOver(\Throwable $failure, bool $counts): void
    {
        $isTheClicks = new \ReflectionMethod(ReClickBackfill::class, 'isTheClicks');

        self::assertSame($counts, $isTheClicks->invoke(null, $failure));
    }
}

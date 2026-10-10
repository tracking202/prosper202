<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\QueryInt;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The LTV reads' whole-number parameters (limit, offset, days, months).
 *
 * They were read as `max(1, min(500, (int) ($params['limit'] ?? 50)))`: the
 * cast made anything that is not a number 0, and the clamp made 0 the
 * minimum, so `months=abc` answered one cohort month and `days=0` one day of
 * engagement, each as though it had been asked for, and `limit=1000` came
 * back as 500 rows that read as all of them. Each is now a 422 naming the
 * parameter and its range (QueryInt), as GET /reports/* and GET /clicks
 * refuse them, and nothing is read.
 */
final class LtvIntegerParamTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<mixed>}> read, parameter, refused values */
    public static function parameters(): iterable
    {
        $notWhole = ['abc', '-1', '1.5', '1e2', ' 5', '5 ', '+5', '0x10', ['5'], true, 1.0];
        foreach (['customers', 'breakdown', 'products', 'abm', 'listCompanies', 'listSubscriptions'] as $read) {
            yield "$read limit" => [$read, 'limit', [...$notWhole, '0', 0, '501', '99999999999999999999']];
            yield "$read offset" => [$read, 'offset', $notWhole];
        }
        yield 'cohorts months' => ['cohorts', 'months', [...$notWhole, '0', '25']];
        yield 'abm days' => ['abm', 'days', [...$notWhole, '0', '366']];
    }

    /**
     * @dataProvider parameters
     * @param list<mixed> $refused
     */
    public function testAValueThatIsNotAWholeNumberInRangeIsRefusedNamingIt(string $read, string $param, array $refused): void
    {
        foreach ($refused as $value) {
            $conn = new FakeMysqliConnection();
            $shown = var_export($value, true);
            try {
                (new LtvController($conn, 7))->{$read}([$param => $value]);
                self::fail("$read($param=$shown) answered instead of refusing");
            } catch (ValidationException $e) {
                self::assertSame([$param], array_keys($e->getFieldErrors()), "$read($param=$shown): the 422 names $param");
                self::assertStringStartsWith('A whole number, ', $e->getFieldErrors()[$param]);
            }
            self::assertSame([], $conn->preparedSql, "$read($param=$shown): nothing was read");
        }
    }

    public function testAbsentAndEmptyAreTheDefaultAndTheRangeIsInclusive(): void
    {
        self::assertSame(50, QueryInt::param([], 'limit', 50, 1, 500));
        self::assertSame(50, QueryInt::param(['limit' => ''], 'limit', 50, 1, 500));
        self::assertSame(50, QueryInt::param(['limit' => null], 'limit', 50, 1, 500));
        self::assertSame(1, QueryInt::param(['limit' => '1'], 'limit', 50, 1, 500));
        self::assertSame(500, QueryInt::param(['limit' => '500'], 'limit', 50, 1, 500));
        self::assertSame(500, QueryInt::param(['limit' => 500], 'limit', 50, 1, 500));
        self::assertSame(0, QueryInt::param(['offset' => '0'], 'offset', 0, 0, PHP_INT_MAX));
        self::assertSame(7, QueryInt::param(['offset' => '007'], 'offset', 0, 0, PHP_INT_MAX), 'leading zeros are the same number');
    }

    public function testTheMessageSaysTheRangeAndWhatIsCounted(): void
    {
        try {
            QueryInt::param(['months' => '25'], 'months', 6, 1, 24, 'acquisition months, newest first');
            self::fail('25 months was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['months' => 'A whole number, 1 to 24: acquisition months, newest first'], $e->getFieldErrors());
        }
        try {
            QueryInt::param(['offset' => '-1'], 'offset', 0, 0, PHP_INT_MAX);
            self::fail('-1 was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['offset' => 'A whole number, 0 or more'], $e->getFieldErrors());
        }
    }
}

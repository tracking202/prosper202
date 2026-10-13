<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * `period` on the LTV reads (summary, customers, breakdown, predict).
 *
 * The parse was `!empty($params['period'])`, and empty('0') is true, so
 * period=0 named no period: the read answered 200 over the default window
 * (all time) as though that were the window asked for, while period=1 was
 * refused. 0 is not one of TimeBound::PERIODS and means nothing here, so it
 * is refused with the list, as the reports refuse it (ReportFilter). Absent
 * and '' stay "no period", as ReportFilter reads them.
 *
 * Every case drives a public read of the real controller; the refusals
 * happen before any statement is prepared, so the fake connection is only
 * reached by the accepted values.
 */
final class LtvPeriodParamTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function reads(): iterable
    {
        foreach (['summary', 'customers', 'breakdown', 'predict'] as $read) {
            yield $read => [$read];
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function refusedPeriods(): iterable
    {
        foreach (['summary', 'customers', 'breakdown', 'predict'] as $read) {
            yield "$read, '0'" => [$read, '0'];
            yield "$read, int 0" => [$read, 0];
            yield "$read, '00'" => [$read, '00'];
            yield "$read, ' '" => [$read, ' '];
            yield "$read, a list" => [$read, ['today']];
        }
    }

    /**
     * @dataProvider refusedPeriods
     */
    public function testAPeriodThatIsNotOneOfThePeriodsIsRefusedNamingPeriod(string $read, mixed $period): void
    {
        $conn = new FakeMysqliConnection();
        try {
            (new LtvController($conn, 7))->{$read}(['period' => $period]);
            self::fail("$read(period=" . var_export($period, true) . ') answered instead of refusing');
        } catch (ValidationException $e) {
            self::assertSame(['period'], array_keys($e->getFieldErrors()), 'the 422 names period');
            self::assertStringStartsWith('Valid: today, yesterday', $e->getFieldErrors()['period']);
        }
        self::assertSame([], $conn->preparedSql, 'nothing was read for a refused period');
    }

    /**
     * Absent, '' and alltime are the same window (none); a named period is
     * not. Read through what the controller asked the database.
     *
     * @dataProvider reads
     */
    public function testAbsentAndEmptyAreNoPeriodAndANamedPeriodIsHonoured(string $read): void
    {
        $absent = $this->sqlFor($read, []);
        self::assertNotSame([], $absent, "$read read nothing; the comparison below would be vacuous");
        self::assertSame($absent, $this->sqlFor($read, ['period' => '']), "period='' is no period");
        self::assertSame($absent, $this->sqlFor($read, ['period' => 'alltime']), 'alltime is no bound');
        self::assertNotSame($absent, $this->sqlFor($read, ['period' => 'last7']), 'last7 bounds the read');
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function sqlFor(string $read, array $params): array
    {
        $conn = new FakeMysqliConnection();
        try {
            (new LtvController($conn, 7))->{$read}($params);
        } catch (ValidationException $e) {
            self::fail("$read(" . json_encode($params) . ') was refused: ' . $e->getMessage());
        }

        return $conn->preparedSql;
    }
}

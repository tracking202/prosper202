<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\TimeBound;
use PHPUnit\Framework\TestCase;

/**
 * time_from/time_to were read with (int): `2026-10-01` was 2026 (a second in
 * 1970) and the list answered with every row, as if it were the ones asked
 * for. These are the values TimeBound reads and the ones it refuses.
 */
final class TimeBoundTest extends TestCase
{
    private static function newYork(): string
    {
        return 'America/New_York';
    }

    /** @return iterable<string, array{0: string, 1: mixed, 2: ?int}> */
    public static function accepted(): iterable
    {
        yield 'unix seconds' => ['time_from', '1759276800', 1759276800];
        yield 'unix seconds as an integer' => ['time_from', 1759276800, 1759276800];
        yield 'zero is no bound' => ['time_to', '0', null];
        yield 'absent' => ['time_to', '', null];
        yield 'a date starts at midnight in the account timezone' => ['time_from', '2026-10-01', 1790827200];
        yield 'a date ends at its last second' => ['time_to', '2026-10-01', 1790913599];
        yield 'the day the clocks go forward ends at 23:59:59 local' => ['time_to', '2026-03-08', 1773028799];
        yield 'a UTC time without seconds' => ['time_from', '2026-10-01T09:30Z', 1790847000];
        yield 'a time with an offset' => ['time_from', '2026-10-01T09:30:00+02:00', 1790839800];
    }

    /** @dataProvider accepted */
    public function testReads(string $field, mixed $value, ?int $expected): void
    {
        self::assertSame($expected, TimeBound::parse([$field => $value], $field, self::newYork(...)));
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function refused(): iterable
    {
        yield 'a date that does not exist' => ['2026-02-30', 'is not a date'];
        yield 'a time that does not exist' => ['2026-02-28T25:00Z', 'is not a real time'];
        yield 'a time on a date that does not exist' => ['2026-02-30T09:00Z', 'is not a real time'];
        yield 'milliseconds' => ['1759276800000', 'send seconds (1759276800)'];
        yield 'negative' => ['-5', 'Unix seconds'];
        yield 'scientific notation' => ['1.5e9', 'Unix seconds'];
        yield 'a time with no offset' => ['2026-10-01 09:30', 'Unix seconds'];
        yield 'true' => [true, 'Unix seconds'];
        yield 'a list' => [['1'], 'Unix seconds'];
    }

    /** @dataProvider refused */
    public function testRefuses(mixed $value, string $says): void
    {
        try {
            TimeBound::parse(['time_from' => $value], 'time_from', self::newYork(...));
            self::fail('read ' . json_encode($value));
        } catch (ValidationException $e) {
            self::assertStringContainsString($says, $e->getFieldErrors()['time_from'] ?? '');
        }
    }

    public function testABackwardsRangeIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        TimeBound::window(['time_from' => '2026-10-02', 'time_to' => '2026-10-01'], self::newYork(...));
    }

    public function testTheTimezoneIsAskedForOnlyWhenADateNeedsIt(): void
    {
        $asked = false;
        $tz = static function () use (&$asked): string {
            $asked = true;

            return 'UTC';
        };
        self::assertSame([5, 10], TimeBound::window(['time_from' => '5', 'time_to' => '10'], $tz));
        self::assertFalse($asked, 'a query per request for nothing');
    }
}

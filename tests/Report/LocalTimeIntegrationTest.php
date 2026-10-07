<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\LocalTime;

/**
 * LocalTime's SQL gives the same wall-clock time PHP gives, on a real
 * server, whatever the server knows about zones: the moments on each side of
 * every offset change, in zones with daylight saving, half-hour and
 * quarter-hour offsets, under a session zone set to something unrelated.
 *
 * Skips unless a database is reachable (P202_TEST_DB_HOST, P202_TEST_DB_PORT,
 * P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME); it creates and
 * drops a temporary table only.
 *
 * @group integration
 */
final class LocalTimeIntegrationTest extends TestCase
{
    private const array ZONES = [
        'UTC', 'America/New_York', 'Europe/London', 'Asia/Kolkata', 'Asia/Kathmandu',
        'America/St_Johns', 'Australia/Lord_Howe', 'Pacific/Chatham', 'Pacific/Apia',
        // Zones PHP keeps no history for: an abbreviation (what the report
        // engine puts in force when the session names no zone) and offsets.
        'GMT', 'EST', '+05:30', '-03:00',
    ];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            self::$db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            ) ?: null;
        } catch (\Throwable) {
            self::$db = null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    /** @return list<int> moments on each side of every offset change, and some in between */
    private static function moments(string $zone, int $now): array
    {
        $moments = [0, 86399, 1_000_000_000, $now];
        foreach (LocalTime::offsets($zone, $now) as [$from]) {
            array_push($moments, $from - 1, $from, $from + 1, $from + 1799, $from + 3600);
        }
        for ($t = 0; $t < $now; $t += 7_777_777) {
            $moments[] = $t;
        }

        return array_values(array_unique(array_filter($moments, static fn (int $t): bool => $t >= 0)));
    }

    public function testTheSqlAgreesWithPhpEverywhere(): void
    {
        $now = 1_791_331_200; // 2026-10-07, fixed so a run is reproducible
        foreach (['SYSTEM', '+07:00', '-09:30'] as $sessionZone) {
            self::assertTrue(self::$db->query("SET time_zone = '$sessionZone'"), self::$db->error);
            foreach (self::ZONES as $zone) {
                $moments = self::moments($zone, $now);
                self::assertTrue(self::$db->query('DROP TEMPORARY TABLE IF EXISTS lt_moments'));
                self::assertTrue(self::$db->query('CREATE TEMPORARY TABLE lt_moments (t INT UNSIGNED NOT NULL)'), self::$db->error);
                self::assertTrue(self::$db->query('INSERT INTO lt_moments (t) VALUES (' . implode('), (', $moments) . ')'), self::$db->error);

                $local = LocalTime::datetimeSql('m.t', $zone, $now);
                $result = self::$db->query("SELECT m.t, DATE_FORMAT($local, '%Y-%m-%d %H:%i:%s') AS wall,"
                    . " HOUR($local) AS h, WEEKDAY($local) AS wd, DATE_FORMAT($local, '%x-W%v') AS wk FROM lt_moments m");
                self::assertNotFalse($result, self::$db->error);
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                self::assertCount(count($moments), $rows, "$zone: every moment read back");

                $tz = new \DateTimeZone($zone);
                foreach ($rows as $row) {
                    $php = (new \DateTimeImmutable('@' . $row['t']))->setTimezone($tz);
                    $at = "$zone, t={$row['t']}, session $sessionZone";
                    self::assertSame($php->format('Y-m-d H:i:s'), $row['wall'], $at);
                    self::assertSame((int) $php->format('G'), (int) $row['h'], "$at: hour");
                    self::assertSame((int) $php->format('N') - 1, (int) $row['wd'], "$at: weekday");
                    self::assertSame($php->format('o-\WW'), $row['wk'], "$at: ISO week");
                }
            }
        }
        self::$db->query("SET time_zone = 'SYSTEM'");
    }

    /**
     * An hour number read as its first second (`r.bucket * 3600`, the
     * attribution rollup's buckets) agrees with PHP at every hour on each
     * side of every offset change.
     */
    public function testAnHourNumberTimesItsLengthIsTheHoursFirstSecond(): void
    {
        $now = 1_791_331_200;
        self::assertTrue(self::$db->query("SET time_zone = '-09:30'"), self::$db->error);
        foreach (self::ZONES as $zone) {
            $hours = [0, intdiv($now, 3600)];
            foreach (LocalTime::offsets($zone, $now) as [$from]) {
                array_push($hours, intdiv($from, 3600) - 1, intdiv($from, 3600), intdiv($from, 3600) + 1);
            }
            $hours = array_values(array_unique(array_filter($hours, static fn (int $h): bool => $h >= 0)));
            self::assertTrue(self::$db->query('DROP TEMPORARY TABLE IF EXISTS lt_hours'));
            self::assertTrue(self::$db->query('CREATE TEMPORARY TABLE lt_hours (h INT UNSIGNED NOT NULL)'), self::$db->error);
            self::assertTrue(self::$db->query('INSERT INTO lt_hours (h) VALUES (' . implode('), (', $hours) . ')'), self::$db->error);
            $local = LocalTime::datetimeSql('m.h * 3600', $zone, $now);
            $result = self::$db->query("SELECT m.h, DATE_FORMAT($local, '%Y-%m-%d %H:%i:%s') AS wall FROM lt_hours m");
            self::assertNotFalse($result, self::$db->error);
            $tz = new \DateTimeZone($zone);
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
                $php = (new \DateTimeImmutable('@' . ((int) $row['h'] * 3600)))->setTimezone($tz);
                self::assertSame($php->format('Y-m-d H:i:s'), $row['wall'], "$zone, hour {$row['h']}");
            }
        }
        self::$db->query("SET time_zone = 'SYSTEM'");
    }

    public function testAZoneWithoutChangesIsOneOffsetAndAColumnIsAColumn(): void
    {
        self::assertSame('(CAST(de.click_time AS SIGNED) + 19800)', LocalTime::secondsSql('de.click_time', 'Asia/Kolkata', 1_791_331_200));
        self::assertSame('(CAST(click_time AS SIGNED) + 0)', LocalTime::secondsSql('click_time', 'UTC', 1_791_331_200));
        self::assertSame('(CAST(click_time AS SIGNED) + 0)', LocalTime::secondsSql('click_time', 'GMT', 1_791_331_200));
        self::assertSame('(CAST(click_time AS SIGNED) + -18000)', LocalTime::secondsSql('click_time', 'EST', 1_791_331_200));
        self::assertSame('(CAST(r.bucket * 3600 AS SIGNED) + 19800)', LocalTime::secondsSql('r.bucket * 3600', 'Asia/Kolkata', 1_791_331_200));
        foreach (['de.click_time; DROP TABLE x', 'a.b.c', '1', '', 'de.click_time)', 'r.bucket * x', 'r.bucket * 0',
            'r.bucket * -1', 'r.bucket*3600', 'r.bucket * 3600 + 1', '3600 * r.bucket', 'r.bucket * 3600 * 2'] as $bad) {
            try {
                LocalTime::secondsSql($bad, 'UTC');
                self::fail("$bad was accepted as a column");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

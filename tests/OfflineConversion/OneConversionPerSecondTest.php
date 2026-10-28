<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\OfflineConversion\ClickSeconds;
use Prosper202\OfflineConversion\ExportOptions;
use Prosper202\OfflineConversion\FileLines;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\OfflineExport;

/**
 * Google and Microsoft each keep one conversion per click and conversion
 * time, to the second, whatever its Order ID. Two conversions on one click in
 * one second are both sent, the one recorded first at its own second and the
 * other at the next second no conversion on the click holds, and every fetch
 * of the same conversions gives each the same second. Every format has a
 * click to key on (the Uploads template its gclid), so no format's lines are
 * left as they were.
 */
final class OneConversionPerSecondTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const T = self::NOW - 86400;
    private const CLICK = '0123456789abcdef0123456789abcdef';
    private const OTHER_CLICK = 'fedcba9876543210fedcba9876543210';
    private const GCLID = 'EAIaIQ_' . self::CLICK;

    /** @return array<string, mixed> a row as OfflineExport::query() reads it, which every check passes */
    private static function row(int $convId, int $convTime, array $over = []): array
    {
        return $over + [
            'conv_id' => $convId,
            'click_time' => self::T - 3600,
            'conv_time' => $convTime,
            'match_method' => 'click_id',
            'counted_amount' => $convId . '.00000',
            'gclid' => 'EAIaIQ_' . ($over['msclkid'] ?? self::CLICK),
            'gbraid' => '',
            'wbraid' => '',
            'msclkid' => self::CLICK,
            'conversion_name' => 'Sale',
            'owned' => 1,
        ];
    }

    /**
     * The rows in the order query() reads them: newest second first, and
     * within one second the lowest conv_id first.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function inReadOrder(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int
            => [$b['conv_time'], $a['conv_id']] <=> [$a['conv_time'], $b['conv_id']]);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string> the file's lines below its header
     */
    private static function file(array $rows, Format $format, ?string $onlyName = null): array
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        $lines = new FileLines(new ExportOptions($format, 30, self::NOW, null, null, $onlyName), 'UTC', 'USD', $handle);
        foreach (self::inReadOrder($rows) as $row) {
            $lines->add($row);
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text === '' ? [] : explode("\r\n", substr($text, 0, -2));
    }

    /**
     * Each line's value (its conversion's id, in these rows), its second,
     * and the ids it names, which the platform keeps one conversion a second
     * of.
     *
     * @param list<string> $lines
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function sent(array $lines, Format $format): array
    {
        [$time, $value, $ids] = match ($format) {
            Format::GOOGLE => [4, 5, [0, 1, 2]],
            Format::GOOGLE_UPLOADS => [2, 3, [0]],
            Format::MICROSOFT => [1, 2, [4]],
        };

        return array_map(static function (string $line) use ($time, $value, $ids): array {
            $cells = explode(',', $line);

            return [
                (int) $cells[$value],
                (new \DateTimeImmutable($cells[$time]))->getTimestamp(),
                implode(' ', array_map(static fn (int $i): string => $cells[$i], $ids)),
            ];
        }, $lines);
    }

    /** @param list<array{0: int, 1: int, 2: string}> $sent */
    private static function assertOneASecond(array $sent, Format $format): void
    {
        $tuples = array_map(
            static fn (array $line): string => ($format === Format::MICROSOFT ? strtolower($line[2]) : $line[2])
                . ' ' . $line[1],
            $sent
        );
        self::assertSame(array_values(array_unique($tuples)), $tuples, $format->value . ': one a click a second');
    }

    /**
     * @param list<array{0: int, 1: int, 2: string}> $sent
     * @return list<array{0: int, 1: int}> each line's conversion and second
     */
    private static function seconds(array $sent): array
    {
        return array_map(static fn (array $line): array => [$line[0], $line[1]], $sent);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{0: int, 1: int}> each line's conversion and second
     */
    private static function secondsOf(array $rows, Format $format): array
    {
        return self::seconds(self::sent(self::file($rows, $format), $format));
    }

    public function testTwoConversionsOnOneClickInOneSecondAreSentASecondApart(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(14, self::T + 1)];
        foreach (Format::cases() as $format) {
            $lines = self::file($rows, $format);
            $sent = self::sent($lines, $format);
            self::assertSame(
                [[14, self::T + 1], [11, self::T], [12, self::T + 2]],
                self::seconds($sent),
                $format->value . ': the one recorded first keeps its second; the other passes the one at T + 1'
            );
            self::assertOneASecond($sent, $format);
            self::assertSame($lines, self::file($rows, $format), $format->value . ': the same rows, the same file');
        }
    }

    /** The lines as each platform reads them, Order IDs and all. */
    public function testEachFilesLinesAsWritten(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(14, self::T + 1)];
        $at = static fn (int $ts, string $format): string => gmdate('Y-m-d\TH:i:s', $ts) . $format;
        self::assertSame([
            self::GCLID . ',,,Sale,' . $at(self::T + 1, '+00:00') . ',14,USD,P202-14,,',
            self::GCLID . ',,,Sale,' . $at(self::T, '+00:00') . ',11,USD,P202-11,,',
            self::GCLID . ',,,Sale,' . $at(self::T + 2, '+00:00') . ',12,USD,P202-12,,',
        ], self::file($rows, Format::GOOGLE));
        self::assertSame([
            self::GCLID . ',Sale,' . $at(self::T + 1, '+0000') . ',14,USD,P202-14,,',
            self::GCLID . ',Sale,' . $at(self::T, '+0000') . ',11,USD,P202-11,,',
            self::GCLID . ',Sale,' . $at(self::T + 2, '+0000') . ',12,USD,P202-12,,',
        ], self::file($rows, Format::GOOGLE_UPLOADS));
        self::assertSame([
            'Sale,' . $at(self::T + 1, '+0000') . ',14,USD,' . self::CLICK . ',,',
            'Sale,' . $at(self::T, '+0000') . ',11,USD,' . self::CLICK . ',,',
            'Sale,' . $at(self::T + 2, '+0000') . ',12,USD,' . self::CLICK . ',,',
        ], self::file($rows, Format::MICROSOFT));
        foreach (Format::cases() as $format) {
            [$sql] = OfflineExport::query(new ExportOptions($format, 30, self::NOW), 1);
            self::assertStringEndsWith(
                ' ORDER BY cl.conv_time DESC, cl.conv_id ASC',
                $sql,
                $format->value . ': the one recorded first is read first'
            );
        }
    }

    public function testThreeInOneSecondTakeTheNextThreeFreeSeconds(): void
    {
        $rows = [
            self::row(11, self::T), self::row(12, self::T), self::row(13, self::T), self::row(14, self::T + 1),
            self::row(15, self::T, ['msclkid' => self::OTHER_CLICK]),
        ];
        foreach (Format::cases() as $format) {
            $lines = self::file($rows, $format);
            $sent = self::sent($lines, $format);
            self::assertSame(
                [[14, self::T + 1], [11, self::T], [12, self::T + 2], [13, self::T + 3], [15, self::T]],
                self::seconds($sent),
                $format->value . ': another click\'s conversion in the same second keeps it'
            );
            self::assertOneASecond($sent, $format);
            self::assertSame(
                $lines,
                self::file(array_reverse($rows), $format),
                $format->value . ': the same conversions, the same file'
            );
        }
    }

    /**
     * A conversion no longer sent still holds its second, so the one moved
     * past it is sent at the second the platform already has it at.
     */
    public function testARefundOrADeletionMovesNothing(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(13, self::T)];
        foreach (Format::cases() as $format) {
            $before = self::file($rows, $format);
            $sent = self::seconds(self::sent($before, $format));
            self::assertSame([[11, self::T], [12, self::T + 1], [13, self::T + 2]], $sent);
            $changed = $rows;
            $changed[0]['counted_amount'] = null;
            $changed[1]['match_method'] = 'same_ip';
            self::assertSame([$before[2]], self::file($changed, $format), $format->value);
        }
    }

    /** The platforms keep one conversion a second whatever its name: a file of one name leaves the others' seconds. */
    public function testAFileOfOneNameLeavesTheOtherNamesSeconds(): void
    {
        $rows = [self::row(11, self::T, ['conversion_name' => 'Lead']), self::row(12, self::T)];
        foreach (Format::cases() as $format) {
            $all = self::file($rows, $format);
            self::assertSame([[11, self::T], [12, self::T + 1]], self::secondsOf($rows, $format), $format->value);
            self::assertSame([$all[0]], self::file($rows, $format, 'Lead'), $format->value);
            self::assertSame([$all[1]], self::file($rows, $format, 'Sale'), $format->value);
        }
    }

    /**
     * An msclkid is a GUID's hex digits, one click in either case. Google's
     * ids are base64url text, where Abc and abc are two clicks (CLAUDE.md
     * #17): neither moves.
     */
    public function testAnMsclkidIsOneClickInEitherCaseAndAGoogleIdIsNot(): void
    {
        $rows = [
            self::row(11, self::T, ['gclid' => 'EAIaIQ_abc']),
            self::row(12, self::T, ['gclid' => 'EAIaIQ_ABC', 'msclkid' => strtoupper(self::CLICK)]),
        ];
        self::assertSame([[11, self::T], [12, self::T + 1]], self::secondsOf($rows, Format::MICROSOFT));
        foreach ([Format::GOOGLE, Format::GOOGLE_UPLOADS] as $format) {
            self::assertSame([[11, self::T], [12, self::T]], self::secondsOf($rows, $format), $format->value);
        }
        $braids = [
            self::row(11, self::T, ['gclid' => '', 'gbraid' => '0AAAAbraid']),
            self::row(12, self::T, ['gclid' => '', 'gbraid' => '0AAAABRAID']),
            self::row(13, self::T, ['gclid' => '', 'wbraid' => 'CkIKwbraid']),
            self::row(14, self::T, ['gclid' => '', 'wbraid' => 'CkIKWBRAID']),
        ];
        self::assertSame(
            [[11, self::T], [12, self::T], [13, self::T], [14, self::T]],
            self::secondsOf($braids, Format::GOOGLE)
        );
    }

    /**
     * A Data Manager line is counted under the first of its gclid, gbraid
     * and wbraid: two lines under different kinds are two clicks, even with
     * the same text, and two under one gbraid are one.
     */
    public function testADataManagerLineIsCountedUnderTheIdItIsSentUnder(): void
    {
        $rows = [
            self::row(11, self::T, ['gclid' => '', 'gbraid' => 'same', 'msclkid' => '']),
            self::row(12, self::T, ['gclid' => '', 'wbraid' => 'same', 'msclkid' => '']),
            self::row(13, self::T, ['gclid' => 'same', 'msclkid' => '']),
            self::row(14, self::T, ['gclid' => 'EAIaIQ_g', 'gbraid' => '0AAAA_b', 'msclkid' => '']),
            self::row(15, self::T, ['gclid' => '', 'gbraid' => '0AAAA_b', 'msclkid' => '']),
            self::row(16, self::T, ['gclid' => '', 'gbraid' => '0AAAA_b', 'msclkid' => '']),
        ];
        $sent = self::sent(self::file($rows, Format::GOOGLE), Format::GOOGLE);
        self::assertSame(
            [[11, self::T], [12, self::T], [13, self::T], [14, self::T], [15, self::T], [16, self::T + 1]],
            self::seconds($sent),
            'the gbraid of 15 and 16 is one click; 14 is counted under its gclid'
        );
        self::assertSame(['gbraid:same', 'wbraid:same', 'gclid:same', 'gclid:EAIaIQ_g', null], [
            Format::GOOGLE->secondKey(['gbraid' => 'same']),
            Format::GOOGLE->secondKey(['wbraid' => 'same']),
            Format::GOOGLE->secondKey(['gclid' => 'same']),
            Format::GOOGLE->secondKey(['gclid' => 'EAIaIQ_g', 'gbraid' => '0AAAA_b', 'wbraid' => 'CkIK']),
            Format::GOOGLE->secondKey([]),
        ]);
        self::assertSame('gclid:Abc', Format::GOOGLE_UPLOADS->secondKey(['gclid' => 'Abc', 'gbraid' => 'x']));
        self::assertSame(
            'msclkid:' . self::CLICK,
            Format::MICROSOFT->secondKey(['msclkid' => strtoupper(self::CLICK)])
        );
    }

    /**
     * The exception ClickSeconds names: a conversion recorded later at the
     * second a moved one took takes it, and the moved one moves on.
     */
    public function testALaterConversionAtAMovedOnesSecondTakesIt(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T)];
        foreach (Format::cases() as $format) {
            self::assertSame([[11, self::T], [12, self::T + 1]], self::secondsOf($rows, $format));
            $later = array_merge($rows, [self::row(15, self::T + 1)]);
            self::assertSame(
                [[15, self::T + 1], [11, self::T], [12, self::T + 2]],
                self::secondsOf($later, $format),
                $format->value
            );
        }
    }

    public function testSecondsAreAskedInTheReadsOrder(): void
    {
        $seconds = new ClickSeconds();
        self::assertSame(self::T, $seconds->take('a', self::T, 5));
        self::assertSame(self::T + 1, $seconds->take('a', self::T, 6));
        try {
            $seconds->take('a', self::T, 6);
            self::fail('a conv_id asked twice');
        } catch (\LogicException $e) {
            self::assertStringContainsString('lowest conv_id first', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        $seconds->take('a', self::T + 1, 7);
    }

    /**
     * Only the last HORIZON seconds' clicks are held, so a window of any
     * size holds about an hour of them; and a click's seconds are held as
     * long as a conversion on it could reach them.
     */
    public function testOnlyTheLastHoursClicksAreHeld(): void
    {
        $seconds = new ClickSeconds();
        $held = new \ReflectionProperty(ClickSeconds::class, 'taken');
        for ($i = 0; $i < 3 * ClickSeconds::HORIZON; $i++) {
            $seconds->take('click' . $i, self::T - $i, $i);
        }
        self::assertLessThanOrEqual(ClickSeconds::HORIZON + 1, count((array) $held->getValue($seconds)));

        $seconds = new ClickSeconds();
        self::assertSame(self::T, $seconds->take('a', self::T, 1));
        self::assertSame(self::T + 1, $seconds->take('a', self::T, 2));
        $low = self::T - ClickSeconds::HORIZON;
        $taken = [];
        for ($id = 3; $id < ClickSeconds::HORIZON + 6; $id++) {
            $taken[] = $seconds->take('a', $low, $id);
        }
        self::assertSame(range($low, self::T - 1), array_slice($taken, 0, ClickSeconds::HORIZON));
        self::assertSame(
            [self::T + 2, self::T + 3],
            array_slice($taken, ClickSeconds::HORIZON, 2),
            'past the seconds held an hour above'
        );
    }
}

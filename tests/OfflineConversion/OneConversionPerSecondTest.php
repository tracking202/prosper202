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
 * Microsoft ignores every conversion after the first with the same
 * MicrosoftClickId and ConversionTime, to the second. Two conversions on one
 * click in one second are both sent, the one recorded first at its own
 * second and the other at the next second no conversion on the click holds,
 * and every fetch of the same conversions gives each the same second. Google
 * files are as they were.
 */
final class OneConversionPerSecondTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const T = self::NOW - 86400;
    private const CLICK = '0123456789abcdef0123456789abcdef';
    private const OTHER_CLICK = 'fedcba9876543210fedcba9876543210';

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
     * The rows in the order query() reads them for the format: newest second
     * first, and within one second the lowest conv_id first for a format that
     * counts one conversion a second, the highest for the others.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function inReadOrder(array $rows, Format $format): array
    {
        $ascending = $format->oneConversionPerSecondOf() !== null;
        usort($rows, static function (array $a, array $b) use ($ascending): int {
            [$first, $then] = $ascending ? [$a, $b] : [$b, $a];

            return [$b['conv_time'], $first['conv_id']] <=> [$a['conv_time'], $then['conv_id']];
        });

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
        foreach (self::inReadOrder($rows, $format) as $row) {
            $lines->add($row);
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text === '' ? [] : explode("\r\n", substr($text, 0, -2));
    }

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s', $ts) . '+0000';
    }

    /**
     * @param list<string> $lines Microsoft lines
     * @return list<string> each line's msclkid and time, which Microsoft keeps one conversion of
     */
    private static function tuples(array $lines): array
    {
        return array_map(static function (string $line): string {
            $cells = explode(',', $line);

            return strtolower($cells[4]) . ' ' . $cells[1];
        }, $lines);
    }

    public function testTwoConversionsOnOneClickInOneSecondAreSentASecondApart(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(14, self::T + 1)];
        $lines = self::file($rows, Format::MICROSOFT);
        self::assertSame([
            'Sale,' . self::utc(self::T + 1) . ',14,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T) . ',11,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T + 2) . ',12,USD,' . self::CLICK . ',,',
        ], $lines, 'the one recorded first keeps its second; the other passes the real conversion a second later');
        self::assertSame(array_unique(self::tuples($lines)), self::tuples($lines));
        self::assertSame($lines, self::file($rows, Format::MICROSOFT), 'the same conversions, the same file');
    }

    public function testThreeInOneSecondTakeTheNextThreeFreeSeconds(): void
    {
        $rows = [
            self::row(11, self::T), self::row(12, self::T), self::row(13, self::T), self::row(14, self::T + 1),
            self::row(15, self::T, ['msclkid' => self::OTHER_CLICK]),
        ];
        $lines = self::file($rows, Format::MICROSOFT);
        self::assertSame([
            'Sale,' . self::utc(self::T + 1) . ',14,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T) . ',11,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T + 2) . ',12,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T + 3) . ',13,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T) . ',15,USD,' . self::OTHER_CLICK . ',,',
        ], $lines, 'another click\'s conversion in the same second keeps it');
        self::assertSame(array_unique(self::tuples($lines)), self::tuples($lines));
        self::assertSame(
            $lines,
            self::file(array_reverse($rows), Format::MICROSOFT),
            'the same conversions, the same file'
        );
    }

    /** Google counts a conversion by its Order ID: its files keep every time as recorded, in their order. */
    public function testAGoogleFileOfTheSameConversionsIsAsItWas(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(13, self::T), self::row(14, self::T + 1)];
        $time = static fn (int $ts): string => gmdate('Y-m-d\TH:i:s', $ts);
        $g = 'EAIaIQ_' . self::CLICK;
        self::assertSame([
            $g . ',,,Sale,' . $time(self::T + 1) . '+00:00,14,USD,P202-14,,',
            $g . ',,,Sale,' . $time(self::T) . '+00:00,13,USD,P202-13,,',
            $g . ',,,Sale,' . $time(self::T) . '+00:00,12,USD,P202-12,,',
            $g . ',,,Sale,' . $time(self::T) . '+00:00,11,USD,P202-11,,',
        ], self::file($rows, Format::GOOGLE));
        self::assertSame([
            $g . ',Sale,' . $time(self::T + 1) . '+0000,14,USD,P202-14,,',
            $g . ',Sale,' . $time(self::T) . '+0000,13,USD,P202-13,,',
            $g . ',Sale,' . $time(self::T) . '+0000,12,USD,P202-12,,',
            $g . ',Sale,' . $time(self::T) . '+0000,11,USD,P202-11,,',
        ], self::file($rows, Format::GOOGLE_UPLOADS));
        [$sql] = OfflineExport::query(new ExportOptions(Format::GOOGLE, 30, self::NOW), 1);
        self::assertStringEndsWith(' ORDER BY cl.conv_time DESC, cl.conv_id DESC', $sql);
        [$sql] = OfflineExport::query(new ExportOptions(Format::MICROSOFT, 30, self::NOW), 1);
        self::assertStringEndsWith(
            ' ORDER BY cl.conv_time DESC, cl.conv_id ASC',
            $sql,
            'the one recorded first is read first'
        );
    }

    /**
     * A conversion no longer sent still holds its second, so the one moved
     * past it is sent at the second Microsoft already has it at.
     */
    public function testARefundOrADeletionMovesNothing(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T), self::row(13, self::T)];
        $before = self::file($rows, Format::MICROSOFT);
        $rows[0]['counted_amount'] = null;
        $rows[1]['match_method'] = 'same_ip';
        self::assertSame([$before[2]], self::file($rows, Format::MICROSOFT));
    }

    /** Microsoft keeps one conversion a second whatever its name: a file of one name leaves the others' seconds. */
    public function testAFileOfOneNameLeavesTheOtherNamesSeconds(): void
    {
        $rows = [self::row(11, self::T, ['conversion_name' => 'Lead']), self::row(12, self::T)];
        $all = self::file($rows, Format::MICROSOFT);
        self::assertSame([
            'Lead,' . self::utc(self::T) . ',11,USD,' . self::CLICK . ',,',
            'Sale,' . self::utc(self::T + 1) . ',12,USD,' . self::CLICK . ',,',
        ], $all);
        self::assertSame([$all[0]], self::file($rows, Format::MICROSOFT, 'Lead'));
        self::assertSame([$all[1]], self::file($rows, Format::MICROSOFT, 'Sale'));
    }

    public function testAnMsclkidIsOneClickInEitherCase(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T, ['msclkid' => strtoupper(self::CLICK)])];
        $lines = self::file($rows, Format::MICROSOFT);
        self::assertSame([self::utc(self::T), self::utc(self::T + 1)], array_map(
            static fn (string $line): string => explode(',', $line)[1],
            $lines
        ));
    }

    /**
     * The exception ClickSeconds names: a conversion recorded later at the
     * second a moved one took takes it, and the moved one moves on.
     */
    public function testALaterConversionAtAMovedOnesSecondTakesIt(): void
    {
        $rows = [self::row(11, self::T), self::row(12, self::T)];
        self::assertSame(self::utc(self::T + 1), explode(',', self::file($rows, Format::MICROSOFT)[1])[1]);
        $rows[] = self::row(15, self::T + 1);
        $lines = self::file($rows, Format::MICROSOFT);
        self::assertSame(
            [self::utc(self::T + 1), self::utc(self::T), self::utc(self::T + 2)],
            array_map(static fn (string $line): string => explode(',', $line)[1], $lines)
        );
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
     * Only the last HORIZON seconds' click ids are held, so a window of any
     * size holds about an hour of them; and a click id's seconds are held as
     * long as a conversion on it could reach them.
     */
    public function testOnlyTheLastHoursClickIdsAreHeld(): void
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

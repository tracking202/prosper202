<?php

declare(strict_types=1);

namespace Tests\OfflineConversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\Amount;
use Prosper202\OfflineConversion\Consent;
use Prosper202\OfflineConversion\Format;
use Prosper202\OfflineConversion\OfflineExport;

/**
 * The layouts as the platforms document them: the headers and the line
 * above them, each row's time in its own offset, the value's precision, and
 * the lines written so a platform's parser and a spreadsheet read the same
 * cells.
 */
final class FormatTest extends TestCase
{
    public function testTheHeadersAreThePlatformsTemplates(): void
    {
        // Microsoft's CSV_Conversion_Enhanced_Import_Template.csv, its header line.
        self::assertSame(
            ['Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Microsoft Click ID',
                'Hashed Email Address', 'Hashed Phone Number'],
            Format::MICROSOFT->header()
        );
        self::assertSame('Google Click ID', Format::GOOGLE_UPLOADS->header()[0], 'Google\'s template begins with it');
        self::assertSame(['Google Click ID', 'GBRAID', 'WBRAID'], array_slice(Format::GOOGLE->header(), 0, 3));
        foreach (Format::cases() as $format) {
            $header = $format->header();
            self::assertCount(count($header), $format->cells(
                ['gclid' => 'g', 'gbraid' => 'b', 'wbraid' => 'w', 'msclkid' => 'm'],
                'Name',
                'time',
                '1',
                'USD',
                7,
                null,
                null
            ), $format->value . ': a line has a cell for each header');
        }
    }

    public function testTheLineAboveTheHeadersIsThePlatformsAndAsWideAsTheHeader(): void
    {
        self::assertNull(Format::GOOGLE->preamble('America/New_York'), 'Data Manager reads the headers on line 1');
        self::assertSame(['Parameters:TimeZone=America/New_York', '', '', '', '', '', '', ''], Format::GOOGLE_UPLOADS->preamble('America/New_York'));
        self::assertSame(
            ['Parameters:TimeZone=+0000', '', '', '', '', '', ''],
            Format::MICROSOFT->preamble('America/New_York'),
            'Microsoft\'s zones are its own names, so its file is UTC whatever the account\'s'
        );
        self::assertSame('UTC', Format::MICROSOFT->zone('Asia/Kolkata'));
        self::assertSame('Asia/Kolkata', Format::GOOGLE->zone('Asia/Kolkata'));
    }

    /**
     * Each time in the offset in force at its own instant (CLAUDE.md #29):
     * a summer and a winter conversion in one New York file differ by an
     * hour of offset, and India's half hour is kept.
     */
    public function testEachTimeCarriesTheOffsetInForceAtItsInstant(): void
    {
        $summer = gmmktime(18, 30, 0, 7, 1, 2026);
        $winter = gmmktime(18, 30, 0, 1, 15, 2026);
        self::assertSame('2026-07-01T14:30:00-04:00', Format::GOOGLE->time($summer, 'America/New_York'));
        self::assertSame('2026-01-15T13:30:00-05:00', Format::GOOGLE->time($winter, 'America/New_York'));
        self::assertSame('2026-07-01T14:30:00-0400', Format::GOOGLE_UPLOADS->time($summer, 'America/New_York'));
        self::assertSame('2026-01-15T13:30:00-0500', Format::GOOGLE_UPLOADS->time($winter, 'America/New_York'));
        self::assertSame('2026-07-02T00:00:00+0530', Format::GOOGLE_UPLOADS->time($summer, 'Asia/Kolkata'));
        self::assertSame('2026-07-01T18:30:00+0000', Format::MICROSOFT->time($summer, Format::MICROSOFT->zone('America/New_York')));
        // The hour a New York clock repeats in November: two instants, two offsets.
        $first = gmmktime(5, 30, 0, 11, 1, 2026);
        self::assertSame('2026-11-01T01:30:00-04:00', Format::GOOGLE->time($first, 'America/New_York'));
        self::assertSame('2026-11-01T01:30:00-05:00', Format::GOOGLE->time($first + 3600, 'America/New_York'));
    }

    public function testValuesAreAsRecordedAndMicrosoftsHaveThreeDecimals(): void
    {
        $units = static fn (string $amount): int => Amount::toUnits($amount);
        self::assertSame('12.5', Format::GOOGLE->value($units('12.50000')));
        self::assertSame('0.00125', Format::GOOGLE->value($units('0.00125')));
        self::assertSame('10', Format::GOOGLE_UPLOADS->value($units('10.00000')));
        self::assertSame('0', Format::GOOGLE->value(0));
        self::assertSame('3.142', Format::MICROSOFT->value($units('3.14159')));
        self::assertSame('0.001', Format::MICROSOFT->value($units('0.0005')), 'half up');
        self::assertSame('0', Format::MICROSOFT->value($units('0.00049')));
        self::assertSame('100', Format::MICROSOFT->value($units('99.9999')));
        $this->expectException(\InvalidArgumentException::class);
        Format::GOOGLE->value(-1);
    }

    public function testALineCarriesTheConversionsOrderIdAndTheConsentStated(): void
    {
        $ids = ['gclid' => 'EAIaIQ_x-1', 'gbraid' => '0AAAAb', 'wbraid' => '', 'msclkid' => 'abc'];
        self::assertSame(
            ['EAIaIQ_x-1', '0AAAAb', '', 'Lead', 't', '1.5', 'EUR', 'P202-42', 'Granted', 'Denied'],
            Format::GOOGLE->cells($ids, 'Lead', 't', '1.5', 'EUR', 42, Consent::GRANTED, Consent::DENIED)
        );
        self::assertSame(
            ['EAIaIQ_x-1', 'Lead', 't', '1.5', 'EUR', 'P202-42', '', ''],
            Format::GOOGLE_UPLOADS->cells($ids, 'Lead', 't', '1.5', 'EUR', 42, null, null)
        );
        self::assertSame(['Lead', 't', '1.5', 'EUR', 'abc', '', ''], Format::MICROSOFT->cells($ids, 'Lead', 't', '1.5', 'EUR', 42, null, null));
        self::assertFalse(Format::MICROSOFT->takesConsent());
        self::assertSame(100000, Format::MICROSOFT->rowLimit());
        self::assertNull(Format::GOOGLE->rowLimit());
    }

    public function testAnIdIsWrittenOnlyInTheShapeThePlatformsIssue(): void
    {
        $issued = ['gclid' => 'EAIaIQobChMI_gclid-1_BwE', 'gbraid' => '0AAAAADgbraid', 'wbraid' => 'CkIK-x_1'];
        foreach ($issued as $column => $id) {
            self::assertTrue(Format::wellFormedId($column, $id), $id);
        }
        foreach (['', '=HYPERLINK("x")', '@SUM(1)', 'a b', 'a,b', "a\nb", 'a"b', str_repeat('a', 151)] as $id) {
            foreach (['gclid', 'gbraid', 'wbraid', 'msclkid'] as $column) {
                self::assertFalse(Format::wellFormedId($column, $id), $column . ' ' . var_export($id, true));
            }
        }
    }

    /** Microsoft: "The MSCLKID is a GUID (32 characters)", as its click carries it. */
    public function testAnMsclkidIsThirtyTwoHexadecimalDigits(): void
    {
        foreach (['0123456789abcdef0123456789abcdef', '0123456789ABCDEF0123456789ABCDEF'] as $id) {
            self::assertTrue(Format::wellFormedId('msclkid', $id), $id);
        }
        $refused = [
            'abc', 'EAIaIQobChMI_gclid-1_BwE', '0123456789abcdef0123456789abcde', '0123456789abcdef0123456789abcdef0',
            '01234567-89ab-cdef-0123-456789abcdef', '0123456789abcdef0123456789abcdeg',
        ];
        foreach ($refused as $id) {
            self::assertFalse(Format::wellFormedId('msclkid', $id), $id);
        }
    }

    /**
     * A field is quoted only when it has to be: Google's template line is
     * `Google Click ID,…`, which fputcsv() would have written quoted.
     */
    public function testLinesQuoteOnlyWhatRfc4180Requires(): void
    {
        $handle = fopen('php://memory', 'w+b');
        self::assertIsResource($handle);
        OfflineExport::put($handle, ['Google Click ID', 'Lead, "A"', "two\nlines", 'back\\"slash', '']);
        rewind($handle);
        self::assertSame("Google Click ID,\"Lead, \"\"A\"\"\",\"two\nlines\",\"back\\\"\"slash\",\r\n", stream_get_contents($handle));
        fclose($handle);
    }
}

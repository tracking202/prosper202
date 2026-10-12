<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\OfflineConversionExport;

final class OfflineConversionExportTest extends TestCase
{
    /** @dataProvider names */
    public function testANameTheFileCannotCarryIsRefused(string $name, string $problem): void
    {
        self::assertStringContainsString($problem, (string) OfflineConversionExport::nameProblem($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'none' => ['', 'is required'];
        yield 'a formula' => ['=cmd', 'formula'];
        yield 'a leading minus' => ['-x', 'formula'];
        yield 'two lines' => ["a\nb", 'one line'];
        yield 'a padded name' => [' Purchase', 'space'];
        yield 'too long' => [str_repeat('a', 101), 'at most 100'];
    }

    public function testANameItCanCarryIsAccepted(): void
    {
        self::assertNull(OfflineConversionExport::nameProblem('Offline purchase (CRM) - 2026'));
    }

    public function testTheFileIsNamedByTheAccountsDays(): void
    {
        // Oct 1 00:00 to Oct 12 23:59:59 in Los Angeles; the end is Oct 13 in UTC.
        $zone = new \DateTimeZone('America/Los_Angeles');
        self::assertSame(
            'google-ads-offline-conversions-20261001-20261012.csv',
            OfflineConversionExport::filename('google', 1_790_838_000, 1_791_874_799, $zone)
        );
        self::assertSame(
            'microsoft-ads-offline-conversions-20261001-20261013.csv',
            OfflineConversionExport::filename('microsoft', 1_790_838_000, 1_791_874_799, new \DateTimeZone('UTC'))
        );
    }
}

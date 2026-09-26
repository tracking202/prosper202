<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ConversionSource;
use Prosper202\Conversion\Ledger\NotCountedReason;
use Prosper202\Conversion\Ledger\SupersededReason;

/**
 * The breakdown reads as documented: the route and the filters are in
 * docs/openapi.yaml, and every enum the spec lists is the code's, in full.
 * The spec is read as text, block by block, as GoalsOpenApiCoverageTest
 * reads it: no YAML library ships with the project.
 */
final class LedgerReadsOpenApiTest extends TestCase
{
    private static function spec(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');
    }

    /** The text of the block that starts at the line "$indent$key:" and ends before the next line at that indent or less. */
    private static function block(string $spec, string $indent, string $key): string
    {
        $at = strpos($spec, "\n" . $indent . $key . ":\n");
        self::assertNotFalse($at, "$key is in the spec");
        $end = preg_match('/\n {0,' . strlen($indent) . '}[^ \n][^\n]*\n/', $spec, $m, PREG_OFFSET_CAPTURE, $at + 1) === 1 ? $m[0][1] : strlen($spec);

        return substr($spec, $at, $end - $at);
    }

    /** @return list<string> the values of the one `enum: [...]` line in $block */
    private static function enum(string $block): array
    {
        self::assertSame(1, preg_match_all('/\n\s*enum: \[([^\]]*)\]/', $block, $m), 'one enum in the block');

        return array_map('trim', explode(',', $m[1][0]));
    }

    public function testTheBreakdownRouteAndTheFiltersAreDocumented(): void
    {
        $spec = self::spec();
        self::assertStringContainsString("\n    get:", self::block($spec, '  ', '/clicks/{id}/conversions'), 'GET /clicks/{id}/conversions is documented');
        $list = self::block(self::block($spec, '  ', '/conversions'), '    ', 'get');
        foreach (['click_id', 'source', 'goal'] as $filter) {
            self::assertStringContainsString("- name: $filter\n", $list, "GET /conversions documents ?$filter");
        }
    }

    public function testTheSpecsEnumsAreTheCodes(): void
    {
        $spec = self::spec();
        $sources = array_map(static fn (ConversionSource $s): string => $s->value, ConversionSource::cases());
        self::assertSame($sources, self::enum(self::block($spec, '    ', 'ConversionSource')));

        $row = self::block($spec, '    ', 'ClickConversion');
        $reasons = array_map(static fn (NotCountedReason $r): string => $r->value, NotCountedReason::cases());
        self::assertSame([...$reasons, 'null'], self::enum(self::block($row, '        ', 'not_counted_reason')));
        $superseded = array_map(static fn (SupersededReason $r): string => $r->value, SupersededReason::cases());
        self::assertSame([...$superseded, 'null'], self::enum(self::block($row, '        ', 'superseded_reason')));
        self::assertSame([...$superseded, 'null'], self::enum(self::block(self::block($spec, '    ', 'Conversion'), '        ', 'superseded_reason')));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ModelStatus;
use Tests\Support\SourceScan;

/**
 * The surfaces that spell out a model's or an export's statuses say what the
 * enums say (CLAUDE.md #5): the Go CLI's lists, the comparisons its optimize
 * report makes, and the OpenAPI schemas. The PHP surfaces (the v3
 * controller, the PHP CLI, the page) read the enums and have nothing to
 * drift. The export list of the Go CLI is held by ExportSurfacesAgreeTest.
 */
final class StatusSurfacesAgreeTest extends TestCase
{
    private static function file(string $path): string
    {
        $source = file_get_contents(SourceScan::repoRoot() . '/' . $path);
        self::assertNotFalse($source, $path);

        return $source;
    }

    /** @return list<string> */
    private static function requestable(): array
    {
        return array_column(ModelStatus::requestable(), 'value');
    }

    public function testTheGoCliOffersTheStatusesARequestMayAskFor(): void
    {
        $src = self::file('go-cli/cmd/attribution.go');
        self::assertSame(1, preg_match('/^var attributionModelStatuses = \[\]string\{([^}]*)\}/m', $src, $m));
        preg_match_all('/"([^"]+)"/', $m[1], $values);

        self::assertSame(self::requestable(), $values[1]);
    }

    /** Every status the Go CLI compares a model's with is a state: one renamed on the server reads there as never matching. */
    public function testEveryStatusTheGoCliComparesIsAState(): void
    {
        $compared = [];
        foreach (['go-cli/cmd/report_optimize.go', 'go-cli/cmd/attribution.go'] as $path) {
            preg_match_all('/\["status"\]\)\s*(?:!=|==)\s*"([^"]*)"|\bst\s*(?:!=|==)\s*"([^"]*)"/', self::file($path), $m);
            array_push($compared, ...array_filter(array_merge($m[1], $m[2]), static fn (string $v): bool => $v !== ''));
        }

        self::assertGreaterThanOrEqual(3, count($compared), 'the comparisons are still read');
        self::assertSame([], array_values(array_diff($compared, ModelStatus::values())));
    }

    public function testTheOpenApiSpecListsTheModelStatuses(): void
    {
        $spec = self::file('docs/openapi.yaml');
        foreach ([
            'AttributionModel' => ModelStatus::values(),
            'AttributionModelCreate' => self::requestable(),
            'AttributionModelUpdate' => self::requestable(),
            'AttributionExport' => ExportStatus::values(),
        ] as $schema => $want) {
            self::assertSame($want, self::schemaStatus($spec, $schema), "docs/openapi.yaml $schema.status");
        }
    }

    public function testTheOpenApiExportFilterListsTheExportStatuses(): void
    {
        $spec = self::file('docs/openapi.yaml');
        $start = strpos($spec, "\n  /attribution/exports:\n");
        self::assertNotFalse($start);
        self::assertSame(1, preg_match('/- name: status\n\s+in: query\n\s+schema:\n\s+type: string\n\s+enum: \[([^\]]*)\]/', substr($spec, $start, 3000), $m));

        self::assertSame(ExportStatus::values(), array_map('trim', explode(',', $m[1])));
    }

    public function testTheApiGuideSaysWhichStatusesARequestSets(): void
    {
        $guide = self::file('documentation/api/13-attribution.md');
        self::assertSame(1, preg_match('/^\| `status` \| string \| (.*)$/m', $guide, $m));
        preg_match_all('/`([a-z_]+)`/', $m[1], $named);

        self::assertSame(ModelStatus::values(), array_values(array_intersect($named[1], ModelStatus::values())));
    }

    /** @return list<string> */
    private static function schemaStatus(string $spec, string $schema): array
    {
        $start = strpos($spec, "\n    $schema:\n");
        self::assertNotFalse($start, "schema $schema is not in docs/openapi.yaml");
        $rest = substr($spec, $start + 1);
        $end = preg_match('/\n    [A-Z]\w*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1 ? $m[0][1] : strlen($rest);
        self::assertSame(1, preg_match('/^        status:\n          type: string\n          enum: \[([^\]]*)\]$/m', substr($rest, 0, $end), $found), "$schema.status is an enum");

        return array_map('trim', explode(',', $found[1]));
    }
}

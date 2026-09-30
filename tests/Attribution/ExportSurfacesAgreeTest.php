<?php

declare(strict_types=1);

namespace Tests\Attribution;

use Api\V3\Controllers\AttributionController;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ExportStore;

/**
 * The export surfaces say the same thing (CLAUDE.md error pattern #5): the
 * OpenAPI schema documents every field the API returns and every field it
 * accepts, and the Go CLI's status list is the server's. Textual, like
 * AttributionOpenApiCoverageTest: one property per `        name:` line.
 */
final class ExportSurfacesAgreeTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function schemaProperties(string $name): array
    {
        $spec = (string) file_get_contents(self::root() . '/docs/openapi.yaml');
        $start = strpos($spec, "\n    $name:\n");
        self::assertNotFalse($start, "schema $name is not in docs/openapi.yaml");
        $rest = substr($spec, $start + 1);
        $end = preg_match('/\n    [A-Z]\w*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 1) === 1 ? $m[0][1] : strlen($rest);
        preg_match_all('/^        (\w+):$/m', substr($rest, 0, $end), $props);

        return $props[1];
    }

    public function testEveryFieldTheApiReturnsIsDocumented(): void
    {
        $row = [
            'export_id' => 1, 'user_id' => 1, 'model_id' => 1, 'compare_model_id' => null, 'group_by' => 'campaign',
            'range_start' => 0, 'range_end' => 1, 'status' => 'pending', 'file_path' => null, 'rows_exported' => null,
            'webhook_url' => null, 'webhook_secret' => null, 'webhook_status_code' => null, 'attempts' => 0, 'last_error' => null,
            'queued_at' => 0, 'started_at' => null, 'completed_at' => null, 'created_at' => 0, 'updated_at' => 0,
        ];
        $returned = array_merge(array_keys(AttributionController::presentExport($row)), ['webhook_secret']);
        self::assertSame([], array_values(array_diff($returned, self::schemaProperties('AttributionExport'))), 'returned but not documented');
        self::assertSame([], array_values(array_diff(self::schemaProperties('AttributionExport'), $returned)), 'documented but not returned');
    }

    public function testTheAcceptedFieldsAreTheDocumentedOnes(): void
    {
        $source = (string) file_get_contents(self::root() . '/api/v3/Controllers/AttributionController.php');
        self::assertSame(1, preg_match('/public function createExport.*?\$known = \[([^\]]*)\];/s', $source, $m));
        preg_match_all("/'(\w+)'/", $m[1], $known);
        $documented = self::schemaProperties('AttributionExportInput');
        sort($documented);
        $accepted = $known[1];
        sort($accepted);
        self::assertSame($accepted, $documented);
    }

    public function testTheGoCliStatusesAreTheServers(): void
    {
        $src = (string) file_get_contents(self::root() . '/go-cli/cmd/attribution_export.go');
        self::assertSame(1, preg_match('/^var attributionExportStatuses = \[\]string\{([^}]*)\}/m', $src, $m));
        preg_match_all('/"([^"]+)"/', $m[1], $values);
        self::assertSame(ExportStore::STATUSES, $values[1]);
    }
}

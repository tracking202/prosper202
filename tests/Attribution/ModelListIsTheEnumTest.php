<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ModelType;
use Prosper202\Database\Tables\AttributionTables;

/**
 * One enum is the only list of models (plan §6.3). The old engine's API
 * accepted `first_touch` and `linear`, which its engine could not load, and
 * `algorithmic`, which silently ran last touch: every surface kept its own
 * list and they drifted (CLAUDE.md error pattern #5). So every surface that
 * spells the list out — the column type, the Go CLI, the OpenAPI spec, the
 * API guide — is read here and compared with ModelType, as is the report
 * dimension list the Go CLI and the spec repeat. The PHP surfaces (the v3
 * controller, the PHP CLI) read the enum itself and have nothing to drift.
 */
final class ModelListIsTheEnumTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function goList(string $var): array
    {
        $src = (string) file_get_contents(self::root() . '/go-cli/cmd/attribution.go');
        self::assertSame(1, preg_match('/^var ' . preg_quote($var, '/') . ' = \[\]string\{([^}]*)\}/m', $src, $m), "go-cli/cmd/attribution.go declares $var");
        preg_match_all('/"([^"]+)"/', $m[1], $values);

        return $values[1];
    }

    public function testTheColumnIsTheEnum(): void
    {
        $sql = AttributionTables::attributionModels()->createStatement;
        self::assertSame(1, preg_match("/`model_type` enum\\(([^)]*)\\)/", $sql, $m));
        preg_match_all("/'([^']+)'/", $m[1], $values);
        self::assertSame(ModelType::values(), $values[1]);
    }

    public function testTheGoCliListsAreTheServers(): void
    {
        self::assertSame(ModelType::values(), self::goList('attributionModelTypes'));
        self::assertSame(AttributionReports::dimensions(), self::goList('attributionDimensions'));
    }

    public function testTheOpenApiSpecListsTheSame(): void
    {
        $spec = (string) file_get_contents(self::root() . '/docs/openapi.yaml');
        foreach (['AttributionModelType' => ModelType::values(), 'AttributionDimension' => AttributionReports::dimensions()] as $schema => $want) {
            self::assertSame(1, preg_match('/^    ' . $schema . ":\n(?:      .*\n)*?      enum:\n((?:        - .*\n)+)/m", $spec, $m), "docs/openapi.yaml defines $schema with an enum");
            preg_match_all('/- (\S+)/', $m[1], $values);
            self::assertSame($want, $values[1], "docs/openapi.yaml $schema");
        }
        // Every other model_type in the spec must reference that one schema.
        preg_match_all('/model_type:\n\s+(\S.*)/', $spec, $uses);
        foreach ($uses[1] as $use) {
            self::assertSame('$ref: "#/components/schemas/AttributionModelType"', trim($use), 'a model_type in the spec that does not use the enum schema');
        }
    }

    public function testTheApiGuideDocumentsEveryModelAndNoOther(): void
    {
        $guide = (string) file_get_contents(self::root() . '/documentation/api/13-attribution.md');
        preg_match_all('/^\| `([a-z_]+)` \| /m', substr($guide, (int) strpos($guide, '## Models'), 2000), $m);
        self::assertSame(ModelType::values(), array_values(array_intersect($m[1], array_merge(ModelType::values(), ['algorithmic', 'assisted']))));
    }

    public function testNoSurfaceStillOffersARemovedModel(): void
    {
        $offenders = [];
        foreach (['api/v3/Controllers/AttributionController.php', 'go-cli/cmd/attribution.go', 'docs/openapi.yaml', 'docs/cli.md', 'docs/cli-agent.md',
                  'cli/Commands/AttributionModelCreateCommand.php', 'cli/Commands/AttributionModelUpdateCommand.php', 'cli/Commands/AttributionModelListCommand.php'] as $file) {
            $src = (string) file_get_contents(self::root() . '/' . $file);
            foreach (['algorithmic'] as $gone) {
                if (str_contains($src, $gone)) {
                    $offenders[] = "$file mentions $gone";
                }
            }
        }
        self::assertSame([], $offenders);
    }
}

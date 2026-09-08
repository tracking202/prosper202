<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\ParsedPostback;
use Api\V3\Attribution\PostbackProtocol;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\SkadnetworkProtocol;
use Prosper202\Database\Tables\AttributionPostbackTables;
use Tests\TestCase;

/**
 * Every PostbackProtocol implementation writes a row the shared table can
 * hold and the shared report can read:
 *
 *  - only columns the DDL defines, each bound with the type its column
 *    takes (error pattern #7, checked against the schema rather than by
 *    eye);
 *  - none of the receiver-owned GENERIC_COLUMNS (identity, ownership and
 *    trust have one author);
 *  - the family's generic dimensions — conversion_type,
 *    ad_interaction_type — with values from the sets the API filters and
 *    the report aggregate on, and the platform's signed proof in
 *    attribution_signature;
 *  - and, together with the receiver, every NOT NULL column without a
 *    default, so no protocol's INSERT can fail on a column it forgot.
 *
 * Executed, not read: each protocol parses a real fixture and the columns
 * it produces are compared with the CREATE TABLE the installer runs.
 * Implementations are discovered from the source tree, so a new protocol
 * is held to the contract as soon as it exists — and fails here, naming
 * itself, until it has a fixture.
 */
final class ProtocolColumnContractTest extends TestCase
{
    /**
     * A body each protocol parses successfully. A protocol without an entry
     * fails testEveryProtocolHasAFixture rather than silently going untested.
     *
     * @return array<class-string<PostbackProtocol>, array<string, mixed>>
     */
    private static function fixtures(): array
    {
        return [
            SkadnetworkProtocol::class => [
                'version' => '4.0',
                'ad-network-id' => 'contract.skadnetwork',
                'source-identifier' => '4213',
                'app-id' => 525463029,
                'transaction-id' => 'contract-tx',
                'redownload' => true,
                'source-app-id' => 1234567891,
                'fidelity-type' => 0,
                'did-win' => true,
                'conversion-value' => 21,
                'coarse-conversion-value' => 'high',
                'country-code' => 'US',
                'postback-sequence-index' => 1,
                'attribution-signature' => 'AA==',
            ],
            AdAttributionKitProtocol::class => AdAttributionKitFixtures::exampleBody(['coarse-conversion-value' => 'low']),
        ];
    }

    /** @return list<class-string<PostbackProtocol>> */
    private function implementations(): array
    {
        $dir = dirname(__DIR__, 3) . '/api/v3/Attribution';
        $classes = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            if (preg_match('/^(?:final\s+)?class\s+(\w+)\s+implements\s+PostbackProtocol\b/m', $source, $m) === 1) {
                $classes[] = 'Api\\V3\\Attribution\\' . $m[1];
            }
        }
        sort($classes);
        $this->assertNotEmpty($classes, 'no PostbackProtocol implementation found; a silent zero would pass vacuously');
        /** @var list<class-string<PostbackProtocol>> $classes */
        return $classes;
    }

    /**
     * The postbacks table's columns from the shipped DDL:
     * name => [bind type, nullable, has default or auto-increment].
     *
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    private function ddlColumns(): array
    {
        $ddl = AttributionPostbackTables::attributionPostbacks()->createStatement;
        $columns = [];
        foreach (explode("\n", $ddl) as $line) {
            if (preg_match('/^\s*`(\w+)`\s+(\w+)(?:\([^)]*\))?(?:\s+unsigned)?\s*(.*?),?\s*$/', $line, $m) !== 1) {
                continue;
            }
            [, $name, $type, $rest] = $m;
            $bindType = match (strtolower($type)) {
                'tinyint', 'smallint', 'mediumint', 'int', 'bigint' => 'i',
                'varchar', 'char', 'text', 'mediumtext', 'longtext' => 's',
                'decimal', 'double', 'float' => 'd',
                default => self::fail("column $name has a type this test does not know: $type"),
            };
            $columns[$name] = [
                $bindType,
                stripos($rest, 'NOT NULL') === false,
                stripos($rest, 'DEFAULT') !== false || stripos($rest, 'AUTO_INCREMENT') !== false,
            ];
        }
        $this->assertArrayHasKey('postback_id', $columns, 'the DDL parser found no columns');
        return $columns;
    }

    /** @param class-string<PostbackProtocol> $class */
    private function parsed(string $class): ParsedPostback
    {
        $fixture = self::fixtures()[$class] ?? null;
        $this->assertNotNull($fixture, "$class has no fixture in ProtocolColumnContractTest::fixtures()");
        $result = (new $class())->parse($fixture);
        $this->assertInstanceOf(ParsedPostback::class, $result, "$class rejected its own fixture: " . json_encode($result));
        return $result;
    }

    public function testEveryProtocolHasAFixture(): void
    {
        foreach ($this->implementations() as $class) {
            $this->assertArrayHasKey($class, self::fixtures(), "$class implements PostbackProtocol but has no fixture here");
        }
    }

    public function testTheReceiverOwnedColumnsExistInTheDdl(): void
    {
        $ddl = $this->ddlColumns();
        foreach (PostbackReceiver::GENERIC_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $ddl, "GENERIC_COLUMNS names $column, which the DDL does not define");
        }
    }

    public function testEveryProtocolWritesOnlyDdlColumnsWithTheirBindTypes(): void
    {
        $ddl = $this->ddlColumns();
        foreach ($this->implementations() as $class) {
            $columns = $this->parsed($class)->columns;
            $this->assertNotEmpty($columns, "$class contributes no columns");
            foreach ($columns as $name => $pair) {
                $this->assertArrayHasKey($name, $ddl, "$class writes $name, which the DDL does not define");
                $this->assertNotContains($name, PostbackReceiver::GENERIC_COLUMNS, "$class writes receiver-owned column $name");
                $this->assertSame($ddl[$name][0], $pair[0], "$class binds $name as '{$pair[0]}' but the column takes '{$ddl[$name][0]}'");
                if ($pair[1] === null) {
                    $this->assertTrue($ddl[$name][1], "$class binds NULL to $name, which is NOT NULL");
                }
            }
        }
    }

    public function testEveryProtocolFillsTheGenericDimensions(): void
    {
        foreach ($this->implementations() as $class) {
            $columns = $this->parsed($class)->columns;
            foreach (['conversion_type', 'ad_interaction_type', 'attribution_signature'] as $required) {
                $this->assertArrayHasKey($required, $columns, "$class does not write $required");
            }
            $this->assertContains(
                $columns['conversion_type'][1],
                AdAttributionKitProtocol::CONVERSION_TYPES,
                "$class emits a conversion_type the API does not filter on"
            );
            $this->assertContains(
                $columns['ad_interaction_type'][1],
                AdAttributionKitProtocol::INTERACTION_TYPES,
                "$class emits an ad_interaction_type the API does not filter on"
            );
            $this->assertNotSame('', trim((string)$columns['attribution_signature'][1]), "$class stores an empty signature");
        }
    }

    public function testTheReceiverAndEachProtocolTogetherCoverEveryRequiredColumn(): void
    {
        $ddl = $this->ddlColumns();
        $required = array_keys(array_filter(
            $ddl,
            static fn(array $column): bool => !$column[1] && !$column[2] // NOT NULL, no default, not auto-increment
        ));
        $this->assertNotEmpty($required);
        foreach ($this->implementations() as $class) {
            $written = array_merge(PostbackReceiver::GENERIC_COLUMNS, array_keys($this->parsed($class)->columns));
            $this->assertSame(
                [],
                array_values(array_diff($required, $written)),
                "$class leaves NOT NULL columns unwritten; its INSERT would fail under strict mode"
            );
        }
    }
}

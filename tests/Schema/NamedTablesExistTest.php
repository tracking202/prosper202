<?php

declare(strict_types=1);

namespace Tests\Schema;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Schema\TableRegistry;
use Tests\Support\SourceScan;

/**
 * Every table a SQL string in the served tree names is one the schema
 * creates (TableRegistry), CLAUDE.md error pattern #2 for table names.
 *
 * ipx.php inserted into 202_clicks_impressions and every landing-page click
 * then updated it, and no installer or upgrade has ever created that table:
 * the pixel recorded nothing and the click path ran a failing query and
 * logged it on every click, while reading as a working feature. The scan
 * needs no database (StaticSqlSchemaTest prepares the v3 statements against
 * a real server; this covers the legacy scripts it cannot reach).
 *
 * A reference is a 202_ name after FROM, JOIN, INTO, UPDATE, TABLE (with or
 * without IF [NOT] EXISTS) or TRUNCATE in a string literal or the literal
 * part of an interpolated string, backticks allowed, any case. A name that
 * ends at an interpolation (`"202_tracking_" . $name`) is a prefix and must
 * begin some registered table. Comments are not read; a table name built
 * entirely at run time is not seen.
 */
final class NamedTablesExistTest extends TestCase
{
    /**
     * Files whose job is to name tables the current schema does not have:
     * the upgrade ladder creates, converts, renames and drops the tables of
     * every older schema.
     */
    private const EXEMPT = ['202-config/functions-upgrade.php'];

    /**
     * Unknown tables a file may still name, with the reason. Empty: the one
     * entry it held, class-indexes.php's INDEXES::get_c1_id()..get_c4_id()
     * on 202_clicks_c1..c4, was dead code (reached only through
     * functions-indexes.php's get_cN_id() wrappers, which nothing called) and
     * is gone with them.
     *
     * @var array<string, array{list<string>, string}>
     */
    private const KNOWN_UNKNOWN = [];

    private const REFERENCE = '/\b(?:FROM|JOIN|INTO|UPDATE|TRUNCATE(?:\s+TABLE)?|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?)'
        . '\s+`?(202_[a-z0-9_]+)`?/i';

    /** @return list<array{string, int}> name, line */
    private static function references(string $source): array
    {
        $found = [];
        foreach (token_get_all($source) as $token) {
            $strings = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
            if (!is_array($token) || !in_array($token[0], $strings, true)) {
                continue;
            }
            if (preg_match_all(self::REFERENCE, $token[1], $m) > 0) {
                foreach ($m[1] as $name) {
                    $found[] = [strtolower($name), $token[2]];
                }
            }
        }

        return $found;
    }

    /** @param array<string, true> $known */
    private static function exists(string $name, array $known): bool
    {
        if (isset($known[$name])) {
            return true;
        }
        if (str_ends_with($name, '_')) {
            foreach (array_keys($known) as $table) {
                if (str_starts_with($table, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testEveryNamedTableIsOneTheSchemaCreates(): void
    {
        $known = array_fill_keys(TableRegistry::getAllTables(), true);
        $missing = [];
        $read = 0;
        $knownUnknownSeen = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (in_array($path, self::EXEMPT, true)) {
                continue;
            }
            foreach (self::references($source) as [$name, $line]) {
                $read++;
                if (self::exists($name, $known)) {
                    continue;
                }
                if (in_array($name, self::KNOWN_UNKNOWN[$path][0] ?? [], true)) {
                    $knownUnknownSeen[$path][$name] = true;
                    continue;
                }
                $missing[] = "$path:$line names $name";
            }
        }
        self::assertSame([], $missing, 'no installer or upgrade creates these tables (TableRegistry): '
            . 'add the table to the schema and the upgrade ladder, or remove the code');
        foreach (self::KNOWN_UNKNOWN as $path => [$names]) {
            self::assertSame(
                $names,
                array_keys($knownUnknownSeen[$path] ?? []),
                "$path no longer names every listed table: take it off KNOWN_UNKNOWN"
            );
        }
        // Without a floor a scan that read nothing would pass.
        self::assertGreaterThan(1000, $read);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function shapes(): iterable
    {
        yield 'select' => ['<?php $q = "SELECT a FROM 202_clicks WHERE 1";', ['202_clicks']];
        yield 'a join, backticks' => ['<?php $q = "SELECT 1 FROM x LEFT JOIN `202_ips` i ON 1";', ['202_ips']];
        yield 'insert' => ["<?php \$q = 'INSERT INTO 202_clicks_impressions SET a = 1';", ['202_clicks_impressions']];
        yield 'update, lower case' => ['<?php $q = "update 202_clicks set a = 1";', ['202_clicks']];
        yield 'delete' => ['<?php $q = "DELETE FROM 202_clicks";', ['202_clicks']];
        yield 'create' => ['<?php $q = "CREATE TABLE IF NOT EXISTS `202_x` (a int)";', ['202_x']];
        yield 'alter' => ['<?php $q = "ALTER TABLE 202_x ADD b int";', ['202_x']];
        yield 'truncate' => ['<?php $q = "TRUNCATE TABLE 202_x";', ['202_x']];
        yield 'interpolated' => ['<?php $q = "SELECT 1 FROM 202_x WHERE a = $b";', ['202_x']];
        yield 'a prefix before a variable' => ['<?php $q = "INSERT INTO 202_tracking_" . $v;', ['202_tracking_']];
        yield 'a heredoc' => ["<?php \$q = <<<SQL\nSELECT 1 FROM 202_x\nWHERE a = \$b\nSQL;\n", ['202_x']];
        yield 'a comment is not read' => ["<?php // INSERT INTO 202_x\n\$a = 1;", []];
        yield 'a bare name is not a reference' => ["<?php \$t = '202_x';", []];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $expected
     */
    public function testTheShapesAReferenceTakes(string $source, array $expected): void
    {
        self::assertSame($expected, array_column(self::references($source), 0));
    }

    public function testAPrefixMustBeginARegisteredTable(): void
    {
        $known = ['202_tracking_c1' => true];
        self::assertTrue(self::exists('202_tracking_', $known));
        self::assertFalse(self::exists('202_nothing_', $known));
        self::assertFalse(self::exists('202_tracking', $known), 'a whole name is not a prefix');
    }
}

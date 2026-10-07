<?php

declare(strict_types=1);

namespace Tests\Schema;

use Api\V3\Controller;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Every CRUD controller's fields() says what its columns can hold, and the
 * installed schema agrees.
 *
 * Controller::validatePayload() refuses a value its field cannot hold rather
 * than letting the database decide: under strict mode MySQL answers an
 * out-of-range number or an over-long string with an error (a 500), and
 * without it — a host's own sql_mode — it clamps or truncates and the API
 * answers 201 for a value it did not store. So each declaration is a claim
 * about a column, and MySQL is the only thing that knows the column:
 *
 *  - 'nullable' is true exactly when the column IS NULL-able: a null in a
 *    request clears the field when it can hold NULL and is refused when it
 *    cannot, and a GET body sent back carries the nulls GET answered;
 *  - a writable 'i' or 'd' field declares 'range', the column's own (an
 *    integer type's, or a DECIMAL(M,D)'s ±(10^(M-D) - 10^-D)); BIGINT
 *    UNSIGNED stops at PHP_INT_MAX, which is all mysqli can bind;
 *  - a writable 's' field on a character column declares a max_length no
 *    longer than the column's (or 'allowed' values that all fit);
 *  - a writable field on a DATE column declares 'format' => 'date' (and no
 *    other column carries it), and no writable field sits on a temporal
 *    column validatePayload() has no format for.
 *
 * Read-only fields are not written by the base class, so only their columns'
 * existence is held. Installs the schema into the scratch database named by
 * P202_TEST_DB_* (it drops nothing), and skips without one.
 *
 * @group integration
 */
final class ControllerFieldsMatchSchemaTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        // SchemaInstaller calls the global _mysqli_query(). The real one, as
        // StaticSqlSchemaTest beside this loads it: a stand-in declared here
        // would make that require a redeclaration when both run in one process.
        require_once __DIR__ . '/../../202-config/functions.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    /** @return iterable<string, array{class-string<Controller>}> */
    public static function controllers(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/api/v3/Controllers/*.php') ?: [] as $file) {
            $class = 'Api\\V3\\Controllers\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Controller::class) && !(new \ReflectionClass($class))->isAbstract()) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    public function testEveryCrudControllerIsRead(): void
    {
        $names = array_keys(iterator_to_array(self::controllers()));
        foreach (['CampaignsController', 'LandingPagesController', 'TrackersController', 'AppSkanEncodingsController'] as $expected) {
            self::assertContains($expected, $names, 'the scan finds the CRUD controllers');
        }
    }

    /**
     * @dataProvider controllers
     * @param class-string<Controller> $class
     */
    public function testItsFieldsDescribeItsColumns(string $class): void
    {
        $controller = new $class(self::$db, 1);
        $table = self::call($controller, 'tableName');
        /** @var array<string, array<string, mixed>> $fields */
        $fields = self::call($controller, 'fields');
        $columns = self::columns($table);
        self::assertNotSame([], $columns, "$table is in the schema");

        $problems = [];
        foreach ($fields as $name => $def) {
            $column = $columns[$name] ?? null;
            if ($column === null) {
                $problems[] = "$name: no such column in $table";
                continue;
            }
            if ($def['readonly'] ?? false) {
                continue;
            }
            $nullable = $column['IS_NULLABLE'] === 'YES';
            if (($def['nullable'] ?? false) !== $nullable) {
                $problems[] = "$name: 'nullable' is " . var_export($def['nullable'] ?? false, true) . ' but the column ' . ($nullable ? 'is' : 'is not') . ' NULL-able';
            }
            $type = (string) $def['type'];
            if ($type === 'i' || $type === 'd') {
                $range = self::columnRange($column);
                if ($range === null) {
                    $problems[] = "$name: a '$type' field on a {$column['COLUMN_TYPE']} column";
                } elseif (!isset($def['range'])) {
                    $problems[] = "$name: declares no 'range'; the column holds " . self::describe($range);
                } elseif (!self::sameRange($def['range'], $range)) {
                    $problems[] = "$name: 'range' is " . self::describe($def['range']) . ' but the column holds ' . self::describe($range);
                }
            }
            // A temporal column takes a value only in its own form, and
            // MySQL answers any other one under strict mode with an error —
            // a 500 for "" in a DATE (PUT /forecast-events/{id} {"end_date":
            // ""}). validatePayload() knows one such form, 'date'; a field on
            // any other temporal type needs one taught to it first.
            $format = $def['format'] ?? null;
            $dataType = (string) $column['DATA_TYPE'];
            if ($dataType === 'date' && $format !== 'date') {
                $problems[] = "$name: a DATE column; declare 'format' => 'date' "
                    . 'so a value that is not a day is a 422, not a 500';
            } elseif (in_array($dataType, ['datetime', 'timestamp', 'time', 'year'], true)) {
                $problems[] = "$name: a writable {$column['COLUMN_TYPE']} column, and validatePayload() "
                    . 'has no format for one; add it there before declaring the field';
            }
            if ($format !== null && !($format === 'date' && $dataType === 'date' && $type === 's')) {
                $problems[] = "$name: 'format' => " . var_export($format, true)
                    . " on a '$type' field over a {$column['COLUMN_TYPE']} column; "
                    . "only 'date' on an 's' field over a DATE column is read";
            }
            if ($type === 's' && $column['CHARACTER_MAXIMUM_LENGTH'] !== null && in_array($column['DATA_TYPE'], ['char', 'varchar'], true)) {
                $columnMax = (int) $column['CHARACTER_MAXIMUM_LENGTH'];
                if (isset($def['allowed'])) {
                    foreach ($def['allowed'] as $value) {
                        if (mb_strlen((string) $value) > $columnMax) {
                            $problems[] = "$name: allowed value '$value' is longer than the column's $columnMax";
                        }
                    }
                } elseif (!isset($def['max_length'])) {
                    $problems[] = "$name: declares no max_length; the column holds $columnMax characters";
                } elseif ((int) $def['max_length'] > $columnMax) {
                    $problems[] = "$name: max_length {$def['max_length']} is longer than the column's $columnMax characters";
                }
            }
        }

        self::assertSame([], $problems, "$class::fields() disagrees with $table");
    }

    /**
     * @param array<string, string|null> $column
     * @return array{int|float, int|float}|null
     */
    private static function columnRange(array $column): ?array
    {
        $unsigned = str_contains((string) $column['COLUMN_TYPE'], 'unsigned');
        $bits = ['tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'bigint' => 64][$column['DATA_TYPE']] ?? null;
        if ($bits !== null) {
            if ($bits === 64) {
                return $unsigned ? [0, PHP_INT_MAX] : [PHP_INT_MIN, PHP_INT_MAX];
            }
            return $unsigned ? [0, 2 ** $bits - 1] : [-(2 ** ($bits - 1)), 2 ** ($bits - 1) - 1];
        }
        if ($column['DATA_TYPE'] === 'decimal') {
            $precision = (int) $column['NUMERIC_PRECISION'];
            $scale = (int) $column['NUMERIC_SCALE'];
            $max = round(10 ** ($precision - $scale) - 10 ** -$scale, $scale);

            return [$unsigned ? 0 : -$max, $max];
        }

        return null;
    }

    /**
     * @param array<int|float> $declared
     * @param array{int|float, int|float} $column
     */
    private static function sameRange(array $declared, array $column): bool
    {
        return count($declared) === 2
            && abs((float) $declared[0] - (float) $column[0]) < 1e-9
            && abs((float) $declared[1] - (float) $column[1]) < 1e-9
            && (is_int($column[0]) ? is_int($declared[0]) && is_int($declared[1]) : true);
    }

    /** @param array<int|float> $range */
    private static function describe(array $range): string
    {
        return '[' . implode(', ', array_map(static fn ($v): string => var_export($v, true), $range)) . ']';
    }

    /** @return array<string, array<string, string|null>> */
    private static function columns(string $table): array
    {
        $stmt = self::$db->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }
        $stmt->close();

        return $columns;
    }

    private static function call(object $object, string $method): mixed
    {
        $m = new \ReflectionMethod($object, $method);
        $m->setAccessible(true);

        return $m->invoke($object);
    }
}

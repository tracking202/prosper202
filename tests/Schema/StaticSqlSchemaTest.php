<?php

declare(strict_types=1);

namespace Tests\Schema;

use Prosper202\Database\SchemaInstaller;
use Tests\TestCase;

/**
 * Checks that the SQL the v3 API ships actually matches the schema it ships.
 *
 * CLAUDE.md #2: "Never reference DB columns, tables, or config keys without
 * verifying they exist in the actual schema. Code that calls prepare() with
 * nonexistent columns fails silently or crashes depending on the error
 * handling path."
 *
 * No SQL parser is involved, and none is wanted: a parser knows syntax, while
 * the question here is whether an identifier exists in THIS schema. MySQL is
 * the only thing that can answer that, so every statement is handed to
 * mysqli::prepare() against a freshly installed schema. Preparing does not
 * execute — it resolves tables and columns and plans the query — so this is a
 * read-only check that still rejects unknown tables, unknown columns, unknown
 * columns inside JOINs, and syntax errors.
 *
 * Only statements passed as a single string literal can be checked; queries
 * assembled by concatenation cannot be reconstructed without executing the
 * code that builds them. In api/v3 that covers a little over half the call
 * sites, because the modern code passes literals with `?` placeholders. Those
 * are exactly the queries a parser could not check either — the rest are
 * covered by the integration suites, which validate SQL by running it.
 *
 * @group integration
 */
final class StaticSqlSchemaTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return; // no DB configured; the tests skip
        }

        // SchemaInstaller calls the global _mysqli_query() helper. Load the
        // real definition rather than a stand-in, so the schema this test
        // checks against is built exactly the way the installer builds it.
        require_once __DIR__ . '/../../202-config/functions.php';

        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return; // unreachable; the tests skip rather than erroring
        }
        if (!$db) {
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

    /**
     * Every SQL statement in api/v3 written as a single string literal.
     *
     * @return array<int, array{file: string, line: int, sql: string}>
     */
    private function staticStatements(): array
    {
        $root = dirname(__DIR__, 2) . '/api/v3';
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/->(?:prepare|query)\(\s*(.{0,800}?)\)\s*;/s', $source, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($matches[1] as $index => [$argument, $offset]) {
                $argument = trim($argument);
                // A single quoted string with no interpolation and no
                // concatenation. Anything else is assembled at runtime.
                if (preg_match('/^([\'"])((?:(?!\1)[^\\\\$]|\\\\.)*)\1$/s', $argument, $literal) !== 1) {
                    continue;
                }
                $sql = str_replace(["\\'", '\\"'], ["'", '"'], $literal[2]);
                if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) !== 1) {
                    continue;
                }
                $found[] = [
                    'file' => str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname()),
                    'line' => substr_count(substr($source, 0, $matches[0][$index][1]), "\n") + 1,
                    'sql' => (string) preg_replace('/\s+/', ' ', trim($sql)),
                ];
            }
        }

        return $found;
    }

    public function testTheScannerFindsStatementsToCheck(): void
    {
        // A silent zero would make the schema assertion pass vacuously.
        $this->assertGreaterThan(
            40,
            count($this->staticStatements()),
            'Far fewer static SQL statements than expected — the extractor is probably broken.'
        );
    }

    public function testEveryStaticStatementMatchesTheSchema(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set P202_TEST_DB_HOST to check SQL against a real schema.');
        }

        $rejected = [];
        foreach ($this->staticStatements() as $statement) {
            try {
                $prepared = self::$db->prepare($statement['sql']);
                if ($prepared === false) {
                    $rejected[] = sprintf(
                        "%s:%d\n    %s\n    -> %s",
                        $statement['file'],
                        $statement['line'],
                        $statement['sql'],
                        self::$db->error
                    );
                    continue;
                }
                $prepared->close();
            } catch (\mysqli_sql_exception $e) {
                // MYSQLI_REPORT_STRICT surfaces the same failure as a throw.
                $rejected[] = sprintf(
                    "%s:%d\n    %s\n    -> %s",
                    $statement['file'],
                    $statement['line'],
                    $statement['sql'],
                    $e->getMessage()
                );
            }
        }

        $this->assertSame([], $rejected, sprintf(
            "These statements do not match the shipped schema:\n\n%s\n\n"
            . 'Either the column or table is misspelled, or the schema needs a migration '
            . 'that has not been written.',
            implode("\n\n", $rejected)
        ));
    }
}

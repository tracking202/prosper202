<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The structural scanners are only as good as this helper, so its own claims
 * are pinned here -- against every call shape the scanners were once blind to.
 */
final class SourceScanTest extends TestCase
{
    private const TX_METHODS = ['begin_transaction', 'commit', 'autocommit'];
    private const TX_FUNCTIONS = ['mysqli_begin_transaction', 'mysqli_commit', 'mysqli_autocommit'];

    public function testEveryUncheckedCallShapeIsFound(): void
    {
        $source = "<?php\n"
            . "\$db->begin_transaction();\n"                              // 2
            . "\$this->db->begin_transaction();\n"                        // 3
            . "\$db->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);\n" // 4  arguments
            . "\$db->commit(0, \"name\");\n"                              // 5  arguments
            . "\$conn->begin_transaction(); // start\n"                   // 6  trailing comment
            . "\$this->getDb()->begin_transaction();\n"                   // 7  chained receiver
            . "DB::getInstance()->getConnection()->commit();\n"           // 8  static chain
            . "self::\$db->commit();\n"                                   // 9  static property
            . "\$this->connections['w']->commit();\n"                     // 10 array element
            . "\$db->begin_transaction();\$db->commit();\n"               // 11 two per line
            . "\$this->db->commit();\r\n"                                 // 12 CRLF
            . "\$db\n  ->commit(\n  );\n"                                 // 14 multi-line (name on 14)
            . "mysqli_begin_transaction(\$db);\n"                         // 16 procedural
            . "mysqli_commit(\$db);\n"                                    // 17
            . "\$db->autocommit(false);\n"                                // 18
            . "\$db->commit() ;\n"                                        // 19 space before ;
            . "if (\$x) \$db->commit();\n"                                // 20 brace-less if
            . "else \$db->commit();\n";                                   // 21 brace-less else

        self::assertSame(
            [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 11, 12, 14, 16, 17, 18, 19, 20, 21],
            SourceScan::uncheckedCallStatements($source, self::TX_METHODS, self::TX_FUNCTIONS)
        );
    }

    public function testCheckedCallsAreNotReported(): void
    {
        $source = "<?php\n"
            . "if (!\$db->begin_transaction()) { throw new E(); }\n"
            . "\$ok = \$db->begin_transaction();\n"
            . "return \$db->commit();\n"
            . "\$ok && \$db->commit();\n"
            . "\$r = \$a ? \$db->commit() : false;\n"
            . "if (\$x) \$ok = \$db->commit();\n"
            . "if (!mysqli_commit(\$db)) { return; }\n"
            . "\$db->rollback();\n"          // not in the list
            . "\$stmt->execute();\n"         // not in the list
            . "\$x->committed();\n"          // different name
            . "\$c = new Commit(); commit();\n"; // bare function not in the function list

        self::assertSame([], SourceScan::uncheckedCallStatements($source, self::TX_METHODS, self::TX_FUNCTIONS));
    }

    public function testZeroArgsOnlyTellsRawExecuteFromTheCheckedWrappers(): void
    {
        $source = "<?php\n"
            . "\$stmt->execute();\n"                       // 2: raw
            . "\$this->conn->execute(\$stmt);\n"           // wrapper: has an argument
            . "\$this->execute(\$stmt, 'Create failed');\n" // wrapper
            . "\$stmt->execute(); // c\n";                 // 5: raw

        self::assertSame([2, 5], SourceScan::uncheckedCallStatements($source, ['execute'], [], true));
        self::assertSame([2, 3, 4, 5], SourceScan::uncheckedCallStatements($source, ['execute']));
    }

    public function testEveryDoubleCloseShapeIsFound(): void
    {
        $source = "<?php\nclass A {\n"
            . " function a() { \$row = \$this->conn->fetchOne(\$stmt) ?? [];\n \$stmt->close(); }\n"           // 4
            . " function b() { if (\$this->conn->fetchOne(\$stmt) === null) { return; }\n \$stmt->close(); }\n" // 6
            . " function c() { \$rows = \$this->conn->fetchAll(\$stmt);\n /* c */\n \$stmt->close(); }\n"       // 9
            . " function d() { \$id = \$this->conn->executeInsert(\$stmt);\n # note\n \$stmt->close(); }\n"     // 12
            . " function e() { \$n = \$this->conn->executeUpdate(\$stmt);\n if (\$x) {}\n \$stmt->close(); }\n" // 15
            . " function f() { if (\$x) { \$this->conn->fetchOne(\$stmt); }\n \$stmt->close(); }\n"             // 17 (double on the if path)
            . "}\n";

        self::assertSame(
            [4, 6, 9, 12, 15, 17],
            SourceScan::closesAfterClosingHelper($source, ['fetchOne', 'fetchAll', 'executeInsert', 'executeUpdate'])
        );
    }

    public function testLegitimateClosesAreNotReported(): void
    {
        $source = "<?php\nclass A {\n"
            . " function f() { \$row = \$this->conn->fetchOne(\$stmt);\n \$stmt = \$this->conn->prepareRead('x');\n \$stmt->close(); }\n" // reassigned
            . " function g() { \$row = \$this->conn->fetchOne(\$stmt); }\n function h() { \$stmt->close(); }\n"                              // other function
            . " function i() { \$row = \$this->conn->fetchOne(\$stmt); \$other->close(); }\n"                                                 // other variable
            . " function j() { \$this->conn->execute(\$stmt); \$stmt->close(); }\n"                                                           // execute() does not close
            . "}\n";

        self::assertSame([], SourceScan::closesAfterClosingHelper($source, ['fetchOne', 'fetchAll', 'executeInsert', 'executeUpdate']));
    }

    public function testCountMatchesRefusesToReportAFailedScanAsClean(): void
    {
        // A nested quantifier over a long input exhausts the backtrack limit;
        // preg_match_all() returns false, which must not read as zero.
        $pathological = str_repeat('a', 100000) . '!';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scanning x.php failed');
        SourceScan::countMatches('/^(a+)+$/', $pathological, 'x.php');
    }

    public function testPrunedDirectoriesContainNoPhp(): void
    {
        // PRUNED skips these for speed on the claim that they hold no PHP. If
        // that stops being true the scanners go blind to whatever lands there,
        // so the claim is checked rather than assumed.
        $root = SourceScan::repoRoot();
        foreach (SourceScan::PRUNED as $dir) {
            if (in_array($dir, ['vendor', 'node_modules', '.git'], true) || !is_dir("$root/$dir")) {
                continue;
            }
            $php = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $php[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
            self::assertSame([], $php, "$dir/ is pruned from the source scans but now contains PHP; remove it from SourceScan::PRUNED.");
        }
    }

    public function testTheWalkFindsTheTreeAndExcludesTestsByDefault(): void
    {
        $files = SourceScan::phpFiles();
        self::assertGreaterThan(400, count($files));
        self::assertArrayHasKey('api/v3/Support/StatementHelpers.php', $files);
        self::assertArrayNotHasKey('tests/Support/SourceScan.php', $files);
        self::assertArrayHasKey('tests/Support/SourceScan.php', SourceScan::phpFiles(includeTests: true));
    }
}

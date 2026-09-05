<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Sibling of UncheckedExecuteTest for the two calls that open and close a
 * transaction.
 *
 * connect.php sets mysqli_report(MYSQLI_REPORT_STRICT) alone, so a failed
 * begin_transaction() or commit() RETURNS FALSE instead of throwing. Both are
 * uniquely bad to ignore:
 *
 *  - A dropped begin_transaction() leaves the connection in autocommit. Every
 *    statement in the "transaction" lands individually, and the rollback() in
 *    the failure path has nothing to undo — so the caller is told the operation
 *    failed while half of it is permanently committed.
 *  - A dropped commit() reports success for work that was never durable.
 *
 * rollback() is deliberately NOT scanned: it is called from failure paths that
 * already have a root-cause error to report, and replacing that error with the
 * rollback's own would hide why the work was abandoned.
 */
final class UncheckedTransactionBoundaryTest extends TestCase
{
    /**
     * Linear patterns only. A nested quantifier here can exhaust PCRE's
     * backtrack limit on a large file, and preg_match_all then returns FALSE —
     * which a scanner reads as "no matches" and reports the tree clean while
     * the defect is sitting in it. See testTheScannerSurvivesALargeFile().
     */
    private const PATTERNS = [
        'begin_transaction' => '/^[ \t]*\$[\w\->]*->begin_transaction\(\s*\);[ \t]*$/m',
        'commit'            => '/^[ \t]*\$[\w\->]*->commit\(\s*\);[ \t]*$/m',
    ];

    /** @return array<string, int> file (repo-relative) => count of unchecked boundary calls */
    private function uncheckedBoundaries(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    // Tests may exercise failure modes deliberately.
                    return !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', 'tests'], true);
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            $count = 0;
            foreach (self::PATTERNS as $label => $pattern) {
                $hits = preg_match_all($pattern, $source);
                if ($hits === false) {
                    // Never downgrade a scan failure to "clean".
                    self::fail(sprintf(
                        'Scanning %s for unchecked %s() failed: %s',
                        $file->getPathname(),
                        $label,
                        preg_last_error_msg()
                    ));
                }
                $count += $hits;
            }
            if ($count > 0) {
                $found[str_replace($root . '/', '', $file->getPathname())] = $count;
            }
        }

        return $found;
    }

    public function testTheScannerMatchesTheShapesItClaimsTo(): void
    {
        $sample = <<<'PHP_SAMPLE'
            $db->begin_transaction();
            $this->db->begin_transaction();
            if (!$db->begin_transaction()) {
            $ok = $db->begin_transaction();
            $db->commit();
            $connection->commit();
            if (!$this->db->commit()) {
            return $db->commit();
        PHP_SAMPLE;

        self::assertSame(2, preg_match_all(self::PATTERNS['begin_transaction'], $sample));
        self::assertSame(2, preg_match_all(self::PATTERNS['commit'], $sample));
    }

    public function testTheScannerSurvivesALargeFile(): void
    {
        // A pattern that backtracks catastrophically returns false here rather
        // than a count, which is the failure mode this guard exists to catch.
        $big = str_repeat("        \$this->db->query('SELECT 1 FROM t WHERE a = 1');\n", 4000)
            . "        \$this->db->commit();\n";

        foreach (self::PATTERNS as $label => $pattern) {
            self::assertNotFalse(
                preg_match_all($pattern, $big),
                "Pattern for $label failed on a large input: " . preg_last_error_msg()
            );
        }
        self::assertSame(1, preg_match_all(self::PATTERNS['commit'], $big));
    }

    public function testNoUncheckedTransactionBoundaryExists(): void
    {
        $found = $this->uncheckedBoundaries();

        self::assertSame([], $found, sprintf(
            "These open or close a transaction without checking the result:\n%s\n"
            . 'Under MYSQLI_REPORT_STRICT both return false instead of throwing. An unchecked '
            . 'begin_transaction() silently downgrades the block to autocommit, so the matching '
            . 'rollback() undoes nothing; an unchecked commit() reports success for work that was '
            . 'never written.',
            implode("\n", array_map(
                static fn(string $f, int $n): string => "  $f ($n)",
                array_keys($found),
                $found
            ))
        ));
    }
}

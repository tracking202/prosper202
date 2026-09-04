<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * Connection::fetchOne(), fetchAll() and executeInsert() all close the statement
 * they are handed. Closing it again is not harmless: on PHP 8 every method call
 * against a closed mysqli_stmt throws `Error: mysqli_stmt object is already
 * closed`, so the second close takes down whatever transaction it sits in.
 *
 * This shipped twice in MysqlRotatorRepository and survived its unit tests,
 * because those drive the repository through a fake connection whose close()
 * does not throw — CLAUDE.md's "tests that mock the seam under test". A textual
 * scan does not care what the tests mock.
 */
final class DoubleStatementCloseTest extends TestCase
{
    /** Connection helpers that close the statement before returning. */
    private const CLOSING_HELPERS = ['fetchOne', 'fetchAll', 'executeInsert'];

    /**
     * A call to a closing helper followed by close() on the same variable,
     * separated only by whitespace and full-line comments. Written to run in
     * linear time: no quantifier over an optional group.
     */
    private static function pattern(): string
    {
        return '/->(?:' . implode('|', self::CLOSING_HELPERS) . ')\(\s*\$(\w+)\s*\)\s*;'
            . '\s*(?:\/\/[^\n]*\n\s*)*'
            . '\$\1->close\(\s*\)\s*;/';
    }

    /** @return array<string, list<string>> file (repo-relative) => offending snippets */
    private function redundantCloses(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    return !in_array($file->getFilename(), ['vendor', 'node_modules', '.git', 'tests'], true);
                }
            )
        );

        $pattern = self::pattern();

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            $hits = preg_match_all($pattern, $source, $matches);
            // preg_match_all returns FALSE on a regex failure (a backtrack-limit
            // blow-up on a large file, say), which reads exactly like "no
            // matches" -- the silent-failure shape this whole suite exists to
            // catch. An earlier revision of this pattern did precisely that and
            // reported the tree clean while the defect was still in it.
            if ($hits === false) {
                self::fail(sprintf(
                    'scanning %s failed: %s',
                    $file->getPathname(),
                    preg_last_error_msg()
                ));
            }
            if ($hits > 0) {
                $found[str_replace($root . '/', '', $file->getPathname())] = $matches[0];
            }
        }

        return $found;
    }

    public function testTheScannerWorks(): void
    {
        $pattern = self::pattern();

        // Positive: the shape this test exists to catch, at real indentation.
        $bad = "        \$row = \$this->conn->fetchOne(\$stmt);\n        \$stmt->close();\n";
        // Positive: separated by a comment line.
        $commented = "        \$row = \$this->conn->fetchOne(\$stmt);\n        // note\n        \$stmt->close();\n";
        // Negative: a close on a DIFFERENT statement is legitimate.
        $ok = "        \$row = \$this->conn->fetchOne(\$stmt);\n        \$other->close();\n";

        self::assertSame(1, preg_match_all($pattern, $bad), 'scanner misses the shape it targets');
        self::assertSame(1, preg_match_all($pattern, $commented), 'scanner misses a commented gap');
        self::assertSame(0, preg_match_all($pattern, $ok), 'scanner flags an unrelated close');

        // And it must not fall over on a realistically large file, which is how
        // the first version of this pattern silently reported the tree clean.
        $large = str_repeat("        \$x = \$this->conn->fetchOne(\$s);\n        return \$x;\n\n", 2000);
        self::assertNotFalse(preg_match_all($pattern, $large), 'pattern failed on a large input: ' . preg_last_error_msg());
    }

    public function testNoStatementIsClosedTwice(): void
    {
        $found = $this->redundantCloses();

        $this->assertSame([], $found, sprintf(
            "These close a statement that %s already closed:\n%s\n"
            . 'On PHP 8 the second close throws "mysqli_stmt object is already closed", '
            . 'aborting whatever transaction it sits in. Drop the redundant close().',
            implode('()/', self::CLOSING_HELPERS) . '()',
            implode("\n", array_map(
                static fn(string $f, array $hits): string => "  $f (" . count($hits) . ')',
                array_keys($found),
                $found
            ))
        ));
    }
}

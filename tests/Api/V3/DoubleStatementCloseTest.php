<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Connection::fetchOne(), fetchAll(), executeInsert() and executeUpdate() all
 * close the statement they are handed (see 202-config/Database/Connection.php).
 * A `$stmt->close()` after one of them is a second close, and on PHP 8 that
 * throws "mysqli_stmt object is already closed" -- from inside whatever
 * transaction the code sits in, which then rolls back. The rotator repository
 * shipped exactly this, twice, in code that read as a tidy cleanup.
 *
 * The scan is token-based: it follows the variable handed to the helper
 * through the rest of the enclosing function and reports a close() on it
 * unless the variable was reassigned first. Shapes covered are pinned in
 * SourceScanTest.
 */
final class DoubleStatementCloseTest extends TestCase
{
    /** Connection methods that close the statement themselves. */
    private const CLOSING_HELPERS = ['fetchOne', 'fetchAll', 'executeInsert', 'executeUpdate'];

    public function testTheHelperListMatchesConnection(): void
    {
        // If Connection gains another closing helper, or one stops closing,
        // this list must follow -- otherwise the scan below is quietly wrong.
        $source = SourceScan::phpFiles()['202-config/Database/Connection.php'];
        foreach (self::CLOSING_HELPERS as $helper) {
            self::assertSame(
                1,
                preg_match('/public function ' . $helper . '\(object \$stmt\).*?\$stmt->close\(\);/s', $source),
                "Connection::$helper() must close the statement it is given, or be removed from CLOSING_HELPERS"
            );
        }
    }

    public function testNoStatementIsClosedTwice(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, '->close(')) {
                continue;
            }
            $lines = SourceScan::closesAfterClosingHelper($source, self::CLOSING_HELPERS);
            if ($lines !== []) {
                $found[$path] = $lines;
            }
        }

        self::assertSame([], $found, sprintf(
            "These close a statement that fetchOne()/fetchAll()/executeInsert()/executeUpdate() already closed:\n%s\n"
            . 'On PHP 8 the second close throws "mysqli_stmt object is already closed", aborting whatever '
            . 'transaction it sits in. Drop the redundant close().',
            implode("\n", array_map(
                static fn(string $f, array $lines): string => "  $f: line " . implode(', ', $lines),
                array_keys($found),
                $found
            ))
        ));
    }
}

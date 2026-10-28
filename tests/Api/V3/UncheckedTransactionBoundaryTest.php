<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * No mysqli transaction boundary may have its result discarded, anywhere in
 * the tree.
 *
 * Which failure mode applies depends on the entry point. Under connect.php's
 * mysqli_report(MYSQLI_REPORT_STRICT) -- the UI and cron paths -- a failed
 * begin_transaction() or commit() RETURNS FALSE. api/v3 never includes
 * connect.php and runs under PHP's default ERROR|STRICT, where the same calls
 * throw. The check is required everywhere regardless, because a file cannot
 * know which bootstrap loaded it, and both boundaries are uniquely bad to
 * ignore where they do return false:
 *
 *  - A dropped begin_transaction() leaves the connection in autocommit. Every
 *    statement in the "transaction" lands individually, and the rollback() in
 *    the failure path has nothing to undo -- so the caller is told the
 *    operation failed while half of it is permanently committed.
 *  - A dropped commit() reports success for work that was never durable.
 *
 * rollback() is deliberately NOT scanned: it is called from failure paths that
 * already have a root-cause error to report, and replacing that error with the
 * rollback's own would hide why the work was abandoned.
 *
 * This is the inference-blind floor. UncheckedTransactionBoundaryRule (PHPStan)
 * covers the same shapes wherever the receiver is known to be mysqli; this
 * test covers the untyped legacy receivers PHPStan cannot see. Both work on
 * statements rather than lines, so arguments, trailing comments, chained
 * receivers and two statements on one line are all ordinary
 * (SourceScanTest pins the shapes).
 */
final class UncheckedTransactionBoundaryTest extends TestCase
{
    private const METHODS = ['begin_transaction', 'commit', 'autocommit'];
    private const FUNCTIONS = ['mysqli_begin_transaction', 'mysqli_commit', 'mysqli_autocommit'];

    public function testNoUncheckedTransactionBoundaryExists(): void
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $lines = SourceScan::uncheckedCallStatements($source, self::METHODS, self::FUNCTIONS);
            if ($lines !== []) {
                $found[$path] = $lines;
            }
        }

        self::assertSame([], $found, sprintf(
            "These open or close a transaction without checking the result:\n%s\n"
            . 'Under MYSQLI_REPORT_STRICT both return false instead of throwing. An unchecked '
            . 'begin_transaction() silently downgrades the block to autocommit, so the matching '
            . 'rollback() undoes nothing; an unchecked commit() reports success for work that was '
            . 'never written. Check the result, or use Connection::transaction() / '
            . 'StatementHelpers::transaction().',
            implode("\n", array_map(
                static fn(string $f, array $lines): string => "  $f: line " . implode(', ', $lines),
                array_keys($found),
                $found
            ))
        ));
    }
}

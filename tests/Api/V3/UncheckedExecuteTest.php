<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * A bare `$stmt->execute();` discards the return value. Under the error mode
 * the app actually sets (connect.php calls mysqli_report(MYSQLI_REPORT_STRICT)
 * alone, not the ERROR|STRICT default), a failed execute RETURNS FALSE rather
 * than throwing -- so the failure is silent, and whatever the code does next
 * runs on the assumption that the statement succeeded. In a batch loop that
 * reads as "no more rows"; in a dedupe guard it reads as "not yet processed".
 *
 * ForbidDirectMysqliStmtCallRule covers some of this, but only where PHPStan
 * can infer the caller is a mysqli_stmt -- which legacy code often does not
 * allow -- and it flags every direct call, checked or not. This test is the
 * complement: token-based rather than typed, so inference cannot hide anything
 * from it, and concerned only with whether the result is used.
 *
 * Only zero-argument calls count. The checked wrappers are also named
 * execute() -- Connection::execute($stmt), StatementHelpers::execute($stmt,
 * $message) -- and are told apart from a raw mysqli_stmt::execute() by taking
 * arguments.
 */
final class UncheckedExecuteTest extends TestCase
{
    /**
     * Sites that predate this test. Each silently ignores a failed statement
     * and should be worked through; the point of the list is that it only
     * ever shrinks.
     *
     * @var string[]
     */
    private const KNOWN_UNCHECKED = [
        '202-Mobile/202-login.php',
        '202-login.php',
        '202-account/account.php',
        'api/v2/app.php',
        '202-config/Attribution/AttributionIntegrationService.php',
    ];

    /** @return array<string, list<int>> file (repo-relative) => lines of bare execute() calls */
    private function bareExecuteCalls(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, '->execute(')) {
                continue;
            }
            $lines = SourceScan::uncheckedCallStatements($source, ['execute'], [], true);
            if ($lines !== []) {
                $found[$path] = $lines;
            }
        }

        return $found;
    }

    public function testNoNewUncheckedExecuteIsIntroduced(): void
    {
        $unexpected = array_diff_key($this->bareExecuteCalls(), array_flip(self::KNOWN_UNCHECKED));

        $this->assertSame([], $unexpected, sprintf(
            "These files ignore the result of a statement execution:\n%s\n"
            . 'A failed execute() returns false under this app\'s mysqli error mode, so the code '
            . 'carries on as though the statement succeeded. Check the return and fail, warn, or '
            . 'recover explicitly.',
            implode("\n", array_map(
                static fn(string $f, array $lines): string => "  $f: line " . implode(', ', $lines),
                array_keys($unexpected),
                $unexpected
            ))
        ));
    }

    public function testTheKnownListHasNoStaleEntries(): void
    {
        $current = $this->bareExecuteCalls();
        foreach (self::KNOWN_UNCHECKED as $file) {
            $this->assertArrayHasKey(
                $file,
                $current,
                "$file no longer has an unchecked execute() -- remove it from KNOWN_UNCHECKED."
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ConsentWithheld;
use Prosper202\Database\Exceptions\QueryException;

/**
 * The refusals' storage not there yet (before the 1.9.80 upgrade) is told
 * from every other failure by the server's errno and the name it gives,
 * which carries the statement's alias ('cl.consent_withheld'). A failure
 * that is not that one is not read as "nothing was ever refused".
 */
final class ConsentStorageMissingTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, bool}> */
    public static function failures(): iterable
    {
        $prepare = static fn (string $error, int $errno): QueryException
            => new QueryException('Failed to prepare MySQL statement: ' . $error . ' [errno ' . $errno . ']');

        yield 'MariaDB 10.11, aliased' => [$prepare("Unknown column 'cl.consent_withheld' in 'SELECT'", 1054), true];
        yield 'MySQL 8.0, aliased' => [$prepare("Unknown column 'cl.consent_withheld' in 'field list'", 1054), true];
        yield 'mysqli exception, a joined alias' => [new \mysqli_sql_exception("Unknown column 'rr.consent_withheld' in 'where clause'", 1054), true];
        yield 'bare' => [$prepare("Unknown column 'consent_withheld' in 'field list'", 1054), true];
        yield 'the refusals table' => [$prepare("Table 'p202.202_click_consent_refusals' doesn't exist", 1146), true];
        yield 'wrapped' => [new \RuntimeException('read failed', 0, $prepare("Unknown column 'cl.consent_withheld' in 'SELECT'", 1054)), true];

        yield 'another column' => [$prepare("Unknown column 'cl.currency' in 'SELECT'", 1054), false];
        yield 'a longer name' => [$prepare("Unknown column 'cl.consent_withheld_at' in 'SELECT'", 1054), false];
        yield 'another table' => [$prepare("Table 'p202.202_conversion_logs' doesn't exist", 1146), false];
        yield 'the name with another errno' => [$prepare("Lock wait timeout exceeded; 'cl.consent_withheld'", 1205), false];
        yield 'no errno' => [new \RuntimeException("Unknown column 'cl.consent_withheld' in 'SELECT'"), false];
    }

    /** @dataProvider failures */
    public function testOnlyTheMissingStorageReadsAsMissing(\Throwable $e, bool $missing): void
    {
        self::assertSame($missing, ConsentWithheld::storageMissing($e));
    }
}

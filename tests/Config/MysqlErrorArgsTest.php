<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * record_mysql_error() is called as ($sql), ($db, $sql) and ($db) alone, and
 * used to tell them apart by whether a second argument was passed: ($db)
 * alone took the ($sql) branch and cast the connection to a string, an
 * uncaught Error in place of the error page. p202MysqlErrorArgs() is how both
 * definitions now read their arguments.
 */
final class MysqlErrorArgsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../202-config/mysql-error-args.php';
    }

    private static function connection(): \mysqli
    {
        // A mysqli the type check can see; nothing here talks to a server.
        return (new \ReflectionClass(\mysqli::class))->newInstanceWithoutConstructor();
    }

    /**
     * A pattern that fails (PCRE's backtrack limit, forced low here) names
     * itself in the logged statement instead of leaving it empty, which read
     * like a blank query.
     */
    public function testARedactionThatFailsIsNamedNotEmpty(): void
    {
        $run = static function (int $limit, string $statement): string {
            $code = 'require ' . var_export(dirname(__DIR__, 2) . '/202-config/mysql-error-args.php', true) . ';'
                . ' echo p202RedactedStatement(' . var_export($statement, true) . ');';

            return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -d pcre.backtrack_limit=' . $limit
                . ' -r ' . escapeshellarg($code) . ' 2>&1');
        };
        // Each reaches a different pattern before the limit stops it: the
        // whitespace collapse, the cut where two readings of a quote part, and
        // the cut to length that keeps a character whole.
        $statements = [
            'SELECT a,   b FROM t WHERE x = 1 ' . str_repeat('AND z = 3 ', 80),
            "SELECT 'a\\', k='s3cr3t'",
            str_repeat('é', 400),
        ];
        foreach ($statements as $statement) {
            $failed = $run(1, $statement);
            self::assertStringStartsWith(
                '(a statement of ' . strlen($statement) . ' bytes, not shown: ',
                $failed,
                $statement . ' => ' . $failed
            );
        }
        $shown = $run(1000000, $statements[0]);
        self::assertStringStartsWith('SELECT a, b FROM t WHERE x = 1 AND z = 3', $shown);
        self::assertSame(500, strlen($shown), 'cut to 500 bytes');
        self::assertSame('SELECT ?…', $run(1000000, $statements[1]), 'only what both readings keep');
        $multibyte = $run(1000000, $statements[2]);
        self::assertLessThanOrEqual(500, strlen($multibyte));
        self::assertTrue(mb_check_encoding($multibyte, 'UTF-8'), 'no character cut in half');
    }

    public function testAConnectionAloneIsTheConnectionNotAStatement(): void
    {
        $db = self::connection();

        [$resolved, $sql] = p202MysqlErrorArgs($db);

        self::assertSame($db, $resolved);
        self::assertSame('(statement not given)', $sql);
    }

    public function testAConnectionAndAStatementAreBoth(): void
    {
        $db = self::connection();

        [$resolved, $sql] = p202MysqlErrorArgs($db, 'SELECT 1');

        self::assertSame($db, $resolved);
        self::assertSame('SELECT 1', $sql);
    }

    public function testAStatementAloneLeavesTheConnectionToTheCaller(): void
    {
        [$resolved, $sql] = p202MysqlErrorArgs('SELECT 2');

        self::assertNull($resolved);
        self::assertSame('SELECT 2', $sql);
    }

    public function testSomethingThatIsNeitherIsNamedNotCast(): void
    {
        // aff_networks.php passed the query's result (false) as the statement.
        [$resolved, $sql] = p202MysqlErrorArgs(false);
        self::assertNull($resolved);
        self::assertSame('(statement not given: got bool)', $sql);

        // An object with no __toString() is named, never cast (the cast is the fatal).
        [, $sql] = p202MysqlErrorArgs(new \stdClass());
        self::assertSame('(statement not given: got stdClass)', $sql);

        // A connection in the statement position is not the statement either.
        [$resolved, $sql] = p202MysqlErrorArgs(null, self::connection());
        self::assertNull($resolved);
        self::assertSame('(statement not given: got mysqli)', $sql);
    }
}

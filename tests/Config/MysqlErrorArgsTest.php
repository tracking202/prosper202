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

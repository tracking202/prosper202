<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * getDashEmail() is read by the footer of every signed-in page. A failed
 * read (the admin _mysqli_query() answers false, and logs it) called
 * fetch_assoc() on false: a fatal in place of the rest of the page. And the
 * first read, which fetches the address from the account server and stores
 * it, answered the copy escaped for its UPDATE: `o\'brien@example.com`.
 *
 * The shipped body runs in a process of its own, beside stand-ins for the
 * connection, the query function and the account server.
 */
final class DashEmailReadTest extends TestCase
{
    /** A stored row with no address, so the address is fetched and stored. */
    private const NO_ADDRESS_YET = ' function _mysqli_query($sql) { $GLOBALS["sql"][] = $sql;'
        . ' return str_starts_with($sql, "SELECT") ? new class extends \mysqli_result {'
        . ' public function __construct() {}'
        . ' public function fetch_assoc(): array|null|false { return $GLOBALS["row"]; } } : true; }'
        . ' function getSetDashEmail($key) { return ["code" => 200, "email" => "o\'brien@example.com"]; }'
        . ' $db = new class { public function real_escape_string($v) { return addslashes($v); } };';

    /**
     * @param string $standIns PHP declaring _mysqli_query() and whatever else the call reaches
     * @param string $row the PHP expression the read's fetch_assoc() answers
     * @return array{0: string, 1: list<string>} what getDashEmail() answered, var_export()ed, and the statements run
     */
    private static function dashEmail(string $standIns, string $row = 'null'): array
    {
        $code = 'require ' . var_export(__DIR__ . '/fixtures/lift-function.php', true) . ';'
            . $standIns
            . " \$GLOBALS['sql'] = []; \$GLOBALS['row'] = $row;"
            . ' eval(lift_function(' . var_export(dirname(__DIR__, 2) . '/202-config/functions-tracking202.php', true)
            . ", 'getDashEmail'));"
            . " \$_SESSION = ['user_id' => 1];"
            . ' echo json_encode([var_export(getDashEmail(), true), $GLOBALS["sql"]]);';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "the footer's read ended the page: $out $err");
        $answer = json_decode($out, true);
        self::assertIsArray($answer, $out . $err);

        return $answer;
    }

    public function testAFailedReadAnswersNoAddress(): void
    {
        self::assertSame('false', self::dashEmail(' function _mysqli_query($sql) { return false; }')[0]);
    }

    public function testAnAddressFetchedOnTheFirstReadIsAnsweredAsFetched(): void
    {
        [$answer, $statements] = self::dashEmail(
            self::NO_ADDRESS_YET,
            '["user_dash_email" => "", "p202_customer_api_key" => "k"]'
        );

        self::assertSame(var_export("o'brien@example.com", true), $answer);
        self::assertCount(2, $statements);
        self::assertStringContainsString("user_dash_email='o\\'brien@example.com'", $statements[1]);
    }

    /** @return array<string, array{string}> */
    public static function noRow(): array
    {
        return ['no such account' => ['null'], 'a failed fetch' => ['false']];
    }

    /** @dataProvider noRow */
    public function testNoRowAnswersNoAddress(string $row): void
    {
        [$answer, $statements] = self::dashEmail(self::NO_ADDRESS_YET, $row);

        self::assertSame('false', $answer);
        self::assertCount(1, $statements, 'nothing is fetched or stored');
    }
}

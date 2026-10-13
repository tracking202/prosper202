<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Which privacy setting connect2.php's bootstrap puts in force for a click
 * path request: the install's, user 1's row — with memcache working or not.
 *
 * With memcache working it used to read account.php's per-account key under
 * the request's tracker id instead. No such key is ever written, the miss
 * came back false, false counted as set, and the database was never asked:
 * a redirect tracked every visitor in full, cookies and unmasked address,
 * whatever the owner had chosen. This runs the bootstrap's own lines,
 * extracted from connect2.php (which a test cannot include), against stubs
 * for the cache and the database.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PrivacyBootstrapTest extends TestCase
{
    /** @return iterable<string, array{bool, array<string, string>}> memcache working, the request's query */
    public static function requests(): iterable
    {
        yield 'a redirect, memcache working' => [true, ['t202id' => '345003576']];
        yield 'a landing page click, memcache working' => [true, ['lpip' => '12']];
        yield 'a pixel, memcache working' => [true, []];
        yield 'a redirect, no memcache' => [false, ['t202id' => '345003576']];
    }

    /**
     * @dataProvider requests
     * @param array<string, string> $query
     */
    public function testTheInstallsSettingIsInForce(bool $memcacheWorking, array $query): void
    {
        $GLOBALS['p202TestSql'] = [];
        $GLOBALS['p202TestStored'] = 'all';
        self::stubs();
        $_SESSION = [];
        $_GET = $query;
        self::assertSame('all', self::bootstrap($memcacheWorking));
        self::assertCount(1, $GLOBALS['p202TestSql'], 'the setting is read from the database');
        self::assertMatchesRegularExpression(
            "/`user_id`='1'/",
            $GLOBALS['p202TestSql'][0],
            'user 1\'s row: the install\'s setting'
        );
    }

    /** @return iterable<string, array{string, string}> stored, in force */
    public static function storedValues(): iterable
    {
        yield 'disabled' => ['disabled', 'disabled'];
        yield 'eu' => ['eu', 'eu'];
        yield 'all' => ['all', 'all'];
        // Not a setting: held back, as the app intakes read it (PrivacySetting).
        yield 'a value that is not a setting' => ['EU', 'all'];
        yield 'an empty value' => ['', 'all'];
        // The read failed (the helper's false): held back, never 'disabled'.
        yield 'a read that failed' => [false, 'all'];
        // No preferences row: nothing set, the column's default.
        yield 'no row' => [null, 'disabled'];
    }

    /** @dataProvider storedValues */
    public function testTheStoredValueIsReadAsTheSettingItIs(string|false|null $stored, string $inForce): void
    {
        self::stubs();
        $GLOBALS['p202TestStored'] = $stored;
        $_SESSION = [];
        $_GET = [];
        // What the bootstrap logs, kept off this process's stderr.
        $log = (string) tempnam(sys_get_temp_dir(), 'p202-privacy-log-');
        ini_set('error_log', $log);
        try {
            self::assertSame($inForce, self::bootstrap(false));
            $logged = (string) file_get_contents($log);
        } finally {
            @unlink($log);
        }
        if ($stored === false) {
            self::assertStringContainsString('could not be read; holding back', $logged, 'a failed read is logged, so it can be found');
        } else {
            self::assertSame('', $logged, 'nothing is logged for a read that worked');
        }
    }

    /**
     * What the bootstrap's lines call: a cache that holds nothing under any
     * key (what a redirect's tracker-id key always was), and a database whose
     * user 1 row holds $GLOBALS['p202TestStored'] (false: the query failed;
     * null: there is no row), recording the SQL asked.
     */
    private static function stubs(): void
    {
        if (function_exists('systemHash')) {
            return;
        }
        eval(<<<'PHP'
            function systemHash(): string { return 'test'; }
            function getCache(string $key, $default = false) { return false; }
            function memcache_mysql_fetch_assoc($sql) {
                $GLOBALS['p202TestSql'][] = $sql;
                $stored = array_key_exists('p202TestStored', $GLOBALS) ? $GLOBALS['p202TestStored'] : 'all';
                // false: the query failed; null: no row (fetch_assoc()'s answer).
                return $stored === false || $stored === null ? $stored : ['user_pref_privacy' => $stored];
            }
            PHP);
    }

    /** The privacy setting connect2.php's bootstrap leaves in $_SESSION. */
    private static function bootstrap(bool $memcacheWorking): mixed
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/connect2.php');
        $start = strpos($src, '// Initialize database connection using the DB class from 202-config.php');
        $end = strpos($src, "// The visitor's address: one rule");
        self::assertIsInt($start, 'the bootstrap\'s database step is not where this test looks');
        self::assertIsInt($end);

        return (static function (string $code, bool $memcacheWorking): mixed {
            // What the lines read: no database (the stubs answer for it).
            $db = false;
            $tid = 1;
            eval($code);

            return $_SESSION['privacy'] ?? null;
        })(substr($src, $start, $end - $start), $memcacheWorking);
    }
}

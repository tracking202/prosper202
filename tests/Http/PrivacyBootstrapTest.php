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
    }

    /** @dataProvider storedValues */
    public function testTheStoredValueIsReadAsTheSettingItIs(string $stored, string $inForce): void
    {
        self::stubs();
        $GLOBALS['p202TestStored'] = $stored;
        $_SESSION = [];
        $_GET = [];
        self::assertSame($inForce, self::bootstrap(false));
    }

    /**
     * What the bootstrap's lines call: a cache that holds nothing under any
     * key (what a redirect's tracker-id key always was), and a database whose
     * user 1 row holds $GLOBALS['p202TestStored'], recording the SQL asked.
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
                return ['user_pref_privacy' => $GLOBALS['p202TestStored'] ?? 'all'];
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

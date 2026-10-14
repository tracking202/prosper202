<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * systemHash() is the namespace of every cache key: the lookup tables' ids,
 * the rows memcache_mysql_fetch_assoc() keeps by their SQL (the landing-page
 * scripts read a tracker's that way, by its public id), and the URL a MySQL
 * outage redirects to. It was the host and the server's address alone, so
 * two installs on one host sharing a memcached read each other's entries: a
 * landing-page click on one could be filed under the other's tracker row for
 * the same public id, and any click take the other database's keyword or c1
 * id. The database is part of it now, in both copies, which agree.
 */
final class SystemHashNamesTheDatabaseTest extends TestCase
{
    private const COPIES = ['202-config/connect2.php', '202-config/functions-tracking202.php'];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/lift-function.php';
        foreach (['dbhost', 'dbname'] as $name) {
            $this->saved[$name] = $GLOBALS[$name] ?? null;
        }
        $this->saved['server'] = $_SERVER;
    }

    protected function tearDown(): void
    {
        foreach (['dbhost', 'dbname'] as $name) {
            if ($this->saved[$name] === null) {
                unset($GLOBALS[$name]);
            } else {
                $GLOBALS[$name] = $this->saved[$name];
            }
        }
        $_SERVER = $this->saved['server'];
    }

    public function testBothCopiesAreOneFunction(): void
    {
        $root = dirname(__DIR__, 2);
        [$first, $second] = array_map(
            static fn (string $file): string => lift_function($root . '/' . $file, 'systemHash'),
            self::COPIES
        );

        self::assertStringContainsString('dbname', $first);
        self::assertSame($first, $second, 'the admin pages write keys the click path reads');
    }

    public function testTwoDatabasesOnOneHostAreTwoNamespaces(): void
    {
        $hash = self::copy();
        $_SERVER['HTTP_HOST'] = 'track.example';
        $_SERVER['SERVER_ADDR'] = '203.0.113.7';

        $GLOBALS['dbhost'] = 'localhost';
        $GLOBALS['dbname'] = 'install_a';
        $a = $hash();
        self::assertSame($a, $hash(), 'one install answers one namespace');

        $GLOBALS['dbname'] = 'install_b';
        self::assertNotSame($a, $hash());

        $GLOBALS['dbhost'] = 'localhost_';
        $GLOBALS['dbname'] = 'b';
        $joined = $hash();
        $GLOBALS['dbhost'] = 'localhost';
        $GLOBALS['dbname'] = '_b';
        self::assertNotSame($joined, $hash(), 'the host and name are not run together');
    }

    public function testNoDatabaseSettingsIsTheHostsNamespaceStill(): void
    {
        $hash = self::copy();
        $_SERVER['HTTP_HOST'] = 'track.example';
        $_SERVER['SERVER_ADDR'] = '203.0.113.7';
        unset($GLOBALS['dbhost'], $GLOBALS['dbname']);
        $none = $hash();

        $GLOBALS['dbname'] = ['not', 'a', 'name'];
        self::assertSame($none, $hash());
    }

    /** connect2.php's copy, under a name no test has declared. */
    private static function copy(): \Closure
    {
        $name = 'p202SystemHashUnderTest';
        if (!function_exists($name)) {
            $source = lift_function(dirname(__DIR__, 2) . '/' . self::COPIES[0], 'systemHash');
            eval(str_replace('function systemHash(', 'function ' . $name . '(', $source));
        }

        return \Closure::fromCallable($name);
    }
}

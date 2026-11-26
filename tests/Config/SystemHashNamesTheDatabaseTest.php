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
 * id. The install is part of it now, in the one copy both bootstraps load
 * (functions-shared.php): its directory, known in every scope, and its
 * database. With no database
 * settings in scope the directory still tells two installs apart, so no
 * scope falls back to the namespace two installs shared.
 *
 * The directory and database are a place, which an in-place reinstall keeps:
 * the install's id (InstallIdCache) is in the namespace too, so a database
 * installed again where another was is a new namespace.
 */
final class SystemHashNamesTheDatabaseTest extends TestCase
{
    private const SHARED = '202-config/functions-shared.php';
    private const FOR_BOOTSTRAP = '\Prosper202\Database\InstallIdCache::forBootstrap(';

    /** What the copies' stand-in for InstallIdCache::forBootstrap() answers. */
    public static string $installId = 'install-id-a';

    /** @var list<string> the place the stand-in was last asked for */
    public static array $askedFor = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/lift-function.php';
        foreach (['dbhost', 'dbname'] as $name) {
            $this->saved[$name] = $GLOBALS[$name] ?? null;
        }
        $this->saved['server'] = $_SERVER;
        self::$installId = 'install-id-a';
        self::$askedFor = [];
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

    public function testTheOneCopyNamesTheInstall(): void
    {
        $place = lift_function(dirname(__DIR__, 2) . '/' . self::SHARED, 'p202InstallPlace');

        self::assertStringContainsString('dbname', $place);
        self::assertStringContainsString('__DIR__', $place);
        self::assertSame('202-config', basename(dirname(self::SHARED)), 'the directory the bootstraps are in');
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

    /** No database settings in scope (a config included in a function): the directory still separates. */
    public function testWithoutDatabaseSettingsTwoInstallsAreStillTwoNamespaces(): void
    {
        $_SERVER['HTTP_HOST'] = 'track.example';
        $_SERVER['SERVER_ADDR'] = '203.0.113.7';
        unset($GLOBALS['dbhost'], $GLOBALS['dbname']);
        $a = self::copy('/var/www/install-a/202-config')();
        $b = self::copy('/var/www/install-b/202-config')();

        self::assertNotSame($a, $b);
        self::assertNotSame(hash('ripemd160', 'track.example203.0.113.7'), $a, 'the namespace two installs shared');

        $GLOBALS['dbname'] = ['not', 'a', 'name'];
        self::assertSame($a, self::copy('/var/www/install-a/202-config')());
    }

    /** A reinstall in place keeps the directory and database; its new id is a new namespace. */
    public function testANewInstallIdAtOnePlaceIsANewNamespace(): void
    {
        $hash = self::copy();
        $_SERVER['HTTP_HOST'] = 'track.example';
        $_SERVER['SERVER_ADDR'] = '203.0.113.7';
        $GLOBALS['dbhost'] = 'localhost';
        $GLOBALS['dbname'] = 'install_a';

        $old = $hash();
        self::$installId = 'install-id-b';
        self::assertNotSame($old, $hash());
        self::$installId = 'install-id-a';
        self::assertSame($old, $hash(), 'the id, not the moment, decides');
    }

    /** The id is asked for the place the namespace names. */
    public function testTheIdIsAskedForThePlaceTheNamespaceNames(): void
    {
        $GLOBALS['dbhost'] = 'db.internal';
        $GLOBALS['dbname'] = 'install_a';
        self::copy('/var/www/p202/202-config')();

        self::assertSame(['/var/www/p202/202-config', 'db.internal', 'install_a'], self::$askedFor);
    }

    /** @param list<string> $place */
    public static function forBootstrap(array $place): string
    {
        self::$askedFor = $place;

        return self::$installId;
    }

    /**
     * The shared copy as it runs from $directory, under names no test has
     * declared, with the install id answered by self::forBootstrap().
     */
    private static function copy(string $directory = '/var/www/p202/202-config'): \Closure
    {
        $suffix = md5($directory);
        $name = 'p202SystemHashUnderTest_' . $suffix;
        if (!function_exists($name)) {
            $shared = dirname(__DIR__, 2) . '/' . self::SHARED;
            $hash = lift_function($shared, 'systemHash');
            self::assertSame(1, substr_count($hash, self::FOR_BOOTSTRAP), 'systemHash() asks for the id once');
            self::assertSame(1, substr_count($hash, 'p202InstallPlace('), 'and for the place once');
            $hash = str_replace(self::FOR_BOOTSTRAP, '\\' . self::class . '::forBootstrap(', $hash);
            $hash = str_replace('p202InstallPlace(', 'p202InstallPlaceUnderTest_' . $suffix . '(', $hash);
            eval(str_replace('function systemHash(', 'function ' . $name . '(', $hash));

            $place = lift_function($shared, 'p202InstallPlace');
            $place = str_replace('__DIR__', var_export($directory, true), $place);
            eval(str_replace('function p202InstallPlace(', 'function p202InstallPlaceUnderTest_' . $suffix . '(', $place));
        }

        return \Closure::fromCallable($name);
    }
}

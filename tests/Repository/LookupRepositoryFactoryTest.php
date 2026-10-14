<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\LookupRepositoryFactory;
use Prosper202\Repository\Mysql\MysqlDeviceRepository;
use Prosper202\Repository\Mysql\MysqlLocationRepository;
use Prosper202\Repository\Mysql\MysqlTrackingRepository;

/**
 * One bundle of repositories per mysqli. The factory used to keep one of
 * each in a static, so the first connection it was handed answered for
 * every later one.
 */
final class LookupRepositoryFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        LookupRepositoryFactory::reset();
    }

    protected function tearDown(): void
    {
        LookupRepositoryFactory::reset();
    }

    public function testEachConnectionGetsItsOwnRepositories(): void
    {
        $first = mysqli_init();
        $second = mysqli_init();

        $firstConn = LookupRepositoryFactory::connection($first);
        $secondConn = LookupRepositoryFactory::connection($second);

        self::assertNotSame($firstConn, $secondConn);
        self::assertSame($first, $firstConn->writeConnection());
        self::assertSame($second, $secondConn->writeConnection());
        self::assertNotSame(LookupRepositoryFactory::location($first), LookupRepositoryFactory::location($second));
        self::assertNotSame(LookupRepositoryFactory::tracking($first), LookupRepositoryFactory::tracking($second));
        self::assertNotSame(LookupRepositoryFactory::device($first), LookupRepositoryFactory::device($second));
    }

    public function testOneConnectionKeepsItsRepositories(): void
    {
        $db = mysqli_init();

        self::assertSame(LookupRepositoryFactory::connection($db), LookupRepositoryFactory::connection($db));
        self::assertSame(LookupRepositoryFactory::location($db), LookupRepositoryFactory::location($db));
        self::assertSame(LookupRepositoryFactory::tracking($db), LookupRepositoryFactory::tracking($db));
        self::assertSame(LookupRepositoryFactory::device($db), LookupRepositoryFactory::device($db));
    }

    public function testResetBuildsAfresh(): void
    {
        $db = mysqli_init();
        $before = LookupRepositoryFactory::connection($db);

        LookupRepositoryFactory::reset();

        self::assertNotSame($before, LookupRepositoryFactory::connection($db));
    }

    /**
     * Without connect2.php's getCache() (connect.php's pages) nothing is cached.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWithoutTheBootstrapCacheTheRepositoriesReadTheDatabase(): void
    {
        self::assertFalse(function_exists('getCache'), 'this process has no bootstrap cache');
        eval('function setCache($key, $value, $exp = null) { return true; }');
        $GLOBALS['memcacheWorking'] = true;
        $db = mysqli_init();

        self::assertInstanceOf(MysqlLocationRepository::class, LookupRepositoryFactory::location($db));
        self::assertInstanceOf(MysqlTrackingRepository::class, LookupRepositoryFactory::tracking($db));
        self::assertInstanceOf(MysqlDeviceRepository::class, LookupRepositoryFactory::device($db));
    }

    /**
     * With connect2.php's helpers and a working memcache, a lookup reads
     * getCache() under the install's namespace: the entry answers with no
     * database (the mysqli here is not connected, so a miss would throw).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWithTheBootstrapCacheALookupReadsItFirst(): void
    {
        eval('function getCache(string $key, $default = false) {'
            . ' $GLOBALS["p202TestCacheReads"][] = $key;'
            . ' return $GLOBALS["p202TestCache"][$key] ?? $default; }');
        eval('function setCache($key, $value, $exp = null) {'
            . ' $GLOBALS["p202TestCacheWrites"][] = [$key, $value, $exp]; return true; }');
        eval('function systemHash(): string { return "install-a"; }');
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['p202TestCache'] = [
            md5('c1-id' . 'x' . 'install-a') => '77',
            md5('country-id' . 'US' . 'install-a') => 12,
            md5('browser-id' . 'Chrome' . 'install-a') => 5,
            md5('c2-id' . 'x' . 'install-b') => 99,
        ];
        $GLOBALS['p202TestCacheReads'] = [];
        $GLOBALS['p202TestCacheWrites'] = [];
        $db = mysqli_init();

        $tracking = LookupRepositoryFactory::tracking($db);
        $location = LookupRepositoryFactory::location($db);
        $device = LookupRepositoryFactory::device($db);

        self::assertInstanceOf(CachedTrackingRepository::class, $tracking);
        self::assertInstanceOf(CachedLocationRepository::class, $location);
        self::assertInstanceOf(CachedDeviceRepository::class, $device);
        self::assertSame(77, $tracking->findOrCreateC1('x'), 'memcache hands an int back as a string');
        self::assertSame(12, $location->findOrCreateCountry('United States', 'US'));
        self::assertSame(5, $device->findOrCreateBrowser('Chrome'));

        // Another install's entry is not this one's: the lookup asks only
        // this install's key, misses, and reaches the database, which this
        // unconnected handle cannot answer.
        try {
            $answer = $tracking->findOrCreateC2('x');
            self::fail('c2 was answered ' . $answer . ' with no entry of this install\'s and no database');
        } catch (\Error $e) {
            self::assertSame('mysqli object is not fully initialized', $e->getMessage());
        }
        self::assertSame([
            md5('c1-id' . 'x' . 'install-a'),
            md5('country-id' . 'US' . 'install-a'),
            md5('browser-id' . 'Chrome' . 'install-a'),
            md5('c2-id' . 'x' . 'install-a'),
        ], $GLOBALS['p202TestCacheReads']);
        self::assertSame([], $GLOBALS['p202TestCacheWrites']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMemcacheThatDidNotAnswerIsNotUsed(): void
    {
        eval('function getCache(string $key, $default = false) { return 1; }');
        eval('function setCache($key, $value, $exp = null) { return true; }');
        $GLOBALS['memcacheWorking'] = false;

        self::assertInstanceOf(MysqlTrackingRepository::class, LookupRepositoryFactory::tracking(mysqli_init()));
    }
}

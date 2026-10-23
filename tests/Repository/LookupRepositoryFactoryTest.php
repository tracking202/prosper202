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

    /**
     * A bundle holds its own connection (Connection keeps the mysqli), so
     * the entry is a cycle through its key: PHP's collector frees it once
     * nothing else holds the connection, and a long-running process does
     * not keep every connection it ever had.
     */
    public function testAFreedConnectionsBundleIsFreedToo(): void
    {
        $bundles = new \ReflectionProperty(LookupRepositoryFactory::class, 'bundles');
        $db = mysqli_init();
        LookupRepositoryFactory::location($db);
        $kept = mysqli_init();
        LookupRepositoryFactory::location($kept);
        self::assertCount(2, $bundles->getValue());

        unset($db);
        gc_collect_cycles();

        self::assertCount(1, $bundles->getValue(), 'the freed connection\'s bundle is gone');
        self::assertSame($kept, LookupRepositoryFactory::connection($kept)->writeConnection());
    }

    public function testResetBuildsAfresh(): void
    {
        $db = mysqli_init();
        $before = LookupRepositoryFactory::connection($db);

        LookupRepositoryFactory::reset();

        self::assertNotSame($before, LookupRepositoryFactory::connection($db));
    }

    /**
     * Both bootstraps load getCache(), setCache() and systemHash()
     * (functions-shared.php), and off the click path (connect.php's pages,
     * the API, the cron jobs) nothing is cached, memcache answering or not.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testOffTheClickPathTheRepositoriesReadTheDatabase(): void
    {
        self::onTheClickPath(false);
        self::bootstrapCache();
        $GLOBALS['memcacheWorking'] = true;
        $db = mysqli_init();

        self::assertInstanceOf(MysqlLocationRepository::class, LookupRepositoryFactory::location($db));
        self::assertInstanceOf(MysqlTrackingRepository::class, LookupRepositoryFactory::tracking($db));
        self::assertInstanceOf(MysqlDeviceRepository::class, LookupRepositoryFactory::device($db));
    }

    /**
     * On the click path, with a working memcache, a lookup reads
     * getCache() under the install's namespace: the entry answers with no
     * database (the mysqli here is not connected, so a miss would throw).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWithTheBootstrapCacheALookupReadsItFirst(): void
    {
        self::onTheClickPath(true);
        self::bootstrapCache();
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['p202TestCache'] = [
            md5('c1-id' . 'x' . self::ns()) => '77',
            md5('country-id' . 'US' . self::ns()) => 12,
            md5('browser-id' . 'Chrome' . self::ns()) => 5,
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
            md5('c1-id' . 'x' . self::ns()),
            md5('country-id' . 'US' . self::ns()),
            md5('browser-id' . 'Chrome' . self::ns()),
            md5('c2-id' . 'x' . self::ns()),
        ], $GLOBALS['p202TestCacheReads']);
        self::assertSame([], $GLOBALS['p202TestCacheWrites']);
    }

    /**
     * The install's namespace is systemHash(); without it an entry would be
     * keyed for every install alike, so there is no cache.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWithoutTheInstallsNamespaceThereIsNoCache(): void
    {
        self::onTheClickPath(true);
        self::bootstrapCache(withNamespace: false);
        $GLOBALS['memcacheWorking'] = true;

        self::assertInstanceOf(MysqlTrackingRepository::class, LookupRepositoryFactory::tracking(mysqli_init()));
        self::assertInstanceOf(MysqlLocationRepository::class, LookupRepositoryFactory::location(mysqli_init()));
        self::assertInstanceOf(MysqlDeviceRepository::class, LookupRepositoryFactory::device(mysqli_init()));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testMemcacheThatDidNotAnswerIsNotUsed(): void
    {
        self::onTheClickPath(true);
        self::bootstrapCache();
        $GLOBALS['memcacheWorking'] = false;

        self::assertInstanceOf(MysqlTrackingRepository::class, LookupRepositoryFactory::tracking(mysqli_init()));
    }

    /**
     * An id cached under the namespace before 1.9.79 (the install alone) or
     * under another version is not read: an upgrade starts cold.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnIdAnotherVersionCachedIsNotRead(): void
    {
        self::onTheClickPath(true);
        self::bootstrapCache();
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['p202TestCache'] = [
            md5('c1-id' . 'x' . 'install-a') => 77,
            md5('c1-id' . 'x' . "install-a\0" . '1.9.78') => 78,
        ];
        $GLOBALS['p202TestCacheReads'] = [];

        try {
            $answer = LookupRepositoryFactory::tracking(mysqli_init())->findOrCreateC1('x');
            self::fail('c1 was answered ' . $answer . ' from another version\'s entry');
        } catch (\Error $e) {
            self::assertSame('mysqli object is not fully initialized', $e->getMessage());
        }
        self::assertSame([md5('c1-id' . 'x' . self::ns())], $GLOBALS['p202TestCacheReads']);
        self::assertSame("install-a\0" . PROSPER202_VERSION, LookupRepositoryFactory::cacheNamespace());
    }

    /** The namespace the factory keys by: the install's, with this code's version. */
    private static function ns(): string
    {
        require_once __DIR__ . '/../../202-config/version.php';

        return "install-a\0" . PROSPER202_VERSION;
    }

    /** connect2.php's constant, and the shipped p202OnTheClickPath() that reads it. */
    private static function onTheClickPath(bool $clickPath): void
    {
        if ($clickPath) {
            define('P202_CLICK_PATH', true);
        }
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        eval(lift_function(__DIR__ . '/../../202-config/functions-shared.php', 'p202OnTheClickPath'));
    }

    /**
     * Memcache's stand-ins: getCache() records each key it is asked and
     * answers $GLOBALS['p202TestCache'], setCache() records each write.
     */
    private static function bootstrapCache(bool $withNamespace = true): void
    {
        self::assertFalse(function_exists('getCache'), 'this process has no bootstrap cache yet');
        eval('function getCache(string $key, $default = false) {'
            . ' $GLOBALS["p202TestCacheReads"][] = $key;'
            . ' return $GLOBALS["p202TestCache"][$key] ?? $default; }');
        eval('function setCache($key, $value, $exp = null) {'
            . ' $GLOBALS["p202TestCacheWrites"][] = [$key, $value, $exp]; return true; }');
        if ($withNamespace) {
            eval('function systemHash(): string { return "install-a"; }');
        }
    }
}

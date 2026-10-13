<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * Cache writes made while a click's transaction is open are written when it
 * commits and dropped when it does not.
 *
 * rtr.php resolves the redirect URL's site URL id inside the transaction,
 * and INDEXES::get_site_url_id() caches the id of a row it inserts at once:
 * a rollback after it left the entry naming no row, and every later click to
 * that URL stored the dangling id for the 7 days the entry lived (measured
 * on a live instance with memcached). The real setCache(),
 * p202HoldCacheWrites() and p202ReleaseCacheWrites() are lifted out of
 * connect2.php under other names, so they cannot collide with the setCache()
 * other suites declare.
 */
final class HeldCacheWritesTest extends TestCase
{
    /** @var list<array{string, mixed, mixed}> */
    public static array $written = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        if (function_exists('p202HeldTest_setCache')) {
            return;
        }
        $connect2 = __DIR__ . '/../../202-config/connect2.php';
        foreach (['setCache', 'p202HoldCacheWrites', 'p202ReleaseCacheWrites'] as $name) {
            $source = lift_function($connect2, $name);
            eval(str_replace(
                ['setCache(', 'p202HoldCacheWrites(', 'p202ReleaseCacheWrites('],
                ['p202HeldTest_setCache(', 'p202HeldTest_hold(', 'p202HeldTest_release('],
                $source
            ));
        }
    }

    protected function setUp(): void
    {
        self::$written = [];
        $GLOBALS['whatCache'] = 'memcached';
        $GLOBALS['memcache'] = new class {
            public function set(string $key, mixed $value, mixed $exp): bool
            {
                HeldCacheWritesTest::$written[] = [$key, $value, $exp];

                return true;
            }
        };
        $GLOBALS['p202HeldCacheWrites'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['whatCache'], $GLOBALS['memcache'], $GLOBALS['p202HeldCacheWrites']);
    }

    public function testAWriteGoesStraightToTheCacheWhenNothingIsHeld(): void
    {
        self::assertTrue(\p202HeldTest_setCache('url-id-a', 7, 604800));

        self::assertSame([['url-id-a', 7, 604800]], self::$written);
    }

    public function testHeldWritesAreWrittenInOrderWhenTheTransactionCommits(): void
    {
        \p202HeldTest_hold();
        self::assertTrue(\p202HeldTest_setCache('domain-id-a', 9, 2592000));
        self::assertTrue(\p202HeldTest_setCache('url-id-a', 78, 604800));
        self::assertSame([], self::$written, 'nothing reaches the cache while the transaction is open');

        \p202HeldTest_release(true);

        self::assertSame([['domain-id-a', 9, 2592000], ['url-id-a', 78, 604800]], self::$written);
        \p202HeldTest_setCache('url-id-b', 79, 604800);
        self::assertSame(['url-id-b', 79, 604800], self::$written[2], 'and writes go straight through again');
    }

    public function testHeldWritesAreDroppedWhenTheTransactionRollsBack(): void
    {
        \p202HeldTest_hold();
        \p202HeldTest_setCache('url-id-a', 75, 604800);

        \p202HeldTest_release(false);

        self::assertSame([], self::$written);
        \p202HeldTest_setCache('url-id-b', 76, 604800);
        self::assertSame([['url-id-b', 76, 604800]], self::$written, 'holding stops with the release');
    }

    public function testTheDefaultExpiryIsAppliedWhenAHeldWriteIsReleased(): void
    {
        \p202HeldTest_hold();
        \p202HeldTest_setCache('keyword-id-a', 3);
        \p202HeldTest_release(true);

        self::assertSame([['keyword-id-a', 3, 2592000]], self::$written);
    }
}

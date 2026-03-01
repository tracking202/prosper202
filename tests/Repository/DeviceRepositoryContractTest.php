<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;

final class DeviceRepositoryContractTest extends TestCase
{
    /**
     * @return iterable<string, array{DeviceRepositoryInterface}>
     */
    public static function implementations(): iterable
    {
        yield 'in-memory' => [new InMemoryDeviceRepository()];
        yield 'cached' => [self::buildCached()];
    }

    private static function buildCached(): CachedDeviceRepository
    {
        $store = [];

        return new CachedDeviceRepository(
            new InMemoryDeviceRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            'test-hash',
        );
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateBrowserReturnsStableId(DeviceRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateBrowser('Chrome');
        $id2 = $repo->findOrCreateBrowser('Chrome');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testDifferentBrowsersGetDifferentIds(DeviceRepositoryInterface $repo): void
    {
        $chrome = $repo->findOrCreateBrowser('Chrome');
        $firefox = $repo->findOrCreateBrowser('Firefox');

        self::assertNotSame($chrome, $firefox);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateBrowserReturnsZeroForEmpty(DeviceRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateBrowser(''));
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreatePlatformReturnsStableId(DeviceRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreatePlatform('Windows');
        $id2 = $repo->findOrCreatePlatform('Windows');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreatePlatformReturnsZeroForEmpty(DeviceRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreatePlatform(''));
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateDeviceReturnsStableId(DeviceRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateDevice('Desktop');
        $id2 = $repo->findOrCreateDevice('Desktop');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateDeviceReturnsZeroForEmpty(DeviceRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateDevice(''));
    }
}

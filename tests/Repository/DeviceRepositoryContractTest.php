<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Prosper202\Repository\NullDeviceRepository;

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
        return new CachedDeviceRepository(new InMemoryDeviceRepository(), new ArrayIdCache(), 'test-hash');
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
        $id1 = $repo->findOrCreateDevice('Desktop', DeviceType::Desktop);
        $id2 = $repo->findOrCreateDevice('Desktop', DeviceType::Desktop);

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * Chrome on Android names every phone and every tablet "K".
     *
     * @dataProvider implementations
     */
    public function testOneNameIsOneModelPerType(DeviceRepositoryInterface $repo): void
    {
        $phone = $repo->findOrCreateDevice('K', DeviceType::Mobile);
        $tablet = $repo->findOrCreateDevice('K', DeviceType::Tablet);

        self::assertNotSame($phone, $tablet);
        self::assertSame($phone, $repo->findOrCreateDevice('K', DeviceType::Mobile));
        self::assertSame($tablet, $repo->findOrCreateDevice('K', DeviceType::Tablet));
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateDeviceReturnsZeroForEmpty(DeviceRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateDevice('', DeviceType::Mobile));
    }

    /**
     * Each name is kept to its column's 50 characters, so a longer one is
     * found by its cut.
     *
     * @dataProvider implementations
     */
    public function testANameIsFoundByItsStoredCut(DeviceRepositoryInterface $repo): void
    {
        $long = str_repeat('é', 50) . 'x';
        $cut = str_repeat('é', 50);

        self::assertSame($repo->findOrCreateBrowser($long), $repo->findOrCreateBrowser($cut));
        self::assertSame($repo->findOrCreatePlatform($long), $repo->findOrCreatePlatform($cut));
        self::assertSame(
            $repo->findOrCreateDevice($long, DeviceType::Tablet),
            $repo->findOrCreateDevice($cut, DeviceType::Tablet)
        );
    }

    public function testTheNullRepositoryFindsNothing(): void
    {
        $repo = new NullDeviceRepository();

        self::assertSame(0, $repo->findOrCreateBrowser('Chrome'));
        self::assertSame(0, $repo->findOrCreatePlatform('Windows'));
        self::assertSame(0, $repo->findOrCreateDevice('K', DeviceType::Mobile));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Click;

use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Tests\Support\DeviceRepositoryStandIn;

/**
 * PLATFORMS::parseUserAgentInfo() asks for the device model of the name and
 * the type this click's agent gives. Chrome on Android names every phone and
 * every tablet "K", and the model was found by its name alone, so every later
 * click took the type of the first: a tablet first made every Android phone
 * after it a Tablet in the reports. Runs the real PLATFORMS class extracted
 * from connect2.php, as DeviceInfoCarriesUaFamilyTest does.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeviceInfoModelTypeTest extends TestCase
{
    private const PHONE = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    private const TABLET = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    private static InMemoryDeviceRepository $devices;
    private static mysqli $db;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/DeviceDetect.php';
        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        eval('use UAParser\Parser; ' . lift_class($root . '/202-config/connect2.php', 'PLATFORMS'));

        self::$devices = new InMemoryDeviceRepository();
        self::$db = DeviceRepositoryStandIn::connectionFor(self::$devices);
    }

    public function testAPhoneAndATabletNamingOneModelAreEachTheirType(): void
    {
        $tablet = self::parse(self::TABLET);
        $phone = self::parse(self::PHONE);

        self::assertSame('K', $tablet['ua_device_family']);
        self::assertSame('K', $phone['ua_device_family']);
        self::assertSame([self::model('K', DeviceType::Tablet), '3'], [$tablet['device'], $tablet['type']]);
        self::assertSame([self::model('K', DeviceType::Mobile), '2'], [$phone['device'], $phone['type']]);
        self::assertNotSame($tablet['device'], $phone['device']);
    }

    public function testEachKindOfAgentIsItsModelAndType(): void
    {
        self::assertSame([self::model('iPad', DeviceType::Tablet), '3'], self::deviceOf(self::IPAD));
        self::assertSame([self::model('Desktop', DeviceType::Desktop), '1'], self::deviceOf(self::DESKTOP));
        self::assertSame([self::model('Desktop', DeviceType::Desktop), '1'], self::deviceOf(''));
    }

    /** @return array<string, mixed> */
    private static function parse(string $agent): array
    {
        return \PLATFORMS::parseUserAgentInfo(self::$db, (new \DeviceDetect())->setUserAgent($agent));
    }

    /** @return array{mixed, mixed} */
    private static function deviceOf(string $agent): array
    {
        $info = self::parse($agent);

        return [$info['device'], $info['type']];
    }

    private static function model(string $name, DeviceType $type): int
    {
        return self::$devices->findOrCreateDevice($name, $type);
    }
}

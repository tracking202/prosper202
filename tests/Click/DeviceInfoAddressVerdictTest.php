<?php

declare(strict_types=1);

namespace Tests\Click;

use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickBotDetector;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Tests\Support\DeviceRepositoryStandIn;

/**
 * botCheck() judges the request's address, and get_device_info() caches the
 * user agent's parse per agent. The verdict used to be folded into that
 * entry, so one click from a listed range (Google, Facebook, Apple) marked
 * every later visitor with the same agent as a bot, click_bot and
 * click_filtered included, for as long as the entry lived. Runs the real
 * PLATFORMS class extracted from connect2.php, as DeviceInfoCarriesUaFamilyTest
 * does, against a cache that returns what was written to it and an in-memory
 * device repository where the factory would build the MySQL one.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeviceInfoAddressVerdictTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
    private const LISTED = '17.1.2.3';   // 17.0.0.0/8, a botCheck() range
    private const HUMAN = '203.0.113.7'; // in no range

    private static InMemoryDeviceRepository $devices;
    private static mysqli $db;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/DeviceDetect.php';

        require_once __DIR__ . '/../Config/fixtures/lift-function.php';
        $connect2 = $root . '/202-config/connect2.php';
        self::assertMatchesRegularExpression(
            '/^class PLATFORMS$/m',
            (string) file_get_contents($connect2),
            'class PLATFORMS not found in connect2.php'
        );

        // Stubs for what PLATFORMS calls. Its device repository is an
        // in-memory one, whose ids tell the Bot model from the Desktop one.
        eval(<<<'PHP'
            function systemHash(): string { return 'test'; }
            function setCache($key, $value, $exp = null) {
                $GLOBALS['cacheStore'][$key] = $value;
                $GLOBALS['cacheWrites'][$key] = $value;
                return true;
            }
            PHP);
        eval('use UAParser\Parser; ' . lift_class($connect2, 'PLATFORMS'));
        self::$devices = new InMemoryDeviceRepository();
        self::$db = DeviceRepositoryStandIn::connectionFor(self::$devices);

        $GLOBALS['cacheStore'] = [];
        $GLOBALS['cacheWrites'] = [];
        $GLOBALS['memcacheWorking'] = true;
        $GLOBALS['memcache'] = new class {
            public function get(string $key): mixed
            {
                return $GLOBALS['cacheStore'][$key] ?? false;
            }
        };
    }

    public function testABotAddressDoesNotMarkTheNextVisitorWithTheSameAgent(): void
    {
        $bot = self::click(self::LISTED);
        self::assertSame('4', (string) $bot['type'], 'the listed address is still a bot');
        self::assertSame(self::modelId('Bot'), $bot['device']);
        self::assertNotSame(self::modelId('Desktop'), $bot['device']);
        self::assertTrue(ClickBotDetector::isBot(self::CHROME, $bot));

        $human = self::click(self::HUMAN);
        self::assertSame('1', (string) $human['type'], 'a later visitor inherited the address verdict');
        self::assertSame(self::modelId('Desktop'), $human['device']);
        self::assertFalse(ClickBotDetector::isBot(self::CHROME, $human));
    }

    public function testTheCachedEntryHoldsOnlyWhatTheAgentSays(): void
    {
        self::click(self::LISTED);

        $entry = $GLOBALS['cacheWrites'][self::key()] ?? null;
        self::assertIsArray($entry);
        self::assertSame('1', (string) $entry['type']);
        self::assertSame(self::modelId('Desktop'), $entry['device']);
        self::assertTrue($entry['ua_only']);
    }

    public function testAnAddressInARangeIsABotOnACacheHitToo(): void
    {
        self::click(self::HUMAN);
        $GLOBALS['cacheWrites'] = [];

        $bot = self::click(self::LISTED);

        self::assertArrayNotHasKey(self::key(), $GLOBALS['cacheWrites'], 'the agent was parsed again');
        self::assertSame('4', (string) $bot['type']);
    }

    /** An entry cached by an earlier version may hold another visitor's type 4. */
    public function testAnEntryCachedBeforeTheFixIsParsedAgain(): void
    {
        $GLOBALS['cacheStore'][self::key()] = [
            'browser' => 1, 'platform' => 2, 'device' => self::modelId('Bot'), 'type' => '4', 'ua_device_family' => 'Other',
        ];

        $human = self::click(self::HUMAN);

        self::assertSame('1', (string) $human['type']);
        self::assertTrue($GLOBALS['cacheWrites'][self::key()]['ua_only'] ?? false, 'the stale entry was not replaced');
    }

    public function testWithoutACacheTheVerdictIsStillPerRequest(): void
    {
        $GLOBALS['memcacheWorking'] = false;

        self::assertSame('4', (string) self::click(self::LISTED)['type']);
        self::assertSame('1', (string) self::click(self::HUMAN)['type']);
    }

    /**
     * rtr.php keeps the address as the plain string VisitorIp::fromServer()
     * returns, not ipAddress()'s object, and botCheck() read only the object.
     */
    public function testAnAddressHeldAsAPlainStringIsJudgedToo(): void
    {
        $GLOBALS['ip_address'] = self::LISTED;
        self::assertSame('4', (string) \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME)['type']);

        $GLOBALS['ip_address'] = self::HUMAN;
        self::assertSame('1', (string) \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME)['type']);
    }

    /** @return array<string, mixed> */
    private static function click(string $address): array
    {
        $ip = new \stdClass();
        $ip->address = $address;
        $GLOBALS['ip_address'] = $ip;

        return \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME);
    }

    private static function key(): string
    {
        return md5('user-agent' . self::CHROME . 'test');
    }

    /** The Bot model is type 4 and the Desktop one type 1, as PLATFORMS stores them. */
    private static function modelId(string $name): int
    {
        return self::$devices->findOrCreateDevice($name, $name === 'Bot' ? DeviceType::Bot : DeviceType::Desktop);
    }

    private static function db(): mysqli
    {
        return self::$db;
    }
}

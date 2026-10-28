<?php

declare(strict_types=1);

namespace Tests\Click;

use Prosper202\Repository\LookupRepositoryFactory;
use mysqli;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickBotDetector;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Tests\Support\DeviceRepositoryStandIn;

/**
 * The "Spider" signal reaches ClickBotDetector through
 * PLATFORMS::get_device_info(), from the ua-parser result it already computed
 * and caches per user agent. This runs the real PLATFORMS class, extracted
 * from connect2.php (which cannot be included in a test), against a stub
 * cache and an in-memory device repository, in its own process so the stubs
 * cannot collide with the real ones another test loads.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeviceInfoCarriesUaFamilyTest extends TestCase
{
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

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

        // Stubs for what PLATFORMS calls: setCache records what it was given.
        eval(<<<'PHP'
            function systemHash(): string { return 'test'; }
            function setCache($key, $value, $exp = null) { $GLOBALS['cacheWrites'][$key] = $value; return true; }
            PHP);
        eval('use UAParser\Parser; ' . lift_class($connect2, 'PLATFORMS'));
        self::$devices = new InMemoryDeviceRepository();
        self::$db = DeviceRepositoryStandIn::connectionFor(self::$devices);

        $ip = new \stdClass();
        $ip->address = '203.0.113.7'; // in no range botCheck() lists
        $GLOBALS['ip_address'] = $ip;
        $GLOBALS['cacheWrites'] = [];
        $GLOBALS['memcacheWorking'] = false;
    }

    public function testParseUserAgentInfoKeepsTheFamilyItRewrites(): void
    {
        $bot = \PLATFORMS::parseUserAgentInfo(self::db(), (new \DeviceDetect())->setUserAgent(self::GOOGLEBOT));
        $human = \PLATFORMS::parseUserAgentInfo(self::db(), (new \DeviceDetect())->setUserAgent(self::CHROME));

        // The raw family, not the "Desktop" the device name is rewritten to.
        self::assertSame('Spider', $bot['ua_device_family']);
        self::assertSame('Other', $human['ua_device_family']);
        $desktop = self::$devices->findOrCreateDevice('Desktop', DeviceType::Desktop);
        self::assertSame([$desktop, '1'], [$bot['device'], $bot['type']]);
        self::assertSame([$desktop, '1'], [$human['device'], $human['type']]);
        self::assertTrue(ClickBotDetector::isBot(self::GOOGLEBOT, $bot));
        self::assertFalse(ClickBotDetector::isBot(self::CHROME, $human));
    }

    public function testACachedEntryWithoutTheFamilyIsParsedAgain(): void
    {
        $GLOBALS['memcacheWorking'] = true;
        $stale = ['browser' => 1, 'platform' => 2, 'device' => 3, 'type' => '1'];
        $GLOBALS['memcache'] = self::cache([self::key(self::GOOGLEBOT) => $stale]);

        $info = \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::GOOGLEBOT);

        self::assertSame(
            'Spider',
            $info['ua_device_family'] ?? null,
            'an entry cached before the family existed was served as is'
        );
        $key = self::key(self::GOOGLEBOT);
        self::assertSame($info, $GLOBALS['cacheWrites'][$key] ?? null, 'the re-parsed entry replaces it');
    }

    public function testACurrentCachedEntryIsServedWithoutParsing(): void
    {
        $GLOBALS['memcacheWorking'] = true;
        $cached = ['browser' => 1, 'platform' => 2, 'device' => 3, 'type' => '1', 'ua_device_family' => 'Other', 'ua_only' => true];
        $GLOBALS['memcache'] = self::cache([self::key(self::CHROME) => $cached]);

        self::assertSame($cached, \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME));
        self::assertSame([], $GLOBALS['cacheWrites']);
    }

    /**
     * An entry under the subject before device models were kept per type
     * names the model of whatever type clicked first; it is not served.
     */
    public function testAnEntryUnderTheOldSubjectIsNotServed(): void
    {
        $GLOBALS['memcacheWorking'] = true;
        $old = [
            'browser' => 901, 'platform' => 902, 'device' => 903, 'type' => '1',
            'ua_device_family' => 'Other', 'ua_only' => true,
        ];
        $GLOBALS['memcache'] = self::cache([md5('user-agent' . self::CHROME . 'test') => $old]);

        $info = \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME);

        self::assertNotSame($old, $info);
        self::assertSame(self::$devices->findOrCreateDevice('Desktop', DeviceType::Desktop), $info['device']);
        self::assertSame($info, $GLOBALS['cacheWrites'][self::key(self::CHROME)] ?? null);
    }

    private static function db(): mysqli
    {
        return self::$db;
    }

    /** @param array<string, mixed> $entries */
    private static function cache(array $entries): object
    {
        return new class ($entries) {
            /** @param array<string, mixed> $entries */
            public function __construct(private array $entries)
            {
            }

            public function get(string $key): mixed
            {
                return $this->entries[$key] ?? false;
            }
        };
    }

    /** The entry connect2.php keeps for an agent's parse. */
    private static function key(string $agent): string
    {
        return md5('user-agent-typed' . $agent . LookupRepositoryFactory::cacheNamespace());
    }
}

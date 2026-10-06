<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickBotDetector;

/**
 * The "Spider" signal reaches ClickBotDetector through
 * PLATFORMS::get_device_info(), from the ua-parser result it already computed
 * and caches per user agent. This runs the real PLATFORMS class, extracted
 * from connect2.php (which cannot be included in a test), against stub
 * database and cache functions, in its own process so the stubs cannot
 * collide with the real ones another test loads.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DeviceInfoCarriesUaFamilyTest extends TestCase
{
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/202-config/DeviceDetect.php';

        $src = (string) file_get_contents($root . '/202-config/connect2.php');
        $start = strpos($src, "\nclass PLATFORMS\n");
        $end = strpos($src, "\nclass INDEXES\n");
        self::assertIsInt($start, 'class PLATFORMS not found in connect2.php');
        self::assertIsInt($end);

        // Stubs for what PLATFORMS calls: no row is ever found, so every
        // lookup takes its insert path; setCache records what it was given.
        eval(<<<'PHP'
            function systemHash(): string { return 'test'; }
            function setCache($key, $value, $exp = null) { $GLOBALS['cacheWrites'][$key] = $value; return true; }
            function _mysqli_query($db, $sql) {
                return new class { public function fetch_assoc() { return null; } };
            }
            PHP);
        eval('use UAParser\Parser; ' . substr($src, $start, $end - $start));

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
        self::assertTrue(ClickBotDetector::isBot(self::GOOGLEBOT, $bot));
        self::assertFalse(ClickBotDetector::isBot(self::CHROME, $human));
    }

    public function testACachedEntryWithoutTheFamilyIsParsedAgain(): void
    {
        $GLOBALS['memcacheWorking'] = true;
        $stale = ['browser' => 1, 'platform' => 2, 'device' => 3, 'type' => '1'];
        $GLOBALS['memcache'] = self::cache([md5('user-agent' . self::GOOGLEBOT . 'test') => $stale]);

        $info = \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::GOOGLEBOT);

        self::assertSame(
            'Spider',
            $info['ua_device_family'] ?? null,
            'an entry cached before the family existed was served as is'
        );
        $key = md5('user-agent' . self::GOOGLEBOT . 'test');
        self::assertSame($info, $GLOBALS['cacheWrites'][$key] ?? null, 'the re-parsed entry replaces it');
    }

    public function testACurrentCachedEntryIsServedWithoutParsing(): void
    {
        $GLOBALS['memcacheWorking'] = true;
        $cached = ['browser' => 1, 'platform' => 2, 'device' => 3, 'type' => '1', 'ua_device_family' => 'Other'];
        $GLOBALS['memcache'] = self::cache([md5('user-agent' . self::CHROME . 'test') => $cached]);

        self::assertSame($cached, \PLATFORMS::get_device_info(self::db(), new \DeviceDetect(), self::CHROME));
        self::assertSame([], $GLOBALS['cacheWrites']);
    }

    private static function db(): object
    {
        // __call, because mysqli's method name is not a PSR-12 one to declare.
        return new class {
            public int $insert_id = 9;

            /** @param list<mixed> $args */
            public function __call(string $name, array $args): string
            {
                if ($name !== 'real_escape_string') {
                    throw new \BadMethodCallException($name);
                }

                return addslashes((string) $args[0]);
            }
        };
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
}

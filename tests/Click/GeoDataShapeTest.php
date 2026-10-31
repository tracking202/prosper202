<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * connect2.php's getGeoData() answers with the same keys whether or not the
 * GeoIP library is there.
 *
 * Without it, the answer named the postal code 'postal' where every other
 * answer says 'postal_code', the key landing.php reads: every landing-page
 * script logged "Undefined array key" twice (measured on an instance whose
 * vendor/ has no geoip2). Both branches run here, from the function's own
 * source in a child PHP: once with no GeoIP class at all, once with a
 * stand-in Reader that holds no address, which is the library's answer for
 * an address its database does not hold, and once with one that will not
 * open, as the library answers a file that is missing or corrupt: that one
 * threw out of getGeoData() and took the click with it.
 */
final class GeoDataShapeTest extends TestCase
{
    private const STAND_IN = 'namespace GeoIp2\Database { final class Reader {'
        . ' public function __construct(string $file) {}'
        . ' public function city(string $ip): never { throw new \Exception("not in the database"); }'
        . ' public function close(): void {} } }';

    private const UNOPENABLE = 'namespace GeoIp2\Database { final class Reader {'
        . ' public function __construct(string $file) { throw new \InvalidArgumentException("not a database"); }'
        . ' public function city(string $ip): never { throw new \LogicException("never opened"); }'
        . ' public function close(): void {} } }';

    /** The path getGeoData() reads, without the autoloader the child does not load. */
    private const CITY_DATABASE = 'namespace Prosper202\GeoIp { final class CityDatabase {'
        . ' public const PATH = "/nonexistent/GeoLite2-City.mmdb"; } }';

    /** @return list<string> the keys of the answer for 203.0.113.9 */
    private static function keys(string $reader): array
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/connect2.php');
        self::assertSame(1, preg_match('/^function getGeoData\(.*?^\}\n/ms', $source, $m));
        $code = $reader . self::CITY_DATABASE
            . ' namespace { use GeoIp2\Database\Reader;'
            . ' define("CONFIG_PATH", ' . var_export(SourceScan::repoRoot() . '/202-config', true) . ');'
            . $m[0]
            . ' echo json_encode(array_keys(getGeoData("203.0.113.9"))); }';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $keys = json_decode($out, true);
        self::assertIsArray($keys, $out . $err);

        return $keys;
    }

    public function testBothAnswersCarryTheSameKeys(): void
    {
        $withLibrary = self::keys(self::STAND_IN);
        $without = self::keys('');
        $unopened = self::keys(self::UNOPENABLE);
        sort($withLibrary);
        sort($without);
        sort($unopened);
        self::assertSame($withLibrary, $without);
        self::assertSame($withLibrary, $unopened, 'a database that will not open answers, and the click goes on');
        self::assertContains('postal_code', $without, 'the key landing.php reads');
    }
}

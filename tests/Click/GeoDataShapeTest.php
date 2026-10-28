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
 * source in a child PHP: once with no GeoIP class at all, and once with a
 * stand-in Reader that holds no address, which is the library's answer for
 * an address its database does not hold.
 */
final class GeoDataShapeTest extends TestCase
{
    private const STAND_IN = 'namespace GeoIp2\Database { final class Reader {'
        . ' public function __construct(string $file) {}'
        . ' public function city(string $ip): never { throw new \Exception("not in the database"); }'
        . ' public function close(): void {} } }';

    /** @return list<string> the keys of the answer for 203.0.113.9 */
    private static function keys(bool $withReader): array
    {
        $source = (string) file_get_contents(SourceScan::repoRoot() . '/202-config/connect2.php');
        self::assertSame(1, preg_match('/^function getGeoData\(.*?^\}\n/ms', $source, $m));
        $code = ($withReader ? self::STAND_IN : '')
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
        $withLibrary = self::keys(true);
        $without = self::keys(false);
        sort($withLibrary);
        sort($without);
        self::assertSame($withLibrary, $without);
        self::assertContains('postal_code', $without, 'the key landing.php reads');
    }
}

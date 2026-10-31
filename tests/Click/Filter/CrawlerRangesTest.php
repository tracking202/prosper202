<?php

declare(strict_types=1);

namespace Tests\Click\Filter;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\Filter\CrawlerRanges;

/**
 * The crawler ranges moved out of connect2.php unchanged: every address the
 * old FILTER::checkNetrange() filtered, and only those, is filtered.
 */
final class CrawlerRangesTest extends TestCase
{
    /** [first, last] of each range, as checkNetrange() held them, google5 last. */
    private const RANGES = [
        [1208926208, 1208942591], [3512041472, 3512074239], [1123631104, 1123639295], [1089052672, 1089060863],
        [1094189056, 1094451199], [3515031552, 3515039743], [3633393664, 3633397759], [3640418304, 3640426495],
        [1209925632, 1209991167], [1241907200, 1241972735], [637534208, 654311423], [3475898368, 3475963903],
        [1093926912, 1094189055], [-782925824, -782893057],
    ];

    /** checkNetrange() as it was before 1.9.79, its comparisons verbatim. */
    private static function before(string $address): bool
    {
        $ip_address = ip2long($address);
        foreach (self::RANGES as [$first, $last]) {
            if (($ip_address >= $first) and ($ip_address <= $last)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function addresses(): array
    {
        $out = ['', 'nope', '2001:db8::1', '::ffff:' . long2ip(self::RANGES[0][0]), '203.0.113.7', '0.0.0.0', '255.255.255.255'];
        foreach (self::RANGES as [$first, $last]) {
            foreach ([$first - 1, $first, $first + 1, $last - 1, $last, $last + 1] as $n) {
                $out[] = long2ip($n & 0xFFFFFFFF);
            }
        }
        mt_srand(202);
        for ($i = 0; $i < 20000; $i++) {
            $out[] = long2ip(mt_rand(0, 0xFFFFFFFF));
        }

        return $out;
    }

    public function testEveryAddressIsFilteredExactlyAsBefore(): void
    {
        $filtered = 0;
        foreach (self::addresses() as $address) {
            $was = self::before($address);
            self::assertSame($was, CrawlerRanges::contains($address), $address);
            $filtered += $was ? 1 : 0;
        }
        self::assertGreaterThan(40, $filtered, 'the boundaries inside each range were asked');
    }

    public function testARangesFirstAddressIsFilteredAndTheOneBeforeIsNot(): void
    {
        self::assertTrue(CrawlerRanges::contains(long2ip(self::RANGES[0][0])));
        self::assertFalse(CrawlerRanges::contains(long2ip(self::RANGES[0][0] - 1)));
        self::assertFalse(CrawlerRanges::contains('::ffff:' . long2ip(self::RANGES[0][0])), 'IPv6, as before');
    }
}

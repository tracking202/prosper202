<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The legacy report API's ratios (api/v1 and api/v2 functions.php,
 * legacy_api_ratio()): a zero or missing divisor is 0, not the
 * DivisionByZeroError that `@round($a / $b)` threw on PHP 8 for a row with no
 * clicks or no cost. Each version runs in a process of its own: they declare
 * the same functions.
 */
final class LegacyApiRatioTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function versions(): array
    {
        return ['v1' => ['v1'], 'v2' => ['v2']];
    }

    /** @dataProvider versions */
    public function testAZeroDivisorIsZeroAndTheRestIsTheRatio(string $version): void
    {
        $file = dirname(__DIR__, 2) . '/api/' . $version . '/functions.php';
        $script = 'declare(strict_types=1); require ' . var_export($file, true) . '; echo json_encode(['
            . 'legacy_api_ratio(5, 0), legacy_api_ratio("372.35", "0.00", 0, 100), legacy_api_ratio(null, null, 2),'
            . 'legacy_api_ratio(1, 4, 2, 100), legacy_api_ratio("372.35", "260.40", 0, 100), legacy_api_ratio("632.75", "160", 2),'
            . 'legacy_api_ratio(-130.2, 260.4, 0, 100)]);';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' 2>&1');

        self::assertSame('[0,0,0,25,143,3.95,-50]', trim((string) $out), "$version: " . $out);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The legacy report API's ratios (legacy_api_ratio(), api/legacy/functions.php):
 * a zero or missing divisor is 0, not the DivisionByZeroError that
 * `@round($a / $b)` threw on PHP 8 for a row with no clicks or no cost. Each
 * version's functions.php is loaded alone, in a process of its own, as its
 * endpoint loads it, so each is shown to bring the helper in.
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

    /** Each version declared its own copy of the helpers, so loading both was a fatal redeclare. */
    public function testBothVersionsLoadInOneProcess(): void
    {
        $root = dirname(__DIR__, 2);
        $script = 'declare(strict_types=1); require ' . var_export($root . '/api/v1/functions.php', true) . ';'
            . ' require ' . var_export($root . '/api/v2/functions.php', true) . ';'
            . ' echo function_exists("getStats") && function_exists("getAuth") ? "both" : "missing";';
        $php = escapeshellarg(PHP_BINARY);
        $out = shell_exec($php . ' -d display_errors=stderr -r ' . escapeshellarg($script) . ' 2>&1');

        self::assertSame('both', trim((string) $out), (string) $out);
    }
}

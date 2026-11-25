<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickId;
use Tests\Support\SourceScan;

/**
 * Whether a click id is the edge's is asked of ClickId::isEdge(), and the
 * boundary is written once, as ClickId::EDGE_FIRST: a second spelling of it
 * (`$id >= 10000000000`, `$id > 9_999_999_999`) is a test that stops agreeing
 * when the boundary moves. Every integer or float literal in the served PHP
 * whose value is the boundary or one below it fails outside ClickId.php. A
 * boundary computed (`10 ** 10`) or held in a string is not seen.
 */
final class ClickIdBoundaryIsOneFunctionTest extends TestCase
{
    private const HOME = '202-config/Click/ClickId.php';

    public function testTheBoundaryIsSpelledOnlyInClickId(): void
    {
        $problems = [];
        $atHome = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
                    continue;
                }
                if (!self::isBoundary($token[0], $token[1])) {
                    continue;
                }
                if ($path === self::HOME) {
                    $atHome++;
                    continue;
                }
                $problems[] = "$path:{$token[2]} {$token[1]}";
            }
        }

        self::assertSame([], $problems, "The edge's click id boundary is spelled outside ClickId:\n  "
            . implode("\n  ", $problems) . "\nAsk ClickId::isEdge(), or read ClickId::EDGE_FIRST.");
        self::assertSame(1, $atHome, 'ClickId spells the boundary once, as EDGE_FIRST');
    }

    public function testTheScanReadsEverySpellingOfTheBoundary(): void
    {
        foreach (['10000000000', '10_000_000_000', '0x2540BE400', '9999999999', '9_999_999_999'] as $spelling) {
            self::assertTrue(self::isBoundary(T_LNUMBER, $spelling), $spelling);
        }
        foreach (['1e10', '1E10', '10e9', '1.0e10', '10000000000.0', '9999999999.0'] as $spelling) {
            self::assertTrue(self::isBoundary(T_DNUMBER, $spelling), $spelling);
        }
        self::assertFalse(self::isBoundary(T_LNUMBER, '100000000000'));
    }

    private static function isBoundary(int $kind, string $literal): bool
    {
        $digits = str_replace('_', '', $literal);
        $value = $kind === T_LNUMBER ? (float) intval($digits, 0) : (float) $digits;

        return $value === (float) ClickId::EDGE_FIRST || $value === (float) (ClickId::EDGE_FIRST - 1);
    }
}

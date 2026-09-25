<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;

/**
 * Attribution code reads a conversion's amount only through CountedAmount.
 *
 * The worker credits a conversion's amount net of the reversals naming it;
 * the journey drill-down once showed the row's own `click_payout` instead,
 * so a $10 sale reversed by $4 read $10 over model columns each summing to
 * $6. One function now serves both sides, and this test keeps it the only
 * one: outside CountedAmount, no attribution file names the `click_payout`
 * field of a row (an index, an array_column(), an interpolation). SQL may
 * still select the column, to hand the row to CountedAmount.
 */
final class ConversionAmountReadsThroughCountedAmountTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> */
    private static function files(): array
    {
        $files = [
            self::ROOT . '/api/v3/Controllers/AttributionController.php',
            self::ROOT . '/202-account/attribution.php',
            self::ROOT . '/202-config/functions-attribution-ui.php',
        ];
        $globs = ['/202-config/Attribution/*.php', '/202-cronjobs/attribution-*.php', '/cli/Commands/Attribution*.php'];
        foreach ($globs as $pattern) {
            array_push($files, ...(glob(self::ROOT . $pattern) ?: []));
        }

        return $files;
    }

    /** @return list<int> lines where the file names the click_payout field */
    public static function fieldReads(string $source): array
    {
        $lines = [];
        foreach (token_get_all($source) as $t) {
            if (!is_array($t)) {
                continue;
            }
            [$id, $text, $line] = $t;
            $name = match ($id) {
                T_CONSTANT_ENCAPSED_STRING => substr($text, 1, -1),
                T_STRING, T_ENCAPSED_AND_WHITESPACE => $text,
                default => null,
            };
            if ($name === 'click_payout') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function testNoAttributionFileReadsTheRecordedAmountButCountedAmount(): void
    {
        $files = self::files();
        self::assertGreaterThan(20, count($files), 'the scan found the attribution files');
        $offenders = [];
        foreach ($files as $file) {
            self::assertFileExists($file);
            if (basename($file) === 'CountedAmount.php') {
                $own = self::fieldReads((string) file_get_contents($file));
                self::assertNotSame([], $own, 'the scan sees CountedAmount\'s own reads');
                continue;
            }
            foreach (self::fieldReads((string) file_get_contents($file)) as $line) {
                $relative = substr(realpath($file) ?: $file, strlen(realpath(self::ROOT) ?: '') + 1);
                $offenders[] = $relative . ':' . $line;
            }
        }
        self::assertSame([], $offenders, "these read a conversion's recorded amount directly; show or split "
            . 'CountedAmount::of()/fields() instead, so the amount beside the credits is the amount they sum to');
    }

    /** Every spelling of a field read is seen; SQL naming the column is not. */
    public function testTheScanSeesEverySpelling(): void
    {
        $reads = [
            '<?php $a = $r[\'click_payout\'];',
            '<?php $a = $r["click_payout"];',
            '<?php $a = array_column($rows, \'click_payout\');',
            '<?php $a = "{$r[\'click_payout\']}";',
            '<?php $a = "$r[click_payout]";',
            '<?php $a = $r[\'click_payout\'] ?? 0;',
        ];
        foreach ($reads as $src) {
            self::assertNotSame([], self::fieldReads($src), $src);
        }
        $notReads = [
            '<?php $s = \'SELECT click_payout FROM 202_conversion_logs\';',
            '<?php $s = "SELECT cl.click_payout, cl.payable FROM x";',
            '<?php $a = $r[\'click_payouts\'];',
        ];
        foreach ($notReads as $src) {
            self::assertSame([], self::fieldReads($src), $src);
        }
    }
}

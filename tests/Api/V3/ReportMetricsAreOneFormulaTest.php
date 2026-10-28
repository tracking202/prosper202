<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;

/**
 * Every report computes its metrics with one formula, in SQL. The group
 * report divided its sums in PHP's floats, so the same rows had two
 * conv_rates (33.33333333333333 and the summary's 33.3333) and two EPCs; a
 * report that spelled its ratios a second way would answer the same rows
 * differently again, with nothing failing.
 *
 * So each ReportsController method that names a ratio either spells out
 * metricsSelect()'s own text, whitespace aside (summary, breakdown and the
 * time and part reports write it inline), or builds its SQL with
 * metricsSelect(); and the controller divides nothing in PHP.
 * ReportRatiosAgreeIntegrationTest holds the answers to each other on a
 * real server.
 */
final class ReportMetricsAreOneFormulaTest extends TestCase
{
    /** Methods that compute metrics through metricsSelect() rather than inline. */
    private const BUILDERS = ['groups', 'rotatorStats', 'metricsSelect'];

    private static function squash(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private static function source(\ReflectionMethod $m): string
    {
        $lines = file((string) $m->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function testEveryReportSpellsTheMetricsAsMetricsSelectDoes(): void
    {
        $class = new \ReflectionClass(ReportsController::class);
        $formula = $class->getMethod('metricsSelect');
        $formula->setAccessible(true);
        $text = self::squash((string) $formula->invoke(null));
        self::assertStringContainsString(
            'CASE WHEN SUM(de.click_out) > 0 THEN SUM(de.leads) / SUM(de.click_out) * 100 ELSE 0 END as conv_rate',
            $text
        );

        $inline = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== ReportsController::class) {
                continue;
            }
            $source = self::source($method);
            $name = $method->getName();
            $names = preg_match('/\bas\s+(?:epc|avg_cpc|conv_rate|roi|cpa)\b/i', $source) === 1;
            if (in_array($name, self::BUILDERS, true)) {
                if ($name !== 'metricsSelect') {
                    self::assertStringContainsString('self::metricsSelect(', $source, "$name uses metricsSelect()");
                    self::assertFalse($names, "$name names a ratio itself instead of through metricsSelect()");
                }
                continue;
            }
            if ($names) {
                $why = "$name() spells its metrics differently from metricsSelect()";
                self::assertStringContainsString($text, self::squash($source), $why);
                $inline[] = $name;
            }
        }
        sort($inline);
        self::assertSame(
            ['breakdown', 'daypart', 'summary', 'timeseries', 'weekpart'],
            $inline,
            'the reports that write the formula inline'
        );
    }

    /**
     * The controller divides nothing in PHP: a ratio divided there is a
     * float, and the same ratio in SQL a DECIMAL rounded to its column, so
     * the two answers differ in the last digits (groupMetrics() did this).
     */
    public function testTheControllerDividesNothingInPhp(): void
    {
        $file = (string) (new \ReflectionClass(ReportsController::class))->getFileName();
        $divisions = [];
        foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
            if (in_array(strtolower($token->text), ['/', '/=', '%', '%=', 'fdiv', 'intdiv', 'fmod'], true)) {
                $divisions[] = "line {$token->line}: {$token->text}";
            }
        }
        self::assertSame([], $divisions, 'ReportsController divides in PHP; compute it in SQL, with metricsSelect()');
    }
}

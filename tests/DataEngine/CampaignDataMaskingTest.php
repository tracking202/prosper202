<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * Structural guard over DataEngine's access_to_campaign_data masking.
 *
 * The class cannot be loaded here (the real file needs the full app bootstrap;
 * see ReportIntegrationTest), so this works on the source the way
 * ChartMetricsTest does.
 *
 * Two real defects motivate it, both from the same cause -- five report screens
 * each restating the same masking by hand:
 *
 *  - displayPerPPCReport()'s totals row set $campaign['net'] = '?', a key that
 *    row never prints, and rendered $campaign['total_net'] unmasked.
 *  - maskVariableData() listed only the per-row keys, so the variable report's
 *    "Totals for report" line and its excel download printed real clicks,
 *    leads, income, cost and net to a user denied campaign data.
 *
 * Neither is visible in a diff of one screen. They are visible in the shape of
 * the file, which is what these assertions pin.
 */
final class CampaignDataMaskingTest extends TestCase
{
    private string $source = '';

    /** @var string[] */
    private array $maskedMetrics = [];

    protected function setUp(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine.php');
        self::assertNotFalse($source, 'class-dataengine.php must be readable');
        $this->source = $source;

        self::assertSame(
            1,
            preg_match("/private const MASKED_METRICS = \[(.*?)\];/s", $this->source, $block),
            'MASKED_METRICS must be declared exactly once'
        );
        self::assertNotFalse(
            preg_match_all("/'([a-z_]+)'/", $block[1], $entries),
            'Failed to read MASKED_METRICS: ' . preg_last_error_msg()
        );
        $this->maskedMetrics = $entries[1];
        self::assertNotEmpty($this->maskedMetrics);
    }

    public function testTheMetricListCoversEveryFigureThePermissionWithholds(): void
    {
        self::assertSame(
            ['clicks', 'click_out', 'leads', 'income', 'cost', 'net'],
            $this->maskedMetrics,
            'Ratios (ctr, roi, epc, cpc, su_ratio, payout) are intentionally left visible; '
            . 'the absolute click, lead and money figures are not.'
        );
    }

    public function testThePermissionIsCheckedInExactlyOnePlace(): void
    {
        // Five screens used to spell this predicate out, and they drifted. The
        // only occurrences allowed now are campaignDataHidden()'s own check and
        // the doc comments that name it.
        $lines = preg_grep(
            '/access_to_campaign_data/',
            preg_split('/\R/', $this->source) ?: []
        );
        $code = array_values(array_filter(
            $lines ?: [],
            static fn(string $line): bool => !str_starts_with(ltrim($line), '*')
        ));

        self::assertCount(
            1,
            $code,
            "The permission must be tested only inside campaignDataHidden(). Found:\n  "
            . implode("\n  ", $code)
        );
    }

    public function testNoScreenMasksAMetricByHand(): void
    {
        // Every metric mask must go through maskMetrics(), which is what makes
        // the prefix explicit. A hand-written $x['net'] = '?' next to a template
        // that prints $x['total_net'] is exactly the bug that shipped.
        $pattern = "/\\\$\\w+\\['(" . implode('|', array_map('preg_quote', $this->maskedMetrics)) . ")'\\]\\s*=\\s*'\\?'/";
        $hits = preg_match_all($pattern, $this->source, $matches);
        self::assertNotFalse($hits, 'Scan failed: ' . preg_last_error_msg());

        self::assertSame(
            0,
            $hits,
            "These metrics are masked by hand instead of through maskMetrics(): "
            . implode(', ', $matches[1] ?? [])
            . ". Call self::maskMetrics(\$row) or self::maskMetrics(\$row, self::TOTALS_PREFIX) "
            . 'so the key and its prefix cannot disagree with the template.'
        );
    }

    public function testTotalsTemplatesOnlyPrintPrefixedMetrics(): void
    {
        // If a totals row printed a bare $x['net'], maskMetrics($x, TOTALS_PREFIX)
        // would not touch it and the figure would render. Pinning the templates
        // to the prefix is what makes the prefixed mask sufficient.
        $rows = $this->totalsTemplates();
        self::assertNotEmpty($rows, 'Expected to find the "Totals for report" templates');

        foreach ($rows as $line => $template) {
            self::assertNotFalse(
                preg_match_all("/\\\$\\w+\\['(\\w+)'\\]/", $template, $keys),
                'Scan failed: ' . preg_last_error_msg()
            );
            foreach ($keys[1] as $key) {
                if (in_array($key, $this->maskedMetrics, true)) {
                    self::fail(
                        "The totals template at line $line prints the unprefixed metric '$key'. "
                        . "Totals rows are masked with self::TOTALS_PREFIX, so an unprefixed key "
                        . 'renders the real figure to a user without access_to_campaign_data.'
                    );
                }
            }
        }
    }

    public function testTheVariableReportMasksBothPrefixes(): void
    {
        // maskVariableData() walks a nested structure rather than one flat row,
        // so it builds its own key set. It must derive that set from
        // MASKED_METRICS *and* their total_ variants, not restate either.
        self::assertSame(
            1,
            preg_match('/private function maskVariableData\(.*?\n    \}/s', $this->source, $body),
            'maskVariableData() must be present'
        );

        self::assertStringContainsString(
            'self::MASKED_METRICS',
            $body[0],
            'maskVariableData() must build its key set from MASKED_METRICS, not a copy of it'
        );
        self::assertStringContainsString(
            'self::TOTALS_PREFIX',
            $body[0],
            'maskVariableData() must also mask the total_* keys: the variable report and its '
            . 'excel download both render a "Totals for report" line from the same data.'
        );
        self::assertStringContainsString(
            'self::campaignDataHidden()',
            $body[0],
            'maskVariableData() must use the shared predicate'
        );
    }

    /**
     * The rendered totals rows, keyed by the 1-based line the template starts
     * on. A template runs from `id="totals"` to the closing `</tr>`.
     *
     * @return array<int, string>
     */
    private function totalsTemplates(): array
    {
        $lines = preg_split('/\R/', $this->source) ?: [];
        $templates = [];
        $collecting = null;
        $start = 0;

        foreach ($lines as $index => $line) {
            if ($collecting === null) {
                if (str_contains($line, 'id="totals"')) {
                    $collecting = $line;
                    $start = $index + 1;
                }
                continue;
            }
            $collecting .= "\n" . $line;
            if (str_contains($line, '</tr>')) {
                $templates[$start] = $collecting;
                $collecting = null;
            }
        }

        self::assertNull($collecting, 'A totals template was never closed with </tr>');

        return $templates;
    }
}

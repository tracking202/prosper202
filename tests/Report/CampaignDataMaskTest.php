<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\CampaignDataMask;
use Tests\Support\SourceScan;

/**
 * CampaignDataMask is the single decision about hiding campaign figures from a
 * viewer without access_to_campaign_data. This test does two things:
 *
 *  1. exercises the class -- the predicate against a stubbed $userObj and a
 *     publisher session, apply() for both row and totals prefixes, and
 *     applyDeep() over the nested variable-report shape whose totals used to
 *     leak;
 *  2. pins the shape of the tree, because the defects this class replaced were
 *     all drift between hand-written copies: no file outside the class may
 *     negate the permission itself, and no file may mask a metric key by hand.
 */
final class CampaignDataMaskTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['userObj'], $_SESSION['publisher']);
    }

    private static function userWithPermission(bool $granted): object
    {
        return new class($granted) {
            public function __construct(private bool $granted)
            {
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'access_to_campaign_data' ? $this->granted : false;
            }
        };
    }

    public function testHiddenWhenTheUserLacksThePermission(): void
    {
        $GLOBALS['userObj'] = self::userWithPermission(false);
        self::assertTrue(CampaignDataMask::hidden());
    }

    public function testNotHiddenWhenGranted(): void
    {
        $GLOBALS['userObj'] = self::userWithPermission(true);
        self::assertFalse(CampaignDataMask::hidden());
    }

    public function testPublishersAreExemptAndNoUserMeansNothingToHide(): void
    {
        $GLOBALS['userObj'] = self::userWithPermission(false);
        $_SESSION['publisher'] = 1;
        self::assertFalse(CampaignDataMask::hidden(), 'a publisher session is already scoped to its own data');

        unset($_SESSION['publisher']);
        $GLOBALS['userObj'] = null;
        self::assertFalse(CampaignDataMask::hidden());
    }

    public function testApplyMasksTheMetricsAndTheCostWrapperButNothingElse(): void
    {
        $row = [
            'clicks' => 10, 'click_out' => 5, 'ctr' => '50%', 'leads' => 1, 'su_ratio' => '10%',
            'payout' => '$4.00', 'epc' => '$0.40', 'cpc' => '$0.10', 'income' => '$4.00',
            'cost' => '$1.00', 'cost_wrapper' => '($1.00)', 'net' => '$3.00', 'roi' => '300%',
            'keyword' => 'shoes',
        ];
        $masked = CampaignDataMask::apply($row);

        foreach (['clicks', 'click_out', 'leads', 'income', 'cost', 'cost_wrapper', 'net'] as $k) {
            self::assertSame('?', $masked[$k], $k);
        }
        foreach (['ctr', 'su_ratio', 'payout', 'epc', 'cpc', 'roi', 'keyword'] as $k) {
            self::assertSame($row[$k], $masked[$k], "$k must stay visible");
        }
        self::assertSame(array_keys($row), array_keys($masked), 'apply() must not add or drop keys');
    }

    public function testApplyWithAPrefixTouchesOnlyThatPrefix(): void
    {
        $row = ['clicks' => 10, 'total_clicks' => 100, 'total_net' => '$9', 'total_cost_wrapper' => '($1)', 'rotator_clicks' => 7];
        $masked = CampaignDataMask::apply($row, 'total_');

        self::assertSame(['clicks' => 10, 'total_clicks' => '?', 'total_net' => '?', 'total_cost_wrapper' => '?', 'rotator_clicks' => 7], $masked);
        self::assertSame('?', CampaignDataMask::apply($row, 'rotator_')['rotator_clicks']);
    }

    public function testApplyDeepMasksNestedRowsAndTheTotalsNode(): void
    {
        // The variable report's shape: network rows carrying nested variable
        // rows carrying value rows, then a final node with only total_* keys.
        $data = [
            [
                0 => ['ppc_network_name' => 'Google', 'clicks' => 50, 'net' => '$5'],
                'variables' => [
                    [0 => ['variable_name' => 'c1', 'clicks' => 20], 'values' => [['variable_value' => 'a', 'clicks' => 20, 'income' => '$2', 'roi' => '10%']]],
                ],
            ],
            ['total_clicks' => 50, 'total_leads' => 2, 'total_income' => '$5', 'total_cost' => '$1', 'total_net' => '$4', 'total_roi' => '400%'],
        ];
        $masked = CampaignDataMask::applyDeep($data);

        self::assertSame('?', $masked[0][0]['clicks']);
        self::assertSame('?', $masked[0][0]['net']);
        self::assertSame('Google', $masked[0][0]['ppc_network_name']);
        self::assertSame('?', $masked[0]['variables'][0][0]['clicks']);
        self::assertSame('?', $masked[0]['variables'][0]['values'][0]['clicks']);
        self::assertSame('?', $masked[0]['variables'][0]['values'][0]['income']);
        self::assertSame('10%', $masked[0]['variables'][0]['values'][0]['roi']);
        // The whole point: the totals line.
        foreach (['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net'] as $k) {
            self::assertSame('?', $masked[1][$k], $k);
        }
        self::assertSame('400%', $masked[1]['total_roi']);
    }

    // ------------------------------------------------------------------
    // Tree shape

    public function testThePermissionIsNegatedOnlyInsideTheClass(): void
    {
        // `!$userObj->hasPermission('access_to_campaign_data')` is the masking
        // decision. Positive gates (account_overview.php shows a section only
        // to users WITH the permission) are a different question and allowed.
        $offenders = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if ($path === '202-config/Report/CampaignDataMask.php') {
                continue;
            }
            if (SourceScan::countMatches('/!\s*\$\w+->hasPermission\(\s*[\'"]access_to_campaign_data[\'"]/', $source, $path) > 0) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'These files restate the masking predicate instead of calling '
            . 'CampaignDataMask::hidden(); the copies drifted before (publisher exemption, null guard). '
            . "Found in:\n  " . implode("\n  ", $offenders));
    }

    public function testNoFileMasksAMetricByHand(): void
    {
        // `$x['net'] = '?'`, `$x['total_net'] = '?'`, `$x['rotator_cost_wrapper'] = '?'`:
        // every hand-written mask is a place the key can disagree with the
        // template. All of them go through CampaignDataMask::apply().
        $keys = [...CampaignDataMask::METRICS, CampaignDataMask::COST_WRAPPER];
        $pattern = "/\\\$\\w+\\[['\"](?:\\w+_)?(?:" . implode('|', array_map('preg_quote', $keys)) . ")['\"]\\]\\s*=\\s*'\\?'/";

        $offenders = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $n = SourceScan::countMatches($pattern, $source, $path, $m);
            if ($n > 0) {
                $offenders[$path] = $m[0];
            }
        }

        self::assertSame([], $offenders, "These files mask a metric by hand instead of through CampaignDataMask::apply():\n"
            . implode("\n", array_map(
                static fn(string $f, array $hits): string => "  $f: " . implode(', ', $hits),
                array_keys($offenders),
                $offenders
            )));
    }

    public function testTotalsTemplatesInTheDataEnginePrintOnlyPrefixedMetrics(): void
    {
        // Totals rows are masked with the 'total_' prefix; a bare $x['net'] in
        // one of those templates would render the real figure.
        $source = SourceScan::phpFiles()['202-config/class-dataengine.php'];
        $lines = preg_split('/\R/', $source) ?: [];
        $inTotals = false;
        $offenders = [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'id="totals"')) {
                $inTotals = true;
            }
            if ($inTotals) {
                if (preg_match_all("/\\\$\\w+\\['(\\w+)'\\]/", $line, $m) > 0) {
                    foreach ($m[1] as $key) {
                        if (in_array($key, CampaignDataMask::METRICS, true) || $key === CampaignDataMask::COST_WRAPPER) {
                            $offenders[] = ($i + 1) . ": $key";
                        }
                    }
                }
                if (str_contains($line, '</tr>')) {
                    $inTotals = false;
                }
            }
        }
        self::assertFalse($inTotals, 'a totals template was never closed with </tr>');
        self::assertSame([], $offenders, 'Totals templates print an unprefixed metric: ' . implode('; ', $offenders));
    }

    public function testTheVariableReportMasksThroughApplyDeep(): void
    {
        $source = SourceScan::phpFiles()['202-config/class-dataengine.php'];
        self::assertSame(1, preg_match('/private function maskVariableData\(.*?\n    \}/s', $source, $body));
        self::assertStringContainsString('CampaignDataMask::applyDeep(', $body[0]);
        self::assertStringContainsString('CampaignDataMask::hidden()', $body[0]);
    }
}

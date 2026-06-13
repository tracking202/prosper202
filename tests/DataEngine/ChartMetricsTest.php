<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the DataEngine::CHART_METRICS table that drives getChart().
 *
 * The facade class cannot be loaded here (other tests stub a DataEngine
 * class and the real file performs global setup), so the constant is
 * extracted from the source.
 *
 * Regression context: the legacy 'payout' entry embedded a trailing ", "
 * inside the SELECT expression, so any chart that included payout produced
 * "AS payout, ," — invalid SQL that getChart() swallowed silently, leaving
 * the payout series permanently empty.
 */
final class ChartMetricsTest extends TestCase
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $metrics = [];

    protected function setUp(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine.php');
        self::assertNotFalse($source, 'class-dataengine.php must be readable');

        preg_match('/private const CHART_METRICS = \[(.*?)\];/s', $source, $block);
        self::assertNotEmpty($block, 'Must find the CHART_METRICS constant');

        preg_match_all("/'([a-z_]+)' => \['([^']*)', '([^']*)'\]/", $block[1], $entries, PREG_SET_ORDER);
        foreach ($entries as $entry) {
            $this->metrics[$entry[1]] = [$entry[2], $entry[3]];
        }
    }

    public function testAllTwelveChartMetricsAreDefined(): void
    {
        self::assertSame(
            ['clicks', 'click_out', 'ctr', 'leads', 'su_ratio', 'payout', 'epc', 'cpc', 'income', 'cost', 'net', 'roi'],
            array_keys($this->metrics)
        );
    }

    public function testNoExpressionCarriesItsOwnTrailingComma(): void
    {
        // getChart() appends the separating comma itself ($end); an
        // expression ending in "," doubles it and breaks the SELECT.
        foreach ($this->metrics as $key => [$expression, $label]) {
            self::assertStringEndsNotWith(',', rtrim($expression), "Chart metric '$key' must not end with a comma");
        }
    }

    public function testEveryExpressionAliasesItsOwnKey(): void
    {
        foreach ($this->metrics as $key => [$expression, $label]) {
            self::assertStringEndsWith(
                'AS ' . $key,
                rtrim($expression),
                "Chart metric '$key' must alias its result as '$key' for the series lookup"
            );
        }
    }

    public function testPayoutExpressionIsValidSqlFragment(): void
    {
        self::assertSame(' (SUM(income) / sum(leads)) AS payout', $this->metrics['payout'][0]);
        self::assertSame('Avg Payout', $this->metrics['payout'][1]);
    }
}

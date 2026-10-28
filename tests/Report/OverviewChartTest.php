<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\OverviewChart;

/**
 * The chart builder's posted lines are read strictly (OverviewChart::lines()),
 * and the figures it accepts are the ones DataEngine::getChart() draws.
 *
 * charts.php serialized whatever arrived: a figure getChart() does not know
 * was stored and drawn as a line of zeros, and a body with no lines was a
 * TypeError (array_keys(null)).
 */
final class OverviewChartTest extends TestCase
{
    /**
     * Read from the source, as ChartMetricsTest does: other tests stub a
     * DataEngine class, and the real file performs global setup.
     */
    public function testTheFiguresAreTheOnesTheChartDraws(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/class-dataengine.php');
        self::assertSame(1, preg_match('/private const CHART_METRICS = \[(.*?)\];/s', $source, $block));
        preg_match_all("/^\s*'([a-z_]+)' => \[/m", $block[1], $keys);
        self::assertCount(12, $keys[1], 'the scan read the table');
        self::assertSame($keys[1], OverviewChart::FIGURES);
    }

    public function testTheDefaultChartIsTheInstallers(): void
    {
        self::assertSame(
            'a:3:{i:0;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:6:"clicks";}'
            . 'i:1;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:9:"click_out";}'
            . 'i:2;a:2:{s:11:"campaign_id";s:1:"0";s:10:"value_type";s:5:"leads";}}',
            serialize(OverviewChart::DEFAULT_LINES)
        );
    }

    public function testThePostedLinesAreRead(): void
    {
        self::assertSame(
            [['campaign_id' => '0', 'value_type' => 'clicks'], ['campaign_id' => '12', 'value_type' => 'roi']],
            OverviewChart::lines([['id' => '0'], ['id' => '012']], [['type' => 'clicks'], ['type' => 'roi']])
        );
    }

    /** @return iterable<string, array{mixed, mixed, string}> */
    public static function refused(): iterable
    {
        yield 'no lines' => [null, null, 'levels and types'];
        yield 'an empty list' => [[], [], 'levels and types'];
        yield 'a figure missing' => [[['id' => '0'], ['id' => '1']], [['type' => 'clicks']], 'each line needs both'];
        yield 'a figure the chart does not draw' => [[['id' => '0']], [['type' => 'visits']], 'types[0][type]'];
        yield 'a campaign that is not an id' => [[['id' => '1.5']], [['type' => 'clicks']], 'levels[0][id]'];
        yield 'a negative campaign' => [[['id' => '-1']], [['type' => 'clicks']], 'levels[0][id]'];
        yield 'a campaign as a list' => [[['id' => ['1']]], [['type' => 'clicks']], 'levels[0][id]'];
        yield 'a key the page does not send' => [[['id' => '1', 'x' => '2']], [['type' => 'clicks']], 'levels[0][id]'];
        yield 'too many lines' => [
            array_fill(0, 51, ['id' => '0']), array_fill(0, 51, ['type' => 'clicks']), 'at most 50',
        ];
    }

    /** @dataProvider refused */
    public function testWhatThePageNeverSendsIsRefusedByName(mixed $levels, mixed $types, string $named): void
    {
        try {
            OverviewChart::lines($levels, $types);
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString($named, $refused->getMessage());
            return;
        }
        self::fail('accepted');
    }
}

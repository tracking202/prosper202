<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\ReportTotals;

final class ReportTotalsTest extends TestCase
{
    public function testEmptyTotalsAreAllZero(): void
    {
        $totals = (new ReportTotals())->toArray();

        self::assertSame(
            ['clicks', 'click_out', 'ctr', 'cost', 'cpc', 'leads', 'su_ratio', 'payout', 'income', 'epc', 'net', 'roi'],
            array_keys($totals)
        );
        foreach ($totals as $key => $value) {
            self::assertEquals(0, $value, "Empty totals must report 0 for $key");
        }
    }

    public function testSingleRowAccumulation(): void
    {
        $totals = new ReportTotals();
        $totals->add([
            'clicks' => '100',
            'click_out' => '40',
            'cost' => '10',
            'leads' => '5',
            'payout' => '8',
            'income' => '50',
        ]);

        $result = $totals->toArray();

        self::assertEquals(100.0, $result['clicks']);
        self::assertEquals(40.0, $result['click_out']);
        self::assertEquals(40.0, $result['ctr'], 'CTR = click_out / clicks * 100');
        self::assertEquals(0.1, $result['cpc'], 'CPC = cost / clicks');
        self::assertEquals(5.0, $result['su_ratio'], 'S/U = leads / clicks * 100');
        self::assertEquals(10.0, $result['payout'], 'payout = income / leads');
        self::assertEquals(0.5, $result['epc'], 'EPC = income / clicks');
        self::assertEquals(40.0, $result['net'], 'net = income - cost');
        self::assertEquals(400.0, $result['roi'], 'ROI = net / cost * 100');
    }

    public function testPayoutIsIncomeOverLeadsAcrossTheReport(): void
    {
        $totals = new ReportTotals();
        $totals->add(['clicks' => 1, 'leads' => 1, 'income' => 10]);
        $totals->add(['clicks' => 1, 'leads' => 1, 'income' => 20]);
        $totals->add(['clicks' => 1, 'leads' => 1, 'income' => 30]);

        // 60 income / 3 leads — not the legacy running pseudo-average,
        // which produced 15.0 here (and a different value re-sorted).
        self::assertEquals(20.0, $totals->toArray()['payout']);
        self::assertSame(3, $totals->rowCount());
    }

    public function testPayoutIsOrderIndependent(): void
    {
        $ascending = new ReportTotals();
        $descending = new ReportTotals();
        $rows = [
            ['leads' => 1, 'income' => 10],
            ['leads' => 1, 'income' => 20],
            ['leads' => 1, 'income' => 30],
        ];

        foreach ($rows as $row) {
            $ascending->add($row);
        }
        foreach (array_reverse($rows) as $row) {
            $descending->add($row);
        }

        self::assertEquals(
            $ascending->toArray()['payout'],
            $descending->toArray()['payout'],
            'Re-sorting a report must not change its totals'
        );
    }

    public function testZeroDenominatorsProduceZeroInsteadOfFataling(): void
    {
        $totals = new ReportTotals();
        $totals->add(['clicks' => 0, 'click_out' => 0, 'cost' => 0, 'leads' => 0, 'income' => 0]);

        $result = $totals->toArray();

        self::assertEquals(0, $result['ctr']);
        self::assertEquals(0, $result['cpc']);
        self::assertEquals(0, $result['su_ratio']);
        self::assertEquals(0, $result['epc']);
        self::assertEquals(0, $result['roi']);
    }

    public function testNullMetricValuesAreTreatedAsZero(): void
    {
        $totals = new ReportTotals();
        // LEFT JOINed aggregate rows can contain SQL NULLs.
        $totals->add(['clicks' => '10', 'click_out' => null, 'cost' => null, 'leads' => null, 'payout' => null, 'income' => null]);

        $result = $totals->toArray();

        self::assertEquals(10.0, $result['clicks']);
        self::assertEquals(0, $result['net']);
        self::assertEquals(0, $result['roi']);
    }

    public function testNegativeNetAndRoi(): void
    {
        $totals = new ReportTotals();
        $totals->add(['clicks' => 10, 'cost' => 100, 'income' => 25]);

        $result = $totals->toArray();

        self::assertEquals(-75.0, $result['net']);
        self::assertEquals(-75.0, $result['roi']);
    }
}

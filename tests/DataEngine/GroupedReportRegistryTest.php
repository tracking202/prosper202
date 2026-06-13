<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\GroupedReportRegistry;

final class GroupedReportRegistryTest extends TestCase
{
    private const GROUPED_TYPES = [
        'keyword', 'textad', 'referer', 'ip', 'country', 'region',
        'city', 'isp', 'landingpage', 'device', 'browser', 'platform',
    ];

    public function testEveryGroupedReportHasADefinition(): void
    {
        foreach (self::GROUPED_TYPES as $type) {
            $definition = GroupedReportRegistry::definition($type);
            self::assertNotNull($definition, "Missing definition for $type report");
            self::assertNotSame('', $definition->labelSelect);
            self::assertNotSame('', $definition->groupBy);
        }
    }

    public function testUnknownTypeReturnsNull(): void
    {
        self::assertNull(GroupedReportRegistry::definition('breakdown'));
        self::assertNull(GroupedReportRegistry::definition('nope'));
    }

    public function testEveryReportHasAPaginationCountStrategy(): void
    {
        foreach (self::GROUPED_TYPES as $type) {
            $definition = GroupedReportRegistry::definition($type);
            self::assertTrue(
                $definition->countColumn !== null || $definition->usesRefererCount,
                "Report $type must define a pagination count strategy"
            );
        }
    }

    public function testKeywordReportSkipsTheUserFilterJoin(): void
    {
        // The keyword preference filter joins 202_keywords under the alias
        // `2k`; the keyword report already owns that alias, so including the
        // filter join would produce a duplicate-alias SQL error.
        $keyword = GroupedReportRegistry::definition('keyword');
        self::assertFalse($keyword->includeFilterJoin);
        self::assertStringContainsString('2k', $keyword->joins);

        foreach (array_diff(self::GROUPED_TYPES, ['keyword']) as $type) {
            self::assertTrue(
                GroupedReportRegistry::definition($type)->includeFilterJoin,
                "Report $type must include the user filter join"
            );
        }
    }

    public function testRefererReportUsesDomainGroupingCount(): void
    {
        $referer = GroupedReportRegistry::definition('referer');
        self::assertTrue($referer->usesRefererCount);
        self::assertNull($referer->countColumn);
    }

    public function testIpReportEmbedsIpv6DecodeFunction(): void
    {
        $withUdf = GroupedReportRegistry::definition('ip', 'inet6_ntoa');
        self::assertStringContainsString('IFNULL(inet6_ntoa(2i6.ip_address),2i.ip_address)', $withUdf->labelSelect);

        $withoutUdf = GroupedReportRegistry::definition('ip', '');
        self::assertStringContainsString('IFNULL((2i6.ip_address),2i.ip_address)', $withoutUdf->labelSelect);
    }

    public function testCountColumnsAreDataengineForeignKeys(): void
    {
        $expected = [
            'keyword' => 'keyword_id',
            'textad' => 'text_ad_id',
            'ip' => 'ip_id',
            'country' => 'country_id',
            'region' => 'region_id',
            'city' => 'city_id',
            'isp' => 'isp_id',
            'landingpage' => 'landing_page_id',
            'device' => 'device_id',
            'browser' => 'browser_id',
            'platform' => 'platform_id',
        ];

        foreach ($expected as $type => $column) {
            self::assertSame(
                $column,
                GroupedReportRegistry::definition($type)->countColumn,
                "Unexpected count column for $type"
            );
        }
    }
}

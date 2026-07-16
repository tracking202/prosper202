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

    /**
     * Pagination counts the report's own GROUP BY (DataEngine::countReportGroups), so a
     * definition needs nothing extra to be countable — but the groupBy must be resolvable
     * in that count's inner SELECT, which selects labelSelect and nothing else. A groupBy
     * naming an alias is fine only if labelSelect actually defines that alias.
     */
    public function testEveryGroupByIsResolvableFromItsLabelSelect(): void
    {
        foreach (self::GROUPED_TYPES as $type) {
            $definition = GroupedReportRegistry::definition($type, 'inet6_ntoa');
            $groupBy = $definition->groupBy;
            $labelSelect = $definition->labelSelect;

            $resolvable = str_contains($labelSelect, $groupBy)
                || str_contains($labelSelect, '`' . $groupBy . '`');

            self::assertTrue(
                $resolvable,
                "Report $type groups by '$groupBy', which its labelSelect '$labelSelect' does not "
                . 'provide — the pagination count would fail with an unknown column.'
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

    public function testRefererReportGroupsByItsDomainAlias(): void
    {
        $referer = GroupedReportRegistry::definition('referer');
        self::assertSame('referer_name', $referer->groupBy);
        self::assertStringContainsString('site_domain_host as referer_name', $referer->labelSelect);
    }

    public function testIpReportEmbedsIpv6DecodeFunction(): void
    {
        $withUdf = GroupedReportRegistry::definition('ip', 'inet6_ntoa');
        self::assertStringContainsString('IFNULL(inet6_ntoa(2i6.ip_address),2i.ip_address)', $withUdf->labelSelect);

        $withoutUdf = GroupedReportRegistry::definition('ip', '');
        self::assertStringContainsString('IFNULL((2i6.ip_address),2i.ip_address)', $withoutUdf->labelSelect);
    }

    /**
     * Regression guard. Reports group by the joined *name*, so pagination must count that
     * grouping. Counting DISTINCT foreign keys on 202_dataengine instead — which is what
     * the removed $countColumn described — counts a different thing: two ids sharing a
     * name, or several ids with no lookup row (all of which collapse into one NULL-name
     * group), make the count exceed the rows the report returns.
     */
    public function testReportsGroupByTheJoinedNameNotTheForeignKey(): void
    {
        $expected = [
            'textad' => 'text_ad_name',
            'country' => 'country_name',
            'region' => 'region_name',
            'city' => 'city_name',
            'isp' => 'isp_name',
            'landingpage' => 'landing_page_nickname',
            'device' => 'device_name',
            'browser' => 'browser_name',
            'platform' => 'platform_name',
        ];

        foreach ($expected as $type => $groupBy) {
            $definition = GroupedReportRegistry::definition($type);
            self::assertSame($groupBy, $definition->groupBy, "Unexpected grouping for $type");
            self::assertStringNotContainsString(
                '_id',
                $definition->groupBy,
                "Report $type must not group by a foreign key"
            );
        }
    }
}

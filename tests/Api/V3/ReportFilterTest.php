<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ReportFilter;
use PHPUnit\Framework\TestCase;

/**
 * The report filters the Analyze pages have (ReportFilter), checked on the
 * raw query string: each one's SQL and binds, the values that mean "not
 * filtering", and a 422 naming the parameter for everything else — an
 * unknown name, a list, a malformed value (CLAUDE.md #4).
 */
final class ReportFilterTest extends TestCase
{
    private const KEYWORD_IN = "IN (SELECT k.keyword_id FROM 202_keywords k WHERE k.keyword LIKE ? ESCAPE '!')";
    private const REFERER_IN = 'IN (SELECT su.site_url_id FROM 202_site_urls su'
        . " WHERE su.site_url_address LIKE ? ESCAPE '!')";

    /**
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<int|string>, 2: string}
     */
    private static function applied(array $params, string $timezone = 'UTC'): array
    {
        $where = [];
        $binds = [];
        $types = '';
        ReportFilter::apply($params, static fn (): string => $timezone, $where, $binds, $types);

        return [$where, $binds, $types];
    }

    public function testNoParametersFilterNothing(): void
    {
        self::assertSame([[], [], ''], self::applied([]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function idFilters(): iterable
    {
        foreach (ReportFilter::ID_FILTERS as $param => $column) {
            yield $param => [$param, $column];
        }
    }

    /** @dataProvider idFilters */
    public function testAnIdFilterNarrowsItsColumn(string $param, string $column): void
    {
        self::assertSame([["de.$column = ?"], [42], 'i'], self::applied([$param => '42']));
        self::assertSame(
            [[], [], ''],
            self::applied([$param => '0']),
            "$param=0 is not filtering, as the pages' menus send it"
        );
        self::assertSame([[], [], ''], self::applied([$param => '']));
        foreach (['abc', '-3', '1.5', ' 7', '007', '1e3', '99999999999999999999'] as $bad) {
            try {
                self::applied([$param => $bad]);
                self::fail("$param=$bad was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($param, $e->getFieldErrors(), "$param=$bad");
            }
        }
    }

    public function testTheNewIdFiltersAreTheAnalyzePagesOnes(): void
    {
        foreach (['text_ad_id', 'region_id', 'isp_id', 'browser_id', 'platform_id'] as $param) {
            self::assertArrayHasKey($param, ReportFilter::ID_FILTERS);
        }
        // The six the API always had come first, in their order, so the SQL
        // of a request that names only those is what it was.
        self::assertSame(
            ['aff_campaign_id', 'aff_network_id', 'ppc_account_id', 'ppc_network_id', 'landing_page_id', 'country_id'],
            array_slice(array_keys(ReportFilter::ID_FILTERS), 0, 6)
        );
    }

    /**
     * The pages offer "No traffic source" as ppc_network_id 16777215; it
     * reached SQL as an id no row has, so the API answered nothing where the
     * page listed the clicks. It and `none` are the clicks with no source.
     */
    public function testNoTrafficSourceIsTheClicksWithoutOne(): void
    {
        foreach (['none', '16777215', 16777215] as $value) {
            self::assertSame([['(de.ppc_network_id IS NULL OR de.ppc_network_id = 0)'], [], ''], self::applied(['ppc_network_id' => $value]), var_export($value, true));
        }
        self::assertSame([['de.ppc_network_id = ?'], [16777214], 'i'], self::applied(['ppc_network_id' => '16777214']));
        foreach (['None', 'nil', '-1', '16777215 '] as $bad) {
            try {
                self::applied(['ppc_network_id' => $bad]);
                self::fail("ppc_network_id=$bad was accepted");
            } catch (ValidationException $e) {
                self::assertStringContainsString('or none for the clicks with no traffic source', $e->getFieldErrors()['ppc_network_id']);
            }
        }
        try {
            self::applied(['aff_network_id' => 'none']);
            self::fail('only the traffic source has a none');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('no traffic source', $e->getFieldErrors()['aff_network_id']);
        }
    }

    public function testDeviceTypeIsEveryModelOfThatType(): void
    {
        self::assertSame(
            [['de.device_id IN (SELECT dm.device_id FROM 202_device_models dm WHERE dm.device_type = ?)'], [2], 'i'],
            self::applied(['device_type' => '2'])
        );
        $this->expectException(ValidationException::class);
        self::applied(['device_type' => 'mobile']);
    }

    public function testShowIsThePagesShowMenu(): void
    {
        self::assertSame([[], [], ''], self::applied(['show' => 'all']));
        self::assertSame([['de.click_filtered = 0'], [], ''], self::applied(['show' => 'real']));
        self::assertSame([['de.click_filtered = 1'], [], ''], self::applied(['show' => 'filtered']));
        self::assertSame([['de.click_bot = 1'], [], ''], self::applied(['show' => 'filtered_bot']));
        self::assertSame([['de.click_lead != 0'], [], ''], self::applied(['show' => 'leads']));
        try {
            self::applied(['show' => 'bots']);
            self::fail('show=bots was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('all, real, filtered, filtered_bot, leads', $e->getFieldErrors()['show']);
        }
    }

    public function testMethodOfPromotion(): void
    {
        $method = static fn (string $value): array => self::applied(['method_of_promotion' => $value]);
        self::assertSame([['de.landing_page_id = 0'], [], ''], $method('directlink'));
        self::assertSame([['de.landing_page_id != 0'], [], ''], $method('landingpage'));
        $this->expectException(ValidationException::class);
        self::applied(['method_of_promotion' => 'email']);
    }

    public function testKeywordAndRefererAreLiteralContains(): void
    {
        [$where, $binds, $types] = self::applied(['keyword' => ' blue widgets ']);
        self::assertSame(['de.keyword_id ' . self::KEYWORD_IN], $where);
        self::assertSame(['%blue widgets%'], $binds, 'trimmed, as the pages trim it');
        self::assertSame('s', $types);

        // The pages pasted the text into LIKE: 50% matched every keyword with
        // "50" in it, and a_b matched axb. Here they are the characters.
        self::assertSame(['%50!%!_off!!%'], self::applied(['keyword' => '50%_off!'])[1]);

        [$where, $binds] = self::applied(['referer' => 'news.example']);
        self::assertSame(['de.click_referer_site_url_id ' . self::REFERER_IN], $where);
        self::assertSame(['%news.example%'], $binds);

        self::assertSame([[], [], ''], self::applied(['keyword' => '   ', 'referer' => '']), 'blank is not filtering');
        foreach (['keyword', 'referer'] as $param) {
            try {
                self::applied([$param => str_repeat('x', ReportFilter::MAX_TEXT + 1)]);
                self::fail("an over-long $param was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey($param, $e->getFieldErrors());
            }
        }
    }

    public function testIpIsOneAddressMatchedAsStored(): void
    {
        [$where, $binds, $types] = self::applied(['ip' => '203.0.113.7']);
        self::assertSame(['de.ip_id IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address = ?)'], $where);
        self::assertSame(['203.0.113.7'], $binds);
        self::assertSame('s', $types);

        // IPv6 is compared packed, as MysqlLocationRepository stores it, so
        // every way of writing one address is that address.
        foreach (['2001:db8::1', '2001:DB8:0:0:0:0:0:1', ' 2001:db8::1 '] as $spelling) {
            [$where, $binds] = self::applied(['ip' => $spelling]);
            self::assertSame([inet_pton('2001:db8::1')], $binds, $spelling);
            self::assertStringContainsString(
                "REGEXP '^[0-9]+$'",
                $where[0],
                'only an all-digit ip_address refers to a v6 row'
            );
            self::assertStringContainsString('i6.ip_address = ?', $where[0]);
        }

        foreach (['999.1.1.1', '1.2.3', 'localhost', '010.0.0.1'] as $bad) {
            try {
                self::applied(['ip' => $bad]);
                self::fail("ip=$bad was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('ip', $e->getFieldErrors(), $bad);
            }
        }
    }

    public function testFiltersApplyInOneOrderAfterTheWindow(): void
    {
        [$where, $binds, $types] = self::applied([
            'referer' => 'r', 'ip' => '192.0.2.1', 'keyword' => 'k', 'show' => 'real',
            'method_of_promotion' => 'directlink', 'device_type' => '3', 'platform_id' => '9',
            'aff_campaign_id' => '5', 'time_from' => '100', 'time_to' => '200',
        ]);
        self::assertSame([
            'de.click_time >= ?', 'de.click_time <= ?', 'de.aff_campaign_id = ?', 'de.platform_id = ?',
            'de.device_id IN (SELECT dm.device_id FROM 202_device_models dm WHERE dm.device_type = ?)',
            'de.landing_page_id = 0', 'de.click_filtered = 0',
            'de.keyword_id ' . self::KEYWORD_IN,
            'de.ip_id IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address = ?)',
            'de.click_referer_site_url_id ' . self::REFERER_IN,
        ], $where);
        self::assertSame([100, 200, 5, 9, 3, '%k%', '192.0.2.1', '%r%'], $binds);
        self::assertSame('iiiiisss', $types);
        self::assertSame(strlen($types), count($binds));
    }

    public function testAPeriodIsTheSharedOneAndAlltimeHasNoBound(): void
    {
        self::assertSame([[], [], ''], self::applied(['period' => 'alltime']));
        [$where, $binds] = self::applied(['period' => 'last14'], 'America/New_York');
        self::assertSame(['de.click_time >= ?', 'de.click_time <= ?'], $where);
        // The pages' last 14 days: from midnight 14 days ago in the account's
        // zone to now, not 14 times 24 hours back from now.
        $ny = new \DateTimeZone('America/New_York');
        self::assertSame(
            (new \DateTimeImmutable('@' . $binds[1]))->setTimezone($ny)->setTime(0, 0, 0)->modify('-14 days')->getTimestamp(),
            $binds[0]
        );
        self::assertEqualsWithDelta(time(), $binds[1], 5, 'to now');
        foreach (['last7d', '0', 'Today'] as $bad) {
            try {
                self::applied(['period' => $bad]);
                self::fail("period=$bad was accepted");
            } catch (ValidationException $e) {
                self::assertArrayHasKey('period', $e->getFieldErrors());
            }
        }
    }

    public function testTheTimezoneIsReadOnceAndOnlyWhenABoundNeedsIt(): void
    {
        $reads = 0;
        $tz = static function () use (&$reads): string {
            $reads++;

            return 'America/New_York';
        };
        $where = $binds = [];
        $types = '';
        ReportFilter::apply(['period' => 'alltime', 'aff_campaign_id' => '1'], $tz, $where, $binds, $types);
        self::assertSame(0, $reads, 'all time has no bound to place in a day');
        ReportFilter::apply(['period' => 'last30'], $tz, $where, $binds, $types);
        self::assertSame(1, $reads, 'last 30 days starts at a midnight of the account\'s zone');
        ReportFilter::apply(['time_from' => '2026-10-01', 'period' => 'thismonth'], $tz, $where, $binds, $types);
        self::assertSame(2, $reads, 'a date bound and a calendar period share one read');
    }

    public function testUnknownParametersAndListsAreRefusedByName(): void
    {
        try {
            ReportFilter::rejectUnknown(['campain_id' => '7', 'period' => 'last7'], ReportFilter::params());
            self::fail('a misspelt filter was ignored');
        } catch (ValidationException $e) {
            self::assertSame(['campain_id'], array_keys($e->getFieldErrors()));
            self::assertStringContainsString(
                'aff_campaign_id',
                $e->getFieldErrors()['campain_id'],
                'the message lists the valid names'
            );
        }
        try {
            ReportFilter::rejectUnknown(['aff_campaign_id' => ['1', '2']], ReportFilter::params());
            self::fail('a list was accepted');
        } catch (ValidationException $e) {
            self::assertSame(['aff_campaign_id'], array_keys($e->getFieldErrors()));
        }
        ReportFilter::rejectUnknown(array_fill_keys(ReportFilter::params(), ''), ReportFilter::params());
        $this->addToAssertionCount(1);
    }

    public function testEveryReportEndpointRefusesAnUnknownParameter(): void
    {
        $controller = new ReportsController($this->createMock(\mysqli::class), 1);
        foreach (['summary', 'breakdown', 'timeseries', 'daypart', 'weekpart'] as $report) {
            try {
                $controller->$report(['campain_id' => '7']);
                self::fail("$report ignored an unknown parameter");
            } catch (ValidationException $e) {
                self::assertSame(['campain_id'], array_keys($e->getFieldErrors()), $report);
            }
        }
        try {
            $controller->rotatorStats(1, ['interval' => 'day']);
            self::fail('rotator stats ignored an unknown parameter');
        } catch (ValidationException $e) {
            self::assertSame(['interval'], array_keys($e->getFieldErrors()));
        }
    }

    /**
     * The same filters over another source: GET /clicks reads 202_clicks with
     * 202_clicks_advance, so it maps the columns it keeps elsewhere and nulls
     * the ones it lacks.
     */
    public function testAColumnMapRetargetsEveryFragment(): void
    {
        $map = ['click_time' => 'c.click_time', 'keyword_id' => 'ca.keyword_id', 'ip_id' => 'ca.ip_id',
            'click_filtered' => 'c.click_filtered', 'device_id' => 'ca.device_id',
            'landing_page_id' => 'c.landing_page_id', 'aff_campaign_id' => 'c.aff_campaign_id',
            'click_referer_site_url_id' => 'cs.click_referer_site_url_id'];
        [$where, $binds, $types] = ReportFilter::where([
            'time_from' => '5', 'aff_campaign_id' => '3', 'device_type' => '2', 'method_of_promotion' => 'landingpage',
            'show' => 'real', 'keyword' => 'k', 'ip' => '192.0.2.1', 'referer' => 'r',
        ], static fn (): string => 'UTC', $map);
        self::assertSame([
            'c.click_time >= ?', 'c.aff_campaign_id = ?',
            'ca.device_id IN (SELECT dm.device_id FROM 202_device_models dm WHERE dm.device_type = ?)',
            'c.landing_page_id != 0', 'c.click_filtered = 0',
            'ca.keyword_id ' . self::KEYWORD_IN,
            'ca.ip_id IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address = ?)',
            'cs.click_referer_site_url_id ' . self::REFERER_IN,
        ], $where);
        self::assertSame([5, 3, 2, '%k%', '192.0.2.1', '%r%'], $binds);
        self::assertSame('iiisss', $types);
        foreach ($where as $term) {
            self::assertStringNotContainsString('de.', $term);
        }
    }

    public function testAColumnASourceLacksTakesItsFiltersAway(): void
    {
        $map = ['ppc_network_id' => null, 'click_bot' => null];
        $params = ReportFilter::params($map);
        self::assertNotContains('ppc_network_id', $params);
        self::assertNotContains('show', $params, 'show needs click_bot for filtered_bot');
        self::assertContains('keyword', $params);
        try {
            ReportFilter::rejectUnknown(['ppc_network_id' => '4'], $params);
            self::fail('a filter the source cannot serve was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ppc_network_id', $e->getFieldErrors());
        }
        // Handed one anyway, apply() refuses rather than dropping it.
        try {
            ReportFilter::where(['show' => 'real'], static fn (): string => 'UTC', $map);
            self::fail('a filter the source cannot serve was dropped');
        } catch (ValidationException $e) {
            self::assertSame(['show'], array_keys($e->getFieldErrors()));
        }
        self::assertSame(
            [[], [], ''],
            ReportFilter::where(['show' => ''], static fn (): string => 'UTC', $map),
            'an empty value is no filter'
        );
        $this->expectException(\InvalidArgumentException::class);
        ReportFilter::where([], static fn (): string => 'UTC', ['keyword' => 'k.keyword']);
    }

    public function testAMalformedValueEchoesSafely(): void
    {
        try {
            self::applied(['aff_campaign_id' => "\xff\xfe"]);
            self::fail('invalid UTF-8 was accepted');
        } catch (ValidationException $e) {
            self::assertNotFalse(json_encode($e->toArray()), 'the 422 body must encode');
        }
    }
}

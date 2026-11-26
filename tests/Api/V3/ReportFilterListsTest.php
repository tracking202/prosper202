<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\ReportFilter;
use PHPUnit\Framework\TestCase;

/**
 * Several values on one report filter (`aff_campaign_id=1,4`) and the
 * click_id filter, read on the raw query string: the SQL and binds each
 * list becomes, and a 422 naming the parameter for a list that cannot be
 * read (CLAUDE.md #4). ReportFilterListsIntegrationTest runs them on a
 * server.
 */
final class ReportFilterListsTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, ?string> $columns
     * @return array{0: list<string>, 1: list<int|string>, 2: string}
     */
    private static function applied(array $params, array $columns = []): array
    {
        $where = [];
        $binds = [];
        $types = '';
        ReportFilter::apply($params, static fn (): string => 'UTC', $where, $binds, $types, $columns);

        return [$where, $binds, $types];
    }

    private static function refused(array $params, string $param, string $says = ''): void
    {
        try {
            self::applied($params);
            self::fail(json_encode($params) . ' was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($param, $e->getFieldErrors(), json_encode($params));
            self::assertStringContainsString($says, $e->getFieldErrors()[$param], json_encode($params));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function idFilters(): iterable
    {
        foreach (ReportFilter::ID_FILTERS as $param => $column) {
            yield $param => [$param, $column];
        }
    }

    /** @dataProvider idFilters */
    public function testAnIdFilterTakesSeveralIds(string $param, string $column): void
    {
        self::assertSame([["de.$column IN (?, ?, ?)"], [4, 1, 9], 'iii'], self::applied([$param => '4,1,9']));
        self::assertSame(
            [["de.$column IN (?, ?)"], [4, 1], 'ii'],
            self::applied([$param => '4,1,4']),
            'an id named twice is bound once'
        );
        self::assertSame([["de.$column = ?"], [4], 'i'], self::applied([$param => '4,4']), 'one id is the single form');
        self::assertSame([["de.$column = ?"], [42], 'i'], self::applied([$param => 42]));
    }

    /** @dataProvider idFilters */
    public function testAListThatCannotBeReadIsRefused(string $param): void
    {
        foreach (['1,,2', '1,', ',1', ',', '1, 2', '1;2', '1,abc', '1,-2', '1,007'] as $bad) {
            self::refused([$param => $bad], $param);
        }
        self::refused([$param => '0,4'], $param, '0 means no filter and cannot be one of several');
        self::refused([$param => '4,0'], $param, '0 means no filter');
        $tooMany = implode(',', range(1, ReportFilter::MAX_VALUES + 1));
        self::refused([$param => $tooMany], $param, 'At most ' . ReportFilter::MAX_VALUES);
        [, $binds] = self::applied([$param => implode(',', range(1, ReportFilter::MAX_VALUES))]);
        self::assertCount(ReportFilter::MAX_VALUES, $binds, 'MAX_VALUES ids are taken');
    }

    public function testNoTrafficSourceIsOneValueOfTheList(): void
    {
        $none = '(de.ppc_network_id IS NULL OR de.ppc_network_id = 0)';
        self::assertSame(
            [["($none OR de.ppc_network_id = ?)"], [3], 'i'],
            self::applied(['ppc_network_id' => 'none,3'])
        );
        self::assertSame(
            [["($none OR de.ppc_network_id IN (?, ?))"], [3, 5], 'ii'],
            self::applied(['ppc_network_id' => '3,16777215,5'])
        );
        self::assertSame([[$none], [], ''], self::applied(['ppc_network_id' => 'none,16777215']));
        self::refused(['aff_network_id' => 'none,3'], 'aff_network_id');
        self::refused(
            ['ppc_network_id' => 'None,3'],
            'ppc_network_id',
            'or none for the clicks with no traffic source'
        );
    }

    public function testPublisherIdTakesSeveralPublishersAndNone(): void
    {
        $credited = 'SELECT pc.click_id FROM 202_clicks_publisher pc';
        self::assertSame(
            [["de.click_id IN ($credited WHERE pc.publisher_user_id IN (?, ?))"], [7, 8], 'ii'],
            self::applied(['publisher_id' => '7,8'])
        );
        self::assertSame(
            [["(de.click_id NOT IN ($credited) OR de.click_id IN ($credited WHERE pc.publisher_user_id = ?))"],
                [7], 'i'],
            self::applied(['publisher_id' => 'none,7'])
        );
        [, $binds] = self::applied(['publisher_id' => '16777215,7']);
        self::assertSame([16777215, 7], $binds, '16777215 is a user id here, not a traffic-source word');
        self::refused(['publisher_id' => 'none,0'], 'publisher_id', '0 means no filter');
    }

    public function testDeviceTypeTakesSeveralTypes(): void
    {
        self::assertSame(
            [
                ['de.device_id IN (SELECT dm.device_id FROM 202_device_models dm WHERE dm.device_type IN (?, ?))'],
                [1, 2],
                'ii',
            ],
            self::applied(['device_type' => '1,2'])
        );
        self::refused(['device_type' => 'none,1'], 'device_type');
    }

    public function testChoicesTakeSeveralAndAllStandsAlone(): void
    {
        self::assertSame(
            [['(de.click_filtered = 1 OR de.click_lead != 0)'], [], ''],
            self::applied(['show' => 'filtered,leads,filtered'])
        );
        self::assertSame([[], [], ''], self::applied(['show' => 'all']));
        self::refused(['show' => 'all,real'], 'show', 'all means every click and stands alone');
        self::refused(['show' => 'real,'], 'show');
        self::refused(['show' => 'real,bots'], 'show', 'One of: all, real, filtered, filtered_bot, leads');
        self::assertSame(
            [['(de.landing_page_id = 0 OR de.landing_page_id != 0)'], [], ''],
            self::applied(['method_of_promotion' => 'directlink,landingpage'])
        );
    }

    public function testIpTakesSeveralAddressesOfEitherFamily(): void
    {
        $v4 = 'de.ip_id IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address IN (?, ?))';
        $v6 = 'de.ip_id IN (SELECT i.ip_id FROM 202_ips i INNER JOIN 202_ips_v6 i6'
            . " ON (i.ip_address REGEXP '^[0-9]+$' AND i6.ip_id = CAST(i.ip_address AS UNSIGNED))"
            . ' WHERE i6.ip_address = ?)';
        $packed = (string) inet_pton('2001:db8::1');
        self::assertSame(
            [["($v4 OR $v6)"], ['203.0.113.7', '203.0.113.8', $packed], 'sss'],
            self::applied(['ip' => '203.0.113.7,2001:DB8::1, 203.0.113.8,2001:db8:0::1'])
        );
        self::assertSame(
            [['de.ip_id IN (SELECT i.ip_id FROM 202_ips i WHERE i.ip_address = ?)'], ['203.0.113.7'], 's'],
            self::applied(['ip' => ' 203.0.113.7 '])
        );
        self::refused(['ip' => '203.0.113.7,'], 'ip');
        self::refused(['ip' => '203.0.113.7,999.1.1.1'], 'ip', '"999.1.1.1"');
        self::refused(['ip' => ' '], 'ip');
    }

    public function testClickIdNarrowsToThoseClicks(): void
    {
        self::assertSame([['de.click_id = ?'], [81360001], 'i'], self::applied(['click_id' => '81360001']));
        self::assertSame(
            [['de.click_id IN (?, ?)'], [9223372036854775807, 5], 'ii'],
            self::applied(['click_id' => '9223372036854775807,5,5'])
        );
        foreach (['0', '-1', '01', '1.5', '1e3', '12abc', '9223372036854775808', '5,0', '5,,6'] as $bad) {
            self::refused(['click_id' => $bad], 'click_id');
        }
        self::assertContains('click_id', ReportFilter::params());
        self::assertSame(
            [['c.click_id = ?'], [7], 'i'],
            self::applied(['click_id' => '7'], ['click_id' => 'c.click_id'])
        );
    }

    /** A source with no click column refuses click_id by name, never drops it. */
    public function testASourceWithoutClicksRefusesClickId(): void
    {
        self::assertNotContains('click_id', ReportFilter::params(['click_id' => null]));
        try {
            self::applied(['click_id' => '7'], ['click_id' => null]);
            self::fail('click_id was dropped on a source with no click column');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('click_id', $e->getFieldErrors());
        }
    }

    /** keyword and referer stay one text each: a keyword may hold a comma. */
    public function testTextFiltersKeepTheirCommas(): void
    {
        [, $binds] = self::applied(['keyword' => 'cheap, fast', 'referer' => 'a,b']);
        self::assertSame(['%cheap, fast%', '%a,b%'], $binds);
    }

    /** Values are one string with commas; an array is still refused by name. */
    public function testAnArrayIsStillRefused(): void
    {
        try {
            ReportFilter::rejectUnknown(['aff_campaign_id' => ['1', '2']], ReportFilter::params());
            self::fail('an array was accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('with commas (1,4)', $e->getFieldErrors()['aff_campaign_id']);
        }
    }
}

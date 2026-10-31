<?php

declare(strict_types=1);

namespace Tests\Report;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

final class DeferredFilterChoicesTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-overview.php';
    }

    public function testTheInitialListsReadOnlyTheSelectedLabels(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('WHERE l.region_id = ?', [['label' => 'Selected (US)']]);
        $lists = p202_overview_filter_lists(new Connection($db), 7, array_keys(P202_OVERVIEW_SEEN_LISTS), [
            'data_user_id' => 7, 'from' => 0, 'to' => 1792306799,
            'values' => ['region_id' => '9', 'isp_id' => '0', 'browser_id' => '', 'platform_id' => ''],
        ], true);

        self::assertSame([
            'region_id' => [9 => 'Selected (US)'], 'isp_id' => [], 'browser_id' => [], 'platform_id' => [],
        ], $lists);
        self::assertCount(0, $db->statementsContaining('202_dataengine'));
        self::assertCount(1, $db->statements);
        self::assertSame([9], $db->statements[0]->boundValues);
    }

    public function testUnsetFiltersNeedNoQueries(): void
    {
        $db = new FakeMysqliConnection();
        $lists = p202_overview_filter_lists(new Connection($db), 7, array_keys(P202_OVERVIEW_SEEN_LISTS), [
            'data_user_id' => 7, 'from' => 0, 'to' => 1792306799,
        ], true);

        self::assertSame(array_fill_keys(array_keys(P202_OVERVIEW_SEEN_LISTS), []), $lists);
        self::assertSame([], $db->statements);
    }

    public function testChoicesUrlsKeepTheResolvedWindowAndSelectedValue(): void
    {
        $names = array_keys(P202_OVERVIEW_SEEN_LISTS);
        $html = p202_overview_page([
            'id' => 'test', 'title' => 'Test', 'desc' => '', 'icon' => 'bi-table', 'panel' => 'Report',
            'action' => '/prosper/report.php', 'fragment' => '/prosper/report-data.php', 'base' => '/prosper/',
            'names' => $names,
            'lists' => ['region_id' => [9 => '<Selected>'], 'isp_id' => [], 'browser_id' => [], 'platform_id' => []],
            'choices_window' => ['from' => 1000, 'to' => 2000],
            'state' => [
                'values' => ['region_id' => '9'], 'errors' => [], 'decided' => [],
                'range' => 'thisyear', 'from' => '2026-01-01', 'to' => '2026-10-17', 'view' => 'range=thisyear',
            ],
        ]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $buttons = $xpath->query('//button[@data-p202-filter-choices]');
        self::assertCount(4, $buttons);
        foreach ($buttons as $button) {
            self::assertSame('button', $button->getAttribute('type'));
            $url = $button->getAttribute('data-p202-filter-choices');
            self::assertSame(
                '/prosper/tracking202/ajax/overview_filter_choices.php',
                parse_url($url, PHP_URL_PATH)
            );
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame(['from' => 1000, 'to' => 2000], [
                'from' => (int) $query['from'], 'to' => (int) $query['to'],
            ]);
            self::assertContains($query['filter'], $names);
            self::assertSame($query['filter'] === 'region_id' ? '9' : '', $query['current']);
            self::assertSame('test-filters-' . $query['filter'], $button->getAttribute('aria-controls'));
        }
        self::assertSame(
            '<Selected>',
            $xpath->query('//select[@name="region_id"]/option[@selected]')->item(0)->textContent
        );
        $report = $xpath->query('//*[@data-p202-report]')->item(0)->getAttribute('data-p202-report');
        parse_str((string) parse_url($report, PHP_URL_QUERY), $reportQuery);
        self::assertSame('range=thisyear', $reportQuery['view']);
    }

    public function testValidRequestsKeepOnlyTheFilterWindowAndCurrentChoice(): void
    {
        foreach (array_keys(P202_OVERVIEW_SEEN_LISTS) as $name) {
            self::assertSame(
                ['filter' => $name, 'from' => 1000, 'to' => 2000, 'current' => '9'],
                p202_overview_filter_choices_request([
                    'filter' => $name, 'from' => '1000', 'to' => '2000', 'current' => '9', 'user_id' => '999',
                ])
            );
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        $valid = ['filter' => 'region_id', 'from' => '1000', 'to' => '2000'];
        yield 'missing' => [[]];
        $invalid = [
            'filter' => ['country_id', 'region_id UNION SELECT 1', ['region_id']],
            'from' => ['-1', '1.5', '1e3', '1000 trailing', '12345678901', [], true],
            'to' => ['999', [], null],
            'current' => [['9'], '-1', '9 trailing', "9\n", str_repeat('9', 20)],
        ];
        foreach ($invalid as $key => $values) {
            foreach ($values as $index => $value) {
                yield "$key:$index" => [array_replace($valid, [$key => $value])];
            }
        }
    }

    /** @dataProvider invalidRequests */
    public function testInvalidRequestsAreRefused(array $query): void
    {
        $this->expectException(InvalidArgumentException::class);
        p202_overview_filter_choices_request($query);
    }
}

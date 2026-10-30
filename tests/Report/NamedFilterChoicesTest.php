<?php

declare(strict_types=1);

namespace Tests\Report;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Prosper202\Report\FilterChoicesCache;
use Tests\Support\FakeMysqliConnection;
use Tracking202\Report\ReportPrefsStore;

final class NamedFilterChoicesTest extends TestCase
{
    public function testAnUnambiguousNameDoesNotReadClicks(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('WHERE r.region_name = ?', [
            ['id' => '11', 'region_name' => 'Unique', 'country_code' => 'us'],
        ]);
        $answer = (new ReportPrefsStore($db))->resolveName('region', 'Unique (US)', 7, 100, 200);
        self::assertSame(['id' => '11', 'error' => null], $answer);
        self::assertCount(0, $db->statementsContaining('202_dataengine'));
    }

    public function testCrossCountryAmbiguityIsRefusedBeforeReadingClicks(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('WHERE r.region_name = ?', [
            ['id' => '11', 'region_name' => 'Shared', 'country_code' => 'us'],
            ['id' => '22', 'region_name' => 'Shared', 'country_code' => 'ca'],
        ]);
        $answer = (new ReportPrefsStore($db))->resolveName('region', 'Shared', 7, 100, 200);
        self::assertNull($answer['id']);
        self::assertStringContainsString('More than one country', $answer['error']);
        self::assertCount(0, $db->statementsContaining('202_dataengine'));
    }

    public function testAmbiguousIdsAreRankedInOneAccountAndWindowScopedRead(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('WHERE i.isp_name = ?', [['id' => '11'], ['id' => '22']]);
        $db->whenQueryContainsReturnRows('SELECT chosen.isp_id AS id', [['id' => '22']]);
        $answer = (new ReportPrefsStore($db))->resolveName('isp', 'Shared ISP', 7, 100, 200);
        self::assertSame(['id' => '22', 'error' => null], $answer);
        $reads = $db->statementsContaining('202_dataengine');
        self::assertCount(1, $reads);
        self::assertSame([100, 200, '11', '22', '11', '22'], $reads[0]->boundValues);
        self::assertStringContainsString('2st.user_id = 7', $reads[0]->sql);
        self::assertStringContainsString('COALESCE(usage_counts.used, 0) DESC', $reads[0]->sql);
    }

    public function testNamedSuggestionsReuseTheirOwnCacheWithoutCollidingWithIdChoices(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('JOIN 202_locations_region r', [
            ['region_name' => 'Shared', 'country_code' => 'us'],
        ]);
        $entries = [];
        $cache = new FilterChoicesCache(
            static function (string $key) use (&$entries): mixed {
                return $entries[$key] ?? false;
            },
            static function (string $key, array $value) use (&$entries): void {
                $entries[$key] = $value;
            },
            'test-database'
        );
        $store = new ReportPrefsStore($db);
        $cache->remember('region_id', 7, 100, 200, 300, static fn (): array => [11 => 'ID choice']);
        self::assertSame(['Shared (US)'], $store->suggestions('region', 7, 100, 200, 300, $cache));
        self::assertSame(['Shared (US)'], $store->suggestions('region', 7, 100, 200, 300, $cache));
        self::assertCount(1, $db->statementsContaining('202_dataengine'));
        $store->suggestions('region', 8, 100, 200, 300, $cache);
        $store->suggestions('region', 7, 101, 200, 300, $cache);
        self::assertCount(3, $db->statementsContaining('202_dataengine'));
    }

    public function testDeferredSuggestionsKeepTheTypedValueAndEscapeLabels(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        $html = p202_report_filter_bar([
            'id' => 'named', 'action' => '/report', 'filters' => [[
                'name' => 'region', 'label' => 'Region', 'type' => 'suggest', 'value' => '<Current>',
                'advanced' => true, 'choices_url' => '/choices?filter=region_id&from=100&to=200',
            ]],
        ]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame('<Current>', $xpath->query('//input[@name="region"]')->item(0)->getAttribute('value'));
        self::assertSame(0, $xpath->query('//datalist/option')->length);
        $list = $xpath->query('//datalist')->item(0)->getAttribute('id');
        $button = $xpath->query('//button[@data-p202-filter-choices]')->item(0);
        self::assertSame($list, $button->getAttribute('aria-controls'));
        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame(1, $xpath->query('//details[@open]')->length);
        self::assertStringNotContainsString('value="<Current>"', $html);
    }
}

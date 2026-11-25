<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Report\VisitorsDownload;
use Tests\Support\SourceScan;

/**
 * The Visitors download writes every cell through TsvCell: a tab or a line
 * break planted in any value the page's query reads stays inside its own
 * column, and every row has the header's columns.
 */
final class VisitorsDownloadTest extends TestCase
{
    private const BASE = 'https://track.example/';

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** @return array<string, string|null> a click of the page's query, cloaked through an advanced landing page */
    private static function click(): array
    {
        return [
            'click_id' => '42', 'click_time' => '1767225600', 'click_alp' => '1', 'click_cloaking' => '1',
            'click_id_public' => '8842', 'aff_campaign_id_public' => '77', 'click_lead' => '0',
            'click_filtered' => '0', 'browser_name' => 'Chrome', 'platform_name' => 'Android',
            'ppc_network_name' => 'Ads', 'ppc_account_name' => 'Main',
            'ip_address' => '203.0.113.9', 'isp_name' => 'Carrier', 'country_name' => 'Ireland', 'country_code' => 'IE',
            'city_name' => 'Cork', 'aff_campaign_name' => 'Shoes', 'text_ad_name' => 'Ad 1',
            'referer' => 'https://ref.example/?a=1&b=2', 'landing' => 'https://lp.example/',
            'outbound' => 'https://out.example/',
            'redirect' => 'https://redir.example/', 'keyword' => 'running shoes', 'gclid' => 'g', 'gbraid' => 'gb',
            'wbraid' => 'wb', 'ttclid' => 'tt', 'msclkid' => 'ms', 'fbclid' => 'fb', 'oppref' => 'op',
            'utm_campaign' => 'uc', 'utm_content' => 'uo', 'utm_medium' => 'um', 'utm_source' => 'us',
            'utm_term' => 'ut',
        ];
    }

    /** @return list<string> */
    private static function cells(string $row): array
    {
        self::assertStringEndsWith("\n", $row);
        $line = substr($row, 0, -1);
        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString("\r", $line);

        return explode("\t", $line);
    }

    public function testARowHasTheHeadersColumnsInOrder(): void
    {
        $header = self::cells(VisitorsDownload::header());
        $cells = array_combine($header, self::cells(VisitorsDownload::row(self::click(), self::BASE)));

        self::assertCount(32, $header);
        self::assertSame('42', $cells['Subid']);
        self::assertSame('01/01/26 12:00am', $cells['Date']);
        self::assertSame('real', $cells['Click Real/Filtered']);
        self::assertSame('Cork', $cells['City']);
        self::assertSame('https://ref.example/?a=1&b=2', $cells['Referer'], 'plain text, not an HTML entity');
        self::assertSame(self::BASE . 'tracking202/redirect/off.php?acip=77&pci=8842', $cells['Cloaked Referer']);
        self::assertSame('running shoes', $cells['Keyword']);
        self::assertSame('ut', $cells['utm_term']);
    }

    public function testATabAndALineBreakInAnyColumnStayInIt(): void
    {
        $columns = count(VisitorsDownload::HEADER);
        foreach (array_keys(self::click()) as $key) {
            if (in_array($key, ['click_time', 'click_alp', 'click_cloaking', 'click_lead', 'click_filtered'], true)) {
                continue;
            }
            $click = self::click();
            $click[$key] = "x\ty\r\nz";
            $cells = self::cells(VisitorsDownload::row($click, self::BASE));

            self::assertCount($columns, $cells, $key . ' with a tab and a line break keeps every column in place');
            $last = $key === 'utm_term' ? 'x y z' : 'ut';
            self::assertSame($last, $cells[$columns - 1], $key . ' does not push the last column');
            $hit = array_values(array_filter($cells, static fn (string $cell): bool => str_contains($cell, 'x y z')));
            self::assertCount(1, $hit, $key . ' is written in its own column, on one line');
        }
    }

    public function testEveryValueAtOnce(): void
    {
        $click = array_map(static fn (?string $v): string => "=\t" . $v . "\n", self::click());
        foreach (['click_time', 'click_alp', 'click_cloaking', 'click_lead', 'click_filtered'] as $flag) {
            $click[$flag] = self::click()[$flag];
        }
        $cells = self::cells(VisitorsDownload::row($click, self::BASE));

        self::assertCount(count(VisitorsDownload::HEADER), $cells);
        self::assertSame("'= Cork ", $cells[11], 'a formula a visitor sent is written as text');
    }

    public function testStatusAndCloakedLink(): void
    {
        $click = self::click();
        $click['click_filtered'] = '1';
        $click['click_alp'] = '0';
        $cells = self::cells(VisitorsDownload::row($click, self::BASE));
        self::assertSame('filtered', $cells[6]);
        self::assertSame(self::BASE . 'tracking202/redirect/cl.php?pci=8842', $cells[17]);

        $click['click_filtered'] = '0';
        $click['click_lead'] = '1';
        $click['click_cloaking'] = '0';
        $cells = self::cells(VisitorsDownload::row($click, self::BASE));
        self::assertSame('conversion', $cells[6]);
        self::assertSame('', $cells[17]);
    }

    public function testThePageWritesThroughTheClass(): void
    {
        $page = SourceScan::phpFiles()['tracking202/visitors/download/index.php'];
        self::assertStringContainsString('VisitorsDownload::header()', $page);
        self::assertStringContainsString('VisitorsDownload::row($click_row, $trackingBase)', $page);
        self::assertStringNotContainsString('htmlentities', $page, 'a download is plain text');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\TestCase;

/**
 * The shared v2 partials (202-config/functions-ui-partials.php) and the v2
 * shell's deferred page scripts.
 *
 * The partials are pure functions of their arguments, so they are rendered
 * here and read back as a DOM — the assertions are about the elements and
 * attributes a browser and p202-ui.js act on, not about substrings of the
 * markup. What only a browser can say (the range picker's handler, the sort
 * order on screen, the disclosure staying open) is driven in
 * tests/browser/specs/ui-kit-partials.spec.js.
 */
final class UiPartialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/202-config/functions-ui.php';
    }

    // ── p202_date_range ────────────────────────────────────────────────

    public function testAPresetRendersItsDatesDisabledWithTheHint(): void
    {
        $dom = $this->dom('<form>' . p202_date_range(['range' => 'last7', 'from' => '2026-09-04', 'to' => '2026-09-11', 'id' => 'r']) . '</form>');
        $select = $this->one($dom, '//select[@id="r"]');
        self::assertSame('custom', $select->getAttribute('data-p202-range'), 'the picker names the value that means custom');
        self::assertSame('range', $select->getAttribute('name'));
        self::assertSame('last7', $this->selectedValue($dom, 'r'));

        foreach (['from', 'to'] as $which) {
            $input = $this->one($dom, '//input[@id="r-' . $which . '"]');
            self::assertSame('date', $input->getAttribute('type'), 'a native date input');
            self::assertTrue($input->hasAttribute('disabled'), "$which is not submitted under a preset without JavaScript");
            self::assertSame($which, $input->getAttribute('data-p202-range-field'), 'p202-ui.js restores this name for a custom window');
        }
        self::assertSame(1, $this->nodes($dom, '//span[@data-p202-range-hint]'), 'the no-JavaScript hint is there');
    }

    public function testACustomWindowRendersItsDatesLive(): void
    {
        $dom = $this->dom('<form>' . p202_date_range(['range' => P202_RANGE_CUSTOM, 'from' => '2026-08-01', 'to' => '2026-08-31', 'id' => 'r', 'max' => '2026-09-25']) . '</form>');
        self::assertSame('custom', $this->selectedValue($dom, 'r'));
        foreach (['from' => '2026-08-01', 'to' => '2026-08-31'] as $which => $value) {
            $input = $this->one($dom, '//input[@id="r-' . $which . '"]');
            self::assertFalse($input->hasAttribute('disabled'));
            self::assertSame($value, $input->getAttribute('value'));
            self::assertSame('2026-09-25', $input->getAttribute('max'));
        }
        self::assertSame(0, $this->nodes($dom, '//span[@data-p202-range-hint]'));
    }

    public function testARefusedWindowShowsTheServersSentence(): void
    {
        $dom = $this->dom('<form>' . p202_date_range(['range' => P202_RANGE_CUSTOM, 'from' => '2026-09-11', 'to' => '2026-09-04', 'id' => 'r', 'error' => 'The start date is after the end date.']) . '</form>');
        self::assertStringContainsString('is-invalid', $this->one($dom, '//input[@id="r-from"]')->getAttribute('class'));
        self::assertSame('The start date is after the end date.', trim($this->one($dom, '//div[contains(@class,"invalid-feedback")]')->textContent));
    }

    public function testTheRangeRefusesWhatTheControllerShouldHaveResolved(): void
    {
        foreach ([
            'unknown preset' => ['range' => 'last900'],
            'malformed date' => ['range' => P202_RANGE_CUSTOM, 'from' => '09/04/2026'],
            'custom that is also a preset' => ['range' => 'today', 'custom' => 'today'],
            'empty custom' => ['range' => 'today', 'custom' => ''],
        ] as $case => $spec) {
            try {
                p202_date_range($spec);
                self::fail("$case was accepted");
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage(), "$case says why");
            }
        }
    }

    // ── p202_report_filters / p202_report_filter_bar ───────────────────

    public function testTheCatalogUsesTheClassicNamesAndAsksForItsLists(): void
    {
        $specs = p202_report_filters([], ['ppc_network_id' => ['1' => 'Google'], 'aff_campaign_id' => []], ['ppc_network_id', 'aff_campaign_id', 'user_pref_show', 'subid']);
        self::assertSame(['ppc_network_id', 'aff_campaign_id', 'user_pref_show', 'subid'], array_column($specs, 'name'), 'in the order asked for');
        self::assertSame('all', $specs[2]['value'], 'a display setting with no value shows its default');
        self::assertTrue($specs[3]['advanced']);

        $this->expectException(\InvalidArgumentException::class);
        p202_report_filters([], [], ['country_id']);
    }

    public function testAnUnknownFilterNameIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_report_filters([], [], ['user_pref_country']);
    }

    public function testAValueTheListNoLongerHasIsKeptSelected(): void
    {
        $html = p202_report_filter_bar([
            'action' => '/report.php',
            'id' => 'f',
            'filters' => [
                ['name' => 'aff_campaign_id', 'label' => 'Campaign', 'type' => 'select', 'any' => 'All campaigns', 'value' => '99',
                    'options' => ['Network' => ['11' => 'Campaign A']]],
            ],
        ]);
        $dom = $this->dom($html);
        self::assertSame('99', $this->selectedValue($dom, 'f-aff_campaign_id'), 'the menu still says what the report is filtered by');
        self::assertStringContainsString('not in your list', $this->one($dom, '//select[@id="f-aff_campaign_id"]/option[@value="99"]')->textContent);
        self::assertSame(1, $this->nodes($dom, '//select[@id="f-aff_campaign_id"]/optgroup[@label="Network"]/option[@value="11"]'), 'grouped options render as optgroups');
    }

    public function testAnAdvancedFilterThatIsSetIsNeverFoldedAway(): void
    {
        $filters = p202_report_filters(['country_id' => 'GB'], ['country_id' => ['GB' => 'United Kingdom'], 'device_id' => ['1' => 'Desktop']], ['country_id', 'device_id', 'user_pref_limit']);
        $dom = $this->dom(p202_report_filter_bar(['action' => '/r.php', 'id' => 'f', 'filters' => $filters]));
        $details = $this->one($dom, '//details[contains(@class,"p202-disclosure")]');
        self::assertTrue($details->hasAttribute('open'), 'it opens');
        self::assertFalse($details->hasAttribute('data-p202-remember'), 'a remembered "closed" cannot fold it shut');
        self::assertSame('1 set', trim($this->one($dom, '//span[@class="p202-disclosure__hint"]')->textContent), 'the default row count is not counted as set');
    }

    /**
     * The classic calendar's "--" option posted 0 and set_user_prefs.php
     * stored it as given, so a stored "All" is often '0'. Read as a value it
     * rendered a selected "0 (not in your list)", counted Advanced as "N set"
     * and was resubmitted as 0.
     */
    public function testTheClassicZeroSentinelReadsAsAll(): void
    {
        $names = ['ppc_network_id', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'landing_page_id', 'text_ad_id',
            'method_of_promotion', 'country_id', 'region_id', 'isp_id', 'device_id', 'browser_id', 'platform_id'];
        $lists = [];
        $values = [];
        foreach ($names as $name) {
            $lists[$name] = ['7' => 'Seven'];
            $values[$name] = '0';
        }
        $specs = p202_report_filters($values, $lists, $names);
        foreach ($specs as $spec) {
            self::assertSame('', $spec['value'], "{$spec['name']}: a stored 0 is the classic All");
        }
        $dom = $this->dom(p202_report_filter_bar(['action' => '/r.php', 'id' => 'f', 'filters' => $specs]));
        foreach ($names as $name) {
            self::assertSame('', $this->selectedValue($dom, 'f-' . $name), "$name shows its All option");
        }
        self::assertSame(0, $this->nodes($dom, '//option[@value="0"]'), 'no synthetic 0 option is rendered, so none is resubmitted');
        self::assertFalse($this->one($dom, '//details[contains(@class,"p202-disclosure")]')->hasAttribute('open'), 'nothing counts as set');

        // A 0 on a setting with no "All" entry is not a sentinel: it is kept
        // as the value it is, rather than silently becoming the default.
        $limit = p202_report_filters(['user_pref_limit' => '0'], [], ['user_pref_limit']);
        self::assertSame('0', $limit[0]['value']);
    }

    public function testNothingSetLeavesAdvancedClosedAndRemembered(): void
    {
        $filters = p202_report_filters([], ['device_id' => ['1' => 'Desktop']], ['device_id', 'user_pref_limit', 'user_cpc_or_cpv']);
        $dom = $this->dom(p202_report_filter_bar(['action' => '/r.php', 'id' => 'f', 'remember' => 'kw-adv', 'filters' => $filters]));
        $details = $this->one($dom, '//details[contains(@class,"p202-disclosure")]');
        self::assertFalse($details->hasAttribute('open'));
        self::assertSame('kw-adv', $details->getAttribute('data-p202-remember'));
        self::assertSame('50', $this->selectedValue($dom, 'f-user_pref_limit'), 'the default is pre-selected, not blank');
    }

    public function testTheBarIsOneGetFormCarryingItsHiddenFields(): void
    {
        $dom = $this->dom(p202_report_filter_bar([
            'action' => '/r.php?x=1',
            'id' => 'f',
            'hidden' => ['view' => 'report', 'page' => null],
            'range' => ['range' => 'today'],
            'filters' => [['name' => 'ip', 'label' => 'Visitor IP', 'type' => 'search', 'value' => '"><script>alert(1)</script>', 'error' => 'Not an IP address.']],
            'reset' => '/r.php',
        ]));
        $form = $this->one($dom, '//form');
        self::assertSame('get', $form->getAttribute('method'));
        self::assertSame('/r.php?x=1', $form->getAttribute('action'));
        self::assertSame(1, $this->nodes($dom, '//input[@type="hidden" and @name="view" and @value="report"]'));
        self::assertSame(0, $this->nodes($dom, '//input[@name="page"]'), 'a null hidden field is dropped');
        self::assertSame(1, $this->nodes($dom, '//select[@id="f-range"]'), 'the range control is inside the form');
        self::assertSame(0, $this->nodes($dom, '//script'), 'values are escaped');
        $ip = $this->one($dom, '//input[@name="ip"]');
        self::assertSame('"><script>alert(1)</script>', $ip->getAttribute('value'));
        self::assertStringContainsString('is-invalid', $ip->getAttribute('class'));
        self::assertSame(1, $this->nodes($dom, '//button[@type="submit"]'), 'one primary action');
    }

    public function testASuggestFilterIsADatalist(): void
    {
        $dom = $this->dom(p202_report_filter_bar(['action' => '/r.php', 'id' => 'f', 'filters' => [
            ['name' => 'keyword', 'label' => 'Keyword', 'type' => 'suggest', 'value' => '', 'suggestions' => ['a', 'b']],
        ]]));
        $input = $this->one($dom, '//input[@name="keyword"]');
        self::assertSame('f-keyword-list', $input->getAttribute('list'));
        self::assertSame(2, $this->nodes($dom, '//datalist[@id="f-keyword-list"]/option'));
    }

    public function testAnUnknownFilterTypeIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_report_filter_bar(['action' => '/r.php', 'filters' => [['name' => 'x', 'type' => 'tokens']]]);
    }

    // ── p202_data_table ────────────────────────────────────────────────

    public function testASortableTableIsWiredForTablesortAndKeepsItsTotalsLast(): void
    {
        $dom = $this->dom(p202_data_table(
            [['key' => 'k', 'label' => 'Keyword'], ['key' => 'c', 'label' => 'Clicks', 'num' => true], ['key' => 's', 'label' => 'Status', 'sort' => false]],
            [['k' => 'a', 'c' => ['text' => '1,412', 'sort' => 1412], 's' => ['html' => '<span class="p202-pill">x</span>']]],
            ['sortable' => true, 'id' => 't', 'totals' => ['k' => 'Totals', 'c' => '1,412', 's' => '']]
        ));
        $table = $this->one($dom, '//table[@id="t"]');
        self::assertTrue($table->hasAttribute('data-p202-sort'));
        self::assertStringContainsString('p202-table', $table->getAttribute('class'));
        self::assertSame(1, $this->nodes($dom, '//div[@class="p202-table-wrap"]/table'), 'inside its scrolling wrap');

        $headers = $dom->document->getElementsByTagName('th');
        self::assertSame(1, $this->nodes($dom, '//th[1]/button[@type="button" and @class="p202-sort"]'), 'a text column is a keyboard-reachable button');
        self::assertSame('number', $headers->item(1)->getAttribute('data-sort-method'));
        self::assertStringContainsString('num', $headers->item(1)->getAttribute('class'));
        self::assertStringContainsString('no-sort', $headers->item(2)->getAttribute('class'));
        self::assertSame(0, $this->nodes($dom, '//th[3]/button'));

        self::assertSame('1412', $this->one($dom, '//tbody/tr[1]/td[2]')->getAttribute('data-sort'), 'a formatted number sorts by its raw value');
        self::assertSame(1, $this->nodes($dom, '//tbody/tr[1]/td[3]/span[@class="p202-pill"]'), 'an html cell is rendered as given');
        $last = $this->one($dom, '//tbody/tr[last()]');
        self::assertSame('p202-table__totals no-sort', $last->getAttribute('class'), 'tablesort leaves a no-sort row where it is');
    }

    public function testAPlainTableHasNoSortControlsButSaysHowTheServerOrderedIt(): void
    {
        $dom = $this->dom(p202_data_table(
            [['key' => 'd', 'label' => 'Day'], ['key' => 'c', 'label' => 'Clicks', 'num' => true]],
            [['d' => '2026-09-12', 'c' => '5']],
            ['sorted' => ['key' => 'd', 'dir' => 'descending']]
        ));
        self::assertFalse($this->one($dom, '//table')->hasAttribute('data-p202-sort'), 'sorting is opt-in: only a complete table may sort in the browser');
        self::assertSame(0, $this->nodes($dom, '//button'));
        self::assertSame('descending', $this->one($dom, '//th[1]')->getAttribute('aria-sort'));
    }

    public function testAnEmptyTableIsTheEmptyStateWithItsParts(): void
    {
        $dom = $this->dom(p202_data_table([['key' => 'd', 'label' => 'Day']], [], ['empty' => ['title' => 'No clicks', 'body' => 'Widen the range.', 'action' => 'Get a link', 'href' => '/links']]));
        self::assertSame(0, $this->nodes($dom, '//table'));
        self::assertSame('No clicks', trim($this->one($dom, '//*[@class="p202-empty__title"]')->textContent), 'the title is the component part, not a bare heading (#19)');
        self::assertSame(1, $this->nodes($dom, '//i[contains(@class,"p202-empty__icon")]'));
        self::assertSame('/links', $this->one($dom, '//div[@class="p202-empty__action"]/a')->getAttribute('href'));
    }

    public function testATableRefusesAShapeItCannotRender(): void
    {
        foreach ([
            'no columns' => fn () => p202_data_table([], [['a' => 1]]),
            'bad direction' => fn () => p202_data_table([['key' => 'a', 'label' => 'A']], [['a' => 1]], ['sorted' => ['key' => 'a', 'dir' => 'up']]),
            'bad sort method' => fn () => p202_data_table([['key' => 'a', 'label' => 'A', 'sort' => 'date']], [['a' => 1]], ['sortable' => true]),
        ] as $case => $render) {
            try {
                $render();
                self::fail("$case was accepted");
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }

    // ── The v2 shell defers its page scripts ───────────────────────────

    public function testTheV2ShellDefersItsPageScriptsAndTheClassicOneDoesNot(): void
    {
        self::assertTrue(p202_shell_defers_page_scripts(P202_UI_V2));
        self::assertFalse(p202_shell_defers_page_scripts(P202_UI_CLASSIC));

        $context = ['section' => 'tracking202', 'sub' => 'analyze', 'page' => 'keywords.php', 'logged_in' => true];
        foreach ([P202_UI_V2 => ' defer', P202_UI_CLASSIC => ''] as $ui => $expected) {
            $assets = p202_shell_assets($ui, $context);
            self::assertNotSame([], $assets['js_page']);
            foreach ($assets['js_page'] as $item) {
                $tag = p202_shell_asset_tag($item, 'http://x/', p202_shell_defers_page_scripts($ui));
                self::assertMatchesRegularExpression('~^<script src="[^"]+"' . $expected . '></script>$~', $tag, "$ui: $tag");
            }
            foreach ($assets['js_head'] as $item) {
                self::assertStringNotContainsString(' defer', p202_shell_asset_tag($item, 'http://x/'), "$ui: head scripts stay blocking, because inline page scripts call jQuery as they parse");
            }
        }
    }

    public function testTablesortLoadsBeforeTheScriptThatWiresIt(): void
    {
        $order = array_map(
            static fn (array $item): string => $item['asset'] ?? $item['path'],
            p202_shell_assets(P202_UI_V2, ['section' => '202-account', 'sub' => 'ui-kit.php', 'logged_in' => true])['js_page']
        );
        $tablesort = array_search('tablesort.js', $order, true);
        $ui = array_search('202-js/p202-ui.js', $order, true);
        self::assertIsInt($tablesort, 'the v2 shell loads tablesort.js');
        self::assertIsInt($ui);
        self::assertLessThan($ui, $tablesort, 'deferred scripts run in order, so tablesort must come first');
    }

    public function testOnlyAScriptCanBeDeferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_shell_asset_tag(['path' => '202-css/p202-theme.css'], 'http://x/', true);
    }

    public function testOnlyAScriptAssetCanBeDeferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        p202_asset_tag('bootstrap.css', 'http://x/', true);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // datalist is HTML5, which libxml's HTML 4 parser reports and keeps.
        $loaded = $document->loadHTML('<?xml encoding="utf-8"?><!doctype html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded, 'the partial parses');
        return new \DOMXPath($document);
    }

    private function one(\DOMXPath $dom, string $query): \DOMElement
    {
        $nodes = $dom->query($query);
        self::assertNotFalse($nodes, "$query is a valid query");
        self::assertSame(1, $nodes->length, "exactly one $query");
        $node = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        return $node;
    }

    private function nodes(\DOMXPath $dom, string $query): int
    {
        $nodes = $dom->query($query);
        self::assertNotFalse($nodes, "$query is a valid query");
        return $nodes->length;
    }

    private function selectedValue(\DOMXPath $dom, string $id): string
    {
        $selected = $dom->query('//select[@id="' . $id . '"]//option[@selected]');
        self::assertNotFalse($selected);
        self::assertSame(1, $selected->length, "$id has exactly one selected option");
        $option = $selected->item(0);
        self::assertInstanceOf(\DOMElement::class, $option);
        return $option->getAttribute('value');
    }
}

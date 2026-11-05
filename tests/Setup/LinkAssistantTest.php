<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Setup\LinkAssistant;

/**
 * Setup › Campaigns' Link Assistant: a known network's link gets that
 * network's sub id parameter carrying [[subid]], as a suggestion.
 *
 * The rules run twice, in LinkAssistant and in the page's script
 * (202-js/p202-link-assistant.js), so every link here also goes through the
 * script under node (link-assistant-harness.js) and must get the same answer.
 */
final class LinkAssistantTest extends TestCase
{
    /**
     * One link per network as the network hands it out, and the link with
     * the sub id added.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function networkLinks(): array
    {
        return [
            'ClickBank' => [
                'clickbank',
                'https://affiliate.vendor.hop.clickbank.net',
                'https://affiliate.vendor.hop.clickbank.net?tid=[[subid]]',
            ],
            'ClickBank, legacy hop' => [
                'clickbank',
                'http://hop.clickbank.net/?affiliate=aff&vendor=ven',
                'http://hop.clickbank.net/?affiliate=aff&vendor=ven&tid=[[subid]]',
            ],
            'PayKickstart' => [
                'paykickstart',
                'https://paykstrt.com/1234/5678',
                'https://paykstrt.com/1234/5678?tid=[[subid]]',
            ],
            'JVZoo' => [
                'jvzoo',
                'https://www.jvzoo.com/c/12345/67890',
                'https://www.jvzoo.com/c/12345/67890?tid=[[subid]]',
            ],
            'JVZoo, jvz domain' => [
                'jvzoo',
                'https://jvz8.com/c/12345/67890',
                'https://jvz8.com/c/12345/67890?tid=[[subid]]',
            ],
            'WarriorPlus' => [
                'warriorplus',
                'https://warriorplus.com/o2/a/abc123/0',
                'https://warriorplus.com/o2/a/abc123/0/[[subid]]',
            ],
            'WarriorPlus, old /v/ link' => [
                'warriorplus',
                'https://warriorplus.com/o2/v/abc123/45678/',
                'https://warriorplus.com/o2/v/abc123/45678/[[subid]]',
            ],
            'Zaxaa' => ['zaxaa', 'https://zaxaa.com/o/12345678/1', 'https://zaxaa.com/o/12345678/1/[[subid]]'],
            'Rakuten' => [
                'rakuten',
                'https://click.linksynergy.com/fs-bin/click?id=AbCd123&offerid=1.2&type=3',
                'https://click.linksynergy.com/fs-bin/click?id=AbCd123&offerid=1.2&type=3&u1=[[subid]]',
            ],
            'Rakuten deep link' => [
                'rakuten',
                'https://click.linksynergy.com/deeplink?id=Ab1&mid=4&murl=https%3A%2F%2Fshop.example%2F',
                'https://click.linksynergy.com/deeplink?id=Ab1&mid=4&murl=https%3A%2F%2Fshop.example%2F&u1=[[subid]]',
            ],
            'ShareASale' => [
                'shareasale',
                'https://shareasale.com/r.cfm?b=123&u=456&m=789&urllink=',
                'https://shareasale.com/r.cfm?b=123&u=456&m=789&urllink=&afftrack=[[subid]]',
            ],
            'Awin' => [
                'awin',
                'https://www.awin1.com/cread.php?awinmid=1001&awinaffid=2002',
                'https://www.awin1.com/cread.php?awinmid=1001&awinaffid=2002&clickref=[[subid]]',
            ],
            'AvantLink' => [
                'avantlink',
                'https://www.avantlink.com/click.php?tt=cl&mi=10&pw=20&url=https%3A%2F%2Fshop.example',
                'https://www.avantlink.com/click.php?tt=cl&mi=10&pw=20&url=https%3A%2F%2Fshop.example&ctc=[[subid]]',
            ],
            'LinkConnector' => [
                'linkconnector',
                'https://www.linkconnector.com/ta.php?lc=000123000456000789',
                'https://www.linkconnector.com/ta.php?lc=000123000456000789&atid=[[subid]]',
            ],
            'eBay item link' => [
                'ebay',
                'https://www.ebay.com/itm/123456789?mkcid=1&mkrid=711-53200-19255-0&campid=533800',
                'https://www.ebay.com/itm/123456789?mkcid=1&mkrid=711-53200-19255-0&campid=533800&customid=[[subid]]',
            ],
            'Partnerize' => [
                'partnerize',
                'https://prf.hn/click/camref:1100l3',
                'https://prf.hn/click/camref:1100l3/pubref:[[subid]]',
            ],
            'FlexOffers' => [
                'flexoffers',
                'https://track.flexlinkspro.com/a.ashx?foid=1.2.3&foc=1&fot=9999&fos=1',
                'https://track.flexlinkspro.com/a.ashx?foid=1.2.3&foc=1&fot=9999&fos=1&fobs=[[subid]]',
            ],
            'MaxBounty' => [
                'maxbounty',
                'https://afflat3e1.com/lnk.asp?o=1234&c=918277&a=56789&l=1111',
                'https://afflat3e1.com/lnk.asp?o=1234&c=918277&a=56789&l=1111&s2=[[subid]]',
            ],
            'TUNE' => [
                'tune',
                'https://network.go2cloud.org/aff_c?offer_id=12&aff_id=345',
                'https://network.go2cloud.org/aff_c?offer_id=12&aff_id=345&aff_sub=[[subid]]',
            ],
            'CAKE' => [
                'cake',
                'https://track.network.example/?a=123&c=456',
                'https://track.network.example/?a=123&c=456&s2=[[subid]]',
            ],
            'LinkTrust' => [
                'linktrust',
                'https://track.network.example/?cid=12&afid=34',
                'https://track.network.example/?cid=12&afid=34&sid=[[subid]]',
            ],
            'Affise' => [
                'affise',
                'https://offers.network.example/click?pid=12&offer_id=34',
                'https://offers.network.example/click?pid=12&offer_id=34&sub1=[[subid]]',
            ],
            'Post Affiliate Pro' => [
                'postaffiliatepro',
                'https://shop.example/?a_aid=aff1&a_bid=banner2',
                'https://shop.example/?a_aid=aff1&a_bid=banner2&data1=[[subid]]',
            ],
            'iDevAffiliate' => [
                'idevaffiliate',
                'https://shop.example/idevaffiliate/idevaffiliate.php?idev_id=123',
                'https://shop.example/idevaffiliate/idevaffiliate.php?idev_id=123&tid1=[[subid]]',
            ],
            'CJ' => [
                'cj',
                'https://www.anrdoezrs.net/click-1234567-7654321',
                'https://www.anrdoezrs.net/click-1234567-7654321?sid=[[subid]]',
            ],
            'Pepperjam' => [
                'pepperjam',
                'https://www.pjtra.com/t/8-12345-67890-11111',
                'https://www.pjtra.com/t/8-12345-67890-11111?sid=[[subid]]',
            ],
            'impact.com' => [
                'impact',
                'https://brand.sjv.io/c/12345/67890/1234',
                'https://brand.sjv.io/c/12345/67890/1234?subId1=[[subid]]',
            ],
        ];
    }

    /**
     * @dataProvider networkLinks
     */
    public function testANetworksLinkGetsItsSubIdParameter(string $network, string $link, string $expected): void
    {
        $suggestion = LinkAssistant::suggest($link);
        self::assertNotNull($suggestion, "$link is recognised");
        self::assertSame($network, $suggestion['network'], "$link is $network's, not an earlier network's");
        self::assertSame('add', $suggestion['action']);
        self::assertNull($suggestion['previous']);
        self::assertSame($expected, $suggestion['url']);
    }

    public function testEveryNetworkInTheTableHasALinkHere(): void
    {
        $tested = array_unique(array_column(self::networkLinks(), 0));
        $ids = array_column(LinkAssistant::networks(), 'id');
        sort($tested);
        sort($ids);
        self::assertSame($ids, $tested);
    }

    /**
     * Links that are not changed the way a plain add would: existing
     * parameters, fragments, encoding and the network-specific path forms.
     *
     * @return array<string, array{string, string, string, ?string}>
     */
    public static function placements(): array
    {
        return [
            'an empty parameter is filled' => [
                'https://affiliate.vendor.hop.clickbank.net/?tid=',
                'https://affiliate.vendor.hop.clickbank.net/?tid=[[subid]]',
                'fill',
                null,
            ],
            'an empty parameter mid-query is filled in place' => [
                'https://afflat3e1.com/lnk.asp?o=1&c=2&a=3&s2=&s3=x',
                'https://afflat3e1.com/lnk.asp?o=1&c=2&a=3&s2=[[subid]]&s3=x',
                'fill',
                null,
            ],
            'a value the user set is offered for replacement, and named' => [
                'https://network.go2cloud.org/aff_c?offer_id=1&aff_id=2&aff_sub=mine&aff_sub2=x',
                'https://network.go2cloud.org/aff_c?offer_id=1&aff_id=2&aff_sub=[[subid]]&aff_sub2=x',
                'replace',
                'mine',
            ],
            'a parameter is matched by name whatever its case, and keeps its spelling' => [
                'https://brand.sjv.io/c/1/2/3?SUBID1=',
                'https://brand.sjv.io/c/1/2/3?SUBID1=[[subid]]',
                'fill',
                null,
            ],
            'a longer name that starts with the parameter is not it' => [
                'https://track.network.example/?a=1&c=2&s22=x',
                'https://track.network.example/?a=1&c=2&s22=x&s2=[[subid]]',
                'add',
                null,
            ],
            'a fragment stays last' => [
                'https://www.anrdoezrs.net/click-1-2?url=https%3A%2F%2Fshop.example#top',
                'https://www.anrdoezrs.net/click-1-2?url=https%3A%2F%2Fshop.example&sid=[[subid]]#top',
                'add',
                null,
            ],
            'non-ASCII in the path before the sub id' => [
                "https://www.anrdoezrs.net/\u{e4}/click-1-2",
                "https://www.anrdoezrs.net/\u{e4}/click-1-2?sid=[[subid]]",
                'add',
                null,
            ],
            'a fragment without a query' => [
                'https://paykstrt.com/1/2#x',
                'https://paykstrt.com/1/2?tid=[[subid]]#x',
                'add',
                null,
            ],
            'a query ending in ? or & takes no extra joiner' => [
                'https://paykstrt.com/1/2?',
                'https://paykstrt.com/1/2?tid=[[subid]]',
                'add',
                null,
            ],
            'a query ending in &' => [
                'https://paykstrt.com/1/2?x=1&',
                'https://paykstrt.com/1/2?x=1&tid=[[subid]]',
                'add',
                null,
            ],
            'percent-encoding is left as it was' => [
                'https://www.awin1.com/cread.php?awinmid=1&ued=https%3A%2F%2Fshop.test%2Fa%20',
                'https://www.awin1.com/cread.php?awinmid=1&ued=https%3A%2F%2Fshop.test%2Fa%20&clickref=[[subid]]',
                'add',
                null,
            ],
            'surrounding whitespace is the form\'s, not the link\'s' => [
                "  https://paykstrt.com/1/2\n",
                'https://paykstrt.com/1/2?tid=[[subid]]',
                'add',
                null,
            ],
            'Partnerize: a trailing slash' => [
                'https://prf.hn/click/camref:1100l3/',
                'https://prf.hn/click/camref:1100l3/pubref:[[subid]]',
                'add',
                null,
            ],
            'Partnerize: before the destination, which must stay last' => [
                'https://prf.hn/click/camref:1100l3/destination:https://shop.example/a',
                'https://prf.hn/click/camref:1100l3/pubref:[[subid]]/destination:https://shop.example/a',
                'add',
                null,
            ],
            'Partnerize: an existing pubref' => [
                'https://prf.hn/click/camref:1100l3/pubref:mine/adref:x',
                'https://prf.hn/click/camref:1100l3/pubref:[[subid]]/adref:x',
                'replace',
                'mine',
            ],
            'Partnerize: an empty pubref' => [
                'https://prf.hn/click/camref:1100l3/pubref:',
                'https://prf.hn/click/camref:1100l3/pubref:[[subid]]',
                'fill',
                null,
            ],
            'WarriorPlus: an existing sid segment' => [
                'https://warriorplus.com/o2/a/abc123/0/mine',
                'https://warriorplus.com/o2/a/abc123/0/[[subid]]',
                'replace',
                'mine',
            ],
            'WarriorPlus: a query is kept' => [
                'https://warriorplus.com/o2/a/abc123/0?x=1',
                'https://warriorplus.com/o2/a/abc123/0/[[subid]]?x=1',
                'add',
                null,
            ],
            'Zaxaa: an existing sid segment' => [
                'https://zaxaa.com/o/12345678/1/mine',
                'https://zaxaa.com/o/12345678/1/[[subid]]',
                'replace',
                'mine',
            ],
            'Zaxaa: a trailing slash' => [
                'https://zaxaa.com/o/12345678/0/',
                'https://zaxaa.com/o/12345678/0/[[subid]]',
                'add',
                null,
            ],
            'Partnerize: a character lowercasing lengthens comes before the destination' => [
                "https://prf.hn/click/camref:1/\u{130}x/destination:https://shop.example",
                "https://prf.hn/click/camref:1/\u{130}x/pubref:[[subid]]/destination:https://shop.example",
                'add',
                null,
            ],
        ];
    }

    /**
     * @dataProvider placements
     */
    public function testTheSubIdIsPlacedWithoutDisturbingTheRest(
        string $link,
        string $expected,
        string $action,
        ?string $previous
    ): void {
        $suggestion = LinkAssistant::suggest($link);
        self::assertNotNull($suggestion);
        self::assertSame($expected, $suggestion['url']);
        self::assertSame($action, $suggestion['action']);
        self::assertSame($previous, $suggestion['previous']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noSuggestion(): array
    {
        return [
            'empty' => [''],
            'an unknown site' => ['https://shop.example/product?id=12'],
            'the sub id is already there' => ['https://affiliate.vendor.hop.clickbank.net/?tid=[[subid]]'],
            'the user put [[subid]] somewhere else on purpose' => [
                'https://network.go2cloud.org/aff_c?offer_id=1&aff_id=2&aff_sub2=[[subid]]',
            ],
            'the token in another case is still the token' => ['https://paykstrt.com/1/2?tid=[[SUBID]]'],
            'a network name inside another URL\'s query is not its link' => [
                'https://shop.example/?ref=warriorplus.com/o2/a/x/y',
            ],
            'eBay\'s retired rover links' => [
                'https://rover.ebay.com/rover/1/711-53200-19255-0/1?icep_id=114&campid=5338000000',
            ],
            'Zanox, merged into Awin' => ['https://ad.zanox.com/ppc/?12345C67890&ULP=https://shop.example'],
            'a shop path shaped like /c/a/b/c is not an impact.com link' => ['https://shop.example/c/shoes/men/boots'],
            'a Zaxaa id segment is not a 0/1 flag followed by a sub id' => ['https://zaxaa.com/o/1/10'],
            'a network host as a path segment' => ['https://shop.example/hop.clickbank.net/x'],
            'a network host as another host\'s prefix' => ['https://hop.clickbank.net.shop.example/'],
            'a network host as user info' => ['https://paykstrt.com@shop.example/1/2'],
            'a network link nested in the path' => ['https://shop.example/r/https://www.ebay.com/itm/1?campid=5'],
            'non-ASCII before a network name in a value' => ["https://sh\u{f6}p.example/\u{e4}?r=hop.clickbank.net"],
            'a network host in a parameter value' => ['https://shop.example/?r=hop.clickbank.net'],
            'a network link in a parameter value' => ['https://shop.example/go?u=https://www.ebay.com/itm/1?campid=5'],
            'a network path in the fragment' => ['https://shop.example/#/click-1-2'],
            'a Partnerize link in a parameter value' => ['https://shop.example/p?ref=prf.hn/click/camref:1'],
            'Clickbooth\'s retired link host' => ['https://iluv.clickbooth.com/?E=abc123&s1='],
        ];
    }

    /**
     * @dataProvider noSuggestion
     */
    public function testNothingIsSuggestedWhenThereIsNothingToAdd(string $link): void
    {
        self::assertNull(LinkAssistant::suggest($link));
    }

    /**
     * The browser compiles the same strings with `new RegExp(p, 'i')`: a
     * construct only one engine reads would make the two sides disagree.
     */
    public function testThePatternsReadTheSameInBothEngines(): void
    {
        $ids = [];
        foreach (LinkAssistant::networks() as $network) {
            $ids[] = $network['id'];
            self::assertContains($network['place'], ['query', 'pair', 'segment']);
            self::assertMatchesRegularExpression('/^\w+$/', $network['param'], 'used unescaped in both engines');
            self::assertStringNotContainsString('~', $network['match'], 'the PHP delimiter');
            $id = $network['id'];
            $shared = '/\(\?|\\\\[pPAZzGKQEhHRX]|\[\[:|\{,/';
            self::assertDoesNotMatchRegularExpression($shared, $network['match'], "$id uses only shared constructs");
            self::assertNotFalse(@preg_match(LinkAssistant::regex($network['match']), ''), "$id compiles");
        }
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testThePageScriptSuggestsWhatTheClassSuggests(): void
    {
        $found = self::node();
        $urls = array_merge(
            array_column(self::networkLinks(), 1),
            array_column(self::placements(), 0),
            array_column(self::noSuggestion(), 0),
            self::longLinks(),
        );
        $input = tempnam(sys_get_temp_dir(), 'p202-la');
        self::assertNotFalse($input);
        try {
            $json = json_encode(['networks' => LinkAssistant::networks(), 'urls' => $urls], JSON_THROW_ON_ERROR);
            self::assertNotFalse(file_put_contents($input, $json));
            $command = escapeshellarg($found)
                . ' ' . escapeshellarg(__DIR__ . '/link-assistant-harness.js')
                . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-link-assistant.js')
                . ' ' . escapeshellarg($input) . ' 2>&1';
            exec($command, $lines, $status);
        } finally {
            @unlink($input);
        }
        $out = implode("\n", $lines);
        self::assertSame(0, $status, "the page's script ran under node: $out");
        $script = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($script);
        foreach ($urls as $i => $url) {
            self::assertSame(LinkAssistant::suggest($url), $script[$i], 'both agree on ' . json_encode($url));
        }
    }

    /**
     * Every URL field the campaign form prints has its suggestion box, and
     * the page prints the table and script once.
     */
    public function testTheCampaignFormWiresEveryUrlField(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/setup_ui.php';
        $values = [
            'aff_network_id' => '',
            'aff_campaign_name' => '',
            'aff_campaign_url' => '',
            'aff_campaign_payout' => '',
            'aff_campaign_currency' => 'USD',
            'aff_campaign_rotate' => '1',
            'aff_campaign_cloaking' => '0',
            'payout_mode' => 'replace',
            'attribution_model_id' => '',
        ];
        foreach ([2, 3, 4, 5] as $n) {
            $values['aff_campaign_url_' . $n] = '';
        }
        $campaignForm = [
            'values' => $values,
            'errors' => [],
            'categories' => ['1' => 'Shop'],
            'rotation_offered' => true,
            'account_currency' => 'USD',
        ];
        $render = static function (string $file) use ($campaignForm): string {
            ob_start();
            include $file;

            return (string) ob_get_clean();
        };
        $dir = dirname(__DIR__, 2) . '/tracking202/setup/_includes/campaign_form/';
        $html = $render($dir . 'offer.php') . $render($dir . 'advanced.php');
        $fields = ['aff_campaign_url', 'aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4',
            'aff_campaign_url_5'];
        foreach ($fields as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html);
            $boxes = substr_count($html, 'data-p202-link-assistant="#' . $id . '"');
            self::assertSame(1, $boxes, "$id has one suggestion box");
        }
        // The page needs a database to render; its call is one template line
        // of its own, at the top level, like the Setup script tag beside it.
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/setup/aff_campaigns.php');
        $line = '~^<\?php echo p202_setup_link_assistant_script\(\$base\); \?>$~m';
        self::assertSame(1, preg_match_all($line, $page));
        self::assertSame(1, preg_match_all('~^<\?php echo p202_setup_script_tag\(\$base\); \?>$~m', $page));
    }

    /**
     * Pasted links can be long; no pattern may backtrack into a PCRE error,
     * which would read as "no network" on one side only.
     *
     * @return list<string>
     */
    private static function longLinks(): array
    {
        $params = str_repeat('x=1&', 4000);

        return [
            'https://www.ebay.com/itm/1?' . $params . 'campid=5',
            'https://www.ebay.com/itm/1?' . $params,
            'https://shop.example/' . str_repeat('c/', 4000) . '?' . $params,
        ];
    }

    public function testALongLinkIsReadWithoutAPatternError(): void
    {
        self::assertSame('ebay', LinkAssistant::suggest(self::longLinks()[0])['network'] ?? null);
        foreach (self::longLinks() as $link) {
            LinkAssistant::suggest($link);
            self::assertSame(PREG_NO_ERROR, preg_last_error(), preg_last_error_msg());
            foreach (LinkAssistant::networks() as $network) {
                self::assertNotFalse(preg_match(LinkAssistant::regex($network['match']), $link), $network['id']);
            }
        }
    }

    /**
     * The page wiring, run against a stand-in document: a typed link shows
     * the box, the field changes only on the button, "No thanks" hides it.
     */
    public function testThePageWiringSuggestsAndAppliesOnlyOnAClick(): void
    {
        $node = self::node();
        $input = tempnam(sys_get_temp_dir(), 'p202-la');
        self::assertNotFalse($input);
        try {
            $json = json_encode(LinkAssistant::networks(), JSON_THROW_ON_ERROR);
            self::assertNotFalse(file_put_contents($input, $json));
            $command = escapeshellarg($node)
                . ' ' . escapeshellarg(__DIR__ . '/link-assistant-dom-harness.js')
                . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/202-js/p202-link-assistant.js')
                . ' ' . escapeshellarg($input) . ' 2>&1';
            exec($command, $lines, $status);
        } finally {
            @unlink($input);
        }
        $out = implode("\n", $lines);
        self::assertSame(0, $status, "the page wiring ran under node: $out");
        $seen = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['hidden' => true, 'buttons' => []], $seen['atLoad']);
        self::assertSame(['hidden' => false, 'buttons' => ['Add [[subid]]', 'No thanks']], $seen['typed']);
        self::assertSame('https://paykstrt.com/1/2', $seen['valueBeforeClick'], 'nothing changes before the click');
        self::assertTrue($seen['sameButtonAfterChange'], 'the blur before a click does not rebuild the button');
        self::assertSame('https://paykstrt.com/1/2?tid=[[subid]]', $seen['valueAfterClick']);
        self::assertSame(['hidden' => true, 'buttons' => []], $seen['afterApply']);
        self::assertSame(['hidden' => false, 'buttons' => ['Replace', 'No thanks']], $seen['replace']);
        self::assertSame(['hidden' => true, 'buttons' => []], $seen['afterDismiss']);
        $kept = 'https://network.go2cloud.org/aff_c?offer_id=1&aff_id=2&aff_sub=mine';
        self::assertSame($kept, $seen['valueAfterDismiss'], 'No thanks leaves the field alone');
        self::assertSame(['hidden' => true, 'buttons' => []], $seen['unknown']);
    }

    private static function node(): string
    {
        $found = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($found === '') {
            // CI's runners carry node: a skip there would leave the seam unchecked.
            if (getenv('CI') !== false && getenv('CI') !== '') {
                self::fail('node is not on PATH in CI; the page script must be run');
            }
            self::markTestSkipped('node is not installed; the page script cannot be run here');
        }

        return $found;
    }

    public function testThePageCarriesTheTableAndTheScript(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/setup_ui.php';
        $html = p202_setup_link_assistant_script('https://track.example/p202/');
        $table = '~<script type="application/json" id="p202-link-networks">(.*?)</script>~';
        self::assertSame(1, preg_match($table, $html, $m));
        self::assertSame(LinkAssistant::networks(), json_decode($m[1], true, 512, JSON_THROW_ON_ERROR));
        $hash = substr((string) md5_file(dirname(__DIR__, 2) . '/202-js/p202-link-assistant.js'), 0, 12);
        $src = 'https://track.example/p202/202-js/p202-link-assistant.js?v=' . $hash;
        self::assertStringContainsString('<script src="' . $src . '" defer></script>', $html, 'versioned by content');
        self::assertSame(
            '<div class="mt-2" data-p202-link-assistant="#aff_campaign_url" hidden></div>',
            p202_setup_link_assistant('aff_campaign_url')
        );
    }
}

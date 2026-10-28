<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\SetupCodeController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Setup\LandingPageCode;
use Prosper202\Setup\PostbackCode;

/**
 * GET /landing-pages/{id}/code and GET /conversions/postback-code against a
 * real database: the code is LandingPageCode's and PostbackCode's on the
 * caller's tracking domain, for the caller's own live landing pages, campaigns
 * and redirectors only, and every refusal of the pages — no offer chosen,
 * an offer that is not yours or was removed — is made here too, along with
 * what the pages could not be asked (a malformed offer, a value that would
 * break a snippet, a parameter nobody reads).
 *
 * Skips without a scratch database (SetupScratchDatabase); writes users
 * 5301 and 5302's rows.
 *
 * @group integration
 */
final class SetupCodeIntegrationTest extends TestCase
{
    use SetupScratchDatabase;

    private const USER = 5301;
    private const OTHER = 5302;
    private const NOW = 1791374400;

    /** @var array<string, int> */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        self::connectScratchDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::restoreScratchDatabase();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_landing_pages', '202_aff_campaigns', '202_aff_networks', '202_rotators'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratchDatabase();
        self::cleanUp();
        self::setTrackingDomain('track.example.com');
        foreach ([self::USER => 'mine', self::OTHER => 'theirs'] as $user => $tag) {
            $network = self::exec("INSERT INTO 202_aff_networks SET user_id = $user, aff_network_name = 'cat $tag', aff_network_time = 0");
            $this->ids["network_$tag"] = $network;
            $this->ids["campaign_$tag"] = $this->campaign($user, $network, "Offer $tag");
            $this->ids["rotator_$tag"] = self::exec("INSERT INTO 202_rotators SET user_id = $user, public_id = " . (7000000 + $user) . ", name = 'Split $tag'");
            $this->ids["simple_$tag"] = $this->landingPage($user, $this->ids["campaign_$tag"], 0, "https://lp.example/$tag");
            $this->ids["advanced_$tag"] = $this->landingPage($user, $this->ids["campaign_$tag"], 1, "https://lp.example/adv-$tag");
        }
    }

    private function campaign(int $user, int $network, string $name, int $deleted = 0): int
    {
        $id = self::exec("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = $network, aff_campaign_name = '$name', aff_campaign_url = 'https://offer.example/', aff_campaign_payout = 1, aff_campaign_time = 0, aff_campaign_foreign_payout = 0, aff_campaign_deleted = $deleted");
        self::exec("UPDATE 202_aff_campaigns SET aff_campaign_id_public = CONCAT('3', aff_campaign_id, '1') WHERE aff_campaign_id = $id");

        return $id;
    }

    private function landingPage(int $user, int $campaign, int $type, string $url, int $deleted = 0): int
    {
        $id = self::exec("INSERT INTO 202_landing_pages SET user_id = $user, aff_campaign_id = $campaign, landing_page_nickname = 'LP $type', landing_page_url = '$url', landing_page_type = $type, landing_page_time = 0, landing_page_deleted = $deleted");
        self::exec("UPDATE 202_landing_pages SET landing_page_id_public = CONCAT('6', landing_page_id, '2') WHERE landing_page_id = $id");

        return $id;
    }

    private function controller(int $user = self::USER): SetupCodeController
    {
        return new SetupCodeController(self::requireScratchDatabase(), $user);
    }

    private function publicId(string $table, string $column, string $key, int $id): string
    {
        return (string) self::row("SELECT $column FROM $table WHERE $key = $id")[$column];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('the request was answered, not refused');
    }

    public function testASimplePageGetsThePagesCodeOnTheTrackingDomain(): void
    {
        $id = $this->ids['simple_mine'];
        $public = $this->publicId('202_landing_pages', 'landing_page_id_public', 'landing_page_id', $id);
        $data = $this->controller()->landingPageCode($id, [], self::server(), self::NOW)['data'];

        $base = '//track.example.com/';
        self::assertSame('simple', $data['landing_page_type']);
        self::assertSame($base, $data['base_url'], 'the caller\'s domain (user 1\'s is owner.example), scheme-relative as the page writes it');
        self::assertSame(LandingPageCode::loader($base, $public), $data['loader']);
        self::assertSame($base . 'tracking202/redirect/go.php?lpip=' . $public, $data['outbound_link']);
        self::assertSame(LandingPageCode::simpleOutboundPhp($base, $public, 'https://lp.example/mine', self::NOW), $data['outbound_php']);
        self::assertSame(LandingPageCode::simpleOutboundJavascript($base, $public), $data['outbound_javascript']);
        self::assertSame($this->ids['campaign_mine'], $data['aff_campaign_id']);
        self::assertArrayNotHasKey('offers', $data);
        self::assertSame(LandingPageCode::SEGMENTS, $data['segments']);
    }

    public function testWithNoDomainTheCodeIsOnThisServer(): void
    {
        self::setTrackingDomain('');
        $data = $this->controller()->landingPageCode($this->ids['simple_mine'], [], self::server(), self::NOW)['data'];
        self::assertSame('//server.example/', $data['base_url']);
    }

    /**
     * With no domain stored, the code is on the host the request came in on:
     * behind a proxy, or a container whose port is published elsewhere, the
     * server's own name and port (server.example:8080) are not an address
     * the caller can reach, and the code built on them was a dead link.
     */
    public function testWithNoDomainTheCodeIsOnTheHostTheRequestCameIn(): void
    {
        self::setTrackingDomain('');
        $server = ['SERVER_PORT' => 8080, 'HTTP_HOST' => 'proxy.example:9443'] + self::server();
        $data = $this->controller()->landingPageCode($this->ids['simple_mine'], [], $server, self::NOW)['data'];
        self::assertSame('//proxy.example:9443/', $data['base_url']);
        $postback = $this->controller()->postbackCode([], $server)['data'];
        $url = $postback['simple']['postback_url'];
        self::assertStringStartsWith('https://proxy.example:9443/tracking202/static/', $url);
    }

    public function testAnAdvancedPageGetsTheCodeForEachOfferInOrder(): void
    {
        $id = $this->ids['advanced_mine'];
        $campaign = $this->ids['campaign_mine'];
        $rotator = $this->ids['rotator_mine'];
        $data = $this->controller()->landingPageCode($id, ['offers' => "rotator:$rotator,campaign:$campaign"], self::server(), self::NOW)['data'];

        $base = '//track.example.com/';
        $campaignPublic = $this->publicId('202_aff_campaigns', 'aff_campaign_id_public', 'aff_campaign_id', $campaign);
        $rotatorPublic = (string) (7000000 + self::USER);
        self::assertSame('advanced', $data['landing_page_type']);
        self::assertArrayNotHasKey('outbound_link', $data);
        self::assertCount(2, $data['offers']);
        [$first, $second] = $data['offers'];
        self::assertSame([1, 'rotator', $rotator, 'Split mine'], [$first['position'], $first['type'], $first['id'], $first['name']]);
        self::assertSame($base . 'tracking202/redirect/go.php?rpi=' . $rotatorPublic, $first['outbound_link']);
        self::assertSame(LandingPageCode::rotatorOutboundPhp($base, $rotatorPublic, 'Split mine', 'https://lp.example/adv-mine', self::NOW), $first['outbound_php']);
        self::assertSame([2, 'campaign', $campaign, 'Offer mine'], [$second['position'], $second['type'], $second['id'], $second['name']]);
        self::assertSame($base . 'tracking202/redirect/go.php?acip=' . $campaignPublic, $second['outbound_link']);
        self::assertSame(LandingPageCode::campaignOutboundPhp($base, $campaignPublic, 'Offer mine', 'https://lp.example/adv-mine', self::NOW), $second['outbound_php']);
    }

    public function testThePagesRefusalsAreMade(): void
    {
        $advanced = $this->ids['advanced_mine'];
        // The advanced page with no offer chosen.
        self::assertArrayHasKey('offers', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, [], self::server())));
        // An offer that is another account's.
        $theirs = $this->ids['campaign_theirs'];
        $errors = $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => "campaign:{$this->ids['campaign_mine']},campaign:$theirs"], self::server()));
        self::assertSame(['offers[2]' => 'Offer 2: that campaign is not yours, or it was removed.'], $errors);
        $errors = $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => 'rotator:' . $this->ids['rotator_theirs']], self::server()));
        self::assertSame(['offers[1]' => 'Offer 1: that redirector is not yours, or it was removed.'], $errors);
        // A removed campaign, and a live one in a removed category: the
        // page's list offers neither.
        $removed = $this->campaign(self::USER, $this->ids['network_mine'], 'Gone', 1);
        self::assertArrayHasKey('offers[1]', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => "campaign:$removed"], self::server())));
        $network = self::exec('INSERT INTO 202_aff_networks SET user_id = ' . self::USER . ", aff_network_name = 'gone cat', aff_network_time = 0, aff_network_deleted = 1");
        $orphan = $this->campaign(self::USER, $network, 'Orphan');
        self::assertArrayHasKey('offers[1]', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => "campaign:$orphan"], self::server())));
    }

    public function testOffersAreReadAsSent(): void
    {
        $advanced = $this->ids['advanced_mine'];
        $campaign = $this->ids['campaign_mine'];
        foreach (["campaign:0", "campaign:1e3", "campaign:0$campaign", "campaign: $campaign", "campaign:$campaign,", 'rotator:', 'lp:1', "campaign:$campaign.0", '', ' '] as $offers) {
            $errors = $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => $offers], self::server()));
            self::assertNotSame([], $errors, json_encode($offers) . ' is refused');
            foreach (array_keys($errors) as $key) {
                self::assertStringStartsWith('offers', $key);
            }
        }
        self::assertArrayHasKey('offers', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => ["campaign:$campaign"]], self::server())), 'a list is not the comma-separated string');
        self::assertArrayHasKey('offers', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offers' => implode(',', array_fill(0, 101, "campaign:$campaign"))], self::server())));
        self::assertArrayHasKey('offer', $this->refusal(fn () => $this->controller()->landingPageCode($advanced, ['offer' => "campaign:$campaign"], self::server())), 'a misspelled parameter is refused by name');
        self::assertArrayHasKey('offers', $this->refusal(fn () => $this->controller()->landingPageCode($this->ids['simple_mine'], ['offers' => "campaign:$campaign"], self::server())), 'a simple page has no offers');
    }

    public function testOnlyTheCallersLiveLandingPagesHaveCode(): void
    {
        foreach ([$this->ids['simple_theirs'], $this->ids['advanced_theirs'], $this->landingPage(self::USER, $this->ids['campaign_mine'], 0, 'https://lp.example/gone', 1), 999999] as $id) {
            try {
                $this->controller()->landingPageCode($id, [], self::server());
                self::fail("landing page $id answered");
            } catch (NotFoundException $e) {
                self::assertSame("Landing page $id not found", $e->getMessage());
            }
        }
    }

    public function testASimplePageWhoseCampaignWasRemovedHasNoCode(): void
    {
        $removed = $this->campaign(self::USER, $this->ids['network_mine'], 'Gone', 1);
        $page = $this->landingPage(self::USER, $removed, 0, 'https://lp.example/orphan');
        self::assertArrayHasKey('aff_campaign_id', $this->refusal(fn () => $this->controller()->landingPageCode($page, [], self::server())));
        $odd = $this->landingPage(self::USER, $this->ids['campaign_mine'], 2, 'https://lp.example/odd');
        self::assertArrayHasKey('landing_page_type', $this->refusal(fn () => $this->controller()->landingPageCode($odd, [], self::server())));
    }

    public function testMissingPublicIdsAreGivenBeforeTheyAreWrittenIntoCode(): void
    {
        $page = $this->ids['simple_mine'];
        self::exec("UPDATE 202_landing_pages SET landing_page_id_public = NULL WHERE landing_page_id = $page");
        $data = $this->controller()->landingPageCode($page, [], self::server())['data'];
        $public = $this->publicId('202_landing_pages', 'landing_page_id_public', 'landing_page_id', $page);
        self::assertNotSame('', $public);
        self::assertStringEndsWith('go.php?lpip=' . $public, $data['outbound_link']);

        $campaign = $this->ids['campaign_mine'];
        self::exec("UPDATE 202_aff_campaigns SET aff_campaign_id_public = NULL WHERE aff_campaign_id = $campaign");
        $offer = $this->controller()->landingPageCode($this->ids['advanced_mine'], ['offers' => "campaign:$campaign"], self::server())['data']['offers'][0];
        $public = $this->publicId('202_aff_campaigns', 'aff_campaign_id_public', 'aff_campaign_id', $campaign);
        self::assertMatchesRegularExpression('/^[1-9]' . $campaign . '[1-9]$/', $public, 'the page\'s rand-id-rand');
        self::assertStringEndsWith('go.php?acip=' . $public, $offer['outbound_link']);
    }

    public function testThePostbackCodeIsThePagesForTheValuesGiven(): void
    {
        $data = $this->controller()->postbackCode([], self::server())['data'];
        $root = 'https://track.example.com/tracking202/static/';
        self::assertSame(['https', $root, '', '', null], [$data['scheme'], $data['base_url'], $data['amount'], $data['subid'], $data['campaign_id']]);
        $defaults = PostbackCode::snippets($root, '', '', '');
        foreach (['simple', 'advanced', 'universal'] as $type) {
            self::assertSame($defaults[$type], $data[$type]);
        }

        $campaign = $this->ids['campaign_mine'];
        $data = $this->controller()->postbackCode(['amount' => '{payout}', 'subid' => '#s2#', 'campaign_id' => (string) $campaign, 'scheme' => 'http'], self::server())['data'];
        $root = 'http://track.example.com/tracking202/static/';
        self::assertSame($root . "gpb.php?amount={payout}&cid=$campaign&subid=#s2#", $data['advanced']['postback_url']);
        self::assertSame($root . 'gpb.php?amount={payout}&subid=#s2#', $data['simple']['postback_url']);
        self::assertSame($campaign, $data['campaign_id']);

        self::setTrackingDomain('https://secure.example.com/');
        self::assertSame('https://secure.example.com/tracking202/static/', $this->controller()->postbackCode([], self::server(false))['data']['base_url'], 'a domain stored with its scheme keeps it');
    }

    public function testThePostbackCodeRefusesWhatItCannotWrite(): void
    {
        $code = fn (array $query): array => $this->refusal(fn () => $this->controller()->postbackCode($query, self::server()));
        self::assertArrayHasKey('campaign_id', $code(['campaign_id' => (string) $this->ids['campaign_theirs']]));
        self::assertArrayHasKey('campaign_id', $code(['campaign_id' => (string) $this->campaign(self::USER, $this->ids['network_mine'], 'Gone', 1)]));
        foreach (['0', '1.0', '01', '1e3', ' 5', ''] as $raw) {
            self::assertArrayHasKey('campaign_id', $code(['campaign_id' => $raw]), json_encode($raw) . ' is not a campaign id');
        }
        self::assertArrayHasKey('campaign_id', $code(['campaign_id' => [(string) $this->ids['campaign_mine']]]));
        self::assertArrayHasKey('subid', $code(['subid' => 'a b']));
        self::assertArrayHasKey('amount', $code(['amount' => '1"><script>']));
        self::assertArrayHasKey('amount', $code(['amount' => ['1']]));
        self::assertArrayHasKey('scheme', $code(['scheme' => 'ftp']));
        self::assertArrayHasKey('scheme', $code(['scheme' => 'HTTPS']));
        self::assertArrayHasKey('cid', $code(['cid' => '5']), 'the page\'s cid is campaign_id here; cid is refused by name');
    }
}

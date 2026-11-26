<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A Setup record links only to the caller's own records, as the Setup pages
 * require ("You are not authorized to add a campaign to another user's
 * network", generate_tracking_link.php's owned-field check). The API took
 * every linked id as sent, so a key could file its campaign under another
 * account's category, or build a tracker on another account's campaign,
 * traffic source account, landing page, text ad or redirector — whose names
 * the tracker's reads then served back (CLAUDE.md #5).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5801 and 5802's rows.
 *
 * @group integration
 */
final class OwnedReferencesIntegrationTest extends TestCase
{
    private const USER = 5801;
    private const OTHER = 5802;

    private static ?\mysqli $db = null;

    /** @var array<string, array<string, int>> who => entity => id */
    private array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_trackers', '202_text_ads', '202_landing_pages', '202_aff_campaigns', '202_aff_networks', '202_ppc_accounts', '202_ppc_networks', '202_rotators'] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    /** One of each Setup record for $user; $deleted marks them removed. */
    private static function records(int $user, bool $deleted = false): array
    {
        $d = $deleted ? 1 : 0;
        $network = self::q("INSERT INTO 202_aff_networks SET user_id = $user, aff_network_name = 'n$user$d', aff_network_deleted = $d, aff_network_time = 0");
        $campaign = self::q("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = $network, aff_campaign_name = 'c$user$d', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0, aff_campaign_deleted = $d");
        $source = self::q("INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = 's$user$d', ppc_network_deleted = $d, ppc_network_time = 0");
        $account = self::q("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = $source, ppc_account_name = 'a$user$d', ppc_account_deleted = $d, ppc_account_time = 0");
        $lp = self::q("INSERT INTO 202_landing_pages SET user_id = $user, aff_campaign_id = $campaign, landing_page_url = 'https://lp.example', landing_page_nickname = 'l$user$d', landing_page_deleted = $d, landing_page_time = 0");
        $ad = self::q("INSERT INTO 202_text_ads SET user_id = $user, aff_campaign_id = $campaign, landing_page_id = 0, text_ad_name = 't$user$d', text_ad_headline = 'h', text_ad_description = 'd', text_ad_display_url = 'x', text_ad_deleted = $d, text_ad_time = 0");
        $rotator = self::q("INSERT INTO 202_rotators SET user_id = $user, public_id = " . random_int(100000000, 999999999) . ", name = 'r$user$d', default_url = 'https://r.example'");
        if ($deleted) {
            // A redirector is removed outright; it has no deleted flag.
            self::q("DELETE FROM 202_rotators WHERE id = $rotator");
        }

        return ['aff_network_id' => $network, 'aff_campaign_id' => $campaign, 'ppc_network_id' => $source, 'ppc_account_id' => $account, 'landing_page_id' => $lp, 'text_ad_id' => $ad, 'rotator_id' => $rotator];
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        $this->ids = ['mine' => self::records(self::USER), 'removed' => self::records(self::USER, true), 'theirs' => self::records(self::OTHER)];
    }

    /** controller, a create body over the caller's own records, the linked fields */
    private function cases(): array
    {
        $m = $this->ids['mine'];

        return [
            'campaign' => [new CampaignsController(self::$db, self::USER), ['aff_campaign_name' => 'x', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => $m['aff_network_id']], ['aff_network_id']],
            'ppc account' => [new PpcAccountsController(self::$db, self::USER), ['ppc_account_name' => 'x', 'ppc_network_id' => $m['ppc_network_id']], ['ppc_network_id']],
            'landing page' => [new LandingPagesController(self::$db, self::USER), ['landing_page_url' => 'https://lp.example', 'landing_page_nickname' => 'x', 'aff_campaign_id' => $m['aff_campaign_id']], ['aff_campaign_id']],
            'text ad' => [new TextAdsController(self::$db, self::USER), ['text_ad_name' => 'x', 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'x', 'aff_campaign_id' => $m['aff_campaign_id'], 'landing_page_id' => $m['landing_page_id']], ['aff_campaign_id', 'landing_page_id']],
            'tracker' => [new TrackersController(self::$db, self::USER), ['aff_campaign_id' => $m['aff_campaign_id'], 'ppc_account_id' => $m['ppc_account_id'], 'landing_page_id' => $m['landing_page_id'], 'text_ad_id' => $m['text_ad_id'], 'rotator_id' => $m['rotator_id']], ['aff_campaign_id', 'ppc_account_id', 'landing_page_id', 'text_ad_id', 'rotator_id']],
        ];
    }

    private function assertRefused(callable $write, string $field, string $what): void
    {
        try {
            $write();
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors(), "$what: refused for the wrong field: " . json_encode($e->getFieldErrors()));

            return;
        }
        self::fail("$what was written");
    }

    private function rowCount(string $table): int
    {
        return (int) self::$db->query("SELECT COUNT(*) FROM $table WHERE user_id = " . self::USER)->fetch_row()[0];
    }

    public function testACreateLinksOnlyToTheCallersOwnLiveRecords(): void
    {
        foreach ($this->cases() as $name => [$controller, $body, $linked]) {
            $created = $controller->create($body)['data'];
            foreach ($linked as $field) {
                self::assertSame((int) $body[$field], (int) $created[$field], "$name: its own $field links");
            }
            foreach ($linked as $field) {
                foreach (['theirs' => "another account's", 'removed' => 'a removed'] as $who => $label) {
                    $before = $this->rows($controller);
                    $this->assertRefused(fn () => $controller->create([$field => $this->ids[$who][$field]] + $body), $field, "$name naming $label $field");
                    self::assertSame($before, $this->rows($controller), "$name: a refused create wrote a row");
                }
            }
        }
    }

    public function testAnUpdateMayNotRelinkElsewhereButMayResendWhatItHas(): void
    {
        foreach ($this->cases() as $name => [$controller, $body, $linked]) {
            $pk = ['campaign' => 'aff_campaign_id', 'ppc account' => 'ppc_account_id', 'landing page' => 'landing_page_id', 'text ad' => 'text_ad_id', 'tracker' => 'tracker_id'][$name];
            $id = (int) $controller->create($body)['data'][$pk];
            foreach ($linked as $field) {
                $this->assertRefused(fn () => $controller->update($id, [$field => $this->ids['theirs'][$field]]), $field, "$name relinked to another account's $field");
                $this->assertRefused(fn () => $controller->update($id, [$field => $this->ids['removed'][$field]]), $field, "$name relinked to a removed $field");
                self::assertSame((int) $body[$field], (int) $controller->get($id)['data'][$field], "$name: a refused update changed $field");
            }
            // A record whose link was removed after it was made is not stuck:
            // re-sending the value it already holds (a full PUT, a sync) passes.
            $first = $linked[0];
            $table = ['campaign' => '202_aff_networks', 'ppc account' => '202_ppc_networks', 'landing page' => '202_aff_campaigns', 'text ad' => '202_aff_campaigns', 'tracker' => '202_aff_campaigns'][$name];
            $col = ['202_aff_networks' => 'aff_network', '202_ppc_networks' => 'ppc_network', '202_aff_campaigns' => 'aff_campaign'][$table];
            self::$db->query("UPDATE $table SET {$col}_deleted = 1 WHERE {$col}_id = " . (int) $body[$first]);
            $controller->update($id, [$first => $body[$first]]);
            self::$db->query("UPDATE $table SET {$col}_deleted = 0 WHERE {$col}_id = " . (int) $body[$first]);
        }
    }

    public function testOptionalLinksMayBeNoneButARequiredOneMayNot(): void
    {
        $m = $this->ids['mine'];
        $tracker = (new TrackersController(self::$db, self::USER))->create(['aff_campaign_id' => $m['aff_campaign_id'], 'ppc_account_id' => 0, 'landing_page_id' => 0, 'text_ad_id' => 0, 'rotator_id' => 0])['data'];
        self::assertSame(0, (int) $tracker['landing_page_id'], 'a direct-link tracker has no landing page');
        $ad = (new TextAdsController(self::$db, self::USER))->create(['text_ad_name' => 'x', 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'x'])['data'];
        self::assertSame(0, (int) $ad['aff_campaign_id']);

        $campaigns = new CampaignsController(self::$db, self::USER);
        $this->assertRefused(fn () => $campaigns->create(['aff_campaign_name' => 'x', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => 0]), 'aff_network_id', 'a campaign in no category');
        $this->assertRefused(fn () => (new TrackersController(self::$db, self::USER))->create(['aff_campaign_id' => 0]), 'aff_campaign_id', 'a tracker on no campaign');
    }

    /**
     * Where a Setup page stores a required link as 0, the API takes 0 too:
     * an advanced landing page (type 1) has no campaign (landing_pages.php
     * posts aff_campaign_id 0 for it), and Get Links stores no campaign for
     * a redirector's link or an advanced landing page's
     * (generate_tracking_link.php asks for one only for tracker_type 0).
     * The ownership check refused all three, so the API could make none of
     * them. A simple page, a direct link and a simple landing page's link
     * still need their campaign, and an update that turns a record into
     * one that needs it, leaving it at 0, is refused.
     */
    public function testARequiredLinkIsNoneExactlyWhereThePagesStoreNone(): void
    {
        $m = $this->ids['mine'];
        $pages = new LandingPagesController(self::$db, self::USER);
        $trackers = new TrackersController(self::$db, self::USER);

        $advanced = $pages->create(['landing_page_url' => 'https://adv.example', 'landing_page_nickname' => 'adv', 'landing_page_type' => 1, 'aff_campaign_id' => 0])['data'];
        self::assertSame([1, 0], [(int) $advanced['landing_page_type'], (int) $advanced['aff_campaign_id']], 'an advanced landing page has no campaign');
        $this->assertRefused(fn () => $pages->create(['landing_page_url' => 'https://s.example', 'landing_page_nickname' => 's', 'landing_page_type' => 0, 'aff_campaign_id' => 0]), 'aff_campaign_id', 'a simple landing page on no campaign');
        $this->assertRefused(fn () => $pages->create(['landing_page_url' => 'https://s.example', 'landing_page_nickname' => 's', 'aff_campaign_id' => 0]), 'aff_campaign_id', 'a landing page of the default (simple) type on no campaign');

        $redirector = $trackers->create(['aff_campaign_id' => 0, 'rotator_id' => $m['rotator_id']])['data'];
        self::assertSame([0, $m['rotator_id']], [(int) $redirector['aff_campaign_id'], (int) $redirector['rotator_id']], "a redirector's link has no campaign");
        $advancedLink = $trackers->create(['aff_campaign_id' => 0, 'landing_page_id' => (int) $advanced['landing_page_id']])['data'];
        self::assertSame(0, (int) $advancedLink['aff_campaign_id'], "an advanced landing page's link has no campaign");
        $this->assertRefused(fn () => $trackers->create(['aff_campaign_id' => 0, 'landing_page_id' => $m['landing_page_id']]), 'aff_campaign_id', "a simple landing page's link on no campaign");
        $this->assertRefused(fn () => $trackers->create(['aff_campaign_id' => 0, 'landing_page_id' => $this->ids['theirs']['landing_page_id']]), 'aff_campaign_id', "another account's landing page does not excuse the campaign");
        self::$db->query('UPDATE 202_landing_pages SET landing_page_type = 1 WHERE landing_page_id = ' . (int) $this->ids['removed']['landing_page_id']);
        $this->assertRefused(fn () => $trackers->create(['aff_campaign_id' => 0, 'landing_page_id' => $this->ids['removed']['landing_page_id']]), 'aff_campaign_id', 'a removed advanced landing page does not excuse the campaign');

        // Re-sent as they are, they save; changed into what needs a campaign, they do not.
        $pages->update((int) $advanced['landing_page_id'], ['aff_campaign_id' => 0, 'landing_page_type' => 1, 'landing_page_nickname' => 'adv2']);
        $trackers->update((int) $redirector['tracker_id'], ['aff_campaign_id' => 0, 'rotator_id' => $m['rotator_id']]);
        $this->assertRefused(fn () => $pages->update((int) $advanced['landing_page_id'], ['landing_page_type' => 0]), 'aff_campaign_id', 'an advanced page made simple with no campaign');
        $this->assertRefused(fn () => $trackers->update((int) $redirector['tracker_id'], ['rotator_id' => 0]), 'aff_campaign_id', "a redirector's link made a direct link with no campaign");
        $this->assertRefused(fn () => $trackers->update((int) $redirector['tracker_id'], ['rotator_id' => 0, 'aff_campaign_id' => 0]), 'aff_campaign_id', 'the same, re-sending the 0');
        self::assertSame($m['rotator_id'], (int) $trackers->get((int) $redirector['tracker_id'])['data']['rotator_id'], 'the refused updates changed nothing');
        $trackers->update((int) $redirector['tracker_id'], ['rotator_id' => 0, 'aff_campaign_id' => $m['aff_campaign_id']]);
    }

    /**
     * An advanced landing page's ad (text_ad_type 1) names an advanced page,
     * as Setup › Text Ads offers only those: Get Links offers an ad on the
     * advanced page it names, so one naming a simple page was stored with a
     * 201 and never offered. A campaign's ad may name a simple page.
     */
    public function testAnAdvancedLandingPagesAdNamesAnAdvancedPage(): void
    {
        $m = $this->ids['mine'];
        $ads = new TextAdsController(self::$db, self::USER);
        $advanced = (int) (new LandingPagesController(self::$db, self::USER))->create(['landing_page_url' => 'https://adv.example', 'landing_page_nickname' => 'adv', 'landing_page_type' => 1, 'aff_campaign_id' => 0])['data']['landing_page_id'];
        $body = ['text_ad_name' => 'x', 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'x', 'text_ad_type' => 1, 'aff_campaign_id' => 0];

        $before = $this->rowCount('202_text_ads');
        $this->assertRefused(fn () => $ads->create(['landing_page_id' => $m['landing_page_id']] + $body), 'landing_page_id', 'an advanced ad on a simple page');
        self::assertSame($before, $this->rowCount('202_text_ads'), 'a refused create wrote a row');

        $ad = (int) $ads->create(['landing_page_id' => $advanced] + $body)['data']['text_ad_id'];
        $this->assertRefused(fn () => $ads->update($ad, ['landing_page_id' => $m['landing_page_id']]), 'landing_page_id', 'an advanced ad moved to a simple page');
        $campaignAd = (int) $ads->create(['text_ad_type' => 0, 'aff_campaign_id' => $m['aff_campaign_id'], 'landing_page_id' => $m['landing_page_id']] + $body)['data']['text_ad_id'];
        $this->assertRefused(fn () => $ads->update($campaignAd, ['text_ad_type' => 1]), 'landing_page_id', "a campaign's ad on a simple page made an advanced one");
        $stored = static function (int $id) use ($ads): array {
            $data = (array) $ads->get($id)['data'];

            return ['landing_page_id' => (int) $data['landing_page_id'], 'text_ad_type' => (int) $data['text_ad_type']];
        };
        self::assertSame(['landing_page_id' => $advanced, 'text_ad_type' => 1], $stored($ad), 'the refused update changed the ad');
        self::assertSame(['landing_page_id' => $m['landing_page_id'], 'text_ad_type' => 0], $stored($campaignAd), 'the refused update changed the ad');

        // The page a type change leaves the ad on is read too, whosever it is.
        self::q("UPDATE 202_text_ads SET landing_page_id = {$this->ids['theirs']['landing_page_id']} WHERE text_ad_id = $campaignAd");
        $this->assertRefused(fn () => $ads->update($campaignAd, ['text_ad_type' => 1]), 'landing_page_id', "a campaign's ad on another account's page made an advanced one");

        // An ad stored on a simple page before this was checked saves as it is.
        self::q("UPDATE 202_text_ads SET landing_page_id = {$m['landing_page_id']} WHERE text_ad_id = $ad");
        $ads->update($ad, ['text_ad_type' => 1, 'landing_page_id' => $m['landing_page_id'], 'text_ad_name' => 'y']);
    }

    /**
     * An advanced page is not made simple while text ads of type 1 name it:
     * Get Links offers those only on an advanced page, so the change left
     * them offered nowhere with a 200. The refusal names every such ad and
     * none other (a removed one, a campaign's, another account's), on a PUT
     * and on a bulk row; once none remain, the change is made.
     */
    public function testAnAdvancedPageWithTextAdsIsNotMadeSimple(): void
    {
        $m = $this->ids['mine'];
        $pages = new LandingPagesController(self::$db, self::USER);
        $ads = new TextAdsController(self::$db, self::USER);
        $page = (int) $pages->create(['landing_page_url' => 'https://adv.example', 'landing_page_nickname' => 'adv', 'landing_page_type' => 1, 'aff_campaign_id' => 0])['data']['landing_page_id'];
        $body = ['text_ad_name' => 'x', 'text_ad_headline' => 'h', 'text_ad_description' => 'd', 'text_ad_display_url' => 'x', 'text_ad_type' => 1, 'aff_campaign_id' => 0, 'landing_page_id' => $page];
        $first = (int) $ads->create($body)['data']['text_ad_id'];
        $second = (int) $ads->create(['text_ad_name' => 'y'] + $body)['data']['text_ad_id'];
        $removed = (int) $ads->create(['text_ad_name' => 'z'] + $body)['data']['text_ad_id'];
        $ads->delete($removed);
        self::q("INSERT INTO 202_text_ads SET user_id = " . self::USER . ", aff_campaign_id = {$m['aff_campaign_id']}, landing_page_id = $page, text_ad_type = 0, text_ad_name = 'c', text_ad_headline = 'h', text_ad_description = 'd', text_ad_display_url = 'x', text_ad_time = 0");
        self::q("INSERT INTO 202_text_ads SET user_id = " . self::OTHER . ", aff_campaign_id = 0, landing_page_id = $page, text_ad_type = 1, text_ad_name = 'o', text_ad_headline = 'h', text_ad_description = 'd', text_ad_display_url = 'x', text_ad_time = 0");
        $makeSimple = ['landing_page_type' => 0, 'aff_campaign_id' => $m['aff_campaign_id']];
        $type = static fn (): int => (int) $pages->get($page)['data']['landing_page_type'];

        try {
            $pages->update($page, $makeSimple);
            self::fail('an advanced page its ads name was made simple');
        } catch (ValidationException $e) {
            $said = $e->getFieldErrors()['landing_page_type'] ?? '';
            self::assertStringContainsString("2 text ads of text_ad_type 1 name it (text_ad_id $first, $second)", $said, json_encode($e->getFieldErrors()));
            self::assertStringContainsString("GET /text-ads?filter[landing_page_id]=$page&filter[text_ad_type]=1", $said);
        }
        self::assertSame(1, $type(), 'the refused update changed the type');
        $pages->update($page, ['landing_page_nickname' => 'renamed']);

        \Api\V3\RequestContext::setHeaders(['Idempotency-Key' => 'simple-' . bin2hex(random_bytes(6))]);
        try {
            $result = $pages->bulkUpsert(['rows' => [['landing_page_id' => $page] + $makeSimple]]);
        } finally {
            \Api\V3\RequestContext::reset();
        }
        self::assertSame(['error'], array_column($result['data'], 'status'), json_encode($result));
        self::assertArrayHasKey('landing_page_type', $result['data'][0]['field_errors'] ?? [], json_encode($result['data'][0]));
        self::assertSame(1, $type(), 'the refused row changed the type');

        $ads->update($first, ['text_ad_type' => 0, 'aff_campaign_id' => $m['aff_campaign_id']]);
        $ads->delete($second);
        self::assertSame(0, (int) $pages->update($page, $makeSimple)['data']['landing_page_type']);

        // A page already simple saves with an ad of type 1 stored on it before #260.
        self::q("UPDATE 202_text_ads SET text_ad_type = 1 WHERE text_ad_id = $first");
        $pages->update($page, ['landing_page_type' => 0, 'landing_page_nickname' => 'still simple']);
    }

    public function testAnAdvancedPageItsAdsOrTrackersNameIsNotDeleted(): void
    {
        $m = $this->ids['mine'];
        $u = self::USER;
        $o = self::OTHER;
        $pages = new LandingPagesController(self::$db, $u);
        $ads = new TextAdsController(self::$db, $u);
        $page = (int) $pages->create([
            'landing_page_url' => 'https://adv.example', 'landing_page_nickname' => 'adv',
            'landing_page_type' => 1, 'aff_campaign_id' => 0,
        ])['data']['landing_page_id'];
        $body = [
            'text_ad_name' => 'x', 'text_ad_headline' => 'h', 'text_ad_description' => 'd',
            'text_ad_display_url' => 'x', 'text_ad_type' => 1, 'aff_campaign_id' => 0, 'landing_page_id' => $page,
        ];
        $ad = (int) $ads->create($body)['data']['text_ad_id'];
        $removed = (int) $ads->create(['text_ad_name' => 'z'] + $body)['data']['text_ad_id'];
        $ads->delete($removed);
        $tracker = static fn (int $user, int $lp): int => self::q(
            "INSERT INTO 202_trackers SET user_id = $user, tracker_id_public = " . random_int(100000000, 999999999)
            . ", aff_campaign_id = 0, text_ad_id = 0, ppc_account_id = 0, landing_page_id = $lp, rotator_id = 0,"
            . ' click_cloaking = 0, tracker_time = 0'
        );
        $first = $tracker($u, $page);
        $second = $tracker($u, $page);
        // Another account's rows naming the id are not this page's.
        $tracker($o, $page);
        self::q("INSERT INTO 202_text_ads SET user_id = $o, aff_campaign_id = 0, landing_page_id = $page,"
            . " text_ad_type = 1, text_ad_name = 'o', text_ad_headline = 'h', text_ad_description = 'd',"
            . " text_ad_display_url = 'x', text_ad_time = 0");
        $deleted = static fn (int $id): string => (string) self::$db
            ->query("SELECT landing_page_deleted FROM 202_landing_pages WHERE landing_page_id = $id")->fetch_row()[0];

        $calls = ['delete' => fn () => $pages->delete($page), 'dry run' => fn () => $pages->deletePreview($page)];
        foreach ($calls as $what => $call) {
            try {
                $call();
                self::fail("the $what of an advanced page its ad and trackers name was not refused");
            } catch (ConflictException $e) {
                $said = $e->getMessage();
                self::assertStringContainsString("Landing page $page is advanced and 1 text ad of text_ad_type 1"
                    . " (text_ad_id $ad) and 2 trackers (tracker_id $first, $second) name it", $said, $what);
                self::assertStringContainsString("GET /text-ads?filter[landing_page_id]=$page&filter[text_ad_type]=1"
                    . " and GET /trackers?filter[landing_page_id]=$page list them.", $said, $what);
                self::assertSame(['text_ad_ids' => [$ad], 'tracker_ids' => [$first, $second]], $e->getDetails(), $what);
            }
            self::assertSame('0', $deleted($page), "the refused $what removed the page");
        }

        // Once the ad is a campaign's, the trackers alone still hold the page.
        $ads->update($ad, ['text_ad_type' => 0, 'aff_campaign_id' => $m['aff_campaign_id']]);
        try {
            $pages->delete($page);
            self::fail('an advanced page its trackers name was deleted');
        } catch (ConflictException $e) {
            $said = $e->getMessage();
            self::assertStringContainsString(
                "Landing page $page is advanced and 2 trackers (tracker_id $first, $second) name it",
                $said
            );
            self::assertStringContainsString('or delete it (DELETE /trackers/{id})', $said);
            self::assertSame(['text_ad_ids' => [], 'tracker_ids' => [$first, $second]], $e->getDetails());
        }
        self::q("DELETE FROM 202_trackers WHERE tracker_id IN ($first, $second)");
        self::assertSame('delete', $pages->deletePreview($page)['data']['action']);
        $pages->delete($page);
        self::assertSame('1', $deleted($page));

        // A simple page is removed as before: its trackers name its campaign's link.
        $simple = $m['landing_page_id'];
        $tracker($u, $simple);
        $pages->delete($simple);
        self::assertSame('1', $deleted($simple));
    }

    public function testThePageIsHeldWhileItsDeleteDecides(): void
    {
        $page = $this->ids['mine']['landing_page_id'];
        $conn = new \Prosper202\Database\Connection(self::$db);
        $trackers = new \Prosper202\Setup\LandingPageTrackers($conn);
        try {
            $trackers->lockPage(self::USER, $page);
            self::fail('the page was locked outside a transaction, where the lock ends with the statement');
        } catch (\LogicException) {
        }

        $other = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertTrue($other->query('SET SESSION innodb_lock_wait_timeout = 1'));
        try {
            $conn->transaction(function () use ($trackers, $page, $other): void {
                self::assertSame(0, $trackers->lockPage(self::USER, $page));
                self::assertNull($trackers->lockPage(self::OTHER, $page), "another account's page is no page");
                $wrote = $other->query("UPDATE 202_landing_pages SET landing_page_type = 1"
                    . " WHERE landing_page_id = $page");
                self::assertFalse($wrote, 'a second write of the page landed while the delete held it');
                self::assertSame(1205, $other->errno, $other->error);
            });
        } finally {
            $other->close();
        }
        self::assertNull((new \Prosper202\Database\Connection(self::$db))->transaction(
            fn (): ?int => $trackers->lockPage(self::USER, $this->ids['removed']['landing_page_id'])
        ), 'a removed page is no page');
    }

    public function testBulkUpsertRefusesTheRowNotTheBatch(): void
    {
        $m = $this->ids['mine'];
        $before = $this->rowCount('202_aff_campaigns');
        \Api\V3\RequestContext::setHeaders(['Idempotency-Key' => 'owned-' . bin2hex(random_bytes(6))]);
        try {
            $result = (new CampaignsController(self::$db, self::USER))->bulkUpsert(['rows' => [
                ['aff_campaign_name' => 'ok', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => $m['aff_network_id']],
                ['aff_campaign_name' => 'no', 'aff_campaign_url' => 'https://o.example', 'aff_campaign_payout' => 1, 'aff_network_id' => $this->ids['theirs']['aff_network_id']],
            ]]);
        } finally {
            \Api\V3\RequestContext::reset();
        }
        self::assertSame(['created', 'error'], array_column($result['data'], 'status'), json_encode($result));
        self::assertArrayHasKey('aff_network_id', $result['data'][1]['field_errors'] ?? [], 'the refused row says which field: ' . json_encode($result['data'][1]));
        self::assertSame($before + 1, $this->rowCount('202_aff_campaigns'));
    }

    private function rows(object $controller): int
    {
        $table = match (true) {
            $controller instanceof CampaignsController => '202_aff_campaigns',
            $controller instanceof PpcAccountsController => '202_ppc_accounts',
            $controller instanceof LandingPagesController => '202_landing_pages',
            $controller instanceof TextAdsController => '202_text_ads',
            default => '202_trackers',
        };

        return $this->rowCount($table);
    }
}

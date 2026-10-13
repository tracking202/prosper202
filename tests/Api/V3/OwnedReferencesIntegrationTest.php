<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
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

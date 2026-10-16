<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickPathReads;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\Repository\Cache\RowCache;
use Tests\Support\RecordingRowCache;

/**
 * The click path's reads on a real server: every record a row names by id
 * (a landing page's campaign, a tracker's campaign, text ad, traffic source
 * and redirector, a click's campaign) reads as nothing when it is another
 * account's, as rows written before 229df10 can name one. Each case names
 * exactly one foreign record, so the tie it needs is the one it proves.
 *
 * @group integration
 */
final class ClickPathReadsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const OWNER = 990501;
    private const OTHER = 990502;
    private const FIRST_CLICK = 99050101;
    private const PUBLIC = 99050100;

    /** @var array<string, int> */
    private static array $id = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::OWNER => 'never', self::OTHER => 'origin'] as $user => $referer) {
            self::q('INSERT INTO 202_users SET user_id = ' . $user . ", user_name = 'u$user', user_pass = '',"
                . " user_email = '', user_dash_email = '', user_time_register = 1, install_hash = '',"
                . " user_hash = '', user_timezone = 'Europe/Paris', user_public_publisher_id = 'pub$user'");
            self::q('INSERT INTO 202_users_pref SET user_id = ' . $user . ", user_pref_cloak_referer = '$referer',"
                . " user_keyword_searched_or_bidded = 'bidded', user_pref_dynamic_bid = 1, maxmind_isp = 1");
        }

        foreach (['own' => self::OWNER, 'other' => self::OTHER] as $who => $user) {
            self::$id["{$who}Network"] = self::insert('INSERT INTO 202_ppc_networks SET user_id = ' . $user
                . ", ppc_network_name = '$who', ppc_network_time = 1");
            foreach (['b', 'a'] as $parameter) {
                self::q('INSERT INTO 202_ppc_network_variables SET ppc_network_id = ' . self::$id["{$who}Network"]
                    . ", name = '$who$parameter', parameter = '$who$parameter', placeholder = ''");
            }
            self::q('INSERT INTO 202_ppc_network_variables SET ppc_network_id = ' . self::$id["{$who}Network"]
                . ", name = 'gone', parameter = 'gone', placeholder = '', deleted = 1");
            self::$id["{$who}Account"] = self::account($user, self::$id["{$who}Network"]);
            self::$id["{$who}Campaign"] = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . $user
                . ', aff_campaign_id_public = ' . (self::PUBLIC + ($who === 'own' ? 1 : 2))
                . ", aff_network_id = 1, aff_campaign_name = '$who offer', aff_campaign_url = 'https://$who.example/',"
                . " aff_campaign_url_2 = 'https://$who.example/2', aff_campaign_rotate = 1,"
                . ' aff_campaign_payout = 7.25, aff_campaign_cloaking = 1, identity_signals = 0,'
                . ' aff_campaign_time = 1, aff_campaign_foreign_payout = 0');
            self::$id["{$who}Ad"] = self::insert('INSERT INTO 202_text_ads SET user_id = ' . $user
                . ", aff_campaign_id = 0, landing_page_id = 0, text_ad_name = '$who', text_ad_headline = '',"
                . " text_ad_description = '', text_ad_display_url = '', text_ad_time = 1");
            self::$id["{$who}Rotator"] = self::insert('INSERT INTO 202_rotators SET public_id = 1, user_id = ' . $user
                . ', default_campaign = ' . self::$id["{$who}Campaign"]);
        }
        // A tracker of the other account on this account's campaign, older than
        // this account's own: the untied variables read picked it first.
        $id = self::$id;
        self::tracker(self::OTHER, 50, $id['ownCampaign'], $id['otherAd'], $id['otherAccount'], 0);
        self::tracker(self::OWNER, 1, $id['ownCampaign'], $id['ownAd'], $id['ownAccount'], $id['ownRotator']);
        // This account's tracker naming the other account's campaign, text ad,
        // traffic source account and rotator.
        self::tracker(self::OWNER, 2, $id['otherCampaign'], $id['otherAd'], $id['otherAccount'], $id['otherRotator']);
        // This account's traffic source account under the other account's source.
        self::$id['strayAccount'] = self::account(self::OWNER, self::$id['otherNetwork']);
        self::tracker(self::OWNER, 3, self::$id['ownCampaign'], self::$id['ownAd'], self::$id['strayAccount'], 0);
        self::tracker(self::OTHER, 4, self::$id['otherCampaign'], self::$id['otherAd'], self::$id['otherAccount'], 0);

        self::landingPage(self::OWNER, 1, self::$id['ownCampaign']);
        self::landingPage(self::OWNER, 3, self::$id['otherCampaign']);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    private static function cleanUp(): void
    {
        $users = self::OWNER . ', ' . self::OTHER;
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
            '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site',
            '202_attribution_rollup_dirty_clicks',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q("DELETE FROM 202_site_urls WHERE site_url_address LIKE 'https://cloaked.example/%'");
        self::q("DELETE v FROM 202_ppc_network_variables v JOIN 202_ppc_networks n"
            . " ON n.ppc_network_id = v.ppc_network_id WHERE n.user_id IN ($users)");
        $owned = [
            '202_ppc_networks', '202_ppc_accounts', '202_aff_campaigns', '202_text_ads', '202_rotators',
            '202_trackers', '202_landing_pages', '202_users_pref', '202_users',
        ];
        foreach ($owned as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    private static function account(int $user, int $network): int
    {
        return self::insert('INSERT INTO 202_ppc_accounts SET user_id = ' . $user . ', ppc_network_id = ' . $network
            . ", ppc_account_name = 'a', ppc_account_time = 1");
    }

    private static function tracker(int $user, int $n, int $campaign, int $ad, int $account, int $rotator): void
    {
        self::q('INSERT INTO 202_trackers SET user_id = ' . $user . ', tracker_id_public = ' . (self::PUBLIC + $n)
            . ", aff_campaign_id = $campaign, text_ad_id = $ad, ppc_account_id = $account, landing_page_id = 0,"
            . " rotator_id = $rotator, click_cpc = 0.25, click_cpa = 1.5, click_cloaking = -1, tracker_time = 1");
    }

    private static function landingPage(int $user, int $n, int $campaign): void
    {
        self::q('INSERT INTO 202_landing_pages SET user_id = ' . $user
            . ', landing_page_id_public = ' . (self::PUBLIC + $n) . ", aff_campaign_id = $campaign,"
            . " landing_page_nickname = 'lp', landing_page_url = 'https://lp.example/', landing_page_time = 1");
    }

    private static function reads(?RowCache $cache = null): ClickPathReads
    {
        $conn = new Connection(self::requireScratch());

        return $cache === null ? ClickPathReads::uncached($conn) : ClickPathReads::cached($conn, $cache, 'test');
    }

    /** @param array<string, string|null>|null $row */
    private static function assertText(?array $row): void
    {
        self::assertIsArray($row);
        foreach ($row as $column => $value) {
            self::assertTrue($value === null || is_string($value), "$column is text or null");
        }
    }

    public function testATrackerReadsItsOwnAccountsRecords(): void
    {
        $row = self::reads()->tracker(self::PUBLIC + 1);

        self::assertText($row);
        self::assertSame((string) self::OWNER, $row['user_id']);
        self::assertSame((string) self::$id['ownAd'], $row['text_ad_id']);
        self::assertSame((string) self::$id['ownAccount'], $row['ppc_account_id']);
        self::assertSame((string) self::$id['ownCampaign'], $row['aff_campaign_id']);
        self::assertSame('https://own.example/2', $row['aff_campaign_url_2']);
        self::assertSame('7.25', $row['aff_campaign_payout']);
        self::assertSame('ownb,owna', $row['parameters'], 'the source\'s live variables, in id order');
        self::assertSame('Europe/Paris', $row['user_timezone']);
        self::assertSame('bidded', $row['user_keyword_searched_or_bidded']);
        self::assertSame('1', $row['user_pref_dynamic_bid']);
        self::assertSame('pub' . self::OWNER, $row['user_public_publisher_id']);
    }

    public function testATrackerNamingAnotherAccountsRecordsNamesNone(): void
    {
        $row = self::reads()->tracker(self::PUBLIC + 2);

        self::assertText($row);
        self::assertSame((string) self::OWNER, $row['user_id']);
        self::assertSame('0', $row['text_ad_id']);
        self::assertSame('0', $row['ppc_account_id']);
        self::assertSame('0', $row['aff_campaign_id']);
        self::assertNull($row['aff_campaign_url']);
        self::assertNull($row['aff_campaign_payout']);
        self::assertNull($row['parameters']);
    }

    public function testATrafficSourceAccountUnderAnotherAccountsSourceHasNoVariables(): void
    {
        $row = self::reads()->tracker(self::PUBLIC + 3);

        self::assertText($row);
        self::assertSame((string) self::$id['strayAccount'], $row['ppc_account_id']);
        self::assertNull($row['parameters']);
        self::assertNull($row['ppc_variable_ids']);
    }

    public function testATrackerOfTheVisitIsReadOnlyForTheLandingPagesAccount(): void
    {
        $reads = self::reads();

        $own = $reads->accountTracker(self::OWNER, self::PUBLIC + 1);
        self::assertText($own);
        self::assertSame((string) self::$id['ownCampaign'], $own['aff_campaign_id']);
        self::assertSame('7.25', $own['aff_campaign_payout']);
        self::assertSame('-1', $own['click_cloaking']);

        self::assertNull($reads->accountTracker(self::OWNER, self::PUBLIC + 4), 'another account\'s tracker');
        self::assertNotNull($reads->accountTracker(self::OTHER, self::PUBLIC + 4));

        $stray = $reads->accountTracker(self::OWNER, self::PUBLIC + 2);
        self::assertText($stray);
        self::assertSame('0', $stray['aff_campaign_id'], 'the other account\'s campaign');
        self::assertNull($stray['aff_campaign_payout']);
        self::assertSame('0', $stray['text_ad_id']);
        self::assertSame('0', $stray['ppc_account_id']);
    }

    public function testARedirectorTrackerReadsOnlyItsAccountsRotator(): void
    {
        $reads = self::reads();

        $own = $reads->redirectorTracker(self::PUBLIC + 1);
        self::assertText($own);
        self::assertSame((string) self::$id['ownRotator'], $own['rotator_id']);
        self::assertSame((string) self::$id['ownCampaign'], $own['aff_campaign_id']);
        self::assertSame((string) self::$id['ownAccount'], $own['ppc_account_id']);

        $stray = $reads->redirectorTracker(self::PUBLIC + 2);
        self::assertText($stray);
        self::assertSame('0', $stray['rotator_id'], 'the other account\'s rotator');
        self::assertNull($stray['default_campaign']);
        self::assertNull($stray['aff_campaign_id']);
        self::assertSame('0', $stray['ppc_account_id']);
    }

    public function testALandingPagesOfferIsItsAccountsCampaign(): void
    {
        $reads = self::reads();

        $own = $reads->landingPageOffer(self::PUBLIC + 1);
        self::assertText($own);
        self::assertSame((string) self::$id['ownCampaign'], $own['aff_campaign_id']);
        self::assertSame('own offer', $own['aff_campaign_name']);
        self::assertSame((string) (self::PUBLIC + 1), $own['landing_page_id_public']);

        self::assertNull($reads->landingPageOffer(self::PUBLIC + 3), 'a landing page on another account\'s campaign');
    }

    public function testALandingPagesVariablesComeFromItsOwnAccountsTracker(): void
    {
        $row = self::reads()->landingPageTrackerVariables(self::PUBLIC + 1);

        self::assertText($row);
        self::assertSame('ownb,owna', $row['parameters']);
    }

    public function testAVisitsTrackerGivesItsVariablesOnlyOnItsAccountsPage(): void
    {
        $reads = self::reads();

        $own = $reads->landingPageVisitVariables(self::PUBLIC + 1, self::PUBLIC + 1);
        self::assertText($own);
        self::assertSame('ownb,owna', $own['parameters']);

        self::assertNull(
            $reads->landingPageVisitVariables(self::PUBLIC + 1, self::PUBLIC + 4),
            'another account\'s tracker on this account\'s page'
        );

        $stray = $reads->landingPageVisitVariables(self::PUBLIC + 1, self::PUBLIC + 3);
        self::assertText($stray);
        self::assertNull($stray['parameters'], 'a source account under another account\'s source');
    }

    public function testALandingPagesCaptureSettingsAreItsAccounts(): void
    {
        $reads = self::reads();

        $named = $reads->landingPageCapture(self::PUBLIC + 1, self::PUBLIC + 1);
        self::assertText($named);
        self::assertSame((string) self::$id['ownCampaign'], $named['tracker_campaign']);
        self::assertSame('0', $named['lp_setting']);

        self::assertNotNull($named['tracker_found']);
        self::assertSame((string) self::$id['ownCampaign'], $named['tracker_campaign_named']);

        $other = $reads->landingPageCapture(self::PUBLIC + 1, self::PUBLIC + 4);
        self::assertText($other);
        self::assertNull($other['tracker_campaign'], 'another account\'s tracker');
        self::assertNull($other['tracker_found'], 'another account\'s tracker is not found');

        $stray = $reads->landingPageCapture(self::PUBLIC + 1, self::PUBLIC + 2);
        self::assertText($stray);
        self::assertNull($stray['tracker_campaign'], 'the account\'s tracker on another account\'s campaign');
        self::assertNotNull($stray['tracker_found']);
        self::assertNotNull($stray['tracker_campaign_named']);

        $none = $reads->landingPageCapture(self::PUBLIC + 1, null);
        self::assertText($none);
        self::assertNull($none['tracker_campaign']);

        $foreignPage = $reads->landingPageCapture(self::PUBLIC + 3, null);
        self::assertText($foreignPage);
        self::assertNull($foreignPage['lp_setting'], 'a landing page on another account\'s campaign');
    }

    public function testAnOfferIsReadForItsOwnAccount(): void
    {
        $reads = self::reads();

        $own = $reads->offer(self::PUBLIC + 1);
        self::assertText($own);
        self::assertSame((string) self::OWNER, $own['user_id']);
        self::assertSame((string) self::$id['ownCampaign'], $own['aff_campaign_id']);

        $other = $reads->offer(self::PUBLIC + 2);
        self::assertText($other);
        self::assertSame((string) self::OTHER, $other['user_id']);
    }

    public function testACloakedClickNamesOnlyItsAccountsCampaignAndTakesItsAccountsReferer(): void
    {
        $siteUrl = self::insert("INSERT INTO 202_site_urls SET site_domain_id = 0,"
            . " site_url_address = 'https://cloaked.example/first'");
        $click = static fn (int $campaign, int $time): ClickRecord => new ClickRecord(
            clickId: self::FIRST_CLICK,
            userId: self::OWNER,
            clickTime: $time,
            affCampaignId: $campaign,
            clickIdPublic: '7' . self::FIRST_CLICK . '7',
            clickCloaking: 1,
            clickRedirectSiteUrlId: $siteUrl,
        );
        (new MysqlClickRepository(new Connection(self::requireScratch())))
            ->recordClick($click(self::$id['otherCampaign'], 1000));

        $cache = new RecordingRowCache();
        $row = self::reads($cache)->cloakedClick((int) ('7' . self::FIRST_CLICK . '7'));
        self::assertText($row);
        self::assertNull($row['aff_campaign_name'], 'the other account\'s campaign');
        self::assertSame('https://cloaked.example/first', $row['site_url_address']);
        self::assertSame('never', $row['user_pref_cloak_referer'], 'the click\'s account\'s, not the first account\'s');
        self::assertSame([], $cache->calls, 'a click\'s row is never cached');

        // A redirector re-click's second row, later: the click as it is now.
        self::q('INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_time)'
            . ' VALUES (' . self::FIRST_CLICK . ', ' . self::OWNER . ', ' . self::$id['ownCampaign'] . ', 0, 0, 2000)');
        $row = self::reads()->cloakedClick((int) ('7' . self::FIRST_CLICK . '7'));
        self::assertText($row);
        self::assertSame('own offer', $row['aff_campaign_name']);

        self::assertNull(self::reads()->cloakedClick(1));
    }

    /** @return iterable<string, array{bool}> */
    public static function insertionOrders(): iterable
    {
        yield 'the current visit stored first' => [true];
        yield 'the current visit stored second' => [false];
    }

    /**
     * Two visits of a re-clicked click in one second share their time: the
     * one on the route the click holds now is the click as it is now.
     *
     * @dataProvider insertionOrders
     */
    public function testOfTwoVisitsInOneSecondTheOneOnTheClicksRouteIsTheClick(bool $currentFirst): void
    {
        $click = self::FIRST_CLICK + 5;
        foreach (['202_clicks', '202_clicks_record', '202_clicks_site', '202_clicks_rotator'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = $click");
        }
        $rotator = self::$id['ownRotator'];
        $visit = static fn (int $campaign, int $redirect): string => 'INSERT INTO 202_clicks'
            . ' (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_time, rotator_id, rule_id)'
            . " VALUES ($click, " . self::OWNER . ", $campaign, 0, 0, 5000, $rotator, $redirect)";
        $current = $visit(self::$id['ownCampaign'], 12);
        $earlier = $visit(0, 11);
        foreach ($currentFirst ? [$current, $earlier] : [$earlier, $current] as $sql) {
            self::q($sql);
        }
        self::q("INSERT INTO 202_clicks_rotator SET click_id = $click, rotator_id = $rotator, rule_id = 3,"
            . ' rule_redirect_id = 12');
        self::q("INSERT INTO 202_clicks_record SET click_id = $click, click_id_public = 7{$click}7,"
            . ' click_cloaking = 1');
        $siteUrl = self::insert("INSERT INTO 202_site_urls SET site_domain_id = 0,"
            . " site_url_address = 'https://cloaked.example/same-second'");
        self::q("INSERT INTO 202_clicks_site SET click_id = $click, click_redirect_site_url_id = $siteUrl");

        $row = self::reads()->cloakedClick((int) "7{$click}7");
        self::assertText($row);
        self::assertSame('own offer', $row['aff_campaign_name'], 'the visit on the click\'s route, rule redirect 12');
    }

    public function testALandingPageIsReadByItsPublicId(): void
    {
        $row = self::reads()->landingPage(self::PUBLIC + 3);

        self::assertText($row);
        self::assertSame((string) self::OWNER, $row['user_id']);
        self::assertSame('0', $row['landing_page_type']);
        self::assertNull(self::reads()->landingPage(self::PUBLIC + 90));
    }

    /**
     * 0 is a column's "none" (a click stored without a public id), so it is
     * never looked up: every row without one would match it.
     */
    public function testAnIdBelowOneNamesNothingAndIsNotLookedUp(): void
    {
        $cache = new RecordingRowCache();
        $reads = self::reads($cache);
        foreach ([0, -1] as $id) {
            self::assertNull($reads->tracker($id));
            self::assertNull($reads->accountTracker(self::OWNER, $id));
            self::assertNull($reads->accountTracker($id, self::PUBLIC + 1));
            self::assertNull($reads->redirectorTracker($id));
            self::assertNull($reads->landingPage($id));
            self::assertNull($reads->landingPageOffer($id));
            self::assertNull($reads->landingPageVisitVariables($id, self::PUBLIC + 1));
            self::assertNull($reads->landingPageVisitVariables(self::PUBLIC + 1, $id));
            self::assertNull($reads->landingPageTrackerVariables($id));
            self::assertNull($reads->landingPageCapture($id, null));
            self::assertNull($reads->offer($id));
            self::assertNull($reads->cloakedClick($id));
        }
        self::assertSame([], $cache->calls, 'nothing was looked up');

        $stray = $reads->landingPageCapture(self::PUBLIC + 1, 0);
        self::assertText($stray);
        self::assertNull($stray['tracker_campaign'], 'a tracker id of 0 names no tracker');
    }

    public function testASetupRowIsKeptAndAMissingOneIsNot(): void
    {
        $cache = new RecordingRowCache();
        $reads = self::reads($cache);

        $first = $reads->offer(self::PUBLIC + 1);
        self::q("UPDATE 202_aff_campaigns SET aff_campaign_name = 'renamed' WHERE aff_campaign_id = "
            . self::$id['ownCampaign']);
        try {
            self::assertSame($first, $reads->offer(self::PUBLIC + 1), 'the kept row, not a second read');
            self::assertSame('own offer', $first['aff_campaign_name'] ?? null);
            self::assertSame('renamed', self::reads()->offer(self::PUBLIC + 1)['aff_campaign_name'] ?? null);
        } finally {
            self::q("UPDATE 202_aff_campaigns SET aff_campaign_name = 'own offer' WHERE aff_campaign_id = "
                . self::$id['ownCampaign']);
        }

        self::assertNull($reads->offer(self::PUBLIC + 90));
        self::assertNull($reads->offer(self::PUBLIC + 90));
        $sets = array_values(array_filter($cache->calls, static fn (array $call): bool => $call[0] === 'set'));
        self::assertCount(1, $sets, 'the row is kept; the miss is not');

        // A record made after a miss is read at once, not after an expiry.
        self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = ' . (self::PUBLIC + 90)
            . ' WHERE aff_campaign_id = ' . self::$id['ownCampaign']);
        try {
            self::assertSame('own offer', $reads->offer(self::PUBLIC + 90)['aff_campaign_name'] ?? null);
        } finally {
            self::q('UPDATE 202_aff_campaigns SET aff_campaign_id_public = ' . (self::PUBLIC + 1)
                . ' WHERE aff_campaign_id = ' . self::$id['ownCampaign']);
        }
    }

    public function testEachReadAndEachInstallKeepsItsOwnEntries(): void
    {
        $cache = new RecordingRowCache();
        $reads = self::reads($cache);
        $reads->tracker(self::PUBLIC + 1);
        $reads->redirectorTracker(self::PUBLIC + 1);
        ClickPathReads::cached(new Connection(self::requireScratch()), $cache, 'another install')
            ->tracker(self::PUBLIC + 1);

        $keys = array_column(array_filter($cache->calls, static fn (array $call): bool => $call[0] === 'set'), 1);
        self::assertCount(3, $keys);
        self::assertCount(3, array_unique($keys));
        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/\Ap202-row-[0-9a-f]{64}\z/', $key);
        }
    }
}

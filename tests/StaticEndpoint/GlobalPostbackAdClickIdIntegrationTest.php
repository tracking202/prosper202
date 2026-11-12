<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdIndex;
use Prosper202\Click\ClickByAdClickId;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * A global postback (gpb.php) whose SubID was not filled in (empty, or the
 * network's macro as pasted) records its conversion on the click that
 * carries an ad click id it sends back, as `ad_click_id`: the latest of one
 * visitor's clicks on one campaign, or the one the sale is already on; and
 * records nothing when no click carries it, or clicks that differ in what a
 * report shows do. A SubID that is there is
 * read exactly as before. gpb.php runs in a process of its own
 * (fixtures/gpb-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect2.php's DB class lives there) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class GlobalPostbackAdClickIdIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990725;
    /** An account whose postbacks need its token, and are sent none here. */
    private const OTHER_USER = 990726;

    private const CLICK = 99072501;
    private const OLD_CLICK = 99072502;
    private const TWIN_A = 99072503;
    private const TWIN_B = 99072504;
    private const OTHER_USERS_CLICK = 99072505;
    private const RE_CLICKED = 99072506;
    private const MICROSOFT_CLICK = 99072507;
    private const CAMPAIGN_A = 99072508;
    private const CAMPAIGN_B = 99072509;
    private const SOURCE_A = 99072510;
    private const SOURCE_B = 99072511;
    private const ACCOUNT_A = 99072512;
    private const ACCOUNT_B = 99072513;
    /** Not there until a test has the visitor reload once more. */
    private const TWIN_C = 99072514;
    /** Clicks a test adds: two visitors whose pages wrote no id, and clicks older than the 30 days. */
    private const ADDED = [99072520, 99072550];

    private const GCLID = 'Cj0KCQiA-demo_Shop-CLICK';
    private const OLD_GCLID = 'Cj0KCQiA-demo_Shop-OLD';
    private const TWIN_GCLID = 'Cj0KCQiA-demo_Shop-TWIN';
    private const OTHER_GCLID = 'Cj0KCQiA-demo_Shop-OTHER';
    private const RE_GCLID = 'Cj0KCQiA-demo_Shop-RE';
    private const MSCLKID = '0123456789abcdef0123456789abcdef';
    private const CAMPAIGNS_GCLID = 'Cj0KCQiA-demo_Shop-CAMPAIGNS';
    private const SOURCES_GCLID = 'Cj0KCQiA-demo_Shop-SOURCES';
    private const ACCOUNTS_GCLID = 'Cj0KCQiA-demo_Shop-ACCOUNTS';

    /**
     * click => [owner, ad click ids, seconds since its visits, campaign (0 the first, 1 the second),
     * traffic source account]. Every click is the account's, on its first campaign, from no traffic source
     * account, unless its own case says otherwise.
     */
    private const CLICKS = [
        self::CLICK => [self::USER, ['gclid' => self::GCLID], [600], 0, 0],
        self::OLD_CLICK => [self::USER, ['gclid' => self::OLD_GCLID], [ClickByAdClickId::LOOKBACK + 3600], 0, 0],
        // A reloaded landing URL: one visitor, two clicks.
        self::TWIN_A => [self::USER, ['gclid' => self::TWIN_GCLID], [600], 0, 0],
        self::TWIN_B => [self::USER, ['gclid' => self::TWIN_GCLID], [500], 0, 0],
        self::OTHER_USERS_CLICK => [self::OTHER_USER, ['gclid' => self::OTHER_GCLID], [600], 0, 0],
        self::RE_CLICKED => [self::USER, ['gclid' => self::RE_GCLID], [ClickByAdClickId::LOOKBACK + 3600, 600], 0, 0],
        self::MICROSOFT_CLICK => [self::USER, ['msclkid' => self::MSCLKID], [600], 1, 0],
        // Each pair differs in one thing a report shows, and nothing else.
        self::CAMPAIGN_A => [self::USER, ['gclid' => self::CAMPAIGNS_GCLID], [600], 0, 0],
        self::CAMPAIGN_B => [self::USER, ['gclid' => self::CAMPAIGNS_GCLID], [500], 1, 0],
        self::SOURCE_A => [self::USER, ['gclid' => self::SOURCES_GCLID], [600], 0, 0],
        self::SOURCE_B => [self::USER, ['gclid' => self::SOURCES_GCLID], [500], 0, 7],
        self::ACCOUNT_A => [self::USER, ['gclid' => self::ACCOUNTS_GCLID], [600], 0, 0],
        // The other account's click, its row naming the same campaign id.
        self::ACCOUNT_B => [self::OTHER_USER, ['gclid' => self::ACCOUNTS_GCLID], [500], 0, 0],
    ];
    private const AMBIGUOUS = 'SubID not found, and the ad click id sent does not name one click.'
        . ' No conversion was recorded. Fill in the SubID in the postback URL.';
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits',
        '202_conversion_logs', '202_dataengine', '202_google', '202_clicks_ad_ids', '202_inbound_log'];

    /** @var array<int, list<int>> each account's two campaigns */
    private static array $campaigns = [];

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect2.php has no DB class without one.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        foreach ([self::USER, self::OTHER_USER] as $user) {
            self::user($user);
            foreach (['Store checkout', 'Store newsletter'] as $name) {
                self::$campaigns[$user][] = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $user,"
                    . " aff_network_id = 0, aff_campaign_name = '$name $user',"
                    . " aff_campaign_url = 'https://shop.example',"
                    . ' aff_campaign_payout = 3.00, aff_campaign_foreign_payout = 3.00, aff_campaign_time = 0,'
                    . " payout_mode = 'accumulate'");
            }
        }
        self::postbackAuthOff(self::USER);
        foreach ([self::USER, self::OTHER_USER] as $user) {
            self::row('202_users_pref', ['user_id' => $user]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::deleteClicks();
        self::lists(self::USER, '');
        self::lists(self::OTHER_USER, '');
        foreach (self::CLICKS as $click => [$user, $ids, $ages, $campaign, $source]) {
            // An account's click names its own campaign; the other account's names the same id (see ACCOUNT_B).
            self::click($click, $user, self::$campaigns[self::USER][$campaign], $source, $ids, $ages);
        }
    }

    /**
     * A click as the click path leaves it: its visits, its ad click ids and which clicks carry each.
     *
     * @param array<string, string> $ids
     * @param list<int> $ages seconds since each visit
     */
    private static function click(int $click, int $user, int $campaign, int $source, array $ids, array $ages): void
    {
        foreach ($ages as $age) {
            $columns = "click_id = $click, user_id = $user, aff_campaign_id = $campaign"
                . ", landing_page_id = 0, ppc_account_id = $source, click_cpc = 0.25, click_payout = 3.00,"
                . ' click_lead = 0, click_time = ' . (time() - $age);
            self::q("INSERT INTO 202_clicks SET $columns");
        }
        self::q("INSERT INTO 202_clicks_spy SET $columns");
        self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0,"
            . ' c4_id = 0');
        self::row('202_google', ['click_id' => $click, 'gclid' => '', 'utm_source_id' => 0,
            'utm_medium_id' => 0, 'utm_campaign_id' => 0, 'utm_term_id' => 0, 'utm_content_id' => 0]);
        self::carries($click, $ids);
        LatestVisit::refresh(new Connection(self::$db), $click);
    }

    /**
     * $click keeps $ids, as the click path writes them: in 202_google, with a row for each in the index.
     *
     * @param array<string, string> $ids
     */
    private static function carries(int $click, array $ids): void
    {
        foreach ($ids as $name => $id) {
            self::q("UPDATE 202_google SET `$name` = " . self::str($id) . " WHERE click_id = $click");
            self::indexed($click, $id);
        }
    }

    /** The index names $click for $id, whatever the click keeps. */
    private static function indexed(int $click, string $id): void
    {
        self::q("INSERT IGNORE INTO 202_clicks_ad_ids SET click_id = $click, id_hash = UNHEX('"
            . bin2hex(AdClickIdIndex::hash($id)) . "')");
    }

    /** $user's Match conversions by ad click id setting. */
    private static function lists(int $user, string $parameters): void
    {
        self::q('UPDATE 202_users_pref SET user_pref_click_id_params = ' . self::str($parameters)
            . " WHERE user_id = $user");
    }

    private static function deleteClicks(): void
    {
        foreach ([...array_keys(self::CLICKS), self::TWIN_C, ...range(self::ADDED[0], self::ADDED[1])] as $click) {
            self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
                . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
            foreach (self::CLICK_TABLES as $table) {
                self::q("DELETE FROM $table WHERE click_id = $click");
            }
        }
        self::q('DELETE FROM 202_inbound_log WHERE user_id IN (0, ' . self::USER . ', ' . self::OTHER_USER . ')');
    }

    private static function cleanUp(): void
    {
        self::deleteClicks();
        $tables = ['202_aff_campaigns', '202_revenue_events', '202_dirty_hours', '202_postback_auth', '202_users_pref',
            '202_users'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER_USER . ')');
        }
    }

    /**
     * @param array<string, string> $get
     * @param array<string, string> $post
     * @return array{int, string} status and body
     */
    private static function gpb(array $get, array $post = []): array
    {
        $env = getenv();
        $env['P202_TEST_GET'] = json_encode((object) $get, JSON_THROW_ON_ERROR);
        $env['P202_TEST_POST'] = json_encode((object) $post, JSON_THROW_ON_ERROR);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/gpb-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "gpb-runner exited $exit: $err\n$out");
        $ids = [self::GCLID, self::TWIN_GCLID, self::OTHER_GCLID, self::OLD_GCLID, self::MSCLKID,
            self::CAMPAIGNS_GCLID, self::SOURCES_GCLID, self::ACCOUNTS_GCLID];
        foreach ($ids as $id) {
            self::assertStringNotContainsString($id, $err . $out, 'no answer or log line carries an id\'s value');
        }
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
    }

    /** @return list<array{string, string, string}> click, payout and match method of each conversion */
    private static function conversions(): array
    {
        return self::all('SELECT click_id, click_payout, match_method FROM 202_conversion_logs WHERE click_id IN ('
            . implode(',', [...array_keys(self::CLICKS), self::TWIN_C]) . ') ORDER BY conv_id');
    }

    /** @return list<array<int, string>> */
    private static function all(string $sql): array
    {
        $result = self::$db->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }

        return $result->fetch_all(MYSQLI_NUM);
    }

    public function testAnUnfilledSubidIsMatchedByTheGclidSentBack(): void
    {
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '12.50', 'gclid' => self::GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '12.50000', 'ad_click_id']], self::conversions());
        $logged = self::all(
            'SELECT outcome, reason, click_id, fields FROM 202_inbound_log WHERE user_id = ' . self::USER
        );
        self::assertCount(1, $logged);
        self::assertSame('recorded', $logged[0][0]);
        self::assertStringContainsString('the click was found by the gclid sent', $logged[0][1]);
        self::assertSame((string) self::CLICK, $logged[0][2]);
        self::assertStringNotContainsString(self::GCLID, $logged[0][3], 'the log keeps no id');
    }

    public function testAnEmptySubidAndANetworksOwnMacroAreUnfilledToo(): void
    {
        [$status, $body] = self::gpb(['amount' => '', 'subid' => ''], ['amount' => '4', 'msclkid' => self::MSCLKID]);
        self::assertSame(200, $status, $body);
        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'sid' => '', 'gclid' => self::RE_GCLID, 'amount' => '6']);
        self::assertSame(200, $status, $body);

        self::assertSame([
            [(string) self::MICROSOFT_CLICK, '4.00000', 'ad_click_id'],
            // Visited twice, once before the 30 days: one click, with a visit inside them.
            [(string) self::RE_CLICKED, '6.00000', 'ad_click_id'],
        ], self::conversions());
    }

    public function testASubidThatIsThereIsReadAsBefore(): void
    {
        // The ids sent beside it would name no click, or two: they are not asked.
        [$status, $body] = self::gpb(['subid' => (string) self::CLICK, 'amount' => '5', 'gclid' => self::TWIN_GCLID]);
        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'click_id']], self::conversions());
    }

    public function testASubidThatNamesNoClickIsNotReplacedByAnId(): void
    {
        foreach (['12abc', self::CLICK . '.0', '999999999999'] as $subid) {
            [$status, $body] = self::gpb(['subid' => $subid, 'amount' => '5', 'gclid' => self::GCLID]);
            self::assertSame(404, $status, $subid);
            self::assertStringNotContainsString('carries', $body, $subid);
        }
        self::assertSame([], self::conversions());
    }

    public function testAnUnfilledSubidWithNoIdIsAnsweredAsBefore(): void
    {
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => '{aff_sub3}']);

        self::assertSame(404, $status);
        self::assertSame('SubID not found', json_decode($body, true)['msg'] ?? null);
        self::assertSame([], self::conversions());
    }

    public function testAnotherAccountsClickNeedsThatAccountsAuthentication(): void
    {
        // One click carries the id, inside the 30 days: only its account's token requirement stands in the way.
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::OTHER_GCLID]);

        self::assertSame(403, $status, $body);
        self::assertSame([], self::conversions());
        self::assertSame([['unauthenticated', (string) self::OTHER_USER]], self::all(
            'SELECT outcome, user_id FROM 202_inbound_log WHERE user_id IN (' . self::USER . ', ' . self::OTHER_USER
                . ')'
        ));

        // The refused row keeps the click and how it was found, so its owner can record it from the log, as found.
        $row = (new \Prosper202\InboundLog\InboundLog(self::$db))->list(self::OTHER_USER, false)[0];
        self::assertSame(['ad_click_id', true], [$row['match_method'], $row['can_record']]);
        self::assertIsInt($row['click_id']);
        $replay = new \Prosper202\InboundLog\InboundReplay(self::$db);
        $answer = $replay->replay(self::OTHER_USER, $row['inbound_id']);
        self::assertSame('recorded', $answer['status'], $answer['message']);
        self::assertSame([[(string) $row['click_id'], '5.00000', 'ad_click_id']], self::conversions());
        self::assertSame('already_recorded', $replay->replay(self::OTHER_USER, $row['inbound_id'])['status']);
        self::assertCount(1, self::conversions());
    }

    public function testAReloadedVisitRecordsOnTheLatestOfItsClicks(): void
    {
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::TWIN_GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::TWIN_B, '5.00000', 'ad_click_id']], self::conversions());
        $reason = self::all('SELECT reason FROM 202_inbound_log WHERE click_id = ' . self::TWIN_B)[0][0];
        self::assertStringContainsString('2 clicks of one campaign carry it', $reason);
    }

    public function testTheLatestIsTheLatestVisitNotTheGreatestId(): void
    {
        // The click with the smaller id is visited again, after the other.
        self::q('INSERT INTO 202_clicks SELECT * FROM 202_clicks WHERE click_id = ' . self::TWIN_A);
        self::q('UPDATE 202_clicks SET click_time = ' . (time() - 100) . ' WHERE click_id = ' . self::TWIN_A
            . ' LIMIT 1');
        LatestVisit::refresh(new Connection(self::$db), self::TWIN_A);

        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::TWIN_GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::TWIN_A, '5.00000', 'ad_click_id']], self::conversions());
    }

    /** @return iterable<string, array{string, bool}> the sale's order number, and whether the postbacks carry it */
    public static function retries(): iterable
    {
        yield 'a sale with an order number' => ['ord-7', true];
        yield 'a sale with none' => ['', false];
    }

    /** @dataProvider retries */
    public function testARetryAfterAnotherReloadStaysWithTheSale(string $order): void
    {
        $postback = ['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::TWIN_GCLID]
            + ($order === '' ? [] : ['txid' => $order]);
        [$status, $body] = self::gpb($postback);
        self::assertSame(200, $status, $body);
        // The visitor reloads once more, and the network sends the sale again.
        self::click(self::TWIN_C, self::USER, self::$campaigns[self::USER][0], 0, ['gclid' => self::TWIN_GCLID], [60]);
        [$status, $body] = self::gpb($postback);

        self::assertLessThan(300, $status, $body);
        $sale = [[(string) self::TWIN_B, '5.00000', 'ad_click_id']];
        self::assertSame($sale, self::conversions(), 'one sale, where it was');
    }

    public function testASaleRecordedByItsSubidIsNotRecordedAgainOnALaterClick(): void
    {
        // The first postback named the earlier click; its retry arrives with the macro unfilled.
        [$status, $body] = self::gpb(['subid' => (string) self::TWIN_A, 'amount' => '5', 'txid' => 'ord-8']);
        self::assertSame(200, $status, $body);
        [$status, $body] = self::gpb(
            ['subid' => '{aff_sub}', 'amount' => '5', 'txid' => 'ord-8', 'gclid' => self::TWIN_GCLID]
        );

        self::assertLessThan(300, $status, $body);
        self::assertSame([[(string) self::TWIN_A, '5.00000', 'click_id']], self::conversions());
    }

    public function testAnotherSaleOfTheSameVisitorGoesToTheLatestClick(): void
    {
        [$status, $body] = self::gpb(['subid' => (string) self::TWIN_A, 'amount' => '5', 'txid' => 'ord-8']);
        self::assertSame(200, $status, $body);
        [$status, $body] = self::gpb(['subid' => '', 'amount' => '9', 'txid' => 'ord-9', 'gclid' => self::TWIN_GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame(
            [[(string) self::TWIN_A, '5.00000', 'click_id'], [(string) self::TWIN_B, '9.00000', 'ad_click_id']],
            self::conversions()
        );
    }

    /** @return iterable<string, array{string, string}> the id two clicks carry, and the one thing they differ in */
    public static function differences(): iterable
    {
        yield 'two campaigns' => [self::CAMPAIGNS_GCLID, 'campaign'];
        yield 'two traffic source accounts' => [self::SOURCES_GCLID, 'traffic source account'];
        yield 'two accounts' => [self::ACCOUNTS_GCLID, 'account'];
    }

    /** @dataProvider differences */
    public function testClicksThatDifferInWhatAReportShowsRecordNothing(string $gclid, string $what): void
    {
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => $gclid]);

        self::assertSame(409, $status, $body);
        self::assertSame(self::AMBIGUOUS, json_decode($body, true)['msg'] ?? null, 'the answer names no account');
        self::assertSame([], self::conversions());
        // One account's clicks: its own log says why, as it can settle it. Across accounts: no account's log.
        $owner = $what === 'account' ? 0 : self::USER;
        $logged = self::all('SELECT user_id, outcome, reason FROM 202_inbound_log WHERE user_id IN (0, '
            . self::USER . ', ' . self::OTHER_USER . ')');
        self::assertCount(1, $logged);
        self::assertSame([(string) $owner, 'refused'], [$logged[0][0], $logged[0][1]]);
        self::assertStringContainsString('are on more than one ' . $what . ',', $logged[0][2], 'the log says why');
    }

    public function testIdsNamingClicksOfDifferentCampaignsRecordNothing(): void
    {
        // Each id is carried by one click: the gclid's on the first campaign, the msclkid's on the second.
        [$status, $body] = self::gpb(
            ['subid' => '', 'amount' => '5', 'gclid' => self::GCLID, 'msclkid' => self::MSCLKID]
        );

        self::assertSame(409, $status, $body);
        self::assertSame(self::AMBIGUOUS, json_decode($body, true)['msg'] ?? null);
        $said = 'The clicks carrying the gclid, msclkid sent are on more than one campaign';
        $logged = self::all('SELECT reason FROM 202_inbound_log WHERE user_id = ' . self::USER);
        self::assertStringContainsString($said, $logged[0][0] ?? '');
        self::assertSame([], self::conversions());
    }

    public function testAClickOlderThanThirtyDaysIsNotMatched(): void
    {
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::OLD_GCLID]);

        self::assertSame(404, $status, $body);
        self::assertStringContainsString('no click of the last 30 days carries the gclid sent', $body);
        self::assertSame([], self::conversions());
    }

    public function testAnIdIsComparedExactly(): void
    {
        $nearly = [strtoupper(self::GCLID), strtolower(self::GCLID), substr(self::GCLID, 0, -1), self::GCLID . 'x',
            strtoupper(self::MSCLKID)];
        foreach ($nearly as $i => $id) {
            $name = $i < 4 ? 'gclid' : 'msclkid';
            // The index names the click for it, as a hash shared with the click's own id would.
            self::indexed($i < 4 ? self::CLICK : self::MICROSOFT_CLICK, $id);
            [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', $name => $id]);
            self::assertSame(404, $status, "$id: $body");
        }
        // The right id under the wrong name is another id.
        [$status] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gbraid' => self::GCLID]);
        self::assertSame(404, $status);
        self::assertSame([], self::conversions());
    }

    public function testAnIdThatFillsItsColumnNamesNoClick(): void
    {
        // The column may have cut it: it then stands for every id that starts so.
        $filled = str_repeat('w', \Prosper202\Click\ClickRecord::AD_CLICK_IDS['gbraid'] - 1) . '0';
        self::carries(self::CLICK, ['gbraid' => $filled]);
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gbraid' => $filled]);
        self::assertSame(404, $status, $body);

        // One character shorter is an id the column holds whole.
        $whole = substr($filled, 1);
        self::carries(self::CLICK, ['gbraid' => $whole]);
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gbraid' => $whole]);
        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'ad_click_id']], self::conversions());
    }

    public function testAnIdNoClickCarriesBesideOneThatDoesIsLeftOut(): void
    {
        [$status, $body] = self::gpb(
            ['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::GCLID, 'fbclid' => 'IwAR0-demo-unknown']
        );

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'ad_click_id']], self::conversions());
    }

    public function testAParameterTheAccountListsCarriesAnIdOfAnyKind(): void
    {
        self::lists(self::USER, 'aff_sub3,sub5');

        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::GCLID]);
        self::assertSame(200, $status, $body);
        // Sent as the network spells it; names are read without case.
        [$status, $body] = self::gpb(['subid' => '', 'amount' => '9'], ['SUB5' => self::MSCLKID]);
        self::assertSame(200, $status, $body);

        self::assertSame([
            [(string) self::CLICK, '8.00000', 'ad_click_id'],
            [(string) self::MICROSOFT_CLICK, '9.00000', 'ad_click_id'],
        ], self::conversions());
        self::assertStringContainsString('the click was found by the aff_sub3 sent', self::all(
            'SELECT reason FROM 202_inbound_log WHERE click_id = ' . self::CLICK
        )[0][0]);
    }

    public function testAParameterNoAccountListsIsNotRead(): void
    {
        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::GCLID]);

        self::assertSame(404, $status);
        self::assertSame('SubID not found', json_decode($body, true)['msg'] ?? null);
        self::assertSame([], self::conversions());
    }

    public function testAListedParameterFindsOnlyItsOwnAccountsClicks(): void
    {
        // The other account's click: one click, inside the 30 days, carrying the id.
        self::lists(self::USER, 'aff_sub3');
        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::OTHER_GCLID]);
        self::assertSame(404, $status, $body);
        self::assertStringContainsString('no click of the last 30 days carries the aff_sub3 sent', $body);

        // And the other way round: only the other account lists it, and the click is this account's.
        self::lists(self::USER, '');
        self::lists(self::OTHER_USER, 'aff_sub3');
        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::GCLID]);
        self::assertSame(404, $status, $body);

        self::assertSame([], self::conversions());
    }

    public function testAListedParametersValueOnTwoCampaignsUnderDifferentKindsRecordsNothing(): void
    {
        // One click holds it as its msclkid, another, on another campaign, as its gbraid: each kind has one click.
        self::carries(self::CLICK, ['gbraid' => self::MSCLKID]);
        self::lists(self::USER, 'aff_sub3');

        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::MSCLKID]);

        self::assertSame(409, $status, $body);
        self::assertSame(self::AMBIGUOUS, json_decode($body, true)['msg'] ?? null);
        self::assertStringContainsString(
            'The clicks carrying the aff_sub3 sent are on more than one campaign',
            self::all('SELECT reason FROM 202_inbound_log WHERE user_id = ' . self::USER)[0][0]
        );
        self::assertSame([], self::conversions());
    }

    public function testAStoredSettingThatIsNotAListOfNamesIsNotRead(): void
    {
        // Not as the settings write it (lower case): it is not guessed at.
        self::lists(self::USER, 'Aff_Sub3');
        [$status, $body] = self::gpb(['subid' => '{aff_sub}', 'amount' => '8', 'aff_sub3' => self::GCLID]);

        self::assertSame(404, $status, $body);
        self::assertSame([], self::conversions());
    }

    /** Pages that wrote a word where the id was meant to go: every visitor shares it, so it names no one. */
    public function testAValueNoPlatformIssuesNamesNoClick(): void
    {
        $campaign = self::$campaigns[self::USER][0];
        // Two visitors whose page wrote the same thing where the id goes, for each form a page writes.
        $values = ['undefined', '__GCLID__', '{{ gclid }}', '{gclid}', '$gclid', 'gclid', 'test', '123'];
        foreach ($values as $i => $value) {
            self::click(self::ADDED[0] + 2 * $i, self::USER, $campaign, 0, ['gclid' => $value], [600]);
            self::click(self::ADDED[0] + 2 * $i + 1, self::USER, $campaign, 0, ['gclid' => $value], [500]);
        }

        foreach ($values as $value) {
            [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => $value]);
            self::assertSame(404, $status, $body);
            self::assertSame('SubID not found', json_decode($body, true)['msg'] ?? null, $value);
        }
        self::assertSame([], self::conversions());
    }

    /** Clicks older than the 30 days are not counted against the limit of clicks told apart. */
    /** A ChatGPT ad's click: OpenAI's oppref, sent back under its own name, finds it. */
    public function testAnUnfilledSubidIsMatchedByTheOpprefSentBack(): void
    {
        $click = self::ADDED[0] + 28;
        $oppref = 'op_Q2hhdEdQVC1kZW1vLXNob3A';
        self::click($click, self::USER, self::$campaigns[self::USER][0], 0, ['oppref' => $oppref], [600]);

        [$status, $body] = self::gpb(['subid' => '', 'amount' => '89.00', 'oppref' => $oppref]);

        self::assertSame(200, $status, $body);
        $recorded = 'SELECT click_id, click_payout, match_method FROM 202_conversion_logs WHERE click_id = ' . $click;
        self::assertSame([[(string) $click, '89.00000', 'ad_click_id']], self::all($recorded));
        [$status, $body] = self::gpb(['subid' => '', 'amount' => '1', 'oppref' => strtolower($oppref)]);
        self::assertSame(404, $status, 'an oppref is compared exactly: ' . $body);
        self::assertCount(1, self::all($recorded));
    }

    public function testOldClicksCarryingTheIdDoNotHideARecentOne(): void
    {
        $old = range(self::ADDED[0] + 2, self::ADDED[0] + 25);
        $campaign = self::$campaigns[self::USER][0];
        $age = ClickByAdClickId::LOOKBACK + 3600;
        foreach ($old as $click) {
            self::click($click, self::USER, $campaign, 0, ['gclid' => self::GCLID], [$age]);
        }

        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'ad_click_id']], self::conversions());
    }

    /**
     * A value that cannot be an id, in a parameter another account lists,
     * does not stop an id that finds its click; alone, it is named.
     */
    public function testAnUnreadableValueBesideAnIdThatFindsItsClickIsNotAsked(): void
    {
        self::lists(self::OTHER_USER, 'aff_sub3');

        [$status, $body] = self::gpb(['subid' => '', 'amount' => '5', 'gclid' => self::GCLID, 'aff_sub3' => ['x']]);
        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '5.00000', 'ad_click_id']], self::conversions());

        [$status, $body] = self::gpb(['subid' => '', 'amount' => '5', 'aff_sub3' => ['x']]);
        self::assertSame(404, $status, $body);
        self::assertSame('SubID not found, and aff_sub3 is not a click id', json_decode($body, true)['msg'] ?? null);
    }

    /**
     * Deployed and not yet upgraded: the lookup is missing, or made and not
     * yet filled. Either way the answer is a 503 naming the upgrade, which a
     * sender retries, not a 500 or a "no click carries it".
     */
    public function testBeforeTheUpgradeHasRunTheAnswerNamesIt(): void
    {
        $versions = array_column(self::all('SELECT version FROM 202_version'), 0);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
        try {
            // Made, and not filled yet: this click has no row.
            self::q('DELETE FROM 202_clicks_ad_ids WHERE click_id = ' . self::CLICK);
            [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::GCLID]);
            self::assertSame(503, $status, $body);
            self::assertStringContainsString('until its database upgrade has run', $body);

            // A click the fill has reached is found.
            [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::TWIN_GCLID]);
            self::assertSame(200, $status, $body);

            self::q('RENAME TABLE 202_clicks_ad_ids TO 202_clicks_ad_ids_away');
            try {
                [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::GCLID]);
            } finally {
                self::q('RENAME TABLE 202_clicks_ad_ids_away TO 202_clicks_ad_ids');
            }
            self::assertSame(503, $status, $body);
            self::assertStringContainsString('until its database upgrade has run', $body);

            // With no id at all, the answer is the one it always was.
            [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5']);
            self::assertSame(404, $status, $body);
            self::assertSame('SubID not found', json_decode($body, true)['msg'] ?? null);
        } finally {
            self::q('DELETE FROM 202_version');
            foreach ($versions as $version) {
                self::row('202_version', ['version' => $version]);
            }
        }
        self::assertSame([[(string) self::TWIN_B, '5.00000', 'ad_click_id']], self::conversions());

        // Upgraded, a click no row names is not there.
        [$status, $body] = self::gpb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::GCLID]);
        self::assertSame(404, $status, $body);
    }

    public function testTheLookupReadsTheIndexByItsKey(): void
    {
        $sql = str_replace(
            ['UNHEX(?)', '>= ?'],
            [
                "UNHEX('" . bin2hex(AdClickIdIndex::hash(self::GCLID)) . "')",
                '>= ' . (time() - ClickByAdClickId::LOOKBACK),
            ],
            AdClickIdIndex::lookupSql(20)
        );
        $plan = self::$db->query('EXPLAIN ' . $sql);
        self::assertInstanceOf(\mysqli_result::class, $plan);
        $rows = $plan->fetch_all(MYSQLI_ASSOC);
        self::assertSame('id_hash', $rows[0]['key'] ?? null, $sql);
        self::assertSame('x', $rows[0]['table'] ?? null);
        $keys = array_column(self::all('SHOW INDEX FROM 202_google'), 2);
        self::assertSame(['PRIMARY'], array_values(array_unique($keys)), '202_google has no key on an id');
    }
}

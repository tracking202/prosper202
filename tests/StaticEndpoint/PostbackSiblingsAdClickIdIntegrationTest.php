<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdIndex;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * The per-campaign postback (pb.php) and the global pixels (gpx.php, upx.php)
 * read a SubID the network left unfilled (empty, or its macro as pasted) as
 * missing, and find the click as gpb.php does: the one click carrying an ad
 * click id the request sends, recorded as `ad_click_id`, with the same
 * Inbound Log reason (V4KD). They used to answer 404 (pb.php) or refuse the
 * macro as a malformed click id (gpx.php, upx.php), losing the conversion.
 * pb.php holds the click to its campaign and the campaign's account; a pixel
 * holds it to its account's postback authentication, and when the id finds
 * no click goes on as it did before the lookup. Each script runs in a
 * process of its own (fixtures/gpb-runner.php).
 *
 * px.php and cb202.php are not asked: neither reads a SubID a network fills.
 * px.php's click is the one its visitor's cookie names, and ClickBank's
 * tracking code is filled by Prosper202's own HopLink redirect.
 *
 * Needs a 202-config.php (connect2.php's DB class lives there) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class PostbackSiblingsAdClickIdIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990812;
    /** An account whose postbacks need its token, and are sent none here. */
    private const OTHER_USER = 990813;

    private const CLICK = 99081201;
    /** The same account's click on its other campaign. */
    private const OTHER_CAMPAIGNS_CLICK = 99081202;
    private const OTHER_USERS_CLICK = 99081203;
    /** Another account's click, its row naming the first account's campaign. */
    private const OTHER_USERS_CLICK_ON_OURS = 99081204;

    private const GCLID = 'Cj0KCQiA-demo_Shop-V4KD';
    private const OTHER_CAMPAIGN_GCLID = 'Cj0KCQiA-demo_Shop-V4KD-B';
    private const OTHER_USER_GCLID = 'Cj0KCQiA-demo_Shop-V4KD-O';
    private const NO_CLICKS_GCLID = 'Cj0KCQiA-demo_Shop-V4KD-NONE';
    private const OTHER_USER_ON_OURS_GCLID = 'Cj0KCQiA-demo_Shop-V4KD-OO';

    private const CLICKS = [self::CLICK, self::OTHER_CAMPAIGNS_CLICK, self::OTHER_USERS_CLICK,
        self::OTHER_USERS_CLICK_ON_OURS];
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_click_latest_visits',
        '202_conversion_logs', '202_dataengine', '202_google', '202_clicks_ad_ids', '202_inbound_log'];

    /** @var array<int, list<array{int, int}>> each account's two campaigns: id and public id */
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
        foreach ([self::USER, self::OTHER_USER] as $n => $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
            foreach (['Store checkout', 'Store newsletter'] as $i => $name) {
                $public = 990812000 + $n * 10 + $i;
                $id = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 0,"
                    . " aff_campaign_name = '$name $user', aff_campaign_url = 'https://shop.example',"
                    . " aff_campaign_id_public = $public, aff_campaign_payout = 3.00,"
                    . " aff_campaign_foreign_payout = 3.00, aff_campaign_time = 0, payout_mode = 'accumulate'");
                self::$campaigns[$user][] = [$id, $public];
            }
        }
        self::postbackAuthOff(self::USER);
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
        self::click(self::CLICK, self::USER, self::$campaigns[self::USER][0][0], self::GCLID);
        self::click(
            self::OTHER_CAMPAIGNS_CLICK,
            self::USER,
            self::$campaigns[self::USER][1][0],
            self::OTHER_CAMPAIGN_GCLID
        );
        self::click(
            self::OTHER_USERS_CLICK,
            self::OTHER_USER,
            self::$campaigns[self::OTHER_USER][0][0],
            self::OTHER_USER_GCLID
        );
        self::click(
            self::OTHER_USERS_CLICK_ON_OURS,
            self::OTHER_USER,
            self::$campaigns[self::USER][0][0],
            self::OTHER_USER_ON_OURS_GCLID
        );
    }

    /** A click as the click path leaves it: its visit, its gclid and the index row naming it. */
    private static function click(int $click, int $user, int $campaign, string $gclid): void
    {
        $columns = "click_id = $click, user_id = $user, aff_campaign_id = $campaign, landing_page_id = 0,"
            . ' ppc_account_id = 0, click_cpc = 0.25, click_payout = 3.00, click_lead = 0, click_time = '
            . (time() - 600);
        self::q("INSERT INTO 202_clicks SET $columns");
        self::q("INSERT INTO 202_clicks_spy SET $columns");
        self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0");
        self::row('202_google', ['click_id' => $click, 'gclid' => $gclid, 'utm_source_id' => 0,
            'utm_medium_id' => 0, 'utm_campaign_id' => 0, 'utm_term_id' => 0, 'utm_content_id' => 0]);
        self::q("INSERT IGNORE INTO 202_clicks_ad_ids SET click_id = $click, id_hash = UNHEX('"
            . bin2hex(AdClickIdIndex::hash($gclid)) . "')");
        LatestVisit::refresh(new Connection(self::$db), $click);
    }

    private static function deleteClicks(): void
    {
        foreach (self::CLICKS as $click) {
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
     * @return array{int, string} status and body
     */
    private static function request(string $script, array $get): array
    {
        $env = getenv();
        $env['P202_TEST_SCRIPT'] = $script;
        $env['P202_TEST_GET'] = json_encode((object) $get, JSON_THROW_ON_ERROR);
        $env['P202_TEST_POST'] = '{}';
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
        self::assertSame(0, $exit, "$script exited $exit: $err\n$out");
        $ids = [self::GCLID, self::OTHER_CAMPAIGN_GCLID, self::OTHER_USER_GCLID, self::NO_CLICKS_GCLID,
            self::OTHER_USER_ON_OURS_GCLID];
        foreach ($ids as $id) {
            self::assertStringNotContainsString($id, $err . $out, 'no answer or log line carries an id\'s value');
        }
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
    }

    /** @param array<string, string> $get */
    private static function pb(array $get, int $campaign = 0): array
    {
        return self::request('pb.php', ['acip' => (string) self::$campaigns[self::USER][$campaign][1]] + $get);
    }

    /** @return list<array{string, string, string}> click, pixel type and match method of each conversion */
    private static function conversions(): array
    {
        return self::all('SELECT click_id, pixel_type, match_method FROM 202_conversion_logs WHERE click_id IN ('
            . implode(',', self::CLICKS) . ') ORDER BY conv_id');
    }

    /** @return list<array<int, string|null>> */
    private static function all(string $sql): array
    {
        $result = self::$db->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }

        return $result->fetch_all(MYSQLI_NUM);
    }

    /** @return list<array<int, string|null>> outcome, reason, click and match method of the logged requests */
    private static function logged(): array
    {
        return self::all('SELECT outcome, reason, click_id, match_method FROM 202_inbound_log WHERE user_id IN (0, '
            . self::USER . ', ' . self::OTHER_USER . ') ORDER BY inbound_id');
    }

    /** @return iterable<string, array{string}> */
    public static function unfilled(): iterable
    {
        yield "Prosper202's own token" => ['[[subid]]'];
        yield "a network's macro" => ['{aff_sub}'];
        yield 'nothing' => [''];
    }

    /** @dataProvider unfilled */
    public function testThePostbackFindsTheClickByTheGclidSentBack(string $subid): void
    {
        [$status, $body] = self::pb(['subid' => $subid, 'amount' => '5', 'gclid' => self::GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '2', 'ad_click_id']], self::conversions());
        $logged = self::logged();
        self::assertCount(1, $logged);
        self::assertSame('recorded', $logged[0][0]);
        self::assertStringContainsString('the click was found by the gclid sent', (string) $logged[0][1]);
        self::assertSame([(string) self::CLICK, 'ad_click_id'], [$logged[0][2], $logged[0][3]]);
    }

    public function testThePostbackHoldsTheClickToItsCampaignAndAccount(): void
    {
        // The account's click on its other campaign, another account's click, and another account's click
        // whose row names this campaign.
        foreach ([self::OTHER_CAMPAIGN_GCLID, self::OTHER_USER_GCLID, self::OTHER_USER_ON_OURS_GCLID] as $gclid) {
            [$status, $body] = self::pb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => $gclid]);
            self::assertSame(404, $status, $body);
            self::assertSame('Unknown subid for this campaign', json_decode($body, true)['msg'] ?? null);
        }
        self::assertSame([], self::conversions());
    }

    public function testThePostbackAnswersAsBeforeWhenNoIdIsSent(): void
    {
        [$status, $body] = self::pb(['subid' => '[[subid]]', 'amount' => '5']);
        self::assertSame(404, $status, $body);
        self::assertSame('Missing or malformed subid', json_decode($body, true)['msg'] ?? null);

        [$status, $body] = self::pb(['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::NO_CLICKS_GCLID]);
        self::assertSame(404, $status, $body);
        self::assertStringContainsString('no click of the last 30 days carries the gclid sent', $body);
        self::assertSame([], self::conversions());
    }

    public function testAPostbackSubidThatIsThereIsReadAsBefore(): void
    {
        $postback = ['subid' => (string) self::CLICK, 'amount' => '5', 'gclid' => self::OTHER_USER_GCLID];
        [$status, $body] = self::pb($postback);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, '2', 'click_id']], self::conversions());
    }

    /** @return iterable<string, array{string, string, string}> script, pixel type, subid */
    public static function pixels(): iterable
    {
        foreach (['gpx.php' => '1', 'upx.php' => '3'] as $script => $type) {
            yield "$script, Prosper202's own token" => [$script, $type, '[[subid]]'];
            yield "$script, a network's macro" => [$script, $type, '%25aff_sub%25'];
        }
    }

    /** @dataProvider pixels */
    public function testAPixelFindsTheClickByTheGclidItSends(string $script, string $type, string $subid): void
    {
        [$status, $body] = self::request($script, ['subid' => $subid, 'amount' => '5', 'gclid' => self::GCLID]);

        self::assertSame(200, $status, $body);
        self::assertSame([[(string) self::CLICK, $type, 'ad_click_id']], self::conversions());
        $logged = self::logged();
        self::assertCount(1, $logged);
        self::assertSame('recorded', $logged[0][0]);
        self::assertStringContainsString('the click was found by the gclid sent', (string) $logged[0][1]);
        self::assertSame([(string) self::CLICK, 'ad_click_id'], [$logged[0][2], $logged[0][3]]);
    }

    /** @return iterable<string, array{string}> */
    public static function pixelScripts(): iterable
    {
        yield 'gpx.php' => ['gpx.php'];
        yield 'upx.php' => ['upx.php'];
    }

    /** @dataProvider pixelScripts */
    public function testAPixelHoldsAnotherAccountsClickToThatAccountsAuthentication(string $script): void
    {
        self::request($script, ['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::OTHER_USER_GCLID]);

        self::assertSame([], self::conversions());
        self::assertSame([['unauthenticated', (string) self::OTHER_USERS_CLICK, 'ad_click_id']], array_map(
            static fn (array $row): array => [$row[0], $row[2], $row[3]],
            self::logged()
        ));
    }

    /** @dataProvider pixelScripts */
    public function testAPixelWhoseIdFindsNoClickSaysSo(string $script): void
    {
        self::request($script, ['subid' => '[[subid]]', 'amount' => '5', 'gclid' => self::NO_CLICKS_GCLID]);

        self::assertSame([], self::conversions());
        $logged = self::logged();
        self::assertCount(1, $logged);
        self::assertStringContainsString(
            'The SubID was not filled in, and the ad click id sent did not find the click: SubID not found, and no'
                . ' click of the last 30 days carries the gclid sent.',
            (string) $logged[0][1]
        );
    }

    /** @dataProvider pixelScripts */
    public function testAPixelSubidThatIsNotAClickIsStillRefused(string $script): void
    {
        self::request($script, ['subid' => '{aff_sub}x', 'amount' => '5', 'gclid' => self::GCLID]);

        self::assertSame([], self::conversions());
        self::assertSame('malformed_click', self::logged()[0][0] ?? null);
    }
}

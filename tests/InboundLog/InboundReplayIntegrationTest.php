<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use Api\V3\Controllers\InboundLogController;
use Api\V3\Controllers\PostbackAuthController;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;
use Prosper202\Conversion\PostbackAuth\PostbackAuthReadiness;
use Prosper202\Conversion\PostbackAuth\PostbackAuthStore;
use Prosper202\Database\Connection;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\InboundReplay;
use Prosper202\InboundLog\RequestLog;

/**
 * On a real server: "record it now" records a refused postback once, on the
 * account's own click and campaign only, through the recorder pb.php calls;
 * the log's search finds a request by the ids it carried; what each request
 * presented is kept; and the readiness report says who a stricter postback
 * authentication would refuse.
 *
 * @group integration
 */
final class InboundReplayIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990711;
    private const OTHER = 990712;
    private const CLICK = 99071101;
    private const OTHER_CLICK = 99071201;
    private const CAMPAIGN = 990771;
    private const CAMPAIGN_PUBLIC = 9907711;
    private const SECOND_CAMPAIGN = 990772;
    private const SECOND_PUBLIC = 9907721;
    private const OTHER_CAMPAIGN = 990773;
    private const OTHER_PUBLIC = 9907731;
    private const ARRIVED = 1790000000;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        self::cleanUp();
        foreach ([self::ACCOUNT, self::OTHER] as $user) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
        }
        foreach (
            [
            [self::CAMPAIGN, self::CAMPAIGN_PUBLIC, self::ACCOUNT, 'Northwind Coffee subscription'],
            [self::SECOND_CAMPAIGN, self::SECOND_PUBLIC, self::ACCOUNT, 'Northwind Tea sampler'],
            [self::OTHER_CAMPAIGN, self::OTHER_PUBLIC, self::OTHER, 'Fabrikam CRM trial'],
            ] as [$id, $public, $user, $name]
        ) {
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, aff_campaign_id_public = $public, user_id = $user,"
                . ' aff_network_id = 1, aff_campaign_name = ' . self::str($name) . ','
                . " aff_campaign_url = 'https://shop.northwind.example/?sid=[[subid]]',"
                . ' aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        $db = self::requireScratch();
        self::q('DELETE FROM 202_inbound_log');
        self::q('DELETE FROM 202_postback_auth WHERE user_id IN (' . self::ACCOUNT . ', ' . self::OTHER . ')');
        self::clearClicks();
        $clicks = new MysqlClickRepository(new Connection($db));
        foreach ([[self::CLICK, self::ACCOUNT, self::CAMPAIGN], [self::OTHER_CLICK, self::OTHER, self::OTHER_CAMPAIGN]] as [$click, $user, $campaign]) {
            $clicks->recordClick(new ClickRecord(
                clickId: $click,
                userId: $user,
                clickTime: self::ARRIVED - 600,
                affCampaignId: $campaign,
                clickPayout: '30.00',
                recordsCampaignRows: false,
            ));
        }
        unset($_SERVER['QUERY_STRING']);
    }

    protected function tearDown(): void
    {
        RequestLog::reset();
        unset($_SERVER['QUERY_STRING']);
    }

    private static function clearClicks(): void
    {
        $clicks = self::CLICK . ', ' . self::OTHER_CLICK;
        foreach (
            ['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks', '202_dataengine'] as $table
        ) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        self::q("DELETE FROM 202_notification_pending WHERE user_id IN (" . self::ACCOUNT . ', ' . self::OTHER . ')');
        self::q("DELETE FROM 202_conversion_logs WHERE click_id IN ($clicks)");
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_inbound_log');
        self::clearClicks();
        $users = self::ACCOUNT . ', ' . self::OTHER;
        self::q("DELETE FROM 202_postback_auth WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    /**
     * A refused request in the log, as the endpoint would have left it; answers its id.
     * $click and $match are what an endpoint says of the click it found before it can refuse.
     */
    private static function refused(
        string $endpoint,
        array $fields,
        string $outcome = 'unauthenticated',
        int $user = self::ACCOUNT,
        string $method = 'GET',
        ?int $click = null,
        ?string $match = null,
    ): int {
        $written = (new InboundLog(self::requireScratch()))->append(new Entry(
            $endpoint,
            $method,
            403,
            $outcome,
            'This account accepts only authenticated postbacks and pixels.',
            $user,
            $click,
            null,
            null,
            \Prosper202\InboundLog\Redactor::fields($fields, $endpoint),
            'none',
            $match,
        ), self::ARRIVED);
        self::assertTrue($written);

        return (int) self::$db->insert_id;
    }

    /** @return list<array<string, mixed>> the click's conversion rows, oldest first */
    private static function conversions(int $click = self::CLICK): array
    {
        $result = self::$db->query('SELECT conv_id, user_id, campaign_id, transaction_id, click_payout, conv_time, source, user_agent, pixel_type, match_method, event_name'
            . " FROM 202_conversion_logs WHERE click_id = $click ORDER BY conv_id");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed> */
    private static function logRow(int $id): array
    {
        $row = (new InboundLog(self::requireScratch()))->find(self::ACCOUNT, $id);
        self::assertNotNull($row);

        return $row;
    }

    public function testARefusedGlobalPostbackIsRecordedOnceAsItWouldHaveBeen(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::CLICK, 'amount' => '12.50', 'txid' => 'NW-1001', 'p202_token' => 'stale']);
        self::assertTrue(self::logRow($id)['can_record']);
        $replay = new InboundReplay(self::requireScratch());

        $first = $replay->replay(self::ACCOUNT, $id, self::ARRIVED + 3600);

        self::assertSame('recorded', $first['status'], $first['message']);
        $rows = self::conversions();
        self::assertCount(1, $rows);
        self::assertSame(
            [self::ACCOUNT, self::CAMPAIGN, 'NW-1001', 12.5, self::ARRIVED, 'postback', InboundReplay::USER_AGENT],
            [(int) $rows[0]['user_id'], (int) $rows[0]['campaign_id'], $rows[0]['transaction_id'], (float) $rows[0]['click_payout'],
                (int) $rows[0]['conv_time'], $rows[0]['source'], $rows[0]['user_agent']],
            'the amount, the transaction id and the day are the request\'s'
        );
        $row = self::logRow($id);
        self::assertSame([(int) $rows[0]['conv_id'], self::ARRIVED + 3600, false], [$row['conversion_id'], $row['recorded_later_at'], $row['can_record']]);

        // Pressed again: the row says so, and nothing is written.
        $second = $replay->replay(self::ACCOUNT, $id, self::ARRIVED + 3700);
        self::assertSame(['already_recorded', (int) $rows[0]['conv_id']], [$second['status'], $second['conversion_id']]);
        self::assertCount(1, self::conversions());
    }

    public function testARowGivenBackIsNotRecordedTwiceWithOrWithoutATransactionId(): void
    {
        $replay = new InboundReplay(self::requireScratch());
        $keyed = self::refused('gpb', ['subid' => (string) self::CLICK, 'txid' => 'NW-1002']);
        self::assertSame('recorded', $replay->replay(self::ACCOUNT, $keyed)['status']);
        // As after a failure that hid the write: the row is free again and the conversion is there.
        self::q("UPDATE 202_inbound_log SET replayed_at = NULL, replay_done = 0, conversion_id = NULL WHERE inbound_id = $keyed");

        $again = $replay->replay(self::ACCOUNT, $keyed);

        self::assertSame('duplicate', $again['status'], $again['message']);
        self::assertCount(1, self::conversions());
        self::assertSame((int) self::conversions()[0]['conv_id'], self::logRow($keyed)['conversion_id']);

        // No transaction id: a click converts once, so the second request is refused and the row stays free.
        $plain = self::refused('gpb', ['subid' => (string) self::CLICK]);
        $refused = $replay->replay(self::ACCOUNT, $plain);
        self::assertSame('refused', $refused['status']);
        self::assertStringContainsString('converted already', $refused['message']);
        self::assertCount(1, self::conversions());
        self::assertSame([null, null], [self::logRow($plain)['conversion_id'], self::logRow($plain)['record_started_at']]);
    }

    public function testTwoPressesAtOnceLetOneWrite(): void
    {
        $log = new InboundLog(self::requireScratch());
        $id = self::refused('gpb', ['subid' => (string) self::CLICK]);

        self::assertTrue($log->claimReplay(self::ACCOUNT, $id, self::ARRIVED));
        self::assertFalse($log->claimReplay(self::ACCOUNT, $id, self::ARRIVED + 5), 'the second caller does not write');
        self::assertSame('in_progress', (new InboundReplay(self::requireScratch()))->replay(self::ACCOUNT, $id, self::ARRIVED + 5)['status']);
        self::assertSame([], self::conversions());
        // A claim nobody finished is taken again once it is stale.
        self::assertTrue($log->claimReplay(self::ACCOUNT, $id, self::ARRIVED + InboundLog::REPLAY_CLAIM_SECONDS + 1));
    }

    public function testACampaignPostbackRecordsOnlyOnTheAccountsOwnCampaignAndClick(): void
    {
        $replay = new InboundReplay(self::requireScratch());

        // The acip of another account's campaign names nothing here.
        $foreign = self::refused('pb', ['acip' => (string) self::OTHER_PUBLIC, 'subid' => (string) self::CLICK]);
        $answer = $replay->replay(self::ACCOUNT, $foreign);
        self::assertSame('refused', $answer['status']);
        self::assertStringContainsString('not one of your account', $answer['message']);

        // A campaign of the account the click never visited.
        $mismatch = self::refused('pb', ['acip' => (string) self::SECOND_PUBLIC, 'subid' => (string) self::CLICK]);
        $answer = $replay->replay(self::ACCOUNT, $mismatch);
        self::assertSame('refused', $answer['status']);
        self::assertStringContainsString('never visited the campaign', $answer['message']);

        // Another account's click, named by a request logged on this account.
        $theirs = self::refused('gpb', ['subid' => (string) self::OTHER_CLICK]);
        $answer = $replay->replay(self::ACCOUNT, $theirs);
        self::assertSame('refused', $answer['status']);
        self::assertStringContainsString('another account', $answer['message']);

        self::assertSame([], self::conversions());
        self::assertSame([], self::conversions(self::OTHER_CLICK));
        foreach ([$foreign, $mismatch, $theirs] as $id) {
            self::assertNull(self::logRow($id)['record_started_at'], 'a refused row is given back');
        }

        $own = self::refused('pb', ['acip' => (string) self::CAMPAIGN_PUBLIC, 'subid' => (string) self::CLICK, 'amount' => '99']);
        $answer = $replay->replay(self::ACCOUNT, $own);
        self::assertSame('recorded', $answer['status'], $answer['message']);
        $rows = self::conversions();
        // pb.php takes the campaign's payout, never the request's amount.
        self::assertSame([self::CAMPAIGN, 30.0, 'legacy_pixel'], [(int) $rows[0]['campaign_id'], (float) $rows[0]['click_payout'], $rows[0]['source']]);
    }

    public function testAnotherAccountCannotRecordOrSeeTheRow(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::CLICK]);

        $answer = (new InboundReplay(self::requireScratch()))->replay(self::OTHER, $id);

        self::assertSame('not_found', $answer['status']);
        self::assertSame([], self::conversions());
        self::assertNull(self::logRow($id)['record_started_at']);
        $this->expectException(NotFoundException::class);
        (new InboundLogController(self::requireScratch(), self::OTHER, true))->recordNow($id);
    }

    public function testOnlyAWholeRequestRefusedForAFixableReasonIsOffered(): void
    {
        $subid = ['subid' => (string) self::CLICK];
        $notOffered = [
            'a pixel logged without its click' => self::refused('gpx', $subid),
            'a payment notification' => self::refused('jvzoo', ['ctransreceipt' => 'NW-9']),
            'a refusal the recorder would repeat' => self::refused('gpb', $subid, 'unknown_click'),
            'a form post logged without its body' => self::refused('gpb', $subid, 'unauthenticated', self::ACCOUNT, 'POST'),
            'a redacted value' => self::refused('gpb', $subid + ['txid' => 'buyer@example.com']),
            'a cut value' => self::refused('gpb', $subid + ['txid' => str_repeat('9', 200)]),
            'event properties not kept' => self::refused('gpb', $subid + ['event' => 'signup', 'event_props' => '{"email":"x"}']),
            'a signed subid' => self::refused('gpb', ['subid' => '1-2-abc']),
            // The customer's reference is never kept, so recording would drop the sale's link to its customer.
            'a customer reference' => self::refused('gpb', $subid + ['amount' => '12.50', 'cust' => 'C-20391']),
            'a customer reference under its other name' => self::refused('gpx', ['customer_ref' => 'C-20391'], 'unauthenticated', self::ACCOUNT, 'GET', self::CLICK, 'cookie'),
            'a signed customer' => self::refused('pb', $subid + ['acip' => (string) self::CAMPAIGN_PUBLIC, 'cust_sig' => 'abc']),
            'no campaign' => self::refused('pb', $subid),
        ];
        $replay = new InboundReplay(self::requireScratch());
        foreach ($notOffered as $what => $id) {
            self::assertFalse(self::logRow($id)['can_record'], $what);
            self::assertSame('not_recordable', $replay->replay(self::ACCOUNT, $id)['status'], $what);
        }
        self::assertSame([], self::conversions());

        self::assertTrue(self::logRow(self::refused('gpb', $subid, 'failed'))['can_record'], 'an error here is fixable');
        $blank = self::logRow(self::refused('gpb', $subid + ['cust' => '', 'cust_type' => '']));
        self::assertSame(['', true], [$blank['fields']['cust'], $blank['can_record']], 'an unfilled customer macro names nobody');
        // A request no account was found for is nobody's to record.
        $unattributed = self::refused('gpb', $subid, 'unauthenticated', 0);
        $listed = (new InboundLog(self::requireScratch()))->list(self::ACCOUNT, true, ['search' => (string) self::CLICK]);
        $row = array_values(array_filter($listed, static fn (array $r): bool => $r['inbound_id'] === $unattributed))[0];
        self::assertFalse($row['can_record']);
        self::assertSame('not_found', $replay->replay(self::ACCOUNT, $unattributed)['status']);

        try {
            (new InboundLogController(self::requireScratch(), self::ACCOUNT, false))->recordNow($notOffered['a payment notification']);
            self::fail('the API refuses a row that is not offered');
        } catch (ValidationException $refused) {
            self::assertStringContainsString('payment notification', $refused->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: int, 4: string, 5: float, 6: string|null, 7: int}> */
    public static function pixels(): array
    {
        $click = (string) self::CLICK;

        return [
            // endpoint, fields, match, pixel type, source, payout, transaction id, traffic-source postbacks queued
            'the global pixel, by its cookie' => ['gpx', ['amount' => '7.25', 'txid' => 'NW-3001'], 'cookie', 1, 'pixel', 7.25, 'NW-3001', 0],
            'the global pixel, guessed from the address' => ['gpx', [], 'same_ip', 1, 'pixel', 30.0, null, 0],
            'the universal pixel, by its subid' => ['upx', ['subid' => $click, 'amount' => '9'], 'click_id', 3, 'universal_pixel', 9.0, null, 1],
            // A global postback whose SubID was unfilled, its click found by the ad click id it sent back.
            'the global postback, by its ad click id' => ['gpb', ['subid' => '', 'gclid' => 'Cj0KCQ-demo', 'amount' => '4', 'txid' => 'NW-3003'],
                'ad_click_id', 2, 'postback', 4.0, 'NW-3003', 1],
            // px.php reads neither an amount nor a transaction id.
            'the campaign pixel' => ['px', ['acip' => (string) self::CAMPAIGN_PUBLIC, 'amount' => '99', 'txid' => 'NW-3002'],
                'cookie', 1, 'legacy_pixel', 30.0, null, 0],
        ];
    }

    /**
     * @dataProvider pixels
     * @param array<string, string> $fields
     */
    public function testARefusedPixelIsRecordedAsItsOwnEndpointRecords(
        string $endpoint,
        array $fields,
        string $match,
        int $pixelType,
        string $source,
        float $payout,
        ?string $txid,
        int $queued,
    ): void {
        self::trafficSourcePostback();
        $id = self::refused($endpoint, $fields, 'unauthenticated', self::ACCOUNT, 'GET', self::CLICK, $match);
        self::assertTrue(self::logRow($id)['can_record']);
        $replay = new InboundReplay(self::requireScratch());

        $answer = $replay->replay(self::ACCOUNT, $id);

        self::assertSame('recorded', $answer['status'], $answer['message']);
        $rows = self::conversions();
        self::assertCount(1, $rows);
        self::assertSame(
            [$pixelType, $source, $match, $payout, $txid, self::ARRIVED],
            [(int) $rows[0]['pixel_type'], $rows[0]['source'], $rows[0]['match_method'], (float) $rows[0]['click_payout'],
                $rows[0]['transaction_id'] === '' ? null : $rows[0]['transaction_id'], (int) $rows[0]['conv_time']]
        );
        $pending = self::$db->query('SELECT COUNT(*) FROM 202_notification_pending WHERE conv_id = ' . (int) $rows[0]['conv_id'])->fetch_row();
        self::assertSame($queued, (int) $pending[0], 'the traffic source is told only where the endpoint tells it');

        self::assertSame('already_recorded', $replay->replay(self::ACCOUNT, $id)['status']);
        self::assertCount(1, self::conversions());
    }

    /** A traffic source account on the click with one server postback, so a queued notification can be counted. */
    private static function trafficSourcePostback(): void
    {
        self::q('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = 990781');
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = 990781');
        self::q('DELETE FROM 202_ppc_networks WHERE ppc_network_id = 990781');
        self::q("INSERT INTO 202_ppc_networks SET ppc_network_id = 990781, user_id = " . self::ACCOUNT . ", ppc_network_name = 'Search ads', ppc_network_time = 1");
        self::q("INSERT INTO 202_ppc_accounts SET ppc_account_id = 990781, user_id = " . self::ACCOUNT
            . ", ppc_network_id = 990781, ppc_account_name = 'Search ads main', ppc_account_time = 1");
        self::q("INSERT INTO 202_ppc_account_pixels SET ppc_account_id = 990781, pixel_type_id = 4,"
            . " pixel_code = 'https://ads.example/postback?id=[[subid]]'");
        self::q('UPDATE 202_clicks SET ppc_account_id = 990781 WHERE click_id = ' . self::CLICK);
    }

    public function testAPixelsClickOfAnotherAccountIsRefused(): void
    {
        $id = self::refused('gpx', [], 'unauthenticated', self::ACCOUNT, 'GET', self::OTHER_CLICK, 'cookie');

        $answer = (new InboundReplay(self::requireScratch()))->replay(self::ACCOUNT, $id);

        self::assertSame('refused', $answer['status']);
        self::assertStringContainsString('another account', $answer['message']);
        self::assertSame([], self::conversions(self::OTHER_CLICK));
    }

    public function testAFormPostLoggedWithItsBodyIsRecordedFromIt(): void
    {
        // What gpb.php leaves from now on: the body's values with the query's, and the click it read.
        $id = self::refused('gpb', ['subid' => (string) self::CLICK, 'amount' => '18.40', 'txid' => 'NW-4001'],
            'unauthenticated', self::ACCOUNT, 'POST', self::CLICK, 'click_id');

        $answer = (new InboundReplay(self::requireScratch()))->replay(self::ACCOUNT, $id);

        self::assertSame('recorded', $answer['status'], $answer['message']);
        $rows = self::conversions();
        self::assertSame([18.4, 'NW-4001', 'postback'], [(float) $rows[0]['click_payout'], $rows[0]['transaction_id'], $rows[0]['source']]);
    }

    public function testAnEventOnACampaignWithoutGoalsRecordsItsConversionWithTheEventsName(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::CLICK, 'event' => 'signup', 'event_props' => '{"plan":"pro"}', 'txid' => 'NW-5001']);
        self::assertTrue(self::logRow($id)['can_record']);

        $answer = (new InboundReplay(self::requireScratch()))->replay(self::ACCOUNT, $id);

        self::assertSame('recorded', $answer['status'], $answer['message']);
        self::assertSame(['signup', 'NW-5001'], [self::conversions()[0]['event_name'], self::conversions()[0]['transaction_id']]);

        // A malformed event is the recorder's refusal, and the row is given back.
        $bad = self::refused('gpb', ['subid' => (string) self::CLICK, 'event' => 'sign up!']);
        $answer = (new InboundReplay(self::requireScratch()))->replay(self::ACCOUNT, $bad);
        self::assertSame('refused', $answer['status'], $answer['message']);
        self::assertNull(self::logRow($bad)['record_started_at']);
        self::assertCount(1, self::conversions());
    }

    public function testTheEndpointsNotesAreKeptOnTheRow(): void
    {
        $db = self::requireScratch();
        $_SERVER['QUERY_STRING'] = 'subid=' . self::CLICK;
        $log = RequestLog::watch($db, 'gpb', 'POST', ['subid' => (string) self::CLICK]);
        // As gpb.php: the request with its form body, then the click it read, then the refusal.
        $log->request(['subid' => (string) self::CLICK, 'amount' => '18.40', 'card' => '4111111111111111', 'p202_token' => 'x']);
        $log->matched(self::CLICK, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        $log->account(self::ACCOUNT);
        $log->refuse('unauthenticated', 'This account accepts only authenticated postbacks and pixels.', 403);
        $log->write();

        $row = (new InboundLog($db))->list(self::ACCOUNT, false)[0];

        self::assertSame([self::CLICK, 'click_id', '18.40', '[redacted]', '[redacted]', true], [
            $row['click_id'], $row['match_method'], $row['fields']['amount'], $row['fields']['card'], $row['fields']['p202_token'],
            $row['can_record'],
        ], 'the body\'s amount is kept, and nothing the recorder does not read');
    }

    public function testEventPropertiesAreKeptOnlyWhole(): void
    {
        $kept = static fn (string $props): string => \Prosper202\InboundLog\Redactor::fields(['event' => 'signup', 'event_props' => $props], 'gpb')['event_props'];

        self::assertSame('{"plan":"pro","seats":3}', $kept('{"plan":"pro","seats":3}'));
        foreach ([
            '{"email":"buyer@example.com"}', '{"note":"from 203.0.113.9"}', '{"api_key":"abc"}', '{"plan":{"customer_name":"x"}}',
            'not json', '["a"]', '{"plan":"' . str_repeat('p', 1100) . '"}',
        ] as $props) {
            self::assertSame('[redacted]', $kept($props), $props);
        }
        self::assertSame('[redacted]', \Prosper202\InboundLog\Redactor::fields(['event_props' => '{"plan":"pro"}'], 'jvzoo')['event_props']);
    }

    public function testTheApiRecordsAndAnswersTheSecondCallWithTheSameConversion(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::CLICK, 'txid' => 'NW-1003']);
        $api = new InboundLogController(self::requireScratch(), self::ACCOUNT, false);

        $first = $api->recordNow($id);
        $second = $api->recordNow($id);

        self::assertSame([201, 'recorded'], [$first['_status'], $first['data']['status']]);
        self::assertSame('already_recorded', $second['data']['status']);
        self::assertArrayNotHasKey('_status', $second);
        self::assertSame($first['data']['conversion_id'], $second['data']['conversion_id']);
        self::assertCount(1, self::conversions());
    }

    public function testASearchFindsARequestByTheIdsItCarried(): void
    {
        $store = new InboundLog(self::requireScratch());
        $bySubid = self::refused('gpb', ['subid' => '4410', 'txid' => 'ORD-77']);
        $other = self::refused('gpb', ['subid' => '44100', 'txid' => 'ORD-778', 'amount' => '4410']);
        $store->append(new Entry('pb', 'GET', 200, 'recorded', 'A conversion was recorded.', self::ACCOUNT, 4410, 812, 'ord-77'));
        $recorded = (int) self::$db->insert_id;
        $store->append(new Entry('gpb', 'GET', 403, 'unauthenticated', 'x', self::OTHER, null, null, null, ['subid' => '4410']));
        $ids = static fn (string $search): array => array_column($store->list(self::ACCOUNT, false, ['search' => $search]), 'inbound_id');

        self::assertSame([$recorded, $bySubid], $ids('4410'), 'the click id, as the row\'s click or the request\'s subid; never an amount');
        self::assertSame([$recorded, $bySubid], $ids('ORD-77'), 'the transaction id, whole');
        self::assertSame([$recorded], $ids('812'), 'the conversion id');
        self::assertSame([$other], $ids('ORD-778'));
        self::assertSame([], $ids('ORD-7%'), 'a wildcard is a character');
        self::assertSame([], $ids('ORD_77'), 'so is an underscore');
        $odd = self::refused('gpb', ['subid' => '9', 'txid' => 'A_B\\C|D%']);
        self::assertSame([$odd], $ids('A_B\\C|D%'), 'and a backslash and the escape character are found as sent');
        self::assertSame([], $ids('AxB\\C|D%'));
        self::assertSame([], $ids('ORD'));

        $page = (new InboundLogController(self::requireScratch(), self::ACCOUNT, false))->list(['search' => ' 4410 ']);
        self::assertSame([$recorded, $bySubid], array_column($page['data'], 'inbound_id'));
        $this->expectException(ValidationException::class);
        InboundLogController::filters(['search' => str_repeat('x', 300)]);
    }

    public function testEachRefusalSaysWhatToDoAndWhere(): void
    {
        $id = self::refused('pb', ['acip' => '1', 'subid' => '2']);
        $fix = self::logRow($id)['fix'];
        self::assertSame('tracking202/setup/get_postback.php', $fix['page']);
        self::assertStringContainsString('token', $fix['text']);

        $store = new InboundLog(self::requireScratch());
        $store->append(new Entry('pb', 'GET', 200, 'recorded', 'A conversion was recorded.', self::ACCOUNT));
        self::assertNull($store->list(self::ACCOUNT, false)[0]['fix'], 'nothing to fix on a request that recorded');
        foreach (['unknown_campaign', 'unknown_click', 'no_click', 'malformed_click', 'campaign_mismatch', 'already_lead', 'unverified'] as $outcome) {
            $store->append(new Entry('pb', 'GET', 404, $outcome, 'x', self::ACCOUNT));
            $fix = $store->list(self::ACCOUNT, false)[0]['fix'];
            self::assertNotNull($fix, $outcome);
            self::assertFileExists(__DIR__ . '/../../' . $fix['page'], $outcome . ' links a page that exists');
        }
    }

    public function testTheLogKeepsWhatEachRequestPresentedWhateverTheMode(): void
    {
        $db = self::requireScratch();
        $store = new PostbackAuthStore(new Connection($db));
        $settings = $store->ensureCredentials(self::ACCOUNT, time());
        $store->setMode(self::ACCOUNT, PostbackAuthMode::OFF, time());
        $cases = [
            'none' => 'subid=1',
            'token' => 'subid=1&p202_token=' . $settings->token,
            'invalid' => 'subid=1&p202_token=not-the-token',
        ];
        foreach ($cases as $query) {
            $_SERVER['QUERY_STRING'] = $query;
            parse_str($query, $get);
            $log = RequestLog::watch($db, 'gpb', 'GET', $get);
            $log->account(self::ACCOUNT);
            $log->refuse('unknown_click', 'No click has this id.', 404);
            $log->write();
        }
        // No account found: nothing to read the credentials against.
        $_SERVER['QUERY_STRING'] = 'subid=1';
        RequestLog::watch($db, 'gpb', 'GET', ['subid' => '1'])->write();

        $rows = (new InboundLog($db))->list(self::ACCOUNT, true);
        self::assertSame([null, 'invalid', 'token', 'none'], array_column($rows, 'auth'));
        self::assertSame('[redacted]', $rows[2]['fields']['p202_token'], 'the token itself is never kept');
    }

    public function testReadinessNamesWhoAStricterModeWouldRefuse(): void
    {
        $db = self::requireScratch();
        $now = self::ARRIVED + 3600;
        $readiness = new PostbackAuthReadiness(new Connection($db));
        self::assertNull($readiness->forAccount(self::ACCOUNT, $now)['safe_to_require_token'], 'nothing seen is not "safe"');

        // A conversion recorded by a postback without the token, and one with it.
        $recorded = p202RecordLegacyConversion($db, self::CLICK, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, [
            'user_id' => self::ACCOUNT, 'transaction_id' => 'NW-2001', 'conv_time' => self::ARRIVED,
        ]);
        $store = new InboundLog($db);
        $entry = static fn (string $endpoint, string $auth, ?int $conv, int $user = self::ACCOUNT): Entry => new Entry(
            $endpoint,
            'GET',
            200,
            $conv === null ? 'unknown_click' : 'recorded',
            'x',
            $user,
            null,
            $conv,
            null,
            [],
            $auth
        );
        $store->append($entry('gpb', 'token', null), self::ARRIVED);
        self::assertTrue($readiness->forAccount(self::ACCOUNT, $now)['safe_to_require_token']);
        self::assertFalse($readiness->forAccount(self::ACCOUNT, $now)['safe_to_require_signature'], 'the token alone is not a signature');

        $store->append($entry('gpb', 'none', $recorded['conv_id']), self::ARRIVED);
        $store->append($entry('gpb', 'none', null), self::ARRIVED + 60);
        $store->append($entry('px', 'invalid', null), self::ARRIVED);
        $store->append($entry('gpb', 'none', null), self::ARRIVED - 8 * 86400);
        $store->append($entry('gpb', 'none', null, self::OTHER), self::ARRIVED);
        $store->append(new Entry('jvzoo', 'POST', 200, 'recorded', 'x', self::ACCOUNT), self::ARRIVED);

        $report = $readiness->forAccount(self::ACCOUNT, $now);

        self::assertSame(
            [4, 3, 1, 1, false, false],
            [$report['requests'], $report['without_credentials'], $report['token_only'], $report['pixels'],
                $report['safe_to_require_token'], $report['safe_to_require_signature']],
            'this account\'s postbacks and pixels of the window, and no other request'
        );
        $named = array_values(array_filter($report['senders'], static fn (array $s): bool => $s['campaign_id'] !== null));
        self::assertCount(1, $named);
        self::assertSame(
            ['gpb', 'none', self::CAMPAIGN, 'Northwind Coffee subscription', 1, 1],
            [$named[0]['endpoint'], $named[0]['presented'], $named[0]['campaign_id'], $named[0]['campaign_name'],
                $named[0]['requests'], $named[0]['conversions']]
        );

        // Requests that recorded nothing are named too: by the campaign the URL names, or the click's.
        self::q('DELETE FROM 202_inbound_log');
        $bare = static fn (string $endpoint, array $fields, ?int $click = null): Entry => new Entry(
            $endpoint, 'GET', 403, 'unauthenticated', 'x', self::ACCOUNT, $click, null, null,
            \Prosper202\InboundLog\Redactor::fields($fields, $endpoint), 'none'
        );
        $store->append($bare('pb', ['acip' => (string) self::SECOND_PUBLIC, 'subid' => '77']), self::ARRIVED);
        $store->append($bare('gpb', ['subid' => (string) self::CLICK]), self::ARRIVED);
        $store->append($bare('gpx', [], self::CLICK), self::ARRIVED);
        // Another account's campaign and click name nothing here; the subid is what there is to go by.
        $store->append($bare('pb', ['acip' => (string) self::OTHER_PUBLIC, 'subid' => '4410']), self::ARRIVED);
        $store->append($bare('gpb', ['subid' => (string) self::OTHER_CLICK]), self::ARRIVED);
        // An unfilled SubID macro names nothing to go by.
        $store->append($bare('gpb', ['subid' => '']), self::ARRIVED);
        $told = [];
        foreach ($readiness->forAccount(self::ACCOUNT, $now)['senders'] as $sender) {
            $told[$sender['endpoint'] . ':' . ($sender['campaign_name'] ?? '-')] = $sender['subids'];
        }
        ksort($told);
        self::assertSame([
            'gpb:-' => [(string) self::OTHER_CLICK],
            'gpb:Northwind Coffee subscription' => [],
            'gpx:Northwind Coffee subscription' => [],
            'pb:-' => ['4410'],
            'pb:Northwind Tea sampler' => [],
        ], $told);

        // A request whose credentials were not read keeps the answer from being "safe".
        self::q('DELETE FROM 202_inbound_log WHERE auth IN (\'none\', \'invalid\')');
        $store->append($entry('gpb', '', null), self::ARRIVED);
        self::assertNull($readiness->forAccount(self::ACCOUNT, $now)['safe_to_require_token']);

        $api = (new PostbackAuthController($db, self::ACCOUNT))->readiness($now);
        self::assertSame(['token', 1, 1], [$api['data']['mode'], $api['data']['requests'], $api['data']['unread']]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use Api\V3\Controllers\InboundLogController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\Redactor;
use Prosper202\InboundLog\RequestLog;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\JVZoo\JVZooAdapter;
use Prosper202\PaymentNotification\JVZoo\JVZooKey;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartSignature;
use Prosper202\PaymentNotification\Receiver;
use Tests\PaymentNotification\JVZooAdapterTest;

/**
 * The inbound log on a real server: what a payment notification and a
 * classic postback leave in it, who sees which rows, the hourly prune's
 * bound, and that a log that cannot be written never fails the request.
 *
 * @group integration
 */
final class InboundLogIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990701;
    private const OTHER = 990702;
    private const CLICK = 99070101;
    private const CAMPAIGN = 990761;
    private const KEY = 'jvzipn-inbound-log-key-1';
    private const RECEIPT = 'NW-ORDER-20391';

    private string $log = '';
    private string $previousLog = '';

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
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::ACCOUNT . ','
            . " aff_network_id = 1, aff_campaign_name = 'Northwind Coffee subscription',"
            . " aff_campaign_url = 'https://shop.northwind.example/?sid=[[subid]]',"
            . ' aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30,'
            . " payout_mode = 'accumulate'");
        self::q('UPDATE 202_users_pref SET ' . JVZooKey::COLUMN . ' = ' . self::str(self::KEY) . ' WHERE user_id = ' . self::ACCOUNT);
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
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
            '202_clicks_site', '202_click_latest_visits', '202_attribution_rollup_dirty_clicks', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ')');
        self::q('DELETE FROM 202_conversion_logs WHERE click_id = ' . self::CLICK);
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: self::CLICK,
            userId: self::ACCOUNT,
            clickTime: time() - 600,
            affCampaignId: self::CAMPAIGN,
            clickPayout: '30.00',
            recordsCampaignRows: false,
        ));
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-inbound-log');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        RequestLog::reset();
        if ($this->log !== '') {
            ini_set('error_log', $this->previousLog);
            @unlink($this->log);
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_inbound_log');
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ')');
        self::q('DELETE FROM 202_conversion_logs WHERE click_id = ' . self::CLICK);
        $users = self::ACCOUNT . ', ' . self::OTHER;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private function jvzoo(array $fields): void
    {
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded', $fields, '203.0.113.9', '203.0.113.9', 'JVZoo');
        (new Receiver(new JVZooAdapter(JVZooRole::AFFILIATE), self::requireScratch()))->receive($request);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(int $user = self::ACCOUNT, bool $admin = true, array $filters = []): array
    {
        return (new InboundLog(self::requireScratch()))->list($user, $admin, $filters);
    }

    public function testARecordedNotificationIsLoggedOnItsAccountWithTheBuyerRedacted(): void
    {
        $this->jvzoo(JVZooAdapterTest::signed(JVZooAdapterTest::sale([
            'caffitid' => (string) self::CLICK, 'ctransreceipt' => self::RECEIPT, 'ctranstime' => (string) time(),
            'ccustemail' => 'jamie@northwind.example', 'ccustname' => 'Jamie Rivera',
        ]), self::KEY));

        $rows = self::rows(self::ACCOUNT, false);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(['jvzoo', 'POST', 200, true, 'recorded', self::ACCOUNT, self::CLICK, self::RECEIPT], [
            $row['endpoint'], $row['method'], $row['http_status'], $row['accepted'], $row['outcome'], $row['user_id'],
            $row['click_id'], $row['transaction_id'],
        ]);
        self::assertIsInt($row['conversion_id']);
        self::assertSame(Redactor::REDACTED, $row['fields']['cverify']);
        self::assertSame(Redactor::REDACTED, $row['fields']['ccustemail']);
        self::assertSame(Redactor::REDACTED, $row['fields']['ccustname']);
        self::assertSame('47.00', $row['fields']['ctransamount']);
        $stored = self::$db->query('SELECT fields FROM 202_inbound_log');
        self::assertInstanceOf(\mysqli_result::class, $stored);
        $raw = (string) $stored->fetch_row()[0];
        foreach (['jamie@northwind.example', 'Jamie Rivera', self::KEY, '203.0.113.9'] as $private) {
            self::assertStringNotContainsString($private, $raw);
        }
    }

    public function testAnUnverifiedNotificationIsSeenOnlyByAnAdminWithItsReason(): void
    {
        $this->jvzoo(JVZooAdapterTest::signed(JVZooAdapterTest::sale(['caffitid' => (string) self::CLICK]), 'not-the-key'));

        self::assertSame([], self::rows(self::ACCOUNT, false), 'no account owns a request no key verifies');
        $rows = self::rows(self::ACCOUNT, true);
        self::assertCount(1, $rows);
        self::assertSame(['unverified', 403, false, null], [
            $rows[0]['outcome'], $rows[0]['http_status'], $rows[0]['accepted'], $rows[0]['user_id'],
        ]);
        self::assertStringContainsString('does not verify with any JVZoo secret key', $rows[0]['reason']);
    }

    public function testAClassicPostbackForAnUnknownClickIsLoggedWithWhy(): void
    {
        $db = self::requireScratch();
        $log = RequestLog::watch($db, 'pb', 'GET', ['acip' => '17', 'subid' => '99070199', 'amount' => '12.50']);
        $log->account(self::ACCOUNT);
        $outcome = p202RecordLegacyConversion($db, 99070199, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, [
            'campaign_id' => self::CAMPAIGN,
        ]);
        self::assertSame('unknown_click', $outcome['reason']);
        $log->refuse(null, 'Unknown subid for this campaign', 404);
        $log->write();

        $rows = self::rows(self::ACCOUNT, false);
        self::assertCount(1, $rows);
        self::assertSame(['pb', 404, 'unknown_click', 'Unknown subid for this campaign', 99070199, '12.50'], [
            $rows[0]['endpoint'], $rows[0]['http_status'], $rows[0]['outcome'], $rows[0]['reason'], $rows[0]['click_id'],
            $rows[0]['fields']['amount'],
        ]);
    }

    public function testAClassicPostbackThatConvertsIsLoggedAsRecorded(): void
    {
        $db = self::requireScratch();
        $log = RequestLog::watch($db, 'pb', 'GET', ['subid' => (string) self::CLICK, 'txid' => 'NW-77']);
        $log->account(self::ACCOUNT);
        p202RecordLegacyConversion($db, self::CLICK, 2, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, [
            'campaign_id' => self::CAMPAIGN, 'transaction_id' => 'NW-77',
        ]);
        $log->write();

        $row = self::rows(self::ACCOUNT, false)[0];
        self::assertSame(['recorded', true, self::CLICK, 'NW-77'], [$row['outcome'], $row['accepted'], $row['click_id'], $row['transaction_id']]);
        self::assertIsInt($row['conversion_id']);
    }

    public function testTheListFiltersPagesAndKeepsAccountsApart(): void
    {
        $store = new InboundLog(self::requireScratch());
        $store->append(new Entry('pb', 'GET', 200, 'recorded', 'A conversion was recorded.', self::ACCOUNT));
        $store->append(new Entry('gpb', 'GET', 404, 'unknown_click', 'No click has this id.', self::ACCOUNT));
        $store->append(new Entry('px', 'GET', 200, 'no_click', 'No tracking cookie named a click.', self::ACCOUNT));
        $store->append(new Entry('pb', 'GET', 200, 'recorded', 'A conversion was recorded.', self::OTHER));

        self::assertSame(['px', 'gpb', 'pb'], array_column(self::rows(self::ACCOUNT, false), 'endpoint'));
        self::assertSame(['no_click', 'unknown_click'], array_column(self::rows(self::ACCOUNT, false, ['outcome' => 'refused']), 'outcome'));
        self::assertSame(['recorded'], array_column(self::rows(self::ACCOUNT, false, ['outcome' => 'accepted']), 'outcome'));
        self::assertSame(['gpb'], array_column(self::rows(self::ACCOUNT, false, ['endpoint' => 'gpb']), 'endpoint'));
        self::assertSame([self::OTHER], array_column(self::rows(self::OTHER, false), 'user_id'));

        $page = (new InboundLogController(self::requireScratch(), self::ACCOUNT, false))->list(['limit' => '2']);
        self::assertSame(['px', 'gpb'], array_column($page['data'], 'endpoint'));
        self::assertSame($page['data'][1]['inbound_id'], $page['next_before']);
        $next = (new InboundLogController(self::requireScratch(), self::ACCOUNT, false))->list(['limit' => '2', 'before' => (string) $page['next_before']]);
        self::assertSame(['pb'], array_column($next['data'], 'endpoint'));
        self::assertNull($next['next_before']);
    }

    public function testTheControllerRefusesAFilterItDoesNotKnow(): void
    {
        $controller = new InboundLogController(self::requireScratch(), self::ACCOUNT, false);
        foreach ([['endpoint' => 'stripe'], ['outcome' => 'Refused!'], ['since' => '1'], ['limit' => '0']] as $params) {
            try {
                $controller->list($params);
                self::fail('refused: ' . json_encode($params));
            } catch (ValidationException $e) {
                self::assertArrayHasKey((string) array_key_first($params), $e->getFieldErrors());
            }
        }
    }

    public function testThePruneKeepsEachAccountsNewestRows(): void
    {
        $db = self::requireScratch();
        $extra = 3;
        $values = [];
        for ($i = 0; $i < InboundLog::KEEP_PER_ACCOUNT + $extra; $i++) {
            $values[] = '(' . self::ACCOUNT . ", $i, 'pb', 'GET', 200, 'recorded', '')";
        }
        $values[] = '(' . self::OTHER . ", 1, 'pb', 'GET', 200, 'recorded', '')";
        self::q('INSERT INTO 202_inbound_log (user_id, received_at, endpoint, method, http_status, outcome, reason) VALUES '
            . implode(', ', $values));

        self::assertSame($extra, (new InboundLog($db))->prune());

        $kept = $db->query('SELECT user_id, COUNT(*), MIN(received_at) FROM 202_inbound_log GROUP BY user_id ORDER BY user_id');
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame([
            [(string) self::ACCOUNT, (string) InboundLog::KEEP_PER_ACCOUNT, (string) $extra],
            [(string) self::OTHER, '1', '1'],
        ], $kept->fetch_all());
        self::assertSame(0, (new InboundLog($db))->prune(), 'a second prune has nothing to do');
    }

    /** Rows for $user with outcome $outcome, received at 1..$count, inserted by doubling. */
    private static function seed(int $user, int $count, string $outcome = 'unknown_campaign'): void
    {
        $columns = 'user_id, received_at, endpoint, method, http_status, outcome, accepted, reason';
        $accepted = $user > 0 && in_array($outcome, InboundLog::BACKED_OUTCOMES, true) ? 1 : 0;
        self::q("INSERT INTO 202_inbound_log ($columns) VALUES ($user, 1, 'pb', 'GET', 200, '$outcome', $accepted, '')");
        for ($have = 1; $have < $count; $have *= 2) {
            self::q("INSERT INTO 202_inbound_log ($columns) SELECT user_id, received_at + $have, endpoint, method,"
                . " http_status, outcome, accepted, reason FROM 202_inbound_log WHERE user_id = $user AND accepted = $accepted"
                . " AND received_at + $have <= $count ORDER BY inbound_id");
        }
    }

    /** @return array<int, int> rows per user_id */
    private static function counts(): array
    {
        $result = self::$db->query('SELECT user_id, COUNT(*) FROM 202_inbound_log GROUP BY user_id ORDER BY user_id');
        self::assertInstanceOf(\mysqli_result::class, $result);
        $counts = [];
        foreach ($result->fetch_all() as [$user, $count]) {
            $counts[(int) $user] = (int) $count;
        }

        return $counts;
    }

    /** The review's case: 262,144 unattributed rows used to take the whole prune and leave the account over its cap. */
    public function testAFloodOfUnattributedRowsDoesNotKeepAnAccountOverItsCap(): void
    {
        $db = self::requireScratch();
        self::seed(0, 262144);
        self::seed(self::ACCOUNT, 6000, 'recorded');

        (new InboundLog($db))->prune(0.0);
        self::assertSame(InboundLog::KEEP_PER_ACCOUNT, self::counts()[self::ACCOUNT], 'accounts first, even with no time left');

        (new InboundLog($db))->prune();
        self::assertSame([0 => InboundLog::KEEP_UNATTRIBUTED, self::ACCOUNT => InboundLog::KEEP_PER_ACCOUNT], self::counts(),
            'one prune catches up on everything');
        $oldest = $db->query('SELECT MIN(received_at) FROM 202_inbound_log WHERE user_id = 0');
        self::assertInstanceOf(\mysqli_result::class, $oldest);
        self::assertSame((string) (262144 - InboundLog::KEEP_UNATTRIBUTED + 1), (string) $oldest->fetch_row()[0], 'the newest kept');
    }

    public function testUnattributedAndRefusedRequestsAreLoggedAtMostSixtyAMinute(): void
    {
        $log = new InboundLog(self::requireScratch());
        $now = 1793000000;
        for ($i = 0; $i < InboundLog::UNBACKED_PER_MINUTE; $i++) {
            self::assertTrue($log->append(new Entry('pb', 'GET', 200, 'unknown_campaign', '', 0), $now));
            self::assertTrue($log->append(new Entry('pb', 'GET', 404, 'unknown_click', '', self::ACCOUNT), $now));
        }
        self::assertFalse($log->append(new Entry('pb', 'GET', 200, 'unknown_campaign', '', 0), $now + 59));
        self::assertFalse($log->append(new Entry('pb', 'GET', 404, 'unknown_click', '', self::ACCOUNT), $now + 59));
        self::assertTrue($log->append(new Entry('pb', 'GET', 200, 'recorded', '', self::ACCOUNT), $now + 59),
            'a conversion is always logged');
        self::assertFalse($log->append(new Entry('pb', 'GET', 404, 'unknown_click', '', self::ACCOUNT), $now + 59),
            'and does not reopen the refusals\' window');
        self::assertTrue($log->append(new Entry('pb', 'GET', 200, 'unknown_campaign', '', 0), $now + 60), 'a minute on');
        self::assertTrue($log->append(new Entry('pb', 'GET', 404, 'unknown_click', '', self::ACCOUNT), $now + 60));
        self::assertSame([0 => 61, self::ACCOUNT => 62], self::counts());
    }

    /** A flood of refusals aimed at an account (a public acip) evicts only refusals. */
    public function testRefusalsCannotEvictAnAccountsAcceptedRequests(): void
    {
        $db = self::requireScratch();
        self::seed(self::ACCOUNT, 3000, 'recorded');
        self::seed(self::ACCOUNT, 20000, 'unknown_click');

        (new InboundLog($db))->prune();

        $kept = $db->query('SELECT outcome, COUNT(*) FROM 202_inbound_log WHERE user_id = ' . self::ACCOUNT
            . ' GROUP BY outcome ORDER BY outcome');
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame([['recorded', '3000'], ['unknown_click', (string) InboundLog::KEEP_REFUSED]], $kept->fetch_all());
    }

    /**
     * The review's case: anyone with one converted click can replay its
     * transaction id, and each replay answers duplicate. Replays are capped
     * like refusals and pruned with them, so they never push out the
     * account's recorded conversions.
     */
    public function testReplaysOfOneTransactionAreCappedAndCannotEvictRecordedRows(): void
    {
        $db = self::requireScratch();
        $log = new InboundLog($db);
        $now = 1793000000;
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($log->append(new Entry('gpb', 'GET', 200, 'recorded', '', self::ACCOUNT, self::CLICK, null, 'NW-' . $i), $now));
        }
        for ($i = 0; $i < InboundLog::UNBACKED_PER_MINUTE; $i++) {
            self::assertTrue($log->append(new Entry('gpb', 'GET', 200, 'duplicate', '', self::ACCOUNT, self::CLICK, null, 'NW-0'), $now));
        }
        self::assertFalse($log->append(new Entry('gpb', 'GET', 200, 'duplicate', '', self::ACCOUNT, self::CLICK, null, 'NW-0'), $now + 59),
            'the 61st replay in a minute is not logged');
        self::assertFalse($log->append(new Entry('pb', 'GET', 200, 'already_lead', '', self::ACCOUNT), $now + 59));
        self::assertTrue($log->append(new Entry('gpb', 'GET', 200, 'recorded', '', self::ACCOUNT, self::CLICK, null, 'NW-9'), $now + 59),
            'a new conversion still is');
        $listed = self::rows(self::ACCOUNT, false, ['outcome' => 'duplicate'])[0];
        self::assertTrue($listed['accepted'], 'a replay is still listed as accepted, not refused');

        self::seed(self::ACCOUNT, 20000, 'duplicate');
        $log->prune();

        $kept = $db->query('SELECT outcome, COUNT(*) FROM 202_inbound_log WHERE user_id = ' . self::ACCOUNT
            . ' GROUP BY outcome ORDER BY outcome');
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame([['duplicate', (string) InboundLog::KEEP_REFUSED], ['recorded', '4']], $kept->fetch_all());
    }

    public function testATransactionIdHoldingAnEmailIsNotKept(): void
    {
        $log = new InboundLog(self::requireScratch());
        self::assertTrue($log->append(new Entry('pb', 'GET', 200, 'recorded', '', self::ACCOUNT, self::CLICK, null, 'pat@contoso.example')));
        self::assertSame(Redactor::REDACTED, self::rows(self::ACCOUNT, false)[0]['transaction_id']);
    }

    public function testAPayKickstartNotificationKeepsNoLicenceKeyOrBuyerDetail(): void
    {
        $fields = [
            'event' => 'sales', 'mode' => 'live', 'payment_processor' => 'stripe', 'is_rebill' => '0', 'amount' => '47.00',
            'quantity' => '1', 'buyer_first_name' => 'Ruggero', 'buyer_email' => 'buyer@example.com',
            'transaction_id' => 'PK-TX-0001', 'invoice_id' => 'PK-INV-0001', 'transaction_time' => (string) time(),
            'product_id' => '2354', 'campaign_id' => '215', 'custom_subid' => (string) self::CLICK,
            'affiliate_commission_amount' => '0', 'licenses' => ['LIC-AAAA-1111', 'LIC-BBBB-2222'],
        ];
        $fields['hash'] = PayKickstartSignature::sign(PayKickstartSignature::message($fields), 'not-a-stored-key');
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded', $fields, '203.0.113.9', '203.0.113.9', 'PayKickstart IPN');
        (new Receiver(new PayKickstartAdapter(), self::requireScratch()))->receive($request);

        $rows = self::rows(self::ACCOUNT, true);
        self::assertCount(1, $rows);
        self::assertSame(['paykickstart', 'unverified'], [$rows[0]['endpoint'], $rows[0]['outcome']]);
        self::assertSame('PK-TX-0001', $rows[0]['fields']['transaction_id']);
        self::assertSame(Redactor::REDACTED, $rows[0]['fields']['licenses']);
        $stored = self::$db->query('SELECT fields FROM 202_inbound_log');
        self::assertInstanceOf(\mysqli_result::class, $stored);
        $raw = (string) $stored->fetch_row()[0];
        foreach (['LIC-', 'Ruggero', 'buyer@example.com', $fields['hash'], '203.0.113.9'] as $private) {
            self::assertStringNotContainsString($private, $raw);
        }
    }

    public function testALogThatCannotBeWrittenDoesNotFailTheRequest(): void
    {
        self::q('RENAME TABLE 202_inbound_log TO 202_inbound_log_away');
        try {
            (new InboundLog(self::requireScratch()))->append(new Entry('pb', 'GET', 200, 'recorded', ''));
            $this->jvzoo(JVZooAdapterTest::signed(JVZooAdapterTest::sale([
                'caffitid' => (string) self::CLICK, 'ctransreceipt' => self::RECEIPT, 'ctranstime' => (string) time(),
            ]), self::KEY));
        } finally {
            self::q('RENAME TABLE 202_inbound_log_away TO 202_inbound_log');
        }
        $recorded = self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = ' . self::CLICK);
        self::assertInstanceOf(\mysqli_result::class, $recorded);
        self::assertSame('1', (string) $recorded->fetch_row()[0], 'the notification was recorded all the same');
        self::assertStringContainsString('Inbound log: the jvzoo request could not be logged', (string) file_get_contents($this->log));
    }
}

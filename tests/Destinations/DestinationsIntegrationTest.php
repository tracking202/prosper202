<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\OpenAiAdsDestination;
use Tests\Apps\Android\AndroidDatabase;

/**
 * Destinations against a real server: a conversion recorded through the
 * ledger's one writer is queued for each enabled destination in its own
 * transaction, and the worker sends it through the guarded sender (a
 * stubbed resolver and transport), retries, fails, withholds and cancels as
 * the outbox does. Credentials are sealed and bound to their destination.
 *
 * @group integration
 */
final class DestinationsIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const SECRET = 'Sx9-secretAPIvalue';

    /** @var list<array<string, mixed>> decoded bodies the transport was handed */
    private array $bodies = [];
    /** @var list<array<string, mixed>> answers the transport gives, in order (then 204) */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_destinations', '202_destination_deliveries', '202_customers', '202_clicks_advance', '202_clicks_site', '202_site_urls', '202_ips', '202_ips_v6'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        // A currency change another suite left would put these rows in another currency (RowCurrency::inForceAt()).
        self::fixture('DELETE FROM 202_currency_changes WHERE user_id IN (1, 2)');
        self::fixture('DELETE FROM 202_users WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users SET user_id=1, user_name='store-owner', user_email='owner@example.com', user_active=1, user_deleted=0");
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='saas-owner', user_email='saas@example.com', user_active=1, user_deleted=0");
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
        // A currency change another test recorded would relabel this one's conversions (RowCurrency).
        self::fixture('DELETE FROM 202_currency_changes WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_pref_privacy='disabled', user_account_currency='USD'");
        self::fixture("INSERT INTO 202_users_pref SET user_id=2, user_pref_privacy='disabled', user_account_currency='USD'");
        $this->campaign(31, null);
        $this->campaign(40, null, user: 2);
        $this->bodies = [];
        $this->answers = [];
    }

    /** @param (callable(string): list<string>)|null $resolver */
    private function store(?callable $resolver = null): DestinationStore
    {
        $guard = new WebhookGuard($resolver ?? static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->bodies[] = json_decode((string) $options[CURLOPT_POSTFIELDS], true);

            return (array_shift($this->answers) ?? []) + ['status' => 204, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };

        return new DestinationStore(new Connection(self::$db), [Ga4Destination::TYPE => new Ga4Destination(new WebhookSender($guard, $transport))]);
    }

    /** @var list<string> addresses the privacy check was asked about */
    private array $asked = [];

    /** @param (callable(string): list<string>)|null $resolver */
    private function worker(bool $mayBeEuropean = false, ?callable $resolver = null): DestinationQueue
    {
        return new DestinationQueue(new Connection(self::$db), $this->store($resolver), function (string $ip) use ($mayBeEuropean): bool {
            $this->asked[] = $ip;

            return $mayBeEuropean;
        });
    }

    /** @param array<string, mixed> $settings */
    private function destination(array $settings = [], int $user = 1): int
    {
        return (int) $this->store()->create($user, Ga4Destination::TYPE, 'Store GA4', $settings + ['measurement_id' => 'G-ABC123'], ['api_secret' => self::SECRET], time())['destination_id'];
    }

    private function record(int $click, string $tx, int $user = 1): int
    {
        $result = (new MysqlConversionRepository(new Connection(self::$db)))->record($user, [
            'click_id' => $click, 'payout' => '19.90', 'transaction_id' => $tx, 'source' => 'api',
        ], MatchMethod::CLICK_ID);

        return (int) $result['convId'];
    }

    /** @return list<array<string, mixed>> */
    private static function deliveries(int $destinationId): array
    {
        return self::$db->query("SELECT conv_id, kind, status, attempts, next_attempt_at, last_status_code, last_error FROM 202_destination_deliveries
            WHERE destination_id = $destinationId ORDER BY delivery_id")->fetch_all(MYSQLI_ASSOC);
    }

    public function testANewConversionIsQueuedOnceForEachEnabledDestinationThatTakesItsCampaign(): void
    {
        $all = $this->destination();
        $other = $this->destination(['campaign_ids' => [31]]);
        $off = $this->destination();
        $this->store()->update(1, $off, ['enabled' => false], time());
        $foreign = $this->destination(['measurement_id' => 'G-OTHER1'], 2);

        $this->click(100);
        $convId = $this->record(100, 'ORD-1');
        self::assertGreaterThan(0, $convId);
        $rows = self::deliveries($all);
        self::assertCount(1, $rows, 'the positive control: an enabled destination for every campaign queues it');
        self::assertSame([(string) $convId, 'conversion', 'pending', '0'], [$rows[0]['conv_id'], $rows[0]['kind'], $rows[0]['status'], $rows[0]['attempts']]);
        self::assertSame([], self::deliveries($other), 'a destination for another campaign');
        self::assertSame([], self::deliveries($off), 'a disabled destination');
        self::assertSame([], self::deliveries($foreign), 'another account\'s destination');

        // A replay is a duplicate and queues nothing; a reversal is not a sale.
        $this->record(100, 'ORD-1');
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => 100, 'transaction_id' => 'ORD-1', 'reversal' => true, 'source' => 'api',
        ], MatchMethod::CLICK_ID);
        self::assertCount(1, self::deliveries($all));
    }

    public function testTheWorkerRetriesWithBackoffThenSends(): void
    {
        $id = $this->destination(['params' => ['campaign' => 'utm_campaign']]);
        $this->click(100);
        $convId = $this->record(100, 'ORD-2');
        $now = time() + 1;

        $this->answers = [['status' => 503, 'body' => 'busy']];
        self::assertSame(1, $this->worker()->sendDue(10, 5, $now)['retrying']);
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '1', '503', (string) ($now + 60)], [$row['status'], $row['attempts'], $row['last_status_code'], $row['next_attempt_at']]);
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 30)), 'not due again before its backoff');

        self::assertSame(1, $this->worker()->sendDue(10, 5, $now + 61)['sent']);
        $row = self::deliveries($id)[0];
        self::assertSame(['sent', '2'], [$row['status'], $row['attempts']]);
        self::assertCount(2, $this->bodies);
        $event = $this->bodies[1]['events'][0];
        self::assertSame(['purchase', 'USD', 19.9, 'ORD-2'], [$event['name'], $event['params']['currency'], $event['params']['value'], $event['params']['transaction_id']]);
        self::assertSame('p202.100', $this->bodies[1]['client_id']);
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 9999)), 'a sent row is never sent again');
        self::assertSame((string) $convId, self::deliveries($id)[0]['conv_id']);
    }

    /**
     * A platform is told the currency the conversion recorded. One with none
     * recorded is held back, never sent under the account's, and goes out
     * once the owner has recorded its currency (Personal settings), in that
     * currency; another account's held rows are left alone.
     */
    public function testAConversionWithNoCurrencyRecordedIsHeldBackAndSentOnceTheOwnerRecordsOne(): void
    {
        $id = $this->destination();
        $other = $this->destination(['measurement_id' => 'G-OTHER1'], 2);
        $this->click(100);
        $this->click(101);
        $this->click(200, 40, 2);
        $recorded = $this->record(100, 'ORD-A');
        $unrecorded = $this->record(101, 'ORD-B');
        $foreign = $this->record(200, 'ORD-C', 2);
        self::fixture("UPDATE 202_conversion_logs SET currency='' WHERE conv_id IN ($unrecorded, $foreign)");
        // The account counts in euros now; the row that recorded USD still goes out as USD.
        self::fixture("UPDATE 202_users_pref SET user_account_currency='EUR' WHERE user_id=1");
        $now = time() + 1;

        $out = $this->worker()->sendDue(10, 5, $now);

        self::assertSame([1, 2], [$out['sent'], $out['withheld']]);
        self::assertSame(['USD', 'ORD-A'], [$this->bodies[0]['events'][0]['params']['currency'], $this->bodies[0]['events'][0]['params']['transaction_id']]);
        $held = self::deliveries($id)[1];
        self::assertSame(['withheld', DestinationQueue::NO_CURRENCY], [$held['status'], $held['last_error']]);
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 9999)), 'held back for good until its currency is recorded');

        // The owner records the currency those conversions were in, and what was held goes out in it.
        $conn = new Connection(self::$db);
        self::assertSame(['stamped' => 1, 'left' => 0], \Prosper202\Conversion\Ledger\RowCurrency::recordBefore($conn, 1, 'GBP', 4102444800));
        self::assertSame(1, (new DestinationQueue($conn))->releaseHeldForCurrency(1, $now + 10), 'this account\'s one held row');
        self::assertSame('withheld', self::deliveries($other)[0]['status'], 'another account\'s is left as it is');

        $out = $this->worker()->sendDue(10, 5, $now + 10);

        self::assertSame([1, 0], [$out['sent'], $out['withheld']]);
        self::assertSame(['GBP', 'ORD-B'], [$this->bodies[1]['events'][0]['params']['currency'], $this->bodies[1]['events'][0]['params']['transaction_id']]);
        self::assertSame(['sent', null], [self::deliveries($id)[1]['status'], self::deliveries($id)[1]['last_error']]);

        // A row released while its conversion still has no currency is held back again, not sent.
        self::fixture("UPDATE 202_destination_deliveries SET status='pending', attempts=0, next_attempt_at=0 WHERE destination_id=$other");
        self::assertSame(1, $this->worker()->sendDue(10, 5, $now + 20)['withheld']);
        self::assertCount(2, $this->bodies);
    }

    public function testARefusalFailsAtOnceAndAHostThatDoesNotAnswerIsSkippedForTheRun(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->click(101);
        $this->record(100, 'ORD-3');
        $this->record(101, 'ORD-4');

        $this->answers = [['status' => 0, 'error' => 'Operation timed out']];
        $out = $this->worker()->sendDue(10, 5, time() + 1);
        self::assertSame(['retrying' => 1, 'sent' => 0], ['retrying' => $out['retrying'], 'sent' => $out['sent']]);
        self::assertCount(1, $this->bodies, 'the second row waits: its host gave no answer this run');
        self::assertSame(['1', '0'], array_column(self::deliveries($id), 'attempts'));

        $this->answers = [['status' => 400, 'body' => 'bad'], ['status' => 400, 'body' => 'bad']];
        $out = $this->worker()->sendDue(10, 5, time() + 3600);
        self::assertSame(2, $out['failed']);
        self::assertSame(['failed', 'failed'], array_column(self::deliveries($id), 'status'));
    }

    public function testTheVisitorsPrivacyWithholdsTheSend(): void
    {
        $id = $this->destination();
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        self::fixture('INSERT INTO 202_clicks_advance SET click_id=100, ip_id=5');
        $this->click(100);
        $this->record(100, 'ORD-5');

        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='eu' WHERE user_id=1");
        self::assertSame(1, $this->worker(mayBeEuropean: true)->sendDue(10, 5, time() + 1)['withheld']);
        self::assertSame([], $this->bodies, 'nothing is sent for a visitor who may be in Europe');
        self::assertStringContainsString('privacy setting', (string) self::deliveries($id)[0]['last_error']);

        // Positive control: the same setting sends a visitor placed outside Europe.
        $this->click(101);
        $this->record(101, 'ORD-6');
        self::assertSame(1, $this->worker(mayBeEuropean: false)->sendDue(10, 5, time() + 1)['sent']);

        self::assertSame(['198.51.100.7', ''], $this->asked, 'the click\'s address, and none for a click without one');

        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='all' WHERE user_id=1");
        $this->click(102);
        $this->record(102, 'ORD-7');
        self::assertSame(1, $this->worker(mayBeEuropean: false)->sendDue(10, 5, time() + 1)['withheld']);
        self::assertCount(1, $this->bodies);
    }

    public function testHashedUserDataGoesOnlyWhenTurnedOnAndConsentIsNotDenied(): void
    {
        self::fixture("INSERT INTO 202_customers SET customer_id=9, user_id=1, primary_ref='jane.doe@example.com', email='Jane.Doe@Example.com', phone='+1 555 123 4567',
            first_seen_time=1, last_activity_time=1, created_at=1, updated_at=1");
        $on = $this->destination(['send_user_data' => true, 'user_id_source' => 'customer']);
        $this->click(100);
        $convId = $this->record(100, 'ORD-8');
        self::fixture("UPDATE 202_conversion_logs SET customer_id=9 WHERE conv_id=$convId");
        $this->worker()->sendDue(10, 5, time() + 1);
        self::assertSame([hash('sha256', 'jane.doe@example.com')], $this->bodies[0]['user_data']['sha256_email_address']);
        self::assertSame([hash('sha256', '+15551234567')], $this->bodies[0]['user_data']['sha256_phone_number']);
        self::assertSame('p202c.9', $this->bodies[0]['user_id'], 'a pseudonymous id, never the customer\'s ref');
        self::assertStringNotContainsString('example.com', strtolower((string) json_encode($this->bodies[0])), 'no email, from the ref or the customer');
        $summary = (string) self::$db->query("SELECT summary FROM 202_destination_deliveries WHERE destination_id = $on")->fetch_row()[0];
        self::assertStringContainsString('sha256_email_address', $summary, 'the log names the identifiers sent');
        self::assertStringNotContainsString(hash('sha256', 'jane.doe@example.com'), $summary, 'and never their values');

        $this->store()->update(1, $on, ['settings' => ['measurement_id' => 'G-ABC123', 'send_user_data' => true, 'user_id_source' => 'customer', 'consent' => ['ad_user_data' => 'denied']]], time());
        $this->click(101);
        $second = $this->record(101, 'ORD-9');
        self::fixture("UPDATE 202_conversion_logs SET customer_id=9 WHERE conv_id=$second");
        $this->worker()->sendDue(10, 5, time() + 1);
        self::assertArrayNotHasKey('user_data', $this->bodies[1], 'ad_user_data denied sends no hashed data');
        self::assertArrayNotHasKey('user_id', $this->bodies[1], 'nor the customer\'s id');
    }

    public function testDeletesCancelWhatWasNotSent(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->click(101);
        $gone = $this->record(100, 'ORD-10');
        $this->record(101, 'ORD-11');
        self::fixture("UPDATE 202_conversion_logs SET deleted=1 WHERE conv_id=$gone");
        $out = $this->worker()->sendDue(10, 5, time() + 1);
        self::assertSame([1, 1], [$out['cancelled'], $out['sent']]);

        $this->click(102);
        $this->record(102, 'ORD-12');
        self::assertTrue($this->store()->delete(1, $id, time()));
        self::assertSame('cancelled', self::deliveries($id)[2]['status']);
        self::assertNull($this->store()->get(1, $id));
        self::assertFalse($this->store()->delete(1, $id, time()), 'a second delete finds nothing');
    }

    public function testCredentialsAreSealedAndBoundToTheirDestination(): void
    {
        $a = $this->destination();
        $b = $this->destination(['measurement_id' => 'G-SECOND1']);
        $stored = (string) self::$db->query("SELECT credentials FROM 202_destinations WHERE destination_id = $a")->fetch_row()[0];
        self::assertStringStartsWith('v1.', $stored);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertSame(self::SECRET, $this->store()->forSending(1, $a)['credentials']['api_secret']);
        self::assertTrue($this->store()->get(1, $a)['credentials_set']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($this->store()->list(1)));
        self::assertNull($this->store()->forSending(2, $a), 'another account cannot read it');

        self::fixture("UPDATE 202_destinations SET credentials='" . self::$db->real_escape_string($stored) . "' WHERE destination_id = $b");
        $this->expectException(\RuntimeException::class);
        $this->store()->forSending(1, $b);
    }

    public function testATestFireIsLoggedAndNeverQueuesAConversion(): void
    {
        $id = $this->destination();
        $this->answers = [['status' => 200, 'body' => '{"validationMessages":[]}']];
        $result = $this->worker()->testFire(1, $id, time());
        self::assertTrue($result['valid']);
        self::assertSame([['conv_id' => null, 'kind' => 'test', 'status' => 'sent']], array_map(
            static fn (array $r): array => ['conv_id' => $r['conv_id'], 'kind' => $r['kind'], 'status' => $r['status']],
            self::deliveries($id)
        ));
        self::assertNull($this->worker()->testFire(2, $id, time()), 'another account\'s destination is not found');
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, time() + 1)), 'the worker sends no test row');
    }

    public function testAnIpv6ClicksAddressIsTheOneAskedAboutAndAnUnplaceableOneIsWithheld(): void
    {
        $id = $this->destination();
        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='eu' WHERE user_id=1");
        self::fixture("INSERT INTO 202_ips_v6 SET ip_id=3, ip_address=INET6_ATON('2001:db8::7')");
        self::fixture("INSERT INTO 202_ips SET ip_id=6, ip_address='3'");
        self::fixture('INSERT INTO 202_clicks_advance SET click_id=100, ip_id=6');
        $this->click(100);
        $this->record(100, 'ORD-20');
        self::assertSame(1, $this->worker(mayBeEuropean: false)->sendDue(10, 5, time() + 1)['sent']);
        self::assertSame(['2001:db8::7'], $this->asked, 'the IPv6 address, not its row id');

        // The real check: GeoIP cannot place a click with no address, so it is held back.
        $this->click(101);
        $this->record(101, 'ORD-21');
        $real = new DestinationQueue(new Connection(self::$db), $this->store());
        self::assertSame(1, $real->sendDue(10, 5, time() + 1)['withheld']);
        self::assertSame('withheld', self::deliveries($id)[1]['status']);
    }

    public function testAnUnpaidOrReplacedConversionIsNotSent(): void
    {
        $id = $this->destination();
        $this->click(100);
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => 100, 'payout' => '5', 'transaction_id' => 'LEAD-1', 'source' => 'api', 'payable' => false,
        ], MatchMethod::CLICK_ID);
        self::assertSame([], self::deliveries($id), 'a row that is not paid is not a sale');

        $this->click(101);
        $convId = $this->record(101, 'ORD-22');
        self::fixture("UPDATE 202_conversion_logs SET superseded_by=999 WHERE conv_id=$convId");
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['cancelled']);
        self::assertSame([], $this->bodies);
    }

    public function testAReplacingConversionReusesTheTransactionIdOfTheOneItReplaced(): void
    {
        $this->destination();
        $this->campaign(32, null, 'replace');
        $this->click(103, 32);
        $this->record(103, 'ORD-40');
        $this->worker()->sendDue(10, 5, time() + 1);
        $second = $this->record(103, 'ORD-41');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 2)['sent']);
        self::assertSame(['ORD-40', 'ORD-40'], array_map(static fn (array $b): string => $b['events'][0]['params']['transaction_id'], $this->bodies), 'GA4 counts the sale once');
        $pixel = TrafficSourcePixels::withConversionEventId(new Connection(self::$db), [], 1, 103, $second, 'ORD-41');
        self::assertSame('ORD-40', $pixel[TrafficSourcePixels::EVENT_ID], 'the browser pixel names the sale as the server event does');

        // Positive control: a campaign that adds every conversion sends each under its own id.
        $this->click(104);
        $this->record(104, 'ORD-42');
        $this->record(104, 'ORD-43');
        $this->worker()->sendDue(10, 5, time() + 3);
        self::assertSame(['ORD-42', 'ORD-43'], array_map(static fn (array $b): string => $b['events'][0]['params']['transaction_id'], array_slice($this->bodies, 2)));
    }

    public function testTheFactsCarryTheVisitorsAddressOnlyUnmaskedAndWithUserDataAndThePageTheClickCameFrom(): void
    {
        $perClick = [
            '202_clicks_visitor', '202_identity_observations', '202_click_consent_refusals', '202_clicks_tracking',
            '202_clicks_record', '202_clicks_rotator',
        ];
        foreach ($perClick as $t) {
            self::fixture("DELETE FROM $t WHERE click_id BETWEEN 100 AND 130");
        }
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=11, site_domain_id=1, site_url_address='https://shop.example.com/spring?utm_source=chatgpt&email=jane%40example.com#top'");
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=12, site_domain_id=1, site_url_address='https://track.example.com/tracking202/redirect/dl.php?t202id=7'");
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=13, site_domain_id=1, site_url_address='ftp://files.example.com/receipt'");
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=14, site_domain_id=1, site_url_address='https://jane:pw@shop.example.com:8443/spring;jsessionid=AB12/checkout?x=1'");
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=15, site_domain_id=1, site_url_address='https://shop.example.com/" . str_repeat('a', 2030) . "'");
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        self::fixture("INSERT INTO 202_ips SET ip_id=6, ip_address='198.51.100.0'");
        self::fixture("INSERT INTO 202_ips SET ip_id=7, ip_address='10.0.0.9'");
        self::fixture("INSERT INTO 202_ips SET ip_id=8, ip_address='100.64.0.9'");
        $this->campaign(33, null);
        self::fixture('UPDATE 202_aff_campaigns SET identity_signals=0 WHERE aff_campaign_id=33');
        $conn = new Connection(self::$db);
        $clicks = [
            // click => [ip_id, landing url id, outbound url id, campaign, what the click's own request carried]
            100 => [5, 11, 12, 30, 'cookie'],
            101 => [6, 0, 12, 30, 'cookie'],
            102 => [5, 0, 0, 30, 'page'],
            103 => [7, 13, 0, 30, 'cookie'],
            104 => [5, 14, 0, 30, 'refused'],
            105 => [5, 11, 0, 33, 'cookie'],
            107 => [8, 15, 0, 30, 'cookie'],
            108 => [5, 11, 0, 30, 'cookie'],
            109 => [5, 11, 0, 30, 'cookie'],
            110 => [5, 11, 0, 30, 'cookie'],
            111 => [5, 11, 0, 30, 'cookie'],
            112 => [5, 11, 0, 30, 'cookie'],
            113 => [5, 11, 0, 30, 'cookie'],
            114 => [5, 11, 0, 30, 'refused link'],
            115 => [5, 11, 0, 30, 'cookie'],
            116 => [5, 11, 0, 30, 'page'],
            117 => [5, 11, 0, 30, 'cookie'],
            118 => [5, 11, 0, 30, 'cookie'],
            119 => [5, 11, 0, 30, 'refused link'],
            120 => [5, 11, 0, 30, 'refused link'],
            121 => [5, 11, 0, 30, 'cookie'],
            122 => [5, 11, 0, 30, 'cookie'],
            123 => [5, 11, 0, 30, 'cookie'],
        ];
        $repo = new \Prosper202\Click\MysqlClickRepository($conn);
        $refusals = static fn (int $click): array => self::$db->query(
            "SELECT refused_at FROM 202_click_consent_refusals WHERE click_id=$click"
        )->fetch_all(MYSQLI_NUM);
        foreach ($clicks as $click => [$ip, $landing, $outbound, $campaign, $carried]) {
            if ($carried === 'refused link') {
                // A tracking link that refuses, through the click's one writer: every row and the refusal with them.
                $repo->recordClick(new \Prosper202\Click\ClickRecord(
                    clickId: $click,
                    userId: 1,
                    clickTime: self::CLICK_TIME,
                    affCampaignId: $campaign,
                    ppcAccountId: 70,
                    clickPayout: '2.5',
                    ipId: $ip,
                    recordsCampaignRows: false,
                    clickLandingSiteUrlId: $landing,
                    clickOutboundSiteUrlId: $outbound,
                    identity: \Prosper202\Identity\ClickIdentity::fromRequest(['p202_consent' => '0'], [], true),
                ));
                self::assertSame([[(string) self::CLICK_TIME]], $refusals($click), "click $click's refusal is stored");
                continue;
            }
            self::fixture("INSERT INTO 202_clicks_advance SET click_id=$click, ip_id=$ip");
            self::fixture("INSERT INTO 202_clicks_site SET click_id=$click, click_landing_site_url_id=$landing, click_outbound_site_url_id=$outbound");
            $this->click($click, $campaign);
            // The click path's own identity code, as dl.php and the landing scripts run it.
            $get = match ($carried) {
                'cookie' => [],
                'page' => ['p202lpid' => str_repeat('a7', 16)],
                'refused' => ['p202_consent' => '0'],
            };
            $identity = \Prosper202\Identity\ClickIdentity::fromRequest($get, [], true, $carried === 'cookie');
            $identity->attach($conn, 1, $click, self::CLICK_TIME);
        }
        // A re-click of 110 refuses consent, one of 111 gives it: the address is the latest visit's, and so is the consent asked.
        $cookie = ['p202vid' => str_repeat('b8', 16)];
        foreach ([110 => ['p202_consent' => '0'], 111 => [], 112 => []] as $click => $get) {
            \Prosper202\Identity\ClickIdentity::fromRequest([], $cookie, true, false)->attach($conn, 1, $click, self::CLICK_TIME);
            self::fixture("INSERT INTO 202_clicks SET click_id=$click, user_id=1, aff_campaign_id=30, ppc_account_id=70, click_payout=2.5, click_cpc=0, click_lead=0, click_time=" . (self::CLICK_TIME + 100));
            \Prosper202\Identity\ClickIdentity::fromRequest($get, $cookie, true, false)->attach($conn, 1, $click, self::CLICK_TIME + 100);
        }
        // The same second, through the real re-click (a visit in a second the click has a row at rewrites it):
        // 113 consented and then refuses, 114 refused and then consents.
        $reClick = static function (int $click, array $get, int $time) use ($repo, $cookie): void {
            $repo->recordReClick(new \Prosper202\Click\ClickRecord(
                clickId: $click,
                userId: 1,
                clickTime: $time,
                affCampaignId: 30,
                ppcAccountId: 70,
                clickPayout: '2.5',
                ipId: 5,
                recordsCampaignRows: false,
                rotator: new \Prosper202\Click\RotatorRoute(1, 1, 1),
                identity: \Prosper202\Identity\ClickIdentity::fromRequest($get, $cookie, true, false),
            ), static fn (): int => 0);
        };
        foreach ([113 => ['p202_consent' => '0'], 114 => []] as $click => $get) {
            $reClick($click, $get, self::CLICK_TIME);
        }
        $rows = self::$db->query('SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM 202_clicks WHERE click_id IN (113, 114)'
            . ' GROUP BY click_id) AS per_click')->fetch_row()[0];
        self::assertSame(1, (int) $rows, 'each re-click was in its first visit\'s second: one row');
        // 115's landing page withholds consent after the tracking template's hit gave a cookie: the same visit,
        // no new row.
        \Prosper202\Identity\ClickIdentity::fromRequest(['p202_consent' => '0'], $cookie, true, false)
            ->withdrawIfWithheld($conn, 115, self::CLICK_TIME);
        // 116 was marked by its landing page id alone, and the page then withholds.
        $withheld = \Prosper202\Identity\ClickIdentity::fromRequest(['p202_consent' => '0'], [], true, false);
        $withheld->withdrawIfWithheld($conn, 116, self::CLICK_TIME);
        // A refusal holds for the whole visit: a later request of it that names no p202_consent (the landing
        // page's, absent meaning given) leaves its mark and gives nothing back. 119's tracking link refused.
        $page = static fn (string $lpid): \Prosper202\Identity\ClickIdentity
            => \Prosper202\Identity\ClickIdentity::fromRequest(['p202lpid' => str_repeat($lpid, 16)], [], true, false);
        $marked = static fn (int $click, int $at): int => (int) self::$db->query(
            "SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$click AND signal_type='lpid'"
            . " AND observed_at >= $at"
        )->fetch_row()[0];
        self::assertNotNull($page('c1')->attach($conn, 1, 119, self::CLICK_TIME), 'the page\'s request was linked');
        self::assertSame(1, $marked(119, self::CLICK_TIME), 'and left its mark at the visit\'s time');
        self::assertSame([[(string) self::CLICK_TIME]], $refusals(119), 'a later request does not take a refusal away');
        // 120 refused, and is clicked again later, consenting: another visit, read on its own.
        $reClick(120, [], self::CLICK_TIME + 100);
        self::assertSame([[(string) self::CLICK_TIME]], $refusals(120), 'the refusal stays with its own visit');
        // 121's landing page withholds, and a later request of the page names no consent.
        $withheld->withdrawIfWithheld($conn, 121, self::CLICK_TIME);
        self::assertNotNull($page('c2')->attach($conn, 1, 121, self::CLICK_TIME));
        self::assertSame(1, $marked(121, self::CLICK_TIME));
        // 122 is clicked again later and refuses; the page that visit lands on names no consent.
        $reClick(122, ['p202_consent' => '0'], self::CLICK_TIME + 100);
        self::assertNotNull($page('c3')->attach($conn, 1, 122, self::CLICK_TIME + 100));
        self::assertSame(1, $marked(122, self::CLICK_TIME + 100));
        // 123's refusing re-click is stamped before the visit it follows (a web server's clock): the refusal
        // covers the latest visit recorded, which a late request of that visit then marks.
        $reClick(123, [], self::CLICK_TIME + 100);
        $reClick(123, ['p202_consent' => '0'], self::CLICK_TIME + 50);
        self::assertSame([[(string) (self::CLICK_TIME + 100)]], $refusals(123), 'it covers every visit so far');
        self::assertNotNull($page('c4')->attach($conn, 1, 123, self::CLICK_TIME + 100));
        self::assertSame(1, $marked(123, self::CLICK_TIME + 100));
        self::assertSame([], $refusals(100), 'a click that never withheld consent stores no refusal');
        self::assertSame([], $refusals(111), 'nor does one clicked again with consent');
        // A withdrawal that meets a lock: one that clears is waited out (117); one that does not is the
        // caller's failure, never a log line with the mark left standing (118); its refusal is stored first.
        $open = static function (): \mysqli {
            return new \mysqli(
                (string) getenv('P202_TEST_DB_HOST'),
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        };
        $impatient = $open();
        $impatient->query('SET SESSION innodb_lock_wait_timeout = 1');
        $hold = static function (int $click, int $idleSeconds) use ($open): \mysqli {
            $holder = $open();
            $holder->query('SET SESSION wait_timeout = ' . $idleSeconds);
            $holder->query('START TRANSACTION');
            $held = $holder->query("SELECT 1 FROM 202_identity_observations WHERE click_id=$click FOR UPDATE");
            self::assertGreaterThan(0, $held->num_rows, 'the mark is held');

            return $holder;
        };
        // The server closes 117's holder after two idle seconds; the first try has timed out by then.
        $transient = $hold(117, 2);
        $started = microtime(true);
        $withheld->withdrawIfWithheld(new Connection($impatient), 117, self::CLICK_TIME);
        self::assertGreaterThan(0.9, microtime(true) - $started, 'the first try met the lock');
        unset($transient);
        $holder = $hold(118, 600);
        $thrown = null;
        try {
            $withheld->withdrawIfWithheld(new Connection($impatient), 118, self::CLICK_TIME);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $holder->query('ROLLBACK');
        $holder->close();
        $impatient->close();
        self::assertNotNull($thrown, 'a withdrawal that could not be made is thrown, not logged');
        self::assertTrue(Connection::isRetryableLockError($thrown), 'and it is the lock that is thrown');
        $standing = (int) self::$db->query('SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=118'
            . " AND signal_type='vid' AND observed_at >= " . self::CLICK_TIME)->fetch_row()[0];
        self::assertSame(1, $standing, '118\'s mark could not be taken back');
        self::assertSame([[(string) self::CLICK_TIME]], $refusals(118), 'and its refusal is stored all the same');
        self::assertSame([[(string) self::CLICK_TIME]], $refusals(117), 'the one that cleared stored the refusal too');
        // The refused click is linked afterwards by a customer id its conversion names (the v3 customer_ref path).
        self::assertNotNull(\Prosper202\Identity\ClickIdentity::trustedCustomer('crm-104')->attachToStoredClick($conn, 104), 'the customer link was written');
        $facts = [];
        $type = $this->capturing($facts);
        $store = new DestinationStore(new Connection(self::$db), [Ga4Destination::TYPE => $type]);
        $create = static fn (array $settings): int => (int) $store->create(1, Ga4Destination::TYPE, 'Store GA4', $settings + ['measurement_id' => 'G-ABC123'], ['api_secret' => self::SECRET], time())['destination_id'];
        $queue = static fn (): DestinationQueue => new DestinationQueue(new Connection(self::$db), $store, static fn (string $ip): bool => false);

        $on = $create(['send_user_data' => true]);
        $ids = ['ORD-60' => $this->record(100, 'ORD-60'), '' => $this->record(101, ''), 'ORD-62' => $this->record(102, 'ORD-62')];
        $converting = [
            103, 104, 105, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120, 121, 122, 123,
        ];
        foreach ($converting as $click) {
            $conv = $this->record($click, 'ORD-' . $click);
            if ($click === 108) {
                // A campaign id that names another account's campaign reads as no campaign (CLAUDE.md #27).
                self::fixture("UPDATE 202_conversion_logs SET campaign_id=40 WHERE conv_id=$conv");
            } elseif ($click === 109) {
                self::fixture("UPDATE 202_conversion_logs SET campaign_id=999 WHERE conv_id=$conv");
            }
        }
        // A later row of another account under 112's id: its latest visit is not the account's, and reads as none (CLAUDE.md #27).
        self::fixture('INSERT INTO 202_clicks SET click_id=112, user_id=2, aff_campaign_id=40, ppc_account_id=70, click_payout=2.5, click_cpc=0, click_lead=0, click_time=' . (self::CLICK_TIME + 500));
        // A browser mark as new as that visit sits under the same click id (observations carry no account): only
        // the account tie on the latest-visit read keeps it from standing in for a visit of account 1.
        $foreign = self::CLICK_TIME + 500;
        self::fixture('UPDATE 202_identity_observations SET observed_at=' . $foreign
            . " WHERE click_id=112 AND signal_type='vid'");
        $marks = self::$db->query('SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=112'
            . " AND signal_type='vid' AND observed_at >= $foreign")->fetch_row()[0];
        self::assertGreaterThan(0, (int) $marks, 'the mark is there to be read');
        $run = $queue()->sendDue(30, 5, time() + 1);
        $by = [];
        foreach ($facts as $f) {
            $by[$f->clickId] = $f;
        }
        // A visitor who refused, on the visit or on a request of it, is sent nothing: the delivery is
        // withheld and says why. Reports still count the conversion.
        $refusedVisits = [113, 114, 115, 116, 117, 118, 119, 121, 122, 123];
        self::assertSame(['sent' => 13, 'withheld' => 10], ['sent' => $run['sent'], 'withheld' => $run['withheld']]);
        $why = self::$db->query('SELECT c.click_id, d.status, d.last_error FROM 202_destination_deliveries d'
            . ' JOIN 202_conversion_logs c ON c.conv_id = d.conv_id WHERE d.destination_id = ' . $on
            . ' AND c.click_id IN (' . implode(',', $refusedVisits) . ') ORDER BY c.click_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame($refusedVisits, array_map('intval', array_column($why, 'click_id')));
        foreach ($why as $row) {
            $what = 'nothing of click ' . $row['click_id'] . ' is sent';
            self::assertArrayNotHasKey((int) $row['click_id'], $by, $what);
            self::assertSame('withheld', $row['status']);
            $what = 'and it says the visitor refused';
            self::assertStringStartsWith(DestinationQueue::REFUSED, (string) $row['last_error'], $what);
        }
        self::assertSame('198.51.100.7', $by[100]->ipAddress, 'the positive control: an unmasked address, user data on');
        self::assertNull($by[101]->ipAddress, 'an address stored masked is not the visitor\'s');
        self::assertNull($by[103]->ipAddress, 'a private address names no one a platform could match');
        self::assertNull($by[104]->ipAddress, 'a click whose request left no browser mark sends no address, even once a customer id links it');
        self::assertNull($by[107]->ipAddress, 'a shared (carrier-grade NAT) address names no visitor');
        self::assertNull($by[108]->ipAddress, 'a campaign of another account is no campaign');
        self::assertNull($by[109]->ipAddress, 'nor is a campaign that is gone');
        self::assertSame('198.51.100.7', $by[100]->ipAddress, 'the positive control for the visitor cookie');
        self::assertNull($by[110]->ipAddress, 'a re-click that left no mark sends no address, whatever an earlier visit gave');
        self::assertSame('198.51.100.7', $by[111]->ipAddress, 'and a re-click that gave it sends the address');
        self::assertNull($by[112]->ipAddress, 'another account\'s latest visit is no visit of it, whatever its mark');
        self::assertSame('198.51.100.7', $by[120]->ipAddress, 'a later visit that consents is read on its own');
        self::assertNull($by[105]->ipAddress, 'nor one whose campaign has identity capture off');
        self::assertSame('198.51.100.7', $by[102]->ipAddress, 'and for the landing page id');
        self::assertSame('https://shop.example.com/spring', $by[100]->pageUrl, 'the landing page, without what its query and fragment carry');
        self::assertSame('https://track.example.com/tracking202/redirect/dl.php', $by[101]->pageUrl, 'else the tracking link, without its subids');
        self::assertNull($by[102]->pageUrl, 'no page stored, none guessed');
        self::assertNull($by[103]->pageUrl, 'a stored address that is not an http(s) URL is no page');
        self::assertSame('https://shop.example.com:8443/spring/checkout', $by[104]->pageUrl, 'no user:password@, no ;path parameters, the port kept');
        self::assertNull($by[107]->pageUrl, 'a page longer than PAGE_URL_CHARS is none, not cut into another page');
        self::assertNull($by[100]->userAgent, 'no click stores one');
        self::assertSame(['ORD-60', 'P202-' . $ids[''], 'ORD-62'], [$by[100]->transactionId, $by[101]->transactionId, $by[102]->transactionId]);
        self::assertSame('P202-' . $ids[''], TrafficSourcePixels::withConversionEventId(new Connection(self::$db), [], 1, 101, $ids[''], '')[TrafficSourcePixels::EVENT_ID]);

        $this->store()->update(1, $on, ['enabled' => false], time());
        $facts = [];
        $off = $create([]);
        $denied = $create(['send_user_data' => true, 'consent' => ['ad_user_data' => 'denied']]);
        $this->click(106);
        self::fixture('INSERT INTO 202_clicks_advance SET click_id=106, ip_id=5');
        \Prosper202\Identity\ClickIdentity::fromRequest([], [], true)->attach($conn, 1, 106, self::CLICK_TIME);
        $this->record(106, 'ORD-63');
        self::assertSame(2, $queue()->sendDue(10, 5, time() + 2)['sent']);
        self::assertCount(2, $facts);
        foreach ($facts as $f) {
            self::assertSame(106, $f->clickId);
            self::assertNull($f->ipAddress, 'user data off, or ad_user_data denied: no address');
        }
        self::assertNotSame($off, $denied);
    }

    /**
     * A conversion whose own request withheld consent (a thank-you page's
     * pixel with p202_consent=0, after a click that consented) is sent to no
     * destination: its delivery is withheld and says the visitor refused, a
     * send again checks again, and the install still records it. The
     * refusal is the conversion's, so a later visit that consents, before
     * the send or after, changes neither conversion. A repeat of the same
     * conversion that refuses tightens it; nothing loosens it.
     */
    public function testAConversionWhoseOwnRequestRefusedConsentIsSentToNoDestination(): void
    {
        foreach (['202_clicks_visitor', '202_identity_observations', '202_click_consent_refusals'] as $t) {
            self::fixture("DELETE FROM $t WHERE click_id BETWEEN 140 AND 149");
        }
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        self::fixture("INSERT INTO 202_customers SET customer_id=9, user_id=1, primary_ref='crm-9',
            email='Jane.Doe@Example.com', phone='+1 555 123 4567',
            first_seen_time=1, last_activity_time=1, created_at=1, updated_at=1");
        $conn = new Connection(self::$db);
        $cookie = ['p202vid' => str_repeat('c9', 16)];
        foreach ([140, 141, 142, 143, 144, 145] as $click) {
            self::fixture("INSERT INTO 202_clicks_advance SET click_id=$click, ip_id=5");
            $this->click($click);
            // The click's own request consented.
            \Prosper202\Identity\ClickIdentity::fromRequest([], $cookie, true, false)
                ->attach($conn, 1, $click, self::CLICK_TIME);
        }
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $settings = ['measurement_id' => 'G-ABC123', 'send_user_data' => true, 'user_id_source' => 'customer'];
        $secret = ['api_secret' => self::SECRET];
        $created = $store->create(1, Ga4Destination::TYPE, 'Store GA4', $settings, $secret, time());
        $on = (int) $created['destination_id'];
        $queue = static fn (): DestinationQueue
            => new DestinationQueue($conn, $store, static fn (string $ip): bool => false);
        $repo = new MysqlConversionRepository($conn);
        $record = static function (int $click, string $tx, array $more = []) use ($repo): int {
            $data = ['click_id' => $click, 'payout' => '19.90', 'transaction_id' => $tx, 'source' => 'pixel'] + $more;
            $convId = (int) $repo->record(1, $data, MatchMethod::CLICK_ID)['convId'];
            self::fixture("UPDATE 202_conversion_logs SET customer_id=9 WHERE conv_id=$convId");

            return $convId;
        };
        $refused = $record(140, 'ORD-W1', ['consent_withheld' => true]);
        $given = $record(141, 'ORD-W2', ['consent_withheld' => false]);
        $absent = $record(142, 'ORD-W3');
        $stored = static fn (int $convId): string => (string) self::$db->query(
            "SELECT consent_withheld FROM 202_conversion_logs WHERE conv_id=$convId"
        )->fetch_row()[0];
        self::assertSame(
            ['1', '0', '0'],
            [$stored($refused), $stored($given), $stored($absent)],
            'the refusal is on the row, with it'
        );
        // 143's conversion refuses, and before it is sent the visitor clicks again, consenting: a later visit,
        // whose own conversion is read on its own; the first keeps its refusal.
        $early = $record(143, 'ORD-W4', ['consent_withheld' => true]);
        self::fixture('INSERT INTO 202_clicks SET click_id=143, user_id=1, aff_campaign_id=30, ppc_account_id=70,'
            . ' click_payout=2.5, click_cpc=0, click_lead=0, click_time=' . (self::CLICK_TIME + 100));
        \Prosper202\Identity\ClickIdentity::fromRequest([], $cookie, true, false)
            ->attach($conn, 1, 143, self::CLICK_TIME + 100);
        $later = $record(143, 'ORD-W5');
        // A repeat tightens and never loosens: 144 consents, then the same sale is reported again refusing
        // (a reloaded thank-you page) and again consenting; 145 refuses first, then consents.
        $tightened = $record(144, 'ORD-D1');
        self::assertSame($tightened, $record(144, 'ORD-D1', ['consent_withheld' => true]), 'the same conversion');
        $record(144, 'ORD-D1', ['consent_withheld' => false]);
        $kept = $record(145, 'ORD-D2', ['consent_withheld' => true]);
        $record(145, 'ORD-D2');
        $flags = [$stored($tightened), $stored($kept)];
        self::assertSame(['1', '1'], $flags, 'a refusing repeat tightens; nothing loosens');

        $run = $queue()->sendDue(10, 5, time() + 1);
        self::assertSame(['sent' => 3, 'withheld' => 4], ['sent' => $run['sent'], 'withheld' => $run['withheld']]);
        $by = [];
        foreach ($facts as $f) {
            $by[$f->convId] = $f;
        }
        $consented = [
            $given => 'a conversion that consented',
            $absent => 'one that named no p202_consent',
            $later => 'a later visit\'s conversion',
        ];
        foreach ($consented as $convId => $what) {
            self::assertSame('198.51.100.7', $by[$convId]->ipAddress, "the positive control: $what sends the address");
            $email = [hash('sha256', 'jane.doe@example.com')];
            self::assertSame($email, $by[$convId]->emailHashes, "and the hashed email ($what)");
            self::assertNotSame([], $by[$convId]->phoneHashes, "and the hashed phone ($what)");
            self::assertSame(9, $by[$convId]->customerId, "and the customer id ($what)");
        }
        $withheld = [
            $refused => 'a conversion whose request refused',
            $early => 'one sent after a later visit consented',
            $tightened => 'one a refusing repeat tightened',
            $kept => 'one a consenting repeat did not loosen',
        ];
        $status = static fn (int $convId): array => self::$db->query(
            "SELECT status, last_error FROM 202_destination_deliveries WHERE destination_id=$on AND conv_id=$convId"
        )->fetch_assoc();
        foreach ($withheld as $convId => $what) {
            self::assertArrayNotHasKey($convId, $by, "$what sends nothing, not the event nor its click ids");
            self::assertSame('withheld', $status($convId)['status'], "$what is withheld");
            self::assertSame(
                DestinationQueue::REFUSED . ' on the conversion\'s request',
                $status($convId)['last_error'],
                "$what says the visitor refused"
            );
        }
        // Sent again, each is checked again and stays withheld.
        self::assertSame(4, $queue()->resend(1, $on, null, 'withheld', time() + 2)['queued']);
        $again = $queue()->sendDue(10, 5, time() + 3);
        self::assertSame(['sent' => 0, 'withheld' => 4], ['sent' => $again['sent'], 'withheld' => $again['withheld']]);
        self::assertCount(3, $facts, 'nothing more was sent');
        $counted = (int) self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id BETWEEN 140 AND 145'
            . ' AND deleted = 0')->fetch_row()[0];
        self::assertSame(7, $counted, 'the install records every conversion, the refused ones too');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consent_withheld must be true or false');
        $record(146, 'ORD-W6', ['consent_withheld' => '0']);
    }

    /**
     * A refusal on the click's visit keeps its conversions from every
     * destination, read against the visit the conversion credits: a conversion of a refused visit is withheld though
     * a later visit consents before the send, and one of a consenting visit
     * is withheld when a later visit refuses before the send. A campaign
     * whose identity capture is off is no refusal: its conversion is sent.
     */
    public function testARefusalOnTheClicksVisitIsSentToNoDestination(): void
    {
        // Every row a click id names, so the test runs again on the same database.
        $perClick = self::$db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND COLUMN_NAME = 'click_id' AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')")->fetch_all(MYSQLI_NUM);
        foreach (array_column($perClick, 0) as $t) {
            self::fixture("DELETE FROM `$t` WHERE click_id BETWEEN 155 AND 159");
        }
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        $this->campaign(33, null);
        self::fixture('UPDATE 202_aff_campaigns SET identity_signals=0 WHERE aff_campaign_id=33');
        $conn = new Connection(self::$db);
        $repo = new \Prosper202\Click\MysqlClickRepository($conn);
        $visit = static function (
            int $click,
            array $get,
            int $time,
            bool $again,
            int $campaign = 30
        ) use ($repo): void {
            $record = new \Prosper202\Click\ClickRecord(
                clickId: $click,
                userId: 1,
                clickTime: $time,
                affCampaignId: $campaign,
                ppcAccountId: 70,
                clickPayout: '2.5',
                ipId: 5,
                recordsCampaignRows: false,
                rotator: $again ? new \Prosper202\Click\RotatorRoute(1, 1, 1) : null,
                identity: \Prosper202\Identity\ClickIdentity::fromRequest($get, [], $campaign !== 33, false),
            );
            $again ? $repo->recordReClick($record, static fn (): int => 0) : $repo->recordClick($record);
        };
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $ga4 = ['measurement_id' => 'G-ABC123'];
        $created = $store->create(1, Ga4Destination::TYPE, 'Store GA4', $ga4, ['api_secret' => self::SECRET], time());
        $on = (int) $created['destination_id'];
        // 155 refuses, converts, and clicks again consenting before the send.
        $visit(155, ['p202_consent' => '0'], self::CLICK_TIME, false);
        $refusedVisit = $this->record(155, 'ORD-V1');
        $visit(155, [], self::CLICK_TIME + 100, true);
        $laterVisit = $this->record(155, 'ORD-V2');
        // 156 consents, converts, and clicks again refusing before the send.
        $visit(156, [], self::CLICK_TIME, false);
        $consentedVisit = $this->record(156, 'ORD-V3');
        $visit(156, ['p202_consent' => '0'], self::CLICK_TIME + 100, true);
        // 157 is on a campaign with identity capture off, and names no p202_consent.
        $visit(157, [], self::CLICK_TIME, false, 33);
        $captureOff = $this->record(157, 'ORD-V4');
        $stored = self::$db->query('SELECT click_id FROM 202_click_consent_refusals WHERE click_id = 157')->fetch_all();
        self::assertSame([], $stored, 'capture off stores no refusal');

        (new DestinationQueue($conn, $store, static fn (string $ip): bool => false))->sendDue(10, 5, time() + 1);
        $sent = array_map(static fn ($f): int => (int) $f->convId, $facts);
        $why = static fn (int $convId): string => (string) self::$db->query(
            "SELECT last_error FROM 202_destination_deliveries WHERE destination_id=$on AND conv_id=$convId"
        )->fetch_row()[0];
        $what = 'a refused visit\'s conversion is not sent after a later visit consents';
        self::assertNotContains($refusedVisit, $sent, $what);
        self::assertSame(DestinationQueue::REFUSED . ' on the click\'s visit', $why($refusedVisit));
        self::assertNotContains($consentedVisit, $sent, 'a conversion is not sent once a later visit has refused');
        self::assertSame(DestinationQueue::REFUSED . ' on the click\'s visit', $why($consentedVisit));
        $what = 'the positive control: the later consenting visit\'s conversion is sent';
        self::assertContains($laterVisit, $sent, $what);
        self::assertContains($captureOff, $sent, 'a campaign with capture off is no refusal');
    }

    /**
     * On a campaign that pays the latest conversion, one that replaces a
     * refused conversion would be sent under the refused one's event id
     * (DestinationEventId): it is withheld too. One that replaces a
     * consenting conversion is sent.
     */
    public function testAConversionThatReplacesARefusedOneIsSentToNoDestination(): void
    {
        $this->campaign(34, null, 'replace');
        $facts = [];
        $conn = new Connection(self::$db);
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $ga4 = ['measurement_id' => 'G-ABC123'];
        $created = $store->create(1, Ga4Destination::TYPE, 'Store GA4', $ga4, ['api_secret' => self::SECRET], time());
        $on = (int) $created['destination_id'];
        $queue = static fn (): DestinationQueue
            => new DestinationQueue($conn, $store, static fn (string $ip): bool => false);
        $repo = new MysqlConversionRepository($conn);
        $record = static fn (int $click, string $tx, bool $refuses): int => (int) $repo->record(1, [
            'click_id' => $click, 'payout' => '19.90', 'transaction_id' => $tx, 'source' => 'pixel',
            'consent_withheld' => $refuses,
        ], MatchMethod::CLICK_ID)['convId'];
        $this->click(150, 34);
        $this->click(151, 34);
        $record(150, 'ORD-R1', true);
        $replacing = $record(150, 'ORD-R2', false);
        $record(151, 'ORD-R3', false);
        $control = $record(151, 'ORD-R4', false);
        $queue()->sendDue(10, 5, time() + 1);
        $sent = array_map(static fn ($f): int => (int) $f->convId, $facts);
        self::assertNotContains($replacing, $sent, 'a replacement of a refused sale is not sent under its name');
        self::assertContains($control, $sent, 'the positive control: a replacement of a consenting sale is');
        $why = (string) self::$db->query('SELECT last_error FROM 202_destination_deliveries'
            . " WHERE destination_id=$on AND conv_id=$replacing")->fetch_row()[0];
        self::assertSame(DestinationQueue::REFUSED . ' on a conversion this one replaced', $why);
    }

    /**
     * An event (`event=` on a pixel or postback) that reaches a goal: the
     * request's p202_consent is stored with the event, and every ledger row
     * its outcomes write carries it, first or again: a subject's first
     * event, a later one, a replay (an event older than the last), a replay
     * that renumbers events of one second, and a re-evaluation under a new
     * goal version. None of those rows is sent; the consenting ones are.
     */
    public function testAGoalReachedByARefusingRequestIsSentToNoDestinationHoweverItIsWrittenAgain(): void
    {
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        $conn = new Connection(self::$db);
        foreach ([147, 148, 149, 152] as $click) {
            self::fixture("INSERT INTO 202_clicks_advance SET click_id=$click, ip_id=5");
            $this->click($click);
            \Prosper202\Identity\ClickIdentity::fromRequest([], [], true)->attach($conn, 1, $click, self::CLICK_TIME);
        }
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $ga4 = ['measurement_id' => 'G-ABC123'];
        $store->create(1, Ga4Destination::TYPE, 'Store GA4', $ga4, ['api_secret' => self::SECRET], time());
        $goal = static fn (string $name, string $event, int $amount, array $more = []): array
            => ['name' => $name, 'trigger' => ['event' => $event], 'value' => ['type' => 'fixed', 'amount' => $amount]]
                + $more;
        $this->campaignGoal(30, $goal('Signup', 'signup', 3));
        $purchase = $this->campaignGoal(30, $goal('Purchase', 'purchase', 10));
        $this->campaignGoal(30, $goal('Buy', 'buy', 2, ['repeat' => ['mode' => 'each', 'max' => 10]]));
        $engine = new \Prosper202\Goals\GoalEngine($conn);
        $ingest = static function (int $click, string $event, array $get, int $at) use ($engine): void {
            $engine->ingest(1, $engine->clickSubject(1, $click), [
                \Prosper202\Goals\WebEvents::fromQuery(['event' => $event] + $get, '', $at, true),
            ], MatchMethod::CLICK_ID);
        };
        $now = self::CLICK_TIME + 1000;
        $ingest(147, 'purchase', ['p202_consent' => '0'], $now);
        $ingest(148, 'purchase', [], $now);
        // 149: a consenting signup, then a refusing purchase (a later event), then a refusing
        // trial older than both (a replay).
        $ingest(149, 'signup', [], $now + 20);
        $ingest(149, 'purchase', ['p202_consent' => '0'], $now + 30);
        $this->campaignGoal(30, $goal('Trial', 'trial', 1));
        $ingest(149, 'trial', ['p202_consent' => '0'], $now + 10);
        // 152: a refusing pixel and then a consenting one in the same second; the tie is broken by event id,
        // so the second sorts first and the refusing one is renumbered and written again.
        $ingest(152, 'buy', ['event_id' => 'b-refusing', 'p202_consent' => '0'], $now);
        $ingest(152, 'buy', ['event_id' => 'a-consenting'], $now);
        // A new version of Purchase, applied: 147's and 148's rows are retired and written again.
        $definition = \Prosper202\Goals\GoalDefinition::parse($goal('Purchase', 'purchase', 11));
        $this->goals->addVersion(1, $purchase, $definition, 2);
        self::assertTrue($engine->reevaluate(1, $purchase, null, true)['applied']);

        $live = static fn (int $click): array => array_column(self::$db->query(
            'SELECT o.event_id, c.consent_withheld FROM 202_goal_outcomes o'
                . ' JOIN 202_conversion_logs c ON c.conv_id = o.conversion_id'
                . " WHERE c.click_id = $click AND c.deleted = 0 AND o.superseded_by IS NULL ORDER BY o.event_id"
        )->fetch_all(MYSQLI_ASSOC), 'consent_withheld', 'event_id');
        $stored = static fn (int $click): array => array_column(self::$db->query(
            "SELECT event_id, consent_withheld FROM 202_goal_events WHERE subject_type = 'click'"
                . " AND subject_id = $click"
                . ' ORDER BY event_id'
        )->fetch_all(MYSQLI_ASSOC), 'consent_withheld', 'event_id');
        self::assertSame(['1'], array_values($stored(147)), 'the refusal is stored with its event');
        self::assertSame(['1'], array_values($live(147)), 'a re-evaluation writes the refused sale again refused');
        self::assertSame(['0'], array_values($live(148)), 'the positive control: a consenting one stays consenting');
        $byName = array_column(self::$db->query(
            'SELECT event_name, consent_withheld FROM 202_conversion_logs WHERE click_id = 149 AND deleted = 0'
                . ' AND superseded_by IS NULL'
        )->fetch_all(MYSQLI_ASSOC), 'consent_withheld', 'event_name');
        ksort($byName);
        self::assertSame(['purchase' => '1', 'signup' => '0', 'trial' => '1'], $byName, 'a later event and a replay');
        $what = 'a renumbering replay keeps each event\'s own';
        self::assertSame(['a-consenting' => '0', 'b-refusing' => '1'], $live(152), $what);

        $queue = new DestinationQueue($conn, $store, static fn (string $ip): bool => false);
        $queue->sendDue(30, 5, time() + 1);
        $sent = array_map(static fn ($f): int => (int) $f->convId, $facts);
        $refusedRows = array_map('intval', array_column(self::$db->query(
            'SELECT conv_id FROM 202_conversion_logs WHERE click_id IN (147, 148, 149, 152) AND deleted = 0'
                . ' AND superseded_by IS NULL'
                . ' AND consent_withheld = 1'
        )->fetch_all(MYSQLI_NUM), 0));
        $consentingRows = array_map('intval', array_column(self::$db->query(
            'SELECT conv_id FROM 202_conversion_logs WHERE click_id IN (147, 148, 149, 152) AND deleted = 0'
                . ' AND superseded_by IS NULL'
                . ' AND consent_withheld = 0'
        )->fetch_all(MYSQLI_NUM), 0));
        self::assertCount(4, $refusedRows, 'one live row per refused event');
        self::assertSame([], array_values(array_intersect($refusedRows, $sent)), 'no refused row is sent');
        sort($sent);
        sort($consentingRows);
        self::assertSame($consentingRows, $sent, 'every consenting one is');
    }

    /**
     * A goal event reported again (the same event id, a reloaded pixel or a
     * retried postback) by a request that refuses tightens the stored event
     * and the conversion its outcome wrote; a repeat that consents loosens
     * nothing.
     */
    public function testARepeatedGoalEventThatRefusesTightens(): void
    {
        $conn = new Connection(self::$db);
        foreach ([160, 161] as $click) {
            $this->click($click);
        }
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $ga4 = ['measurement_id' => 'G-ABC123'];
        $store->create(1, Ga4Destination::TYPE, 'Store GA4', $ga4, ['api_secret' => self::SECRET], time());
        $goal = static fn (int $amount): array
            => ['name' => 'Order', 'trigger' => ['event' => 'order'],
                'value' => ['type' => 'fixed', 'amount' => $amount]];
        $this->campaignGoal(30, $goal(4));
        $engine = new \Prosper202\Goals\GoalEngine($conn);
        $ingest = static function (int $click, string $id, array $get) use ($engine): void {
            $engine->ingest(1, $engine->clickSubject(1, $click), [
                \Prosper202\Goals\WebEvents::fromQuery(
                    ['event' => 'order', 'event_id' => $id] + $get,
                    '',
                    self::CLICK_TIME + 500,
                    true
                ),
            ], MatchMethod::CLICK_ID);
        };
        $flags = static fn (int $click): array => [
            (string) self::$db->query("SELECT consent_withheld FROM 202_goal_events WHERE subject_type = 'click'"
                . " AND subject_id = $click")->fetch_row()[0],
            (string) self::$db->query("SELECT consent_withheld FROM 202_conversion_logs WHERE click_id = $click"
                . ' AND deleted = 0 AND superseded_by IS NULL')->fetch_row()[0],
        ];
        $ingest(160, 'e-1', []);
        self::assertSame(['0', '0'], $flags(160));
        $ingest(160, 'e-1', ['p202_consent' => '0']);
        self::assertSame(['1', '1'], $flags(160), 'a refusing repeat tightens the event and its conversion');
        $ingest(160, 'e-1', []);
        self::assertSame(['1', '1'], $flags(160), 'a consenting repeat does not loosen');
        $ingest(161, 'e-2', ['p202_consent' => '0']);
        $ingest(161, 'e-2', []);
        self::assertSame(['1', '1'], $flags(161), 'refused first, it stays refused');

        (new DestinationQueue($conn, $store, static fn (string $ip): bool => false))->sendDue(30, 5, time() + 1);
        self::assertSame([], $facts, 'none of them is sent');
    }

    /**
     * A goal conversion written again (a re-evaluation) credits the visit the
     * row it replaces credited, not the click's latest: a sale made on a
     * visit that refused is not sent because a later visit consented before
     * the re-evaluation.
     */
    public function testAGoalRowWrittenAgainCreditsTheVisitTheFirstDid(): void
    {
        $conn = new Connection(self::$db);
        $perClick = self::$db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND COLUMN_NAME = 'click_id' AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE')")->fetch_all(MYSQLI_NUM);
        foreach (array_column($perClick, 0) as $t) {
            self::fixture("DELETE FROM `$t` WHERE click_id = 163");
        }
        $repo = new \Prosper202\Click\MysqlClickRepository($conn);
        $visit = static function (array $get, int $time, bool $again) use ($repo): void {
            $record = new \Prosper202\Click\ClickRecord(
                clickId: 163,
                userId: 1,
                clickTime: $time,
                affCampaignId: 30,
                ppcAccountId: 70,
                clickPayout: '2.5',
                ipId: 5,
                recordsCampaignRows: false,
                rotator: $again ? new \Prosper202\Click\RotatorRoute(1, 1, 1) : null,
                identity: \Prosper202\Identity\ClickIdentity::fromRequest($get, [], true, false),
            );
            $again ? $repo->recordReClick($record, static fn (): int => 0) : $repo->recordClick($record);
        };
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $ga4 = ['measurement_id' => 'G-ABC123'];
        $store->create(1, Ga4Destination::TYPE, 'Store GA4', $ga4, ['api_secret' => self::SECRET], time());
        $goal = static fn (int $amount): array
            => ['name' => 'Sale', 'trigger' => ['event' => 'sale'],
                'value' => ['type' => 'fixed', 'amount' => $amount]];
        $sale = $this->campaignGoal(30, $goal(4));
        $engine = new \Prosper202\Goals\GoalEngine($conn);
        $visit([], self::CLICK_TIME, false);
        $visit(['p202_consent' => '0'], self::CLICK_TIME + 100, true);
        $engine->ingest(1, $engine->clickSubject(1, 163), [
            \Prosper202\Goals\WebEvents::fromQuery(['event' => 'sale'], '', self::CLICK_TIME + 150, true),
        ], MatchMethod::CLICK_ID);
        $visit([], self::CLICK_TIME + 200, true);
        $this->goals->addVersion(1, $sale, \Prosper202\Goals\GoalDefinition::parse($goal(6)), 2);
        $engine->reevaluate(1, $sale, null, true);
        $live = self::$db->query('SELECT click_time FROM 202_conversion_logs WHERE click_id = 163'
            . ' AND deleted = 0 AND superseded_by IS NULL')->fetch_all(MYSQLI_NUM);
        self::assertSame([[(string) (self::CLICK_TIME + 100)]], $live, 'the new row credits the refusing visit');
        (new DestinationQueue($conn, $store, static fn (string $ip): bool => false))->sendDue(30, 5, time() + 1);
        self::assertSame([], $facts, 'and is not sent');
    }

    /**
     * Before the 1.9.80 upgrade adds 202_conversion_logs.consent_withheld a
     * refusal has nowhere to be kept with its row: the conversion is still
     * recorded, but queued for no destination (CLAUDE.md #11). A consenting
     * one is queued as before.
     */
    public function testARefusalThatCannotBeKeptWithItsRowQueuesNoDelivery(): void
    {
        $destination = $this->destination();
        $this->click(153);
        $this->click(154);
        // A connection of its own: the column's presence is remembered per connection.
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::fixture('ALTER TABLE 202_conversion_logs DROP COLUMN consent_withheld');
        try {
            $repo = new MysqlConversionRepository(new Connection($db));
            $refused = (int) $repo->record(1, ['click_id' => 153, 'payout' => '19.90', 'transaction_id' => 'ORD-U1',
                'source' => 'pixel', 'consent_withheld' => true], MatchMethod::CLICK_ID)['convId'];
            $given = (int) $repo->record(1, ['click_id' => 154, 'payout' => '19.90', 'transaction_id' => 'ORD-U2',
                'source' => 'pixel'], MatchMethod::CLICK_ID)['convId'];
        } finally {
            self::fixture('ALTER TABLE 202_conversion_logs ADD COLUMN consent_withheld'
                . " tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER currency_basis");
            $db->close();
        }
        self::assertGreaterThan(0, $refused, 'the refused conversion is recorded');
        $queued = array_map('intval', array_column(self::deliveries($destination), 'conv_id'));
        self::assertNotContains($refused, $queued, 'and queued for no destination');
        self::assertContains($given, $queued, 'the positive control: a consenting one is queued');
    }

    /**
     * Without the table a click's refusal is kept in, a send cannot tell
     * whether the visitor refused: it sends nothing that time (CLAUDE.md
     * #11), and the run goes on to the next delivery (#24).
     */
    public function testASendThatCannotReadTheClicksRefusalsSendsNothingAndTheRunGoesOn(): void
    {
        self::fixture("INSERT INTO 202_ips SET ip_id=5, ip_address='198.51.100.7'");
        $conn = new Connection(self::$db);
        foreach ([145, 146] as $click) {
            self::fixture("INSERT INTO 202_clicks_advance SET click_id=$click, ip_id=5");
            $this->click($click);
            \Prosper202\Identity\ClickIdentity::fromRequest([], [], true)->attach($conn, 1, $click, self::CLICK_TIME);
        }
        $facts = [];
        $store = new DestinationStore($conn, [Ga4Destination::TYPE => $this->capturing($facts)]);
        $settings = ['measurement_id' => 'G-ABC123', 'send_user_data' => true];
        $store->create(1, Ga4Destination::TYPE, 'Store GA4', $settings, ['api_secret' => self::SECRET], time());
        $this->record(145, 'ORD-T1');
        $this->record(146, 'ORD-T2');
        self::fixture('RENAME TABLE 202_click_consent_refusals TO 202_click_consent_refusals_away');
        try {
            $queue = new DestinationQueue($conn, $store, static fn (string $ip): bool => false);
            $run = $queue->sendDue(10, 5, time() + 1);
        } finally {
            self::fixture('RENAME TABLE 202_click_consent_refusals_away TO 202_click_consent_refusals');
        }
        self::assertSame([], $facts, 'nothing is sent, the address least of all');
        self::assertSame(0, $run['sent']);
        self::assertSame(2, $run['retrying'], 'both deliveries were tried and wait for a later run');
    }

    /**
     * GA4's settings and credentials, with a send that keeps the facts it
     * was handed and calls nothing.
     *
     * @param list<\Prosper202\Destinations\ConversionFacts> $facts
     */
    private function capturing(array &$facts): \Prosper202\Destinations\DestinationType
    {
        return new class ($facts) implements \Prosper202\Destinations\DestinationType {
            private Ga4Destination $ga4;

            /** @param list<\Prosper202\Destinations\ConversionFacts> $facts */
            public function __construct(private array &$facts)
            {
                $this->ga4 = new Ga4Destination();
            }

            public function validate(array $settings, ?array $credentials): array
            {
                return $this->ga4->validate($settings, $credentials);
            }

            public function send(array $settings, array $credentials, \Prosper202\Destinations\ConversionFacts $facts, bool $test, int $now): \Prosper202\Destinations\SendResult
            {
                $this->facts[] = $facts;

                return new \Prosper202\Destinations\SendResult(true, false, 204, '');
            }

            public function settingKeys(): array
            {
                return $this->ga4->settingKeys();
            }

            public function credentialKeys(): array
            {
                return $this->ga4->credentialKeys();
            }

            public function host(array $settings): string
            {
                return $this->ga4->host($settings);
            }

            public function backfillDays(): int
            {
                return $this->ga4->backfillDays();
            }

            public function takesConsent(): bool
            {
                return true;
            }

            public function configured(array $settings): bool
            {
                return true;
            }

            public function hashEmail(?string $email): ?string
            {
                return $this->ga4->hashEmail($email);
            }

            public function hashPhone(?string $phone): ?string
            {
                return $this->ga4->hashPhone($phone);
            }

            public function formSettings(array $post, array $settings): array
            {
                return $this->ga4->formSettings($post, $settings);
            }

            public function label(array $settings): string
            {
                return $this->ga4->label($settings);
            }
        };
    }

    private function openAiStore(): DestinationStore
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $this->bodies[] = json_decode((string) $options[CURLOPT_POSTFIELDS], true) + ['_url' => $options[CURLOPT_URL]];

            return (array_shift($this->answers) ?? []) + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => '{}'];
        };

        return new DestinationStore(new Connection(self::$db), [OpenAiAdsDestination::TYPE => new OpenAiAdsDestination(new WebhookSender($guard, $transport))]);
    }

    public function testAChatGptAdsConversionIsQueuedWithTheSaleAndSentAsOneWebEvent(): void
    {
        self::fixture('DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN (70, 71)');
        self::fixture("INSERT INTO 202_ppc_accounts SET ppc_account_id=70, user_id=1, ppc_network_id=1, ppc_account_name='ChatGPT', ppc_account_deleted=0");
        self::fixture("INSERT INTO 202_ppc_accounts SET ppc_account_id=71, user_id=2, ppc_network_id=1, ppc_account_name='Theirs', ppc_account_deleted=0");
        self::fixture("INSERT INTO 202_site_urls SET site_url_id=11, site_domain_id=1, site_url_address='https://shop.example.com/spring'");
        self::fixture("INSERT INTO 202_customers SET customer_id=9, user_id=1, primary_ref='c-9', email=' Jane.Doe+promo@Gmail.com', phone='+1 555 123 4567',
            first_seen_time=1, last_activity_time=1, created_at=1, updated_at=1");
        $store = $this->openAiStore();
        $create = static fn (array $settings): int => (int) $store->create(1, OpenAiAdsDestination::TYPE, 'ChatGPT Ads', $settings + ['pixel_id' => 'px_4821'], ['conversions_api_key' => 'oai-capi-Key_0123456789abcdef'], time())['destination_id'];

        foreach ([
            'settings.consent' => ['consent' => ['ad_user_data' => 'granted']],
            'settings.ppc_account_id' => ['scope' => 'source', 'ppc_account_id' => 71],
        ] as $field => $settings) {
            try {
                $create($settings);
                self::fail('refused: ' . $field);
            } catch (\Prosper202\Destinations\DestinationInputException $e) {
                self::assertSame($field, $e->field);
            }
        }

        $all = $create(['scope' => 'all', 'send_user_data' => true]);
        $oppref = $create([]);
        $this->click(100);
        self::fixture('INSERT INTO 202_clicks_site SET click_id=100, click_landing_site_url_id=11');
        $convId = $this->record(100, 'ORD-70');
        self::fixture("UPDATE 202_conversion_logs SET customer_id=9 WHERE conv_id=$convId");
        self::assertSame([[(string) $convId, 'pending']], array_map(static fn (array $r): array => [$r['conv_id'], $r['status']], self::deliveries($all)), 'queued in the conversion\'s transaction');

        $queue = new DestinationQueue(new Connection(self::$db), $store, static fn (string $ip): bool => false);
        $out = $queue->sendDue(10, 5, time() + 1);
        self::assertSame([1, 1], [$out['sent'], $out['withheld']]);
        self::assertStringContainsString('oppref', (string) self::deliveries($oppref)[0]['last_error'], 'scope oppref holds back a click without one');
        self::assertCount(1, $this->bodies);
        $body = $this->bodies[0];
        self::assertSame('https://bzr.openai.com/v1/events?pid=px_4821', $body['_url']);
        $event = $body['events'][0];
        self::assertSame(['ORD-70', 'order_created', 'web', 'https://shop.example.com/spring'], [$event['id'], $event['type'], $event['action_source'], $event['source_url']]);
        self::assertSame(['type' => 'contents', 'amount' => 1990, 'currency' => 'USD'], $event['data']);
        self::assertSame([hash('sha256', 'jane.doe+promo@gmail.com')], $event['user']['emails_sha256'], 'OpenAI\'s normalisation, not Google\'s');
        self::assertSame([hash('sha256', '15551234567')], $event['user']['phone_numbers_sha256']);

        // The positive control for scope oppref: a click that carries one is sent, its id unmodified.
        $this->click(102);
        self::fixture('DELETE FROM 202_google WHERE click_id=102');
        self::fixture("INSERT INTO 202_google SET click_id=102, gclid='', oppref='opp_7Hq2-Xk9_aZ'");
        self::fixture('INSERT INTO 202_clicks_site SET click_id=102, click_landing_site_url_id=11');
        $this->record(102, 'ORD-72');
        self::assertSame(2, $queue->sendDue(10, 5, time() + 1)['sent']);
        self::assertSame(['opp_7Hq2-Xk9_aZ', 'opp_7Hq2-Xk9_aZ'], array_map(static fn (array $b): ?string => $b['events'][0]['oppref'] ?? null, array_slice($this->bodies, 1)));
        self::assertSame('sent', self::deliveries($oppref)[1]['status']);
        $this->bodies = [];

        // A visitor who may be in Europe is withheld even with privacy Disabled: OpenAI has no field for their
        // consent to measurement.
        $this->click(101);
        $this->record(101, 'ORD-71');
        $eu = new DestinationQueue(new Connection(self::$db), $store, static fn (string $ip): bool => true);
        self::assertSame(2, $eu->sendDue(10, 5, time() + 2)['withheld']);
        $why = (string) self::deliveries($all)[2]['last_error'];
        self::assertStringContainsString('has no field that states their consent to measurement', $why);
        self::assertSame([], $this->bodies);

        try {
            $queue->backfill(1, $all, 8, time());
            self::fail('a backfill past 7 days is refused');
        } catch (\Prosper202\Destinations\DestinationInputException $e) {
            self::assertStringContainsString('1 to 7', $e->getMessage());
        }
        self::assertSame('ready', $store->get(1, $all)['status']);
    }

    public function testAHostThatDoesNotResolveIsRetriedNotFailed(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-50');
        $out = $this->worker(resolver: static fn (string $host): array => [])->sendDue(10, 5, time() + 1);
        self::assertSame([1, 0], [$out['retrying'], $out['failed']]);
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '1'], [$row['status'], $row['attempts']]);
        self::assertStringContainsString('does not resolve', (string) $row['last_error']);
        self::assertSame([], $this->bodies);

        // Positive control: once the name resolves, the same row is sent.
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 3600)['sent']);
        self::assertCount(1, $this->bodies);
    }

    public function testACampaignFilterChangedAfterQueueingIsHonouredAtSend(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-23');
        $this->store()->update(1, $id, ['settings' => ['measurement_id' => 'G-ABC123', 'campaign_ids' => [31]]], time());
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['cancelled']);
        self::assertSame([], $this->bodies);
    }

    public function testACampaignFilterThatIsNotAListFailsRatherThanSendingEveryCampaign(): void
    {
        $id = $this->destination(['campaign_ids' => [30]]);
        $this->click(100);
        $this->record(100, 'ORD-25');
        self::fixture("UPDATE 202_destinations SET settings = JSON_SET(settings, '$.campaign_ids', '30') WHERE destination_id = $id");
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['failed']);
        self::assertSame([], $this->bodies);
        self::assertStringContainsString('campaign filter cannot be read', (string) self::deliveries($id)[0]['last_error']);

        // Positive control: the same filter as a list sends.
        self::fixture("UPDATE 202_destinations SET settings = JSON_SET(settings, '$.campaign_ids', JSON_ARRAY(30)) WHERE destination_id = $id");
        $this->click(101);
        $this->record(101, 'ORD-26');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['sent']);
    }

    public function testASwitchedOffAccountsConversionsWait(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-24');
        self::fixture('UPDATE 202_users SET user_active=0 WHERE user_id=1');
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, time() + 1)));
        self::assertSame('pending', self::deliveries($id)[0]['status']);
        self::fixture('UPDATE 202_users SET user_active=1 WHERE user_id=1');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['sent'], 'the positive control: active again, it is sent');
    }

    public function testAVisitorWhoMayBeInTheEeaOrUkIsWithheldUnlessConsentIsGranted(): void
    {
        // Privacy 'disabled' (the setUp default): the account tracks everyone in full.
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-30');
        self::assertSame(1, $this->worker(mayBeEuropean: true)->sendDue(10, 5, time() + 1)['withheld']);
        self::assertStringContainsString('EEA or UK', (string) self::deliveries($id)[0]['last_error']);

        // One signal granted is not enough.
        $this->store()->update(1, $id, ['settings' => ['measurement_id' => 'G-ABC123', 'consent' => ['ad_user_data' => 'granted']]], time());
        $this->click(101);
        $this->record(101, 'ORD-31');
        self::assertSame(1, $this->worker(mayBeEuropean: true)->sendDue(10, 5, time() + 1)['withheld']);

        // Positive control: both stated granted, and the visitor is sent.
        $this->store()->update(1, $id, ['settings' => ['measurement_id' => 'G-ABC123', 'consent' => ['ad_user_data' => 'granted', 'ad_personalization' => 'granted']]], time());
        $this->click(102);
        $this->record(102, 'ORD-32');
        self::assertSame(1, $this->worker(mayBeEuropean: true)->sendDue(10, 5, time() + 1)['sent']);
        self::assertSame(['ad_user_data' => 'GRANTED', 'ad_personalization' => 'GRANTED'], $this->bodies[0]['consent']);

        // The account's 'eu' setting still holds them back, consent or not.
        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='eu' WHERE user_id=1");
        $this->click(103);
        $this->record(103, 'ORD-33');
        self::assertSame(1, $this->worker(mayBeEuropean: true)->sendDue(10, 5, time() + 1)['withheld']);
        self::assertCount(1, $this->bodies);
    }

    public function testAGuessedClickIsWithheld(): void
    {
        $id = $this->destination();
        $this->click(100);
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => 100, 'payout' => '19.90', 'transaction_id' => 'ORD-40', 'source' => 'pixel',
        ], MatchMethod::SAME_IP);
        $this->click(101);
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => 101, 'payout' => '19.90', 'transaction_id' => 'ORD-41', 'source' => 'pixel',
        ], MatchMethod::COOKIE);
        $out = $this->worker()->sendDue(10, 5, time() + 1);
        self::assertSame([1, 1], [$out['withheld'], $out['sent']], 'the same-IP guess is withheld; the cookie match, the positive control, is sent');
        self::assertStringContainsString('not named by the conversion', (string) self::deliveries($id)[0]['last_error']);
    }

    public function testADestinationThatCannotBeReadSaysSoOnItsRows(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-42');
        self::fixture("UPDATE 202_destinations SET credentials='v1.bm90LWEtcmVhbC1jaXBoZXJ0ZXh0LWF0LWFsbC0xMjM0NTY=' WHERE destination_id = $id");
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['retrying']);
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '1'], [$row['status'], $row['attempts']]);
        self::assertStringContainsString('cannot be read', (string) $row['last_error']);
        self::assertSame([], $this->bodies);
    }

    public function testAFailedConversionIsSentAgainWithAllItsTriesOneOrAllOfThem(): void
    {
        $id = $this->destination();
        $mine = $this->destination(['measurement_id' => 'G-OTHER1'], 2);
        foreach ([100, 101, 102] as $click) {
            $this->click($click);
            $this->record($click, 'ORD-R' . $click);
        }
        $this->answers = array_fill(0, 3, ['status' => 400, 'body' => 'bad']);
        self::assertSame(3, $this->worker()->sendDue(10, 5, time() + 1)['failed']);
        $first = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id ORDER BY delivery_id LIMIT 1")->fetch_row()[0];

        self::assertNull($this->worker()->resend(2, $id, $first, '', time()), 'another account\'s destination');
        self::assertSame(0, $this->worker()->resend(2, $mine, $first, '', time())['queued'], 'another destination\'s delivery');
        self::assertSame(['failed', 'failed', 'failed'], array_column(self::deliveries($id), 'status'));

        self::assertSame(['queued' => 1, 'more' => false], $this->worker()->resend(1, $id, $first, '', time()));
        self::assertSame(0, $this->worker()->resend(1, $id, $first, '', time())['queued'], 'a row already waiting is not taken again');
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '0', null, null], [$row['status'], $row['attempts'], $row['last_status_code'], $row['last_error']]);
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 2)['sent'], 'the platform takes it now');

        self::assertSame(['queued' => 2, 'more' => false], $this->worker()->resend(1, $id, null, 'failed', time()));
        self::assertSame(2, $this->worker()->sendDue(10, 5, time() + 3)['sent']);
        self::assertSame(['sent', 'sent', 'sent'], array_column(self::deliveries($id), 'status'));
        self::assertSame(0, $this->worker()->resend(1, $id, null, 'failed', time())['queued']);

        $this->expectException(\Prosper202\Destinations\DestinationInputException::class);
        $this->worker()->resend(1, $id, null, 'sent', time());
    }

    public function testAWithheldConversionSentAgainIsCheckedAgain(): void
    {
        $id = $this->destination();
        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='all' WHERE user_id=1");
        $this->click(100);
        $this->record(100, 'ORD-W1');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['withheld']);

        self::assertSame(1, $this->worker()->resend(1, $id, null, 'withheld', time())['queued']);
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 2)['withheld'], 'still held back, so withheld again');
        self::assertSame([], $this->bodies);

        self::fixture("UPDATE 202_users_pref SET user_pref_privacy='disabled' WHERE user_id=1");
        self::assertSame(1, $this->worker()->resend(1, $id, null, 'withheld', time())['queued']);
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 3)['sent']);
    }

    public function testEarlierConversionsAreQueuedOnceForADestinationMadeAfterThem(): void
    {
        $this->click(100, campaign: 31);
        $this->click(101);
        $this->click(102, campaign: 31);
        $kept = $this->record(100, 'ORD-B1');
        $otherCampaign = $this->record(101, 'ORD-B2');
        $old = $this->record(102, 'ORD-B3');
        self::fixture('UPDATE 202_conversion_logs SET conv_time = ' . (time() - 5 * 86400) . " WHERE conv_id = $old");
        (new MysqlConversionRepository(new Connection(self::$db)))->record(1, ['click_id' => 100, 'transaction_id' => 'ORD-B1', 'reversal' => true, 'source' => 'api'], MatchMethod::CLICK_ID);
        $this->click(200, user: 2, campaign: 40);
        $this->record(200, 'ORD-B4', 2);

        $id = $this->destination(['campaign_ids' => [31]]);
        self::assertSame([], self::deliveries($id), 'made after them, it has none');
        self::assertNull($this->worker()->backfill(2, $id, 1, time()));
        self::assertSame(['queued' => 1, 'more' => false], $this->worker()->backfill(1, $id, 3, time()));
        self::assertSame([(string) $kept], array_column(self::deliveries($id), 'conv_id'), 'not the other campaign\'s, the older one, the reversal or another account\'s');
        self::assertSame(0, $this->worker()->backfill(1, $id, 3, time())['queued'], 'a conversion with a row is not queued twice');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['sent']);
        self::assertNotSame($otherCampaign, $kept);

        $this->expectException(\Prosper202\Destinations\DestinationInputException::class);
        $this->worker()->backfill(1, $id, 4, time());
    }

    public function testADestinationsLastWeekIsCountedByStatusAndReason(): void
    {
        $id = $this->destination();
        $quiet = $this->destination(['measurement_id' => 'G-QUIET1']);
        self::fixture("UPDATE 202_destinations SET enabled = 0 WHERE destination_id = $quiet");
        foreach ([100, 101, 102] as $click) {
            $this->click($click);
            $this->record($click, 'ORD-H' . $click);
        }
        $this->answers = [['status' => 400, 'body' => 'bad'], ['status' => 400, 'body' => 'bad']];
        $this->worker()->sendDue(10, 5, time() + 1);
        $this->worker()->testFire(1, $id, time());
        $old = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id AND status = 'failed' ORDER BY delivery_id LIMIT 1")->fetch_row()[0];
        self::fixture('UPDATE 202_destination_deliveries SET created_at = ' . (time() - 10 * 86400) . " WHERE delivery_id = $old");

        $health = $this->worker()->health(1, [$id, $quiet], time() - 7 * 86400, time());
        self::assertSame(['pending' => 0, 'sent' => 1, 'failed' => 1, 'withheld' => 0, 'cancelled' => 0], $health[$id]['counts'], 'the week\'s conversions, without the test or the older failure');
        self::assertSame(['failed' => 2, 'withheld' => 0], $health[$id]['resendable'], 'the older failure can still be sent again');
        self::assertCount(1, $health[$id]['reasons']);
        self::assertSame(['failed', 1], [$health[$id]['reasons'][0]['status'], $health[$id]['reasons'][0]['n']]);
        self::assertNotSame('', $health[$id]['reasons'][0]['reason']);
        self::assertSame([0, 0], [$health[$id]['waiting'], $health[$quiet]['waiting']]);
        self::assertSame(2, $this->worker()->resend(1, $id, null, 'failed', time())['queued']);
        self::assertSame(2, $this->worker()->health(1, [$id], time() - 7 * 86400, time())[$id]['waiting'], 'still to send, the one older than the week included');
        self::fixture("UPDATE 202_destination_deliveries SET status = 'failed' WHERE destination_id = $id AND status = 'pending'");
        self::assertSame(0, array_sum($health[$quiet]['counts']));
        self::assertSame(0, array_sum($this->worker()->health(2, [$id], 0, time())[$id]['counts']), 'another account reads none of them');

        self::assertSame(['failed', 'failed'], array_column($this->worker()->log(1, $id, 'failed', 1, 0), 'status'), 'one more than the page, so the page knows there is another');
        self::assertCount(2, $this->worker()->log(1, $id, 'failed', 5, 0));
        self::assertCount(4, $this->worker()->log(1, $id, null, 50, 0), 'three conversions and the test');
        self::assertSame([], $this->worker()->log(2, $id, null, 50, 0));
    }

    public function testAnEditThatLeavesTheSecretBlankKeepsIt(): void
    {
        $id = $this->destination();
        $kept = $this->store()->update(1, $id, ['name' => 'Renamed', 'settings' => ['measurement_id' => 'G-NEW123'], 'credentials' => []], time(), true);
        self::assertSame(['Renamed', 'G-NEW123'], [$kept['name'], $kept['settings']['measurement_id']]);
        self::assertSame(self::SECRET, $this->store()->forSending(1, $id)['credentials']['api_secret']);

        $this->store()->update(1, $id, ['credentials' => ['api_secret' => 'A-new-secret-9']], time(), true);
        self::assertSame('A-new-secret-9', $this->store()->forSending(1, $id)['credentials']['api_secret']);

        // Positive control: without the flag, credentials are replaced whole and an empty set is refused.
        $this->expectException(\Prosper202\Destinations\DestinationInputException::class);
        $this->store()->update(1, $id, ['credentials' => []], time());
    }

    public function testTheApiSendsAgainAndBackfillsWhatTheBodyNames(): void
    {
        $id = $this->destination();
        $this->click(100);
        $this->record(100, 'ORD-A1');
        $this->answers = [['status' => 400, 'body' => 'bad']];
        $this->worker()->sendDue(10, 5, time() + 1);
        $delivery = (int) self::$db->query("SELECT delivery_id FROM 202_destination_deliveries WHERE destination_id = $id")->fetch_row()[0];
        $api = new \Api\V3\Controllers\DestinationsController(self::$db, 1);

        $refused = static function (callable $call): string {
            try {
                $call();
            } catch (\Api\V3\Exception\ValidationException | \Api\V3\Exception\NotFoundException $e) {
                return $e::class;
            }

            return 'accepted';
        };
        $validation = \Api\V3\Exception\ValidationException::class;
        self::assertSame($validation, $refused(fn () => $api->resend($id, [])), 'neither named');
        self::assertSame($validation, $refused(fn () => $api->resend($id, ['delivery_id' => $delivery, 'status' => 'failed'])), 'both named');
        self::assertSame($validation, $refused(fn () => $api->resend($id, ['delivery_id' => (string) $delivery])), 'an id that is not a number is not read as one');
        self::assertSame($validation, $refused(fn () => $api->resend($id, ['status' => 'sent'])));
        self::assertSame($validation, $refused(fn () => $api->resend($id, ['delivery_id' => $delivery + 999])), 'no such delivery');
        self::assertSame($validation, $refused(fn () => $api->resend($id, ['status' => 'failed', 'force' => true])), 'an unknown key');
        self::assertSame(\Api\V3\Exception\NotFoundException::class, $refused(fn () => (new \Api\V3\Controllers\DestinationsController(self::$db, 2))->resend($id, ['status' => 'failed'])));
        self::assertSame('failed', self::deliveries($id)[0]['status'], 'nothing refused changed it');

        self::assertSame(['data' => ['queued' => 1, 'more' => false]], $api->resend($id, ['delivery_id' => $delivery]));
        self::assertSame(['pending', '0'], [self::deliveries($id)[0]['status'], self::deliveries($id)[0]['attempts']]);
        self::assertSame(['data' => ['queued' => 0, 'more' => false]], $api->resend($id, ['status' => 'failed']), 'none is failed now');

        $later = $this->destination(['measurement_id' => 'G-LATER12']);
        self::assertSame($validation, $refused(fn () => $api->backfill($later, [])));
        self::assertSame($validation, $refused(fn () => $api->backfill($later, ['days' => '3'])));
        self::assertSame($validation, $refused(fn () => $api->backfill($later, ['days' => 30])), 'GA4 takes 3 days at most');
        self::assertSame(\Api\V3\Exception\NotFoundException::class, $refused(fn () => (new \Api\V3\Controllers\DestinationsController(self::$db, 2))->backfill($later, ['days' => 1])));
        self::assertSame(['data' => ['queued' => 1, 'more' => false]], $api->backfill($later, ['days' => 3]));
        self::assertCount(1, self::deliveries($later));
    }
}

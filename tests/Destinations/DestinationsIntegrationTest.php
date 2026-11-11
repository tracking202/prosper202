<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
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
        foreach (['202_destinations', '202_destination_deliveries', '202_customers', '202_clicks_advance', '202_ips', '202_ips_v6'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture('DELETE FROM 202_users WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users SET user_id=1, user_name='store-owner', user_email='owner@example.com', user_active=1, user_deleted=0");
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='saas-owner', user_email='saas@example.com', user_active=1, user_deleted=0");
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
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
        $this->record(103, 'ORD-41');
        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 2)['sent']);
        self::assertSame(['ORD-40', 'ORD-40'], array_map(static fn (array $b): string => $b['events'][0]['params']['transaction_id'], $this->bodies), 'GA4 counts the sale once');

        // Positive control: a campaign that adds every conversion sends each under its own id.
        $this->click(104);
        $this->record(104, 'ORD-42');
        $this->record(104, 'ORD-43');
        $this->worker()->sendDue(10, 5, time() + 3);
        self::assertSame(['ORD-42', 'ORD-43'], array_map(static fn (array $b): string => $b['events'][0]['params']['transaction_id'], array_slice($this->bodies, 2)));
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

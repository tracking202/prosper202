<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationKeyMissing;
use Prosper202\Destinations\DestinationQueue;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsOAuth;
use Prosper202\Destinations\GoogleAdsOAuthCallback;
use Tests\Apps\Android\AndroidDatabase;

/**
 * A Google Ads destination against a real server: created unconnected, its
 * conversions wait (uncounted) until the OAuth grant is stored, then go out
 * with the click's gclid in the account's zone; a click under 6 hours old
 * waits for Google; a conversion Google cannot match is withheld. The
 * transport is stubbed (no network).
 *
 * @group integration
 */
final class GoogleAdsIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const CREDENTIALS = ['developer_token' => 'devTOKEN_abc123XYZ', 'oauth_client_id' => '1234-abcd.apps.googleusercontent.com', 'oauth_client_secret' => 'GOCSPX-clientSecret99'];
    private const REFRESH = '1//0refreshTokenValue-xyz';

    /** @var list<array{url: string, body: string}> */
    private array $requests = [];
    /** @var list<array{status: int, body: string}> the upload endpoint's answers, in order (then 200) */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_destinations', '202_destination_deliveries', '202_customers', '202_clicks_advance', '202_ips', '202_google'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture('DELETE FROM 202_users WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users SET user_id=1, user_name='store-owner', user_email='owner@example.com', user_active=1, user_deleted=0, user_timezone='America/New_York'");
        self::fixture("INSERT INTO 202_users SET user_id=2, user_name='saas-owner', user_email='saas@example.com', user_active=1, user_deleted=0");
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_pref_privacy='disabled', user_account_currency='USD'");
        self::fixture("INSERT INTO 202_users_pref SET user_id=2, user_pref_privacy='disabled', user_account_currency='USD'");
        $this->campaign(31, null);
        $this->campaign(40, null, user: 2);
        $this->requests = [];
        $this->answers = [];
    }

    private function store(): DestinationStore
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $url = (string) $options[CURLOPT_URL];
            $this->requests[] = ['url' => $url, 'body' => (string) $options[CURLOPT_POSTFIELDS]];

            $answer = str_contains($url, 'oauth2') ? null : array_shift($this->answers);

            return ['status' => $answer['status'] ?? 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null,
                'body' => $answer['body'] ?? (str_contains($url, 'oauth2') ? '{"access_token":"ya29.a","expires_in":3599}' : '{"results":[{}]}')];
        };

        return new DestinationStore(new Connection(self::$db), [GoogleAdsDestination::TYPE => new GoogleAdsDestination(new WebhookSender($guard, $transport))]);
    }

    private function worker(): DestinationQueue
    {
        return new DestinationQueue(new Connection(self::$db), $this->store(), static fn (string $ip): bool => false);
    }

    /** @param array<string, mixed> $settings */
    private function destination(array $settings = [], int $user = 1): int
    {
        return (int) $this->store()->create($user, GoogleAdsDestination::TYPE, 'Search', $settings + ['customer_id' => '123-456-7890', 'conversion_action' => '987654'], self::CREDENTIALS, time())['destination_id'];
    }

    private function adsClick(int $id, int $time, string $gclid = 'Cj0KCQ-test'): void
    {
        $this->click($id, time: $time);
        if ($gclid !== '') {
            self::fixture("INSERT INTO 202_google SET click_id=$id, gclid='$gclid', utm_source_id=0, utm_medium_id=0, utm_campaign_id=0, utm_term_id=0, utm_content_id=0");
        }
    }

    private function record(int $click, string $tx): int
    {
        return (int) (new MysqlConversionRepository(new Connection(self::$db)))->record(1, [
            'click_id' => $click, 'payout' => '19.90', 'transaction_id' => $tx, 'source' => 'api',
        ], MatchMethod::CLICK_ID)['convId'];
    }

    /** @return list<array<string, mixed>> */
    private static function deliveries(int $destinationId): array
    {
        return self::$db->query("SELECT conv_id, status, attempts, next_attempt_at, last_error FROM 202_destination_deliveries
            WHERE destination_id = $destinationId AND kind = 'conversion' ORDER BY delivery_id")->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<array<string, mixed>> the uploads' conversions */
    private function uploads(): array
    {
        $out = [];
        foreach ($this->requests as $r) {
            if (str_contains($r['url'], 'uploadClickConversions')) {
                $out[] = json_decode($r['body'], true)['conversions'][0];
            }
        }

        return $out;
    }

    public function testAnUnconnectedDestinationsConversionsWaitThenGoOutOnceItIsConnected(): void
    {
        $id = $this->destination();
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status']);
        $this->adsClick(100, time() - 7 * 3600);
        $this->adsClick(101, time() - 7 * 3600);
        $conv = $this->record(100, 'ORD-1');
        $this->record(101, 'ORD-2');
        $now = time() + 1;

        $out = $this->worker()->sendDue(10, 5, $now);
        self::assertSame(1, $out['retrying'], 'the first row learns the destination waits; the second is not read');
        self::assertSame([], $this->requests, 'nothing is sent without the grant');
        foreach (self::deliveries($id) as $row) {
            self::assertSame(['pending', '0', (string) ($now + GoogleAdsDestination::AUTH_WAIT)], [$row['status'], $row['attempts'], $row['next_attempt_at']], 'waiting is not a try, and every row waits');
        }
        self::assertStringContainsString('not connected', (string) self::deliveries($id)[0]['last_error']);
        self::assertStringContainsString('not connected', (string) ($this->store()->get(1, $id)['waiting']['reason'] ?? ''));
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 60)), 'not read again before it is connected');

        self::assertTrue($this->store()->authorise(1, $id, self::REFRESH, $now + 120));
        self::assertSame('ready', $this->store()->get(1, $id)['status']);
        self::assertStringNotContainsString(self::REFRESH, (string) json_encode($this->store()->list(1)));
        self::assertFalse($this->store()->authorise(2, $id, self::REFRESH, $now), 'another account cannot connect it');

        $out = $this->worker()->sendDue(10, 5, $now + 120);
        self::assertSame(2, $out['sent'], 'connecting made them due at once');
        $first = $this->uploads()[0];
        self::assertSame('Cj0KCQ-test', $first['gclid']);
        self::assertSame('ORD-1', $first['orderId']);
        self::assertSame('customers/1234567890/conversionActions/987654', $first['conversionAction']);
        $convTime = (int) self::$db->query("SELECT conv_time FROM 202_conversion_logs WHERE conv_id = $conv")->fetch_row()[0];
        self::assertSame((new \DateTimeImmutable('@' . $convTime))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i:sP'), $first['conversionDateTime']);
        self::assertSame(['sent', '1'], [self::deliveries($id)[0]['status'], self::deliveries($id)[0]['attempts']]);
        $tokenRequests = array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], 'oauth2'));
        self::assertCount(1, $tokenRequests, 'one access token for the run');
    }

    public function testADestinationMadeWithoutItsAccountWaitsUntilOneIsPickedAndLosesNothing(): void
    {
        require_once dirname(__DIR__, 2) . '/tracking202/setup/_includes/google_ads_picker.php';
        // Made as Setup › Destinations makes it: a name and the three credentials.
        $id = (int) $this->store()->create(1, GoogleAdsDestination::TYPE, 'Search', [], self::CREDENTIALS, time())['destination_id'];
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status']);
        $now = time() + 1;
        self::assertTrue($this->store()->authorise(1, $id, self::REFRESH, $now));
        self::assertSame('needs_account', $this->store()->get(1, $id)['status'], 'connected, with nothing picked yet');

        $this->adsClick(100, time() - 7 * 3600);
        $this->record(100, 'ORD-1');
        $out = $this->worker()->sendDue(10, 5, $now);
        self::assertSame(1, $out['retrying']);
        self::assertSame([], $this->requests, 'nothing is asked of Google without an account');
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '0'], [$row['status'], $row['attempts']], 'waiting is not a try');
        self::assertStringContainsString('pick the Google Ads account and conversion action', (string) $this->store()->get(1, $id)['waiting']['reason']);

        // Another account cannot pick for it, and a value that is no account is refused.
        self::assertNull(p202_gads_pick($this->store(), 2, $id, ['gads_account' => '1234567890'], $now));
        foreach (['', '12abc', '1234567890:', '1234567890:12', ['1234567890']] as $bad) {
            try {
                p202_gads_pick($this->store(), 1, $id, ['gads_account' => $bad, 'gads_conversion_action' => '987654'], $now);
                self::fail('picked ' . json_encode($bad));
            } catch (DestinationInputException $e) {
                self::assertSame('settings.customer_id', $e->field);
            }
        }
        try {
            p202_gads_pick($this->store(), 1, $id, ['gads_account' => '1234567890', 'gads_conversion_action' => 'Purchase'], $now);
            self::fail('picked a conversion action that is no ID');
        } catch (DestinationInputException $e) {
            self::assertSame('settings.conversion_action', $e->field);
        }
        self::assertSame('needs_account', $this->store()->get(1, $id)['status']);

        // The account alone, reached through a manager: still nothing to send to.
        self::assertFalse(p202_gads_pick($this->store(), 1, $id, ['gads_account' => '1234567890:1112223333'], $now));
        self::assertSame('needs_account', $this->store()->get(1, $id)['status']);
        // Then its action: ready, and what waited is due at once.
        self::assertTrue(p202_gads_pick($this->store(), 1, $id, ['gads_account' => '1234567890:1112223333', 'gads_conversion_action' => '987654'], $now + 60));
        $saved = $this->store()->get(1, $id);
        self::assertSame('ready', $saved['status']);
        self::assertSame(
            ['customer_id' => '1234567890', 'login_customer_id' => '1112223333', 'conversion_action' => '987654', 'campaign_ids' => [], 'send_user_data' => false, 'consent' => []],
            (array) $saved['settings']
        );
        $out = $this->worker()->sendDue(10, 5, $now + 60);
        self::assertSame(1, $out['sent']);
        self::assertSame('customers/1234567890/conversionActions/987654', $this->uploads()[0]['conversionAction']);

        // Another account picked: its conversion actions are not the old account's, so they go;
        // one reached directly drops the manager.
        $this->store()->update(1, $id, ['settings' => ['campaign_conversion_actions' => [31 => '555']] + (array) $saved['settings']], $now + 61);
        self::assertFalse(p202_gads_pick($this->store(), 1, $id, ['gads_account' => '5556667777'], $now + 62));
        $moved = $this->store()->get(1, $id);
        self::assertSame(['customer_id' => '5556667777', 'campaign_ids' => [], 'send_user_data' => false, 'consent' => []], (array) $moved['settings']);
        self::assertSame('needs_account', $moved['status']);
        // The same account picked again keeps what it has.
        p202_gads_pick($this->store(), 1, $id, ['gads_account' => '5556667777', 'gads_conversion_action' => 'customers/1112223333/conversionActions/55'], $now + 63);
        self::assertTrue(p202_gads_pick($this->store(), 1, $id, ['gads_account' => '5556667777'], $now + 64));
        self::assertSame('customers/1112223333/conversionActions/55', ((array) $this->store()->get(1, $id)['settings'])['conversion_action']);
    }

    public function testAClickUnderSixHoursOldWaitsForGoogleUncounted(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $clickTime = time() - 3600;
        $this->adsClick(100, $clickTime);
        $this->record(100, 'ORD-3');

        self::assertSame(1, $this->worker()->sendDue(10, 5, time() + 1)['retrying']);
        $row = self::deliveries($id)[0];
        self::assertSame(['pending', '0', (string) ($clickTime + GoogleAdsDestination::CLICK_HOLD)], [$row['status'], $row['attempts'], $row['next_attempt_at']]);
        self::assertSame([], $this->requests);
        self::assertNull($this->store()->get(1, $id)['waiting'], 'a click too recent waits for Google, not for the operator');

        // The positive control: once Google takes it, it is sent.
        self::assertSame(1, $this->worker()->sendDue(10, 5, $clickTime + GoogleAdsDestination::CLICK_HOLD)['sent']);
        self::assertCount(1, $this->uploads());
    }

    public function testAConversionGoogleCannotMatchIsWithheld(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $this->adsClick(100, time() - 7 * 3600, '');
        $this->adsClick(101, time() - 7 * 3600);
        $this->record(100, 'ORD-4');
        $this->record(101, 'ORD-5');
        $out = $this->worker()->sendDue(10, 5, time() + 1);
        self::assertSame([1, 1], [$out['withheld'], $out['sent']], 'no click id and no hashed data: withheld; the gclid click, the positive control, is sent');
        self::assertStringContainsString('nothing Google can match', (string) self::deliveries($id)[0]['last_error']);
    }

    public function testNewCredentialsKeepTheGrantOnlyForTheSameClient(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $settings = ['customer_id' => '1234567890', 'conversion_action' => '987654'];
        $this->store()->update(1, $id, ['settings' => $settings, 'credentials' => ['developer_token' => 'newDevToken12345'] + self::CREDENTIALS], time());
        self::assertSame('ready', $this->store()->get(1, $id)['status']);
        self::assertSame(self::REFRESH, $this->store()->forSending(1, $id)['credentials']['refresh_token']);

        $this->store()->update(1, $id, ['settings' => $settings, 'credentials' => ['oauth_client_id' => '999-other.apps.googleusercontent.com'] + self::CREDENTIALS], time());
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status'], 'another client needs connecting again');

        // Unreadable stored credentials are listed as such, and typing them again mends them.
        self::fixture("UPDATE 202_destinations SET credentials='v1.bm90LWEtcmVhbC1jaXBoZXJ0ZXh0LWF0LWFsbC0xMjM0NTY=' WHERE destination_id = $id");
        self::assertSame('credentials_unreadable', $this->store()->list(1)[0]['status']);
        $this->store()->update(1, $id, ['settings' => $settings, 'credentials' => self::CREDENTIALS], time());
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status']);
    }

    public function testPerCampaignActionsNameOnlyTheAccountsCampaigns(): void
    {
        $id = $this->destination(['campaign_conversion_actions' => [31 => '111']]);
        self::assertSame([31 => '111'], (array) $this->store()->get(1, $id)['settings']['campaign_conversion_actions'], 'the positive control');
        try {
            $this->destination(['campaign_conversion_actions' => [40 => '111']]);
            self::fail('another account\'s campaign is refused');
        } catch (DestinationInputException $e) {
            self::assertSame('settings.campaign_conversion_actions', $e->field);
        }
    }

    public function testATestFireWithoutTheGrantIsLoggedAsFailed(): void
    {
        $id = $this->destination();
        $result = $this->worker()->testFire(1, $id, time());
        self::assertFalse($result['valid']);
        self::assertStringContainsString('not connected', (string) $result['reason']);
        self::assertSame([], $this->requests);
        self::assertSame('failed', self::$db->query("SELECT status FROM 202_destination_deliveries WHERE destination_id = $id AND kind = 'test'")->fetch_row()[0]);
    }

    public function testATokenGoogleHasNotApprovedYetLosesNoConversion(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $this->adsClick(100, time() - 7 * 3600);
        $this->adsClick(101, time() - 7 * 3600);
        $this->record(100, 'ORD-6');
        $this->record(101, 'ORD-7');
        $this->answers = [['status' => 403, 'body' => '{"error":{"code":403,"details":[{"errors":[{"errorCode":{"authorizationError":"DEVELOPER_TOKEN_NOT_APPROVED"},"message":"The developer token is not approved."}]}]}}']];
        $now = time() + 1;

        $out = $this->worker()->sendDue(10, 5, $now);
        self::assertSame([0, 1, 0], [$out['sent'], $out['retrying'], $out['failed']], 'the first row learns the destination waits; the second is not tried');
        self::assertCount(1, $this->uploads());
        foreach (self::deliveries($id) as $row) {
            self::assertSame(['pending', '0', (string) ($now + GoogleAdsDestination::AUTH_WAIT)], [$row['status'], $row['attempts'], $row['next_attempt_at']], 'waiting is not a try, and every row waits');
        }
        self::assertStringContainsString('DEVELOPER_TOKEN_NOT_APPROVED', (string) self::deliveries($id)[0]['last_error'], 'the log says why');
        $waiting = $this->store()->get(1, $id)['waiting'];
        self::assertIsArray($waiting, 'the destination itself says its conversions wait for the operator');
        self::assertSame([403, $now + GoogleAdsDestination::AUTH_WAIT], [$waiting['status_code'], $waiting['until']]);
        self::assertStringStartsWith('fix the developer token', $waiting['reason']);
        self::assertStringContainsString('DEVELOPER_TOKEN_NOT_APPROVED', $waiting['reason']);
        self::assertSame($waiting, $this->store()->list(1)[0]['waiting']);
        self::assertSame(0, array_sum($this->worker()->sendDue(10, 5, $now + 60)), 'not tried again within the hour');

        // A new token (or customer ID) makes them due at once; Google now takes them.
        $this->store()->update(1, $id, ['settings' => ['customer_id' => '1234567890', 'conversion_action' => '987654'], 'credentials' => ['developer_token' => 'approvedToken12345'] + self::CREDENTIALS], $now + 120);
        self::assertSame(2, $this->worker()->sendDue(10, 5, $now + 120)['sent']);
        self::assertSame(['sent', 'sent'], array_column(self::deliveries($id), 'status'));
        self::assertNull($this->store()->get(1, $id)['waiting'], 'sent: nothing waits for the operator any more');
    }

    public function testAMissingConversionActionHoldsOnlyItsRow(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $this->adsClick(100, time() - 7 * 3600);
        $this->adsClick(101, time() - 7 * 3600);
        $this->record(100, 'ORD-8');
        $this->record(101, 'ORD-9');
        $this->answers = [['status' => 200, 'body' => '{"partialFailureError":{"code":3,"message":"m","details":[{"errors":[{"errorCode":{"conversionUploadError":"NO_CONVERSION_ACTION_FOUND"},"message":"The conversion action cannot be found."}]}]},"results":[{}]}']];
        $now = time() + 1;

        $out = $this->worker()->sendDue(10, 5, $now);
        self::assertSame([1, 1, 0], [$out['sent'], $out['retrying'], $out['failed']], 'another campaign\'s action still sends');
        $first = self::deliveries($id)[0];
        self::assertSame(['pending', '0', (string) ($now + GoogleAdsDestination::AUTH_WAIT)], [$first['status'], $first['attempts'], $first['next_attempt_at']]);
        self::assertStringContainsString('NO_CONVERSION_ACTION_FOUND', (string) ($this->store()->get(1, $id)['waiting']['reason'] ?? ''), 'the destination says which action to fix');
        self::assertSame(1, $this->worker()->sendDue(10, 5, $now + GoogleAdsDestination::AUTH_WAIT)['sent'], 'once the action is there, it is sent');
        self::assertNull($this->store()->get(1, $id)['waiting']);
    }

    public function testAKeyMintedInATransactionThatRolledBackIsNotKept(): void
    {
        self::$db->query("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . DestinationStore::SECRET_NAME . "'");
        $store = $this->store();
        $key = new \ReflectionMethod($store, 'key');
        self::$db->begin_transaction();
        $key->invoke($store, true);
        self::$db->rollback();
        $refused = null;
        try {
            $key->invoke($store, false);
        } catch (DestinationKeyMissing $e) {
            $refused = $e->getMessage();
        }
        self::assertNotNull($refused, 'the minted key was rolled back, so there is none');
        self::assertStringContainsString('missing', $refused);
        // The positive control: a key that was read is kept for the store's life.
        $minted = $key->invoke($store, true);
        self::assertSame($minted, $key->invoke($store, false));
        self::$db->query("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . DestinationStore::SECRET_NAME . "'");
        self::assertSame($minted, $key->invoke($store, false));
    }

    public function testASecondMintInTheSameRolledBackTransactionIsNotKeptEither(): void
    {
        // update() mints once to read the stored credentials, then writeCredentials() asks again: the second
        // call's INSERT changes no row and reads the first call's uncommitted key back.
        self::$db->query("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . DestinationStore::SECRET_NAME . "'");
        $store = $this->store();
        $key = new \ReflectionMethod($store, 'key');
        self::$db->begin_transaction();
        $first = $key->invoke($store, true);
        self::assertSame($first, $key->invoke($store, true), 'the same transaction reads its own key back');
        self::$db->rollback();
        $this->expectException(DestinationKeyMissing::class);
        $key->invoke($store, false);
    }

    public function testAMissingKeyReadsAsUnreadableButADatabaseErrorIsNot(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        self::assertSame('ready', $this->store()->get(1, $id)['status']);
        $this->adsClick(130, time() - 7 * 3600);
        $this->record(130, 'ORD-130');
        $pending = self::deliveries($id);
        self::assertSame(['pending', '0'], [$pending[0]['status'], $pending[0]['attempts']]);

        self::$db->query('RENAME TABLE 202_deployment_secrets TO 202_deployment_secrets_away');
        try {
            $listed = null;
            try {
                $listed = $this->store()->get(1, $id)['status'];
            } catch (\Throwable $e) {
                self::assertNotInstanceOf(DestinationKeyMissing::class, $e);
            }
            self::assertNull($listed, 'a failed read of the key is an error, not credentials_unreadable (got ' . var_export($listed, true) . ')');
            $sent = null;
            try {
                $sent = $this->worker()->sendDue(10, 15, time());
            } catch (\Throwable $e) {
                self::assertNotInstanceOf(DestinationKeyMissing::class, $e);
            }
            self::assertNull($sent, 'the run stops on a database error');
            self::assertSame(['pending', '0'], [self::deliveries($id)[0]['status'], self::deliveries($id)[0]['attempts']], 'and the row keeps its tries');
        } finally {
            self::$db->query('RENAME TABLE 202_deployment_secrets_away TO 202_deployment_secrets');
        }

        // The positive control: a key that is really not there reads as unreadable credentials.
        self::$db->query("DELETE FROM 202_deployment_secrets WHERE secret_name = '" . DestinationStore::SECRET_NAME . "'");
        self::assertSame('credentials_unreadable', $this->store()->get(1, $id)['status']);
    }

    public function testAConversionGoogleAlreadyHeldIsFlaggedInTheLog(): void
    {
        $id = $this->destination();
        $this->store()->authorise(1, $id, self::REFRESH, time());
        $this->adsClick(140, time() - 7 * 3600);
        $this->record(140, 'ORD-140');
        $this->answers = [['status' => 200, 'body' => '{"partialFailureError":{"code":3,"message":"m","details":[{"errors":[{"errorCode":{"conversionUploadError":"ORDER_ID_ALREADY_IN_USE"},"message":"The order id is already in use."}]}]},"results":[{}]}']];
        $this->worker()->sendDue(10, 15, time());
        $row = self::$db->query("SELECT status, last_error, summary FROM 202_destination_deliveries WHERE destination_id = $id AND kind = 'conversion'")->fetch_assoc();
        self::assertSame('sent', $row['status']);
        self::assertStringStartsWith(GoogleAdsDestination::ALREADY_IN_GOOGLE_ADS, (string) $row['last_error']);
        self::assertTrue(json_decode((string) $row['summary'], true)['already_in_google_ads'] ?? false);
    }

    private const SERVER = ['HTTP_HOST' => 'track.example', 'HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_URI' => '/tracking202/setup/destination-oauth.php'];

    /** @var list<array{url: string, body: string}> the token endpoint's requests */
    private array $tokenRequests = [];

    /** The callback, with Google's token endpoint answering $answer. */
    private function connectCallback(array $answer = ['status' => 200, 'body' => '{"access_token":"ya29.x","refresh_token":"1//0newGrant-abc","expires_in":3599}']): GoogleAdsOAuthCallback
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options) use ($answer): array {
            $this->tokenRequests[] = ['url' => (string) $options[CURLOPT_URL], 'body' => (string) $options[CURLOPT_POSTFIELDS]];

            return $answer + ['error' => null, 'primary_ip' => '203.0.113.10', 'location' => null];
        };

        return new GoogleAdsOAuthCallback($this->store(), new GoogleAdsOAuth(new WebhookSender($guard, $transport)));
    }

    public function testTheCallbackStoresTheGrantForTheDestinationItsStateNames(): void
    {
        $id = $this->destination();
        $now = time();
        $this->adsClick(150, $now - 7 * 3600);
        $this->record(150, 'ORD-150');
        $this->worker()->sendDue(10, 15, $now);
        self::assertSame('pending', self::deliveries($id)[0]['status'], 'not connected: the conversion waits');
        self::assertGreaterThan($now, (int) self::deliveries($id)[0]['next_attempt_at']);

        $state = GoogleAdsOAuth::state(1, $id, $now, $this->store()->stateKey());
        $outcome = $this->connectCallback()->handle(1, ['state' => $state, 'code' => '4/0consentCode'], self::SERVER, $now);

        self::assertSame(['ok' => true, 'text' => 'Google Ads is connected.', 'destination_id' => $id], $outcome);
        self::assertSame('ready', $this->store()->get(1, $id)['status']);
        self::assertSame('1//0newGrant-abc', $this->store()->forSending(1, $id)['credentials']['refresh_token']);
        self::assertSame((string) $now, self::deliveries($id)[0]['next_attempt_at'], 'connecting makes the waiting conversion due now');
        self::assertCount(1, $this->tokenRequests);
        self::assertSame(GoogleAdsOAuth::TOKEN_URL, $this->tokenRequests[0]['url']);
        parse_str($this->tokenRequests[0]['body'], $form);
        self::assertSame([
            'grant_type' => 'authorization_code', 'code' => '4/0consentCode', 'client_id' => self::CREDENTIALS['oauth_client_id'],
            'client_secret' => self::CREDENTIALS['oauth_client_secret'], 'redirect_uri' => GoogleAdsOAuth::redirectUri(self::SERVER),
        ], $form, 'the code is traded with the destination\'s own client, for the redirect URI Google was given');
    }

    public function testTheCallbackRefusesAStateItDidNotSignForThisUserInTime(): void
    {
        $id = $this->destination();
        $theirs = $this->destination(user: 2);
        $now = time();
        $key = $this->store()->stateKey();
        $signed = GoogleAdsOAuth::state(1, $id, $now, $key);
        $cases = [
            // [user, query, now, the sentence's words, the destination it names]
            'forged' => [1, ['state' => substr($signed, 0, -4) . 'abcd', 'code' => 'c'], $now, 'did not sign', 0],
            'not ours' => [1, ['state' => 'v1.1.' . $id . '.' . ($now + 600) . '.00.x', 'code' => 'c'], $now, 'did not sign', 0],
            'garbage' => [1, ['state' => 'nonsense', 'code' => 'c'], $now, 'did not make', 0],
            'another user' => [2, ['state' => $signed, 'code' => 'c'], $now, 'another user', 0],
            'expired' => [1, ['state' => $signed, 'code' => 'c'], $now + GoogleAdsOAuth::STATE_SECONDS + 1, 'expired', 0],
            'consent refused' => [1, ['state' => $signed, 'error' => 'access_denied'], $now, 'not allowed', $id],
            'no code' => [1, ['state' => $signed], $now, 'without a code', $id],
            'another user\'s destination' => [1, ['state' => GoogleAdsOAuth::state(1, $theirs, $now, $key), 'code' => 'c'], $now, 'not one of yours', 0],
        ];
        foreach ($cases as $name => [$user, $query, $at, $words, $named]) {
            $outcome = $this->connectCallback()->handle($user, $query, self::SERVER, $at);
            self::assertFalse($outcome['ok'], $name);
            self::assertStringContainsString($words, $outcome['text'], $name);
            self::assertSame($named, $outcome['destination_id'], $name);
        }
        self::assertSame([], $this->tokenRequests, 'no code is traded for a refused state');
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status']);
        self::assertSame('needs_authorisation', $this->store()->get(2, $theirs)['status']);
    }

    public function testTheCallbackSaysWhyGoogleGaveNoGrant(): void
    {
        $id = $this->destination();
        $state = GoogleAdsOAuth::state(1, $id, time(), $this->store()->stateKey());
        $none = $this->connectCallback(['status' => 200, 'body' => '{"access_token":"ya29.x","expires_in":3599}'])->handle(1, ['state' => $state, 'code' => 'c'], self::SERVER, time());
        self::assertSame([false, $id], [$none['ok'], $none['destination_id']]);
        self::assertStringContainsString('no refresh token', $none['text']);

        $bad = $this->connectCallback(['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Bad Request ' . self::CREDENTIALS['oauth_client_secret'] . '"}'])
            ->handle(1, ['state' => $state, 'code' => 'c'], self::SERVER, time());
        self::assertFalse($bad['ok']);
        self::assertStringContainsString('invalid_grant', $bad['text']);
        self::assertStringNotContainsString(self::CREDENTIALS['oauth_client_secret'], $bad['text'], 'the client secret is never shown back');
        self::assertSame('needs_authorisation', $this->store()->get(1, $id)['status']);
    }

    public function testOauthStartGivesAConsentLinkWhoseStateTheCallbackAccepts(): void
    {
        $id = $this->destination();
        $theirs = $this->destination(user: 2);
        $controller = new \Api\V3\Controllers\DestinationsController(self::$db, 1);
        $started = $controller->oauthStart($id, self::SERVER)['data'];

        self::assertStringStartsWith(GoogleAdsOAuth::AUTH_URL . '?', $started['authorisation_url']);
        parse_str((string) parse_url($started['authorisation_url'], PHP_URL_QUERY), $query);
        self::assertSame(self::CREDENTIALS['oauth_client_id'], $query['client_id']);
        self::assertSame(GoogleAdsOAuth::redirectUri(self::SERVER), $query['redirect_uri']);
        self::assertSame($query['redirect_uri'], $started['redirect_uri']);
        self::assertSame(['offline', 'consent', GoogleAdsOAuth::SCOPE], [$query['access_type'], $query['prompt'], $query['scope']]);
        self::assertSame($id, GoogleAdsOAuth::verifyState((string) $query['state'], 1, time(), $this->store()->stateKey()), 'the state names this user and destination');
        self::assertTrue($this->connectCallback()->handle(1, ['state' => $query['state'], 'code' => 'c'], self::SERVER, time())['ok']);

        try {
            $controller->oauthStart($theirs, self::SERVER);
            self::fail('another account\'s destination was started');
        } catch (\Api\V3\Exception\NotFoundException) {
        }
    }
    /** A Google answering with two accounts under a manager and three conversion actions, in its REST shapes. */
    private function google(): \Prosper202\Destinations\GoogleAdsAccounts
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $search = static fn (string $key, array $rows): string => (string) json_encode(['results' => array_map(static fn (array $r): array => [$key => $r], $rows)]);
        $transport = function (array $options) use ($search): array {
            $url = (string) $options[CURLOPT_URL];
            $this->requests[] = ['url' => $url, 'body' => (string) ($options[CURLOPT_POSTFIELDS] ?? '')];
            $query = (string) ($options[CURLOPT_POSTFIELDS] ?? '');
            $body = match (true) {
                str_contains($url, 'oauth2') => '{"access_token":"ya29.a","expires_in":3599}',
                str_contains($url, 'listAccessibleCustomers') => '{"resourceNames":["customers/1112223333"]}',
                str_contains($query, 'customer_client') => $search('customerClient', [
                    ['id' => '1112223333', 'descriptiveName' => 'Northwind Agency', 'manager' => true, 'level' => '0'],
                    ['id' => '4445556666', 'descriptiveName' => 'Online Store', 'currencyCode' => 'USD', 'manager' => false, 'level' => '1'],
                    ['id' => '5556667777', 'descriptiveName' => 'Demo Store', 'currencyCode' => 'GBP', 'manager' => false, 'level' => '1'],
                ]),
                str_contains($url, 'customers/5556667777/') => $search('conversionAction', [
                    ['id' => '700', 'name' => 'Demo request', 'type' => 'UPLOAD_CLICKS', 'ownerCustomer' => 'customers/5556667777'],
                ]),
                default => $search('conversionAction', [
                    ['id' => '987654', 'name' => 'Purchase', 'type' => 'UPLOAD_CLICKS', 'ownerCustomer' => 'customers/4445556666'],
                    ['id' => '55', 'name' => 'Sign-up', 'type' => 'UPLOAD_CLICKS', 'ownerCustomer' => 'customers/1112223333'],
                    ['id' => '111', 'name' => 'Website purchase', 'type' => 'WEBPAGE'],
                ]),
            };

            return ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => $body];
        };

        return new \Prosper202\Destinations\GoogleAdsAccounts(new WebhookSender($guard, $transport));
    }

    public function testTheApiListsAccountsAndActionsAndPicksThemByName(): void
    {
        $id = (int) $this->store()->create(1, GoogleAdsDestination::TYPE, 'Search', [], self::CREDENTIALS, time())['destination_id'];
        $theirs = $this->destination(user: 2);
        $api = new \Api\V3\Controllers\DestinationsController(self::$db, 1, $this->google());
        $refused = static function (callable $call): array {
            try {
                $call();
            } catch (\Api\V3\Exception\ValidationException $e) {
                return $e->getFieldErrors();
            }
            self::fail('not refused');
        };

        // Not connected: nothing is asked of Google, and the answer says what to do.
        self::assertStringContainsString('not connected yet', $refused(fn () => $api->googleAdsAccounts($id))['status']);
        self::assertStringContainsString('not connected yet', $refused(fn () => $api->googleAdsPick($id, []))['status']);
        self::assertSame([], $this->requests);
        self::assertTrue($this->store()->authorise(1, $id, self::REFRESH, time()));
        $this->store()->authorise(2, $theirs, self::REFRESH, time());

        $accounts = $api->googleAdsAccounts($id)['data'];
        self::assertSame([], $accounts['notes']);
        self::assertSame([
            ['customer_id' => '5556667777', 'login_customer_id' => '1112223333', 'name' => 'Demo Store', 'currency' => 'GBP', 'via' => 'Northwind Agency'],
            ['customer_id' => '4445556666', 'login_customer_id' => '1112223333', 'name' => 'Online Store', 'currency' => 'USD', 'via' => 'Northwind Agency'],
        ], $accounts['accounts']);

        // No account yet: the actions of one are asked for by its ID.
        self::assertArrayHasKey('customer_id', $refused(fn () => $api->googleAdsConversionActions($id, [])));
        self::assertArrayHasKey('customer_id', $refused(fn () => $api->googleAdsConversionActions($id, ['login_customer_id' => '1112223333'])));
        self::assertArrayHasKey('customer_id', $refused(fn () => $api->googleAdsConversionActions($id, ['customer_id' => '12abc'])));
        self::assertArrayHasKey('account', $refused(fn () => $api->googleAdsConversionActions($id, ['account' => 'Online Store'])));
        $actions = $api->googleAdsConversionActions($id, ['customer_id' => '444-555-6666', 'login_customer_id' => '1112223333'])['data'];
        self::assertSame(['4445556666', '1112223333', 1], [$actions['customer_id'], $actions['login_customer_id'], $actions['others']]);
        self::assertSame(
            [['value' => '987654', 'id' => '987654', 'name' => 'Purchase', 'owner' => null], ['value' => 'customers/1112223333/conversionActions/55', 'id' => '55', 'name' => 'Sign-up', 'owner' => '1112223333']],
            $actions['actions']
        );

        // Two accounts: one has to be named, and the refusal lists them. A body key nobody reads is refused.
        $which = $refused(fn () => $api->googleAdsPick($id, []))['account'];
        self::assertStringContainsString('Demo Store (555-666-7777), Online Store (444-555-6666)', $which);
        self::assertStringContainsString('No Google Ads account is named', $refused(fn () => $api->googleAdsPick($id, ['account' => 'Online']))['account']);
        self::assertArrayHasKey('customer_id', $refused(fn () => $api->googleAdsPick($id, ['customer_id' => '4445556666'])));
        self::assertArrayHasKey('account', $refused(fn () => $api->googleAdsPick($id, ['account' => 4445556666])));
        // The account by name, case ignored; it has two actions, so one has to be named too.
        self::assertStringContainsString('Purchase (987654), Sign-up (55)', $refused(fn () => $api->googleAdsPick($id, ['account' => 'online store']))['conversion_action']);
        self::assertSame('needs_account', $this->store()->get(1, $id)['status'], 'a refused pick saves nothing');

        $picked = $api->googleAdsPick($id, ['account' => 'online store', 'conversion_action' => 'Purchase'])['data'];
        self::assertSame('ready', $picked['status']);
        self::assertSame([
            'customer_id' => '4445556666', 'login_customer_id' => '1112223333', 'conversion_action' => '987654',
            'labels' => ['customer' => ['id' => '4445556666', 'name' => 'Online Store'], 'conversion_action' => ['id' => '987654', 'name' => 'Purchase']],
            'campaign_ids' => [], 'send_user_data' => false, 'consent' => [],
        ], (array) $picked['settings']);

        // The action alone, by ID: the account is kept, without asking Google for accounts again.
        $this->requests = [];
        $changed = (array) $api->googleAdsPick($id, ['conversion_action' => '55'])['data']['settings'];
        self::assertSame(['customers/1112223333/conversionActions/55', 'Sign-up', 'Online Store'], [$changed['conversion_action'], $changed['labels']['conversion_action']['name'], $changed['labels']['customer']['name']]);
        self::assertSame([], array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], 'listAccessibleCustomers')));
        // The actions of the destination's own account need no parameter now.
        self::assertSame('4445556666', $api->googleAdsConversionActions($id, [])['data']['customer_id']);

        // Another account by its dashed ID: its only action is taken, and the old account's names go.
        $moved = (array) $api->googleAdsPick($id, ['account' => '555-666-7777'])['data']['settings'];
        self::assertSame(
            ['5556667777', '700', ['customer' => ['id' => '5556667777', 'name' => 'Demo Store'], 'conversion_action' => ['id' => '700', 'name' => 'Demo request']]],
            [$moved['customer_id'], $moved['conversion_action'], $moved['labels']]
        );
        // An ID typed over it (the CLI's flags, the API) drops the name that no longer names it.
        $typed = (array) $this->store()->update(1, $id, ['settings' => ['conversion_action' => '701'] + $moved], time())['settings'];
        self::assertSame(['customer' => ['id' => '5556667777', 'name' => 'Demo Store']], $typed['labels']);

        // Another account's destination is not there.
        foreach ([fn () => $api->googleAdsAccounts($theirs), fn () => $api->googleAdsConversionActions($theirs, []), fn () => $api->googleAdsPick($theirs, ['account' => 'Online Store'])] as $call) {
            try {
                $call();
                self::fail('another account\'s destination was read');
            } catch (\Api\V3\Exception\NotFoundException) {
            }
        }
        self::assertSame('1234567890', ((array) $this->store()->get(2, $theirs)['settings'])['customer_id']);
    }
}

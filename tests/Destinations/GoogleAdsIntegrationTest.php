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
}

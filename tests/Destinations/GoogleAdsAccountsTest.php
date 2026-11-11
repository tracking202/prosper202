<?php

declare(strict_types=1);

namespace Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Destinations\GoogleAdsAccounts;

/**
 * The accounts and conversion actions Setup › Destinations offers to pick,
 * read through the guarded sender with a stubbed resolver and transport (no
 * network). The answers are in the shapes the Google Ads REST interface
 * documents: customers:listAccessibleCustomers' resourceNames, googleAds:search's
 * results of customerClient and conversionAction rows (int64 as strings), and
 * a google.rpc.Status carrying a GoogleAdsFailure for an error.
 */
final class GoogleAdsAccountsTest extends TestCase
{
    private const DEV = 'devTOKEN_abc123XYZ';
    private const CLIENT = '1234-abcd.apps.googleusercontent.com';
    private const SECRET = 'GOCSPX-clientSecret99';
    private const REFRESH = '1//0refreshTokenValue-xyz';
    private const ACCESS = 'ya29.accessTokenValue';
    private const MANAGER = '1112223333';
    private const DIRECT = '5556667777';
    private const CLIENT_ACCOUNT = '4445556666';

    /** @var list<array<int, mixed>> every request's curl options */
    private array $sent = [];
    /** @var array<string, array<string, mixed>> answers by a fragment of the URL (and, for a search, its login customer) */
    private array $answers = [];
    private float $clock = 0.0;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->clock = 0.0;
        $this->answers = [
            'oauth2.googleapis.com' => ['body' => '{"access_token":"' . self::ACCESS . '","expires_in":3599,"scope":"https://www.googleapis.com/auth/adwords","token_type":"Bearer"}'],
        ];
    }

    private function google(float $perRequest = 0.0): GoogleAdsAccounts
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.10'], '203.0.113.0/24');
        $transport = function (array $options) use ($perRequest): array {
            $this->sent[] = $options;
            $this->clock += $perRequest;
            $answer = ['status' => 404, 'body' => '{"error":{"code":404,"message":"no answer was set for this request","status":"NOT_FOUND"}}'];
            foreach ($this->answers as $fragment => $candidate) {
                if (str_contains((string) $options[CURLOPT_URL], $fragment)) {
                    $answer = $candidate;
                }
            }

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.10', 'location' => null, 'body' => ''];
        };

        return new GoogleAdsAccounts(new WebhookSender($guard, $transport), null, fn (): float => $this->clock);
    }

    /** @return array<string, string> */
    private static function credentials(bool $connected = true): array
    {
        return ['developer_token' => self::DEV, 'oauth_client_id' => self::CLIENT, 'oauth_client_secret' => self::SECRET]
            + ($connected ? ['refresh_token' => self::REFRESH] : []);
    }

    /** @param list<array<string, mixed>> $clients */
    private static function clients(array $clients): array
    {
        return ['body' => (string) json_encode([
            'results' => array_map(static fn (array $c): array => ['customerClient' => $c + ['resourceName' => 'customers/0/customerClients/' . $c['id']]], $clients),
            'fieldMask' => 'customerClient.id,customerClient.descriptiveName,customerClient.currencyCode,customerClient.manager,customerClient.level',
        ])];
    }

    private static function failure(int $status, string $family, string $code, string $message): array
    {
        return ['status' => $status, 'body' => (string) json_encode(['error' => [
            'code' => $status, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED',
            'details' => [['@type' => 'type.googleapis.com/google.ads.googleads.v26.errors.GoogleAdsFailure', 'errors' => [['errorCode' => [$family => $code], 'message' => $message]]]],
        ]])];
    }

    private function hierarchy(): void
    {
        $this->answers['customers:listAccessibleCustomers'] = ['body' => '{"resourceNames":["customers/' . self::MANAGER . '","customers/' . self::DIRECT . '"]}'];
        $this->answers['customers/' . self::MANAGER . '/googleAds:search'] = self::clients([
            ['id' => self::CLIENT_ACCOUNT, 'descriptiveName' => 'Online Store', 'currencyCode' => 'USD', 'manager' => false, 'level' => '1'],
            ['id' => self::MANAGER, 'descriptiveName' => 'Northwind Agency', 'currencyCode' => 'USD', 'manager' => true, 'level' => '0'],
            ['id' => '2223334444', 'descriptiveName' => 'Northwind EU', 'currencyCode' => 'EUR', 'manager' => true, 'level' => '1'],
            ['id' => '7778889999', 'descriptiveName' => 'analytics SaaS trials', 'currencyCode' => 'EUR', 'level' => '2'],
            ['id' => self::DIRECT, 'descriptiveName' => 'Demo Store', 'currencyCode' => 'GBP', 'manager' => false, 'level' => '1'],
        ]);
        $this->answers['customers/' . self::DIRECT . '/googleAds:search'] = self::clients([
            ['id' => self::DIRECT, 'descriptiveName' => 'Demo Store', 'currencyCode' => 'GBP', 'manager' => false, 'level' => '0'],
        ]);
    }

    /** @return array<string, string> a request's headers by name */
    private static function headers(array $options): array
    {
        $out = [];
        foreach ($options[CURLOPT_HTTPHEADER] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $out[strtolower($name)] = $value;
        }

        return $out;
    }

    public function testEveryAccountUnderTheUsersAccessIsListedWithTheManagerItIsReachedThrough(): void
    {
        $this->hierarchy();
        $listed = $this->google()->accounts(self::credentials());

        self::assertNull($listed['error']);
        self::assertSame([], $listed['notes']);
        self::assertSame([
            ['customer_id' => '7778889999', 'login_customer_id' => self::MANAGER, 'name' => 'analytics SaaS trials', 'currency' => 'EUR', 'via' => 'Northwind Agency'],
            // Reached directly as well as under the manager: no login customer is needed.
            ['customer_id' => self::DIRECT, 'login_customer_id' => null, 'name' => 'Demo Store', 'currency' => 'GBP', 'via' => null],
            ['customer_id' => self::CLIENT_ACCOUNT, 'login_customer_id' => self::MANAGER, 'name' => 'Online Store', 'currency' => 'USD', 'via' => 'Northwind Agency'],
        ], $listed['accounts'], 'by name; no manager account is offered');

        // The token, the list (a GET), then one search per account the user reaches, as that account.
        self::assertCount(4, $this->sent);
        self::assertSame('https://oauth2.googleapis.com/token', $this->sent[0][CURLOPT_URL]);
        $list = $this->sent[1];
        self::assertSame('https://googleads.googleapis.com/v26/customers:listAccessibleCustomers', $list[CURLOPT_URL]);
        self::assertTrue($list[CURLOPT_HTTPGET]);
        self::assertArrayNotHasKey(CURLOPT_POST, $list);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $list);
        self::assertSame(
            ['authorization' => 'Bearer ' . self::ACCESS, 'developer-token' => self::DEV, 'user-agent' => 'Prosper202-Destinations/1'],
            self::headers($list)
        );
        $search = $this->sent[2];
        self::assertSame('https://googleads.googleapis.com/v26/customers/' . self::MANAGER . '/googleAds:search', $search[CURLOPT_URL]);
        self::assertTrue($search[CURLOPT_POST]);
        self::assertSame(self::MANAGER, self::headers($search)['login-customer-id']);
        self::assertSame('application/json', self::headers($search)['content-type']);
        $query = json_decode((string) $search[CURLOPT_POSTFIELDS], true);
        self::assertSame(['query'], array_keys($query));
        self::assertSame(
            'SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.manager,'
            . " customer_client.level FROM customer_client WHERE customer_client.status = 'ENABLED' LIMIT 501",
            $query['query']
        );
        self::assertSame(self::DIRECT, self::headers($this->sent[3])['login-customer-id']);
        // Every request keeps the sender's hardening: pinned, https only, no redirects.
        foreach ($this->sent as $options) {
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
            self::assertNotEmpty($options[CURLOPT_RESOLVE]);
        }
    }

    public function testAnAccountTheUserCanNoLongerOpenIsLeftOutAlone(): void
    {
        $this->hierarchy();
        $this->answers['customers/' . self::MANAGER . '/googleAds:search'] = self::failure(403, 'authorizationError', 'CUSTOMER_NOT_ENABLED', 'The customer account can\'t be accessed because it is not yet enabled or has been deactivated.');
        $listed = $this->google()->accounts(self::credentials());
        self::assertNull($listed['error']);
        self::assertSame([self::DIRECT], array_column($listed['accounts'], 'customer_id'));
    }

    public function testWhenNothingCanBeListedGooglesOwnWordsAreGivenWithNoSecretInThem(): void
    {
        $this->hierarchy();
        $refusal = self::failure(403, 'authorizationError', 'DEVELOPER_TOKEN_NOT_APPROVED', 'The developer token ' . self::DEV . ' is only approved for use with test accounts.');
        $this->answers['customers/' . self::MANAGER . '/googleAds:search'] = $refusal;
        $this->answers['customers/' . self::DIRECT . '/googleAds:search'] = $refusal;
        $listed = $this->google()->accounts(self::credentials());
        self::assertSame([], $listed['accounts']);
        self::assertSame(
            'Google Ads did not list the accounts: Google Ads answered 403: DEVELOPER_TOKEN_NOT_APPROVED: The developer token … is only approved for use with test accounts.',
            $listed['error']
        );

        // The list itself refused.
        $this->answers['customers:listAccessibleCustomers'] = ['status' => 401, 'body' => '{"error":{"code":401,"message":"Request had invalid authentication credentials.","status":"UNAUTHENTICATED"}}'];
        self::assertSame(
            'Google Ads did not list your accounts: Google Ads answered 401: Request had invalid authentication credentials.',
            $this->google()->accounts(self::credentials())['error']
        );
    }

    public function testARevokedConnectionSaysToConnectAgainAndAsksGoogleAdsNothing(): void
    {
        $this->hierarchy();
        $this->answers['oauth2.googleapis.com'] = ['status' => 400, 'body' => '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'];
        $listed = $this->google()->accounts(self::credentials());
        self::assertSame([], $listed['accounts']);
        self::assertStringContainsString('invalid_grant', (string) $listed['error']);
        self::assertStringContainsString('connect Google Ads again', (string) $listed['error']);
        self::assertCount(1, $this->sent, 'only the token endpoint was asked');

        $this->sent = [];
        self::assertStringContainsString('not connected yet', (string) $this->google()->accounts(self::credentials(false))['error']);
        self::assertStringContainsString('not connected yet', (string) $this->google()->conversionActions(self::credentials(false), self::DIRECT, null)['error']);
        self::assertSame([], $this->sent);
    }

    public function testAUserWithNoAccountOrOnlyEmptyManagersIsToldSo(): void
    {
        $this->answers['customers:listAccessibleCustomers'] = ['body' => '{}'];
        self::assertStringContainsString('has no Google Ads account', (string) $this->google()->accounts(self::credentials())['error']);

        $this->answers['customers:listAccessibleCustomers'] = ['body' => '{"resourceNames":["customers/' . self::MANAGER . '"]}'];
        $this->answers['customers/' . self::MANAGER . '/googleAds:search'] = self::clients([
            ['id' => self::MANAGER, 'descriptiveName' => 'Northwind Agency', 'currencyCode' => 'USD', 'manager' => true, 'level' => '0'],
        ]);
        self::assertStringContainsString('only manager accounts', (string) $this->google()->accounts(self::credentials())['error']);
    }

    public function testAnAnswerThatIsNotGooglesJsonIsAnErrorNeverAnEmptyList(): void
    {
        foreach (['<html>502 Bad Gateway</html>', '', '[{"results":[]}]', '"ok"'] as $body) {
            $this->answers['customers:listAccessibleCustomers'] = ['body' => $body];
            self::assertStringContainsString('not its JSON', (string) $this->google()->accounts(self::credentials())['error'], $body);
        }
        // Cut off at the read cap: the status is known, the body is not whole.
        $this->answers['customers:listAccessibleCustomers'] = ['body' => '{"resourceNames":["custo', 'error' => 'Failure writing output to destination'];
        self::assertStringContainsString('cut off', (string) $this->google()->accounts(self::credentials())['error']);
        $this->answers['customers:listAccessibleCustomers'] = ['status' => 0, 'error' => 'Operation timed out'];
        self::assertStringContainsString('no answer from Google Ads: Operation timed out', (string) $this->google()->accounts(self::credentials())['error']);
    }

    public function testTheListsAreBoundedAndSayWhatTheyLeftOut(): void
    {
        $many = [];
        for ($i = 1; $i <= GoogleAdsAccounts::MAX_ROOTS + 5; $i++) {
            $many[] = 'customers/' . (3000000000 + $i);
        }
        $this->answers['customers:listAccessibleCustomers'] = ['body' => (string) json_encode(['resourceNames' => $many])];
        $clients = [['id' => self::MANAGER, 'descriptiveName' => 'Northwind Agency', 'manager' => true, 'level' => '0']];
        for ($i = 0; $i <= GoogleAdsAccounts::MAX_ROWS; $i++) {
            $clients[] = ['id' => (string) (4000000000 + $i), 'descriptiveName' => 'Store ' . $i, 'currencyCode' => 'USD', 'manager' => false, 'level' => '1'];
        }
        $this->answers['googleAds:search'] = self::clients($clients);
        $listed = $this->google()->accounts(self::credentials());
        self::assertNull($listed['error']);
        self::assertCount(GoogleAdsAccounts::MAX_ROOTS + 2, $this->sent, 'the token, the list, then MAX_ROOTS searches');
        self::assertStringContainsString('reaches ' . (GoogleAdsAccounts::MAX_ROOTS + 5) . ' accounts directly; the first ' . GoogleAdsAccounts::MAX_ROOTS . ' were read', $listed['notes'][0]);
        self::assertStringContainsString('more than ' . GoogleAdsAccounts::MAX_ROWS . ' accounts', $listed['notes'][1]);
        self::assertCount(GoogleAdsAccounts::MAX_ROWS - 1, $listed['accounts'], 'MAX_ROWS rows read of each, the manager among them');

        // A slow Google: the reading stops at its budget and says so.
        $this->sent = [];
        $slow = $this->google(GoogleAdsAccounts::BUDGET / 2)->accounts(self::credentials());
        self::assertLessThan(GoogleAdsAccounts::MAX_ROOTS, count($this->sent));
        self::assertStringContainsString('took too long', implode(' ', $slow['notes']));
    }

    public function testOnlyConversionActionsThatTakeUploadedClicksAreOffered(): void
    {
        $this->answers['customers/' . self::CLIENT_ACCOUNT . '/googleAds:search'] = ['body' => (string) json_encode(['results' => [
            ['conversionAction' => ['resourceName' => 'customers/' . self::CLIENT_ACCOUNT . '/conversionActions/987654', 'id' => '987654', 'name' => 'Purchase (imported)', 'type' => 'UPLOAD_CLICKS', 'ownerCustomer' => 'customers/' . self::CLIENT_ACCOUNT]],
            ['conversionAction' => ['resourceName' => 'customers/' . self::CLIENT_ACCOUNT . '/conversionActions/111', 'id' => '111', 'name' => 'Website purchase', 'type' => 'WEBPAGE', 'ownerCustomer' => 'customers/' . self::CLIENT_ACCOUNT]],
            ['conversionAction' => ['resourceName' => 'customers/' . self::CLIENT_ACCOUNT . '/conversionActions/55', 'id' => '55', 'name' => 'Demo request', 'type' => 'UPLOAD_CLICKS', 'ownerCustomer' => 'customers/' . self::MANAGER]],
            ['conversionAction' => ['resourceName' => 'customers/' . self::CLIENT_ACCOUNT . '/conversionActions/222', 'id' => '222', 'name' => 'Calls', 'type' => 'UPLOAD_CALLS']],
            ['conversionAction' => ['resourceName' => 'customers/' . self::CLIENT_ACCOUNT . '/conversionActions/333', 'id' => 333, 'name' => 'Trial started', 'type' => 'UPLOAD_CLICKS']],
            ['conversionAction' => ['name' => 'no id', 'type' => 'UPLOAD_CLICKS']],
        ]])];
        $read = $this->google()->conversionActions(self::credentials(), self::CLIENT_ACCOUNT, self::MANAGER);

        self::assertNull($read['error']);
        self::assertSame(2, $read['others']);
        self::assertSame([
            // Owned by the manager: named by its owner, as the destination's settings take it.
            ['value' => 'customers/' . self::MANAGER . '/conversionActions/55', 'id' => '55', 'name' => 'Demo request', 'owner' => self::MANAGER],
            ['value' => '987654', 'id' => '987654', 'name' => 'Purchase (imported)', 'owner' => null],
            ['value' => '333', 'id' => '333', 'name' => 'Trial started', 'owner' => null],
        ], $read['actions']);
        $search = $this->sent[1];
        self::assertSame('https://googleads.googleapis.com/v26/customers/' . self::CLIENT_ACCOUNT . '/googleAds:search', $search[CURLOPT_URL]);
        self::assertSame(self::MANAGER, self::headers($search)['login-customer-id']);
        self::assertSame(
            'SELECT conversion_action.id, conversion_action.name, conversion_action.type, conversion_action.owner_customer'
            . " FROM conversion_action WHERE conversion_action.status = 'ENABLED' LIMIT 501",
            json_decode((string) $search[CURLOPT_POSTFIELDS], true)['query']
        );
        // Every value offered is one the destination's settings accept.
        $ads = new \Prosper202\Destinations\GoogleAdsDestination();
        foreach ($read['actions'] as $action) {
            self::assertSame($action['value'], $ads->validate(['customer_id' => self::CLIENT_ACCOUNT, 'conversion_action' => $action['value']], null)[0]['conversion_action']);
        }

        // An account reached directly sends no login customer; a refusal is Google's words.
        $this->sent = [];
        $this->answers['customers/' . self::DIRECT . '/googleAds:search'] = self::failure(403, 'authorizationError', 'USER_PERMISSION_DENIED', 'User doesn\'t have permission to access customer.');
        $refused = $this->google()->conversionActions(self::credentials(), self::DIRECT, null);
        self::assertArrayNotHasKey('login-customer-id', self::headers($this->sent[1]));
        self::assertSame([], $refused['actions']);
        self::assertSame(
            'Google Ads did not list the conversion actions of 555-666-7777: Google Ads answered 403: USER_PERMISSION_DENIED: User doesn\'t have permission to access customer.',
            $refused['error']
        );
        // An ID that is not one asks nothing.
        $this->sent = [];
        self::assertNotNull($this->google()->conversionActions(self::credentials(), '12abc', null)['error']);
        self::assertSame([], $this->sent);
    }

    public function testOneTokenServesBothReads(): void
    {
        $this->hierarchy();
        $this->answers['customers/' . self::DIRECT . '/googleAds:search'] = self::clients([['id' => self::DIRECT, 'descriptiveName' => 'Demo Store', 'level' => '0']]);
        $google = $this->google();
        $google->accounts(self::credentials());
        $google->conversionActions(self::credentials(), self::DIRECT, null);
        $tokens = array_filter($this->sent, static fn (array $o): bool => str_contains((string) $o[CURLOPT_URL], 'oauth2'));
        self::assertCount(1, $tokens);
        self::assertSame('123-456-7890', GoogleAdsAccounts::dashed('1234567890'));
    }
}

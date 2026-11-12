<?php

declare(strict_types=1);

namespace Tests\AdPlatforms;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionInputException;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\AdPlatforms\OpenAiAds\OpenAiAdsConnections;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Prosper202\Database\Connection;
use Prosper202\Destinations\ConversionFacts;
use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\DestinationType;
use Prosper202\Destinations\SendResult;
use Tests\Apps\Android\AndroidDatabase;

/**
 * ChatGPT Ads connections against a real server, with OpenAI's Advertiser
 * API stubbed at the transport (answers built from tests/fixtures/openai-ads):
 * connecting verifies the key and stores it sealed, the key is never in a
 * read, and setup finds or makes the pixel, the Conversions API key, the
 * destination and the event settings, asking for what an account without
 * those features must give itself.
 *
 * @group integration
 */
final class AdConnectionsIntegrationTest extends TestCase
{
    use AndroidDatabase {
        setUp as private databaseSetUp;
    }

    private const KEY = 'sk-ads-live-Z9q7Xc2Vb8Nm4Lk1';
    private const CAPI_KEY = 'capi-live-7Qm2xVb9Lr4';
    private const INSTALL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @var list<array{method: string, url: string, body: mixed, headers: list<string>}> */
    private array $requests = [];
    /** @var array<string, list<array<string, mixed>>> answers queued by "METHOD path" (the last one repeats) */
    private array $routes = [];

    protected function setUp(): void
    {
        $this->databaseSetUp();
        foreach (['202_ad_connections', '202_destinations', '202_destination_deliveries'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture("DELETE FROM 202_deployment_secrets WHERE secret_name = 'ad_connection_credential'");
        self::fixture('DELETE FROM 202_users_pref WHERE user_id IN (1, 2)');
        self::fixture("INSERT INTO 202_users_pref SET user_id=1, user_account_currency='USD'");
        self::fixture("INSERT INTO 202_users_pref SET user_id=2, user_account_currency='EUR'");
        $this->requests = [];
        $this->routes = [
            'GET ad_account' => [['body' => self::answer('ad_account.json')]],
            'GET conversions/pixels' => [['body' => self::answer('pixels_empty.json')]],
            'POST conversions/pixels' => [['body' => self::answer('pixel_created.json')]],
            'POST conversions/api_keys' => [['body' => self::answer('api_key_created.json')]],
            'GET conversions/event_settings' => [['body' => self::answer('event_settings_empty.json')]],
            'POST conversions/event_settings' => [['body' => self::answer('event_setting_created.json')]],
        ];
    }

    private static function answer(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/openai-ads/' . $name);
    }

    private function api(): AdvertiserApi
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['203.0.113.20'], '203.0.113.0/24');
        $transport = function (array $options): array {
            $url = (string) $options[CURLOPT_URL];
            $method = isset($options[CURLOPT_POSTFIELDS]) && !($options[CURLOPT_HTTPGET] ?? false) ? 'POST' : 'GET';
            $path = (string) parse_url(substr($url, strlen(AdvertiserApi::BASE) - 1), PHP_URL_PATH);
            $route = $method . ' ' . ltrim($path, '/');
            $this->requests[] = ['method' => $method, 'url' => $url, 'route' => $route, 'body' => $method === 'POST' ? json_decode((string) $options[CURLOPT_POSTFIELDS], true) : null,
                'headers' => array_values((array) $options[CURLOPT_HTTPHEADER])];
            $queue = $this->routes[$route] ?? [['status' => 404, 'body' => '{"error":{"message":"Not found"}}']];
            $answer = count($queue) > 1 ? array_shift($this->routes[$route]) : $queue[0];

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '203.0.113.20', 'location' => null, 'body' => '{}'];
        };

        return new AdvertiserApi(new WebhookSender($guard, $transport));
    }

    private function store(): AdConnectionStore
    {
        return new AdConnectionStore(new Connection(self::$db));
    }

    /** @param array<string, DestinationType>|null $types */
    private function connections(?array $types = null): OpenAiAdsConnections
    {
        return new OpenAiAdsConnections($this->store(), $this->api(), new DestinationStore(new Connection(self::$db), $types), self::INSTALL);
    }

    private function connect(int $user = 1): int
    {
        return (int) $this->connections()->connect($user, self::KEY, null, 1_800_000_000)['connection_id'];
    }

    /** @return list<string> */
    private function routes(): array
    {
        return array_column($this->requests, 'route');
    }

    public function testConnectingVerifiesTheKeyAndFillsTheAccountFromOpenAi(): void
    {
        $made = $this->connections()->connect(1, '  ' . self::KEY . "\n", 70, 1_800_000_000);

        self::assertSame(['GET ad_account'], $this->routes());
        self::assertSame(['id' => 'adacct_123', 'name' => 'Northwind Outfitters', 'currency' => 'USD', 'timezone' => 'America/New_York'], $made['account']);
        $facts = array_intersect_key($made, array_flip(['platform', 'ppc_account_id', 'status', 'currency_matches_account']));
        self::assertEquals(['platform' => 'openai_ads', 'ppc_account_id' => 70, 'status' => 'connected', 'currency_matches_account' => true], $facts);
        self::assertSame(['advertiser_api_key' => true, 'conversions_api_key' => false], $made['credentials_set']);
        self::assertSame(1_800_000_000, $made['checked_at']);
    }

    public function testTheKeyIsNeverInAReadAndIsSealedAtRest(): void
    {
        $id = $this->connect();
        $this->connections()->setup(1, $id, [], 1_800_000_100);

        foreach ([$this->store()->get(1, $id), $this->store()->list(1)] as $read) {
            $json = (string) json_encode($read);
            self::assertStringNotContainsString(self::KEY, $json);
            self::assertStringNotContainsString(self::CAPI_KEY, $json);
        }
        $raw = self::$db->query('SELECT * FROM 202_ad_connections WHERE connection_id = ' . $id)->fetch_assoc();
        self::assertStringStartsWith('v1.', (string) $raw['credentials']);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($raw));
        self::assertStringNotContainsString(self::CAPI_KEY, (string) json_encode($raw));
        self::assertSame(['advertiser_api_key' => self::KEY, 'conversions_api_key' => self::CAPI_KEY], $this->store()->forCalling(1, $id)['credentials']);

        // Sealed for this connection and owner: moved onto another row, it does not open.
        $other = $this->connect(2);
        self::$db->query("UPDATE 202_ad_connections SET credentials = '" . self::$db->real_escape_string((string) $raw['credentials']) . "' WHERE connection_id = " . $other);
        self::assertSame('credentials_unreadable', $this->store()->get(2, $other)['status']);
        $this->expectException(AdConnectionInputException::class);
        $this->store()->forCalling(2, $other);
    }

    public function testARefusedKeyAndAnUnansweredCheckStoreNothing(): void
    {
        $this->routes['GET ad_account'] = [['status' => 401, 'body' => '{"error":{"message":"Invalid API key ' . self::KEY . '"}}']];
        try {
            $this->connections()->connect(1, self::KEY, null, 1);
            self::fail('a refused key was stored');
        } catch (AdConnectionInputException $e) {
            self::assertSame('api_key', $e->field);
            self::assertStringContainsString('OpenAI refused that key', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        $this->routes['GET ad_account'] = [['status' => 503, 'body' => '']];
        try {
            $this->connections()->connect(1, self::KEY, null, 1);
            self::fail('an unchecked key was stored');
        } catch (AdConnectionInputException $e) {
            self::assertStringContainsString('could not be checked, so nothing was saved', $e->getMessage());
        }
        self::assertSame([], $this->store()->list(1));
    }

    public function testOneAdAccountIsConnectedOnceAndATrafficSourceAccountMustBeYours(): void
    {
        $id = $this->connect();
        try {
            $this->connect();
            self::fail('the same ad account was connected twice');
        } catch (AdConnectionInputException $e) {
            self::assertStringContainsString('already connected as connection ' . $id, $e->getMessage());
        }
        try {
            $this->connections()->connect(2, self::KEY, 70, 1);
            self::fail('another account\'s traffic source account was linked');
        } catch (AdConnectionInputException $e) {
            self::assertSame('ppc_account_id', $e->field);
        }
        self::assertNull($this->store()->get(2, $id), 'another account does not see it');
        self::assertFalse($this->store()->delete(2, $id, 1), 'nor delete it');
        self::assertTrue($this->store()->get(1, $id)['currency_matches_account'], 'the ad account and account 1 are both USD');
    }

    public function testACurrencyOtherThanTheAccountsIsConnectedAndSaidSo(): void
    {
        $id = $this->connect(2);

        self::assertFalse($this->store()->get(2, $id)['currency_matches_account'], 'the ad account is USD, account 2 is EUR');
    }

    public function testSetupMakesThePixelTheKeyAndAnEventSettingAndRunsAgainWithoutMakingThemTwice(): void
    {
        $id = $this->connect();
        $first = $this->connections()->setup(1, $id, [], 1_800_000_100);

        $outcomes = array_column($first['steps'], 'outcome', 'step');
        self::assertSame(['pixel' => 'created', 'conversions_key' => 'created', 'destination' => 'unavailable', 'event_settings' => 'created'], $outcomes);
        $creates = array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'POST'));
        self::assertSame(['name' => 'Prosper202', 'client_type' => 'web'], $creates[0]['body']);
        self::assertSame(['name' => 'Prosper202 server events'], $creates[1]['body']);
        $setting = ['name' => 'Prosper202 order created', 'event_type' => 'order_created', 'attribution_window_days' => 30, 'source_ids' => ['clidsrc_123']];
        self::assertSame($setting, $creates[2]['body'], 'source_ids takes the source id, not the pixel id');
        foreach ($creates as $create) {
            self::assertCount(1, preg_grep('/^Idempotency-Key: p202-[0-9a-f]{40}$/', $create['headers']));
        }
        $setup = (array) $first['connection']['setup'];
        self::assertSame(['source_id' => 'clidsrc_123', 'pixel_id' => '134534901', 'name' => 'Prosper202', 'how' => 'created'], $setup['pixel']);
        self::assertSame('ces_123', $setup['event_settings']['order_created']['id']);
        self::assertStringNotContainsString(self::CAPI_KEY, (string) json_encode($first));

        // Run again: OpenAI now lists what the first run made.
        $this->routes['GET conversions/pixels'] = [['body' => (string) json_encode(['object' => 'list', 'data' => [json_decode(self::answer('pixel_created.json'), true)], 'first_id' => 'clidsrc_123', 'last_id' => 'clidsrc_123', 'has_more' => false])]];
        $this->routes['GET conversions/event_settings'] = [['body' => (string) json_encode(['object' => 'list', 'data' => [json_decode(self::answer('event_setting_created.json'), true)], 'first_id' => 'ces_123', 'last_id' => 'ces_123', 'has_more' => false])]];
        $this->requests = [];
        $again = $this->connections()->setup(1, $id, [], 1_800_000_200);

        self::assertSame(['pixel' => 'kept', 'conversions_key' => 'kept', 'destination' => 'unavailable', 'event_settings' => 'found'], array_column($again['steps'], 'outcome', 'step'));
        self::assertSame([], array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'POST'), 'nothing is made twice');
    }

    public function testEachStepCanBeSkipped(): void
    {
        $id = $this->connect();
        $this->requests = [];
        $run = $this->connections()->setup(1, $id, ['skip' => OpenAiAdsConnections::STEPS], 1);

        self::assertSame(array_fill_keys(OpenAiAdsConnections::STEPS, 'skipped'), array_column($run['steps'], 'outcome', 'step'));
        self::assertSame([], $this->requests);
    }

    public function testAnAccountWithoutPixelOrKeyCreationIsAskedForThemAndToldWhy(): void
    {
        $id = $this->connect();
        $notFound = [['status' => 404, 'body' => '{"error":{"message":"Not found"}}']];
        $this->routes['GET conversions/pixels'] = $notFound;
        $this->routes['POST conversions/pixels'] = $notFound;
        $this->routes['POST conversions/api_keys'] = $notFound;
        $run = $this->connections()->setup(1, $id, [], 1);

        $steps = array_column($run['steps'], null, 'step');
        self::assertSame('needs_input', $steps['pixel']['outcome']);
        self::assertStringContainsString('pixel management is not switched on for this ad account', $steps['pixel']['detail']);
        self::assertSame('needs_input', $steps['conversions_key']['outcome']);
        self::assertStringContainsString('Conversions API key creation is not switched on', $steps['conversions_key']['detail']);
        self::assertSame('needs_input', $steps['event_settings']['outcome']);

        $given = $this->connections()->setup(1, $id, ['pixel_id' => '998877', 'source_id' => 'clidsrc_777', 'conversions_api_key' => 'capi-from-ads-manager-1'], 2);
        $steps = array_column($given['steps'], null, 'step');
        self::assertSame(['given', 'given'], [$steps['pixel']['outcome'], $steps['conversions_key']['outcome']]);
        self::assertSame('998877', $given['connection']['setup']['pixel']['pixel_id']);
        self::assertSame('capi-from-ads-manager-1', $this->store()->forCalling(1, $id)['credentials']['conversions_api_key']);
    }

    public function testSeveralPixelsAreNamedAndOneIsChosenById(): void
    {
        $id = $this->connect();
        $pixels = ['object' => 'list', 'data' => [
            ['id' => 'clidsrc_1', 'name' => 'Store', 'client_type' => 'web', 'pixel_id' => '111'],
            ['id' => 'clidsrc_2', 'name' => 'Blog', 'client_type' => 'web', 'pixel_id' => '222'],
        ], 'first_id' => 'clidsrc_1', 'last_id' => 'clidsrc_2', 'has_more' => false];
        $this->routes['GET conversions/pixels'] = [['body' => (string) json_encode($pixels)]];
        $run = $this->connections()->setup(1, $id, ['skip' => ['conversions_key', 'destination', 'event_settings']], 1);
        self::assertSame('needs_input', $run['steps'][0]['outcome']);
        self::assertStringContainsString('Store (clidsrc_1), Blog (clidsrc_2)', $run['steps'][0]['detail']);
        self::assertNotContains('POST conversions/pixels', $this->routes(), 'none is made while the account has some');

        $run = $this->connections()->setup(1, $id, ['pixel_source_id' => 'clidsrc_2', 'skip' => ['conversions_key', 'destination', 'event_settings']], 2);
        self::assertSame(['found', '222'], [$run['steps'][0]['outcome'], $run['connection']['setup']['pixel']['pixel_id']]);
    }

    public function testListsAreReadPageByPage(): void
    {
        $id = $this->connect();
        $this->routes['GET conversions/pixels'] = [
            ['body' => (string) json_encode(['object' => 'list', 'data' => [['id' => 'clidsrc_1', 'name' => 'App', 'client_type' => 'app', 'pixel_id' => '1']], 'first_id' => 'clidsrc_1', 'last_id' => 'clidsrc_1', 'has_more' => true])],
            ['body' => (string) json_encode(['object' => 'list', 'data' => [['id' => 'clidsrc_2', 'name' => 'Web', 'client_type' => 'web', 'pixel_id' => '2']], 'first_id' => 'clidsrc_2', 'last_id' => 'clidsrc_2', 'has_more' => false])],
        ];
        $run = $this->connections()->setup(1, $id, ['skip' => ['conversions_key', 'destination', 'event_settings']], 1);

        self::assertSame('found', $run['steps'][0]['outcome'], 'the web pixel on the second page');
        self::assertStringContainsString('after=clidsrc_1', $this->requests[2]['url']);
    }

    public function testTheDestinationIsMadeWhenItsTypeIsInThisVersion(): void
    {
        $type = new class () implements DestinationType {
            public function validate(array $settings, ?array $credentials): array
            {
                if (!is_string($settings['pixel_id'] ?? null)) {
                    throw new DestinationInputException('settings.pixel_id', 'pixel_id is required.');
                }

                return [['pixel_id' => $settings['pixel_id'], 'event_type' => $settings['event_type'] ?? 'order_created'], $credentials];
            }

            public function send(array $settings, array $credentials, ConversionFacts $facts, bool $test, int $now): SendResult
            {
                throw new \LogicException('not sent in this test');
            }

            public function settingKeys(): array
            {
                return ['pixel_id', 'event_type', 'event_types'];
            }

            public function credentialKeys(): array
            {
                return ['conversions_api_key'];
            }

            public function host(array $settings): string
            {
                return 'bzr.openai.com';
            }
        };
        $id = $this->connect();
        $run = $this->connections(['openai_ads' => $type])->setup(1, $id, ['event_types' => ['lead_created']], 1);

        $steps = array_column($run['steps'], null, 'step');
        self::assertSame('created', $steps['destination']['outcome']);
        $destination = (new DestinationStore(new Connection(self::$db), ['openai_ads' => $type]))->forSending(1, $steps['destination']['destination_id']);
        self::assertSame(['pixel_id' => '134534901', 'event_type' => 'lead_created'], array_intersect_key($destination['settings'], array_flip(['pixel_id', 'event_type'])));
        self::assertSame(['conversions_api_key' => self::CAPI_KEY], $destination['credentials']);
        self::assertSame($steps['destination']['destination_id'], $run['connection']['setup']['destination_id']);
    }

    public function testACheckMarksARefusedKeyAndLeavesTheStatusOnAnOutage(): void
    {
        $id = $this->connect();
        $this->routes['GET ad_account'] = [['status' => 503, 'body' => '{"error":{"message":"Service unavailable"}}']];
        $out = $this->connections()->check(1, $id, 5);
        self::assertSame(['connected', 5], [$out['status'], $out['checked_at']], 'an outage says nothing of the key');
        self::assertStringContainsString('OpenAI answered 503', (string) $out['last_error']);

        $this->routes['GET ad_account'] = [['status' => 401, 'body' => '{}']];
        self::assertSame('key_refused', $this->connections()->check(1, $id, 6)['status']);

        $this->routes['GET ad_account'] = [['body' => str_replace('Northwind Outfitters', 'Northwind Outfitters EU', self::answer('ad_account.json'))]];
        $back = $this->connections()->check(1, $id, 7);
        self::assertSame(['connected', null, 'Northwind Outfitters EU'], [$back['status'], $back['last_error'], $back['account']['name']]);
        self::assertNull($this->connections()->check(2, $id, 8), 'not another account\'s');
    }

    public function testAReplacedKeyMustReachTheSameAdAccount(): void
    {
        $id = $this->connect();
        $this->routes['GET ad_account'] = [['body' => str_replace('adacct_123', 'adacct_999', self::answer('ad_account.json'))]];
        try {
            $this->connections()->replaceKey(1, $id, 'sk-ads-other-account-1', 1);
            self::fail('a key for another ad account replaced this one\'s');
        } catch (AdConnectionInputException $e) {
            self::assertStringContainsString('reaches ad account adacct_999, not adacct_123', $e->getMessage());
        }
        self::assertSame(self::KEY, $this->store()->forCalling(1, $id)['credentials']['advertiser_api_key']);
    }

    public function testIdempotencyKeysNameTheInstallTheConnectionAndTheThing(): void
    {
        $id = $this->connect();
        $this->connections()->setup(1, $id, ['skip' => ['conversions_key', 'destination', 'event_settings']], 1);
        self::$db->query('UPDATE 202_ad_connections SET setup = \'{}\' WHERE connection_id = ' . $id);
        $this->connections()->setup(1, $id, ['skip' => ['conversions_key', 'destination', 'event_settings']], 2);
        $other = new OpenAiAdsConnections($this->store(), $this->api(), new DestinationStore(new Connection(self::$db)), str_repeat('f', 64));
        self::$db->query('UPDATE 202_ad_connections SET setup = \'{}\' WHERE connection_id = ' . $id);
        $other->setup(1, $id, ['skip' => ['conversions_key', 'destination', 'event_settings']], 3);

        $keys = [];
        foreach ($this->requests as $r) {
            if ($r['route'] === 'POST conversions/pixels') {
                $keys[] = (string) current(preg_grep('/^Idempotency-Key: /', $r['headers']));
            }
        }
        self::assertCount(3, $keys);
        self::assertSame($keys[0], $keys[1], 'the same pixel, asked for again, is the same create');
        self::assertNotSame($keys[0], $keys[2], 'another install never shares a create');
    }

    public function testSetupOptionsAreReadStrictly(): void
    {
        $id = $this->connect();
        $refused = [
            [['skip' => ['pixels']], 'skip'],
            [['event_types' => ['purchase']], 'event_types'],
            [['source_id' => 'clidsrc_1'], 'source_id'],
            [['pixel_id' => '1', 'pixel_source_id' => 'clidsrc_1'], 'pixel_source_id'],
            [['conversions_api_key' => 'short'], 'conversions_api_key'],
            [['colour' => 'blue'], 'colour'],
        ];
        foreach ($refused as [$options, $field]) {
            try {
                $this->connections()->setup(1, $id, $options, 1);
                self::fail($field . ' was accepted');
            } catch (AdConnectionInputException $e) {
                self::assertSame($field, $e->field);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\AdPlatforms\AdConnectionStore;
use Prosper202\AdPlatforms\OpenAiAds\AdvertiserApi;
use Prosper202\Database\Connection;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\Ga4Destination;
use Prosper202\Destinations\GoogleAdsDestination;

/**
 * tracking202/setup/destinations.php as the web server runs it, signed in
 * (fixtures/page-runner.php): a destination edited in place, its failures
 * counted, listed and sent again, earlier conversions queued, and the consent
 * wording following the account's privacy setting, and a ChatGPT Ads
 * connection's key checked and replaced. Nothing here reaches an ad platform:
 * the connection tests point the page at
 * tests/fixtures/openai-ads/fake_openai_ads.py.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class DestinationsPageIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990091;
    private const OTHER = 990092;
    private const ROLE = 990091;
    private const NETWORK = 990091;
    private const CAMPAIGN = 990091;
    private const CAMPAIGN_B = 990092;
    private const SECRET = 'Sx9-secretAPIvalue';
    private const ADS = ['developer_token' => 'devTOKEN_abc123XYZ', 'oauth_client_id' => '1234-abcd.apps.googleusercontent.com', 'oauth_client_secret' => 'GOCSPX-clientSecret99'];

    private const AD_KEY = 'sk-ads-live-page-seam-01';

    private static string $sessions = '';
    /** @var resource|null the stand-in Advertiser API, started by the first test that needs it */
    private static $openAi = null;
    private static string $openAiOrigin = '';
    private static string $openAiTls = '';
    /** @var list<int> the permission rows this class added */
    private static array $addedPermissions = [];
    /** The install's privacy setting as it was found (user 1's), put back at the end; null when it has no row. */
    private static ?string $installPrivacy = null;

    private int $ga4 = 0;
    private int $ads = 0;
    private int $theirs = 0;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran. tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user, 'user_pref_privacy' => 'eu']);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'destinations page test']);
        foreach (['access_to_setup_section', 'access_to_campaign_data'] as $name) {
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE permission_description = '$name'");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET permission_description = '$name'");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        // The fixtures name only the columns the page reads.
        self::q("SET SESSION sql_mode=''");
        self::q("INSERT INTO 202_aff_networks SET aff_network_id = " . self::NETWORK . ', user_id = ' . self::USER . ", aff_network_name = 'Online store', aff_network_time = 1");
        foreach ([self::CAMPAIGN => 'Running shoes', self::CAMPAIGN_B => 'Trail packs'] as $id => $name) {
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $id, user_id = " . self::USER . ', aff_network_id = ' . self::NETWORK
                . ", aff_campaign_name = '$name', aff_campaign_url = 'https://store.example/', aff_campaign_payout = 20, aff_campaign_time = 1");
        }
        self::q(self::$strict);
        // The install's setting applies on top of the account's: left weakest here, so the account's decides.
        $install = self::$db->query('SELECT user_pref_privacy FROM 202_users_pref WHERE user_id = 1');
        self::$installPrivacy = $install instanceof \mysqli_result ? ($install->fetch_row()[0] ?? null) : null;
        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'disabled' WHERE user_id = 1");
        self::$sessions = sys_get_temp_dir() . '/p202-destinations-page-runner-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$openAi !== null) {
            proc_terminate(self::$openAi);
            proc_close(self::$openAi);
            self::$openAi = null;
        }
        if (self::$openAiTls !== '') {
            foreach (['cert.pem', 'key.pem'] as $file) {
                @unlink(self::$openAiTls . '/' . $file);
            }
            @rmdir(self::$openAiTls);
        }
        putenv(AdvertiserApi::ENV_ENDPOINT);
        putenv(AdvertiserApi::ENV_CA_FILE);
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$installPrivacy !== null) {
                self::q('UPDATE 202_users_pref SET user_pref_privacy = ' . self::str(self::$installPrivacy) . ' WHERE user_id = 1');
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $user) {
            foreach (['202_ad_connections', '202_destination_deliveries', '202_destinations', '202_conversion_logs', '202_aff_campaigns', '202_aff_networks', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
        foreach (self::$addedPermissions as $permission) {
            self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
        }
        self::$addedPermissions = [];
    }

    protected function setUp(): void
    {
        self::requireScratch();
        foreach ([self::USER, self::OTHER] as $user) {
            self::q("DELETE FROM 202_ad_connections WHERE user_id = $user");
            self::q("DELETE FROM 202_destination_deliveries WHERE user_id = $user");
            self::q("DELETE FROM 202_destinations WHERE user_id = $user");
            self::q("DELETE FROM 202_conversion_logs WHERE user_id = $user");
        }
        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'eu' WHERE user_id = " . self::USER);
        $this->ga4 = (int) self::store()->create(self::USER, Ga4Destination::TYPE, 'Store GA4', ['measurement_id' => 'G-ABC123'], ['api_secret' => self::SECRET], time())['destination_id'];
        $this->ads = (int) self::store()->create(self::USER, GoogleAdsDestination::TYPE, 'Search', ['customer_id' => '123-456-7890', 'conversion_action' => '987654'], self::ADS, time())['destination_id'];
        $this->theirs = (int) self::store()->create(self::OTHER, Ga4Destination::TYPE, 'Their GA4', ['measurement_id' => 'G-THEIRS1'], ['api_secret' => self::SECRET], time())['destination_id'];
    }

    private static function store(): DestinationStore
    {
        return new DestinationStore(new Connection(self::requireScratch()));
    }

    /**
     * What the page printed, run as USER. A POST carries the session's token unless $token is false.
     *
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private static function page(array $get = [], array $post = [], bool $token = true): string
    {
        array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
        if ($post !== [] && $token) {
            $post['token'] = 'SESSION';
        }
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', __DIR__ . '/fixtures/page-runner.php', 'tracking202/setup/destinations.php'],
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
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning:', $out . $err, $err);

        return $out;
    }

    /** The edit form of one destination, as the page printed it. */
    private static function editForm(string $html, int $id): string
    {
        $from = strpos($html, '<details class="p202-disclosure mb-3" id="edit' . $id . '"');
        self::assertNotFalse($from, 'the destination has an edit form');

        return substr($html, $from, (int) strpos($html, '</details>', $from) - $from);
    }

    /** @return array<string, mixed> */
    private static function ga4Form(array $over = []): array
    {
        return $over + [
            'action' => 'update', 'name' => 'Store GA4', 'measurement_id' => 'G-ABC123', 'api_secret' => '', 'event_name' => 'purchase',
            'client_id_source' => 'click', 'user_id_source' => 'none', 'param_name' => ['', ''], 'param_field' => ['', ''],
            'all_campaigns' => '1', 'ad_user_data' => '', 'ad_personalization' => '',
        ];
    }

    private function delivery(int $destination, string $status, ?string $error, int $conv, int $age = 0, int $user = self::USER): int
    {
        return self::insert("INSERT INTO 202_destination_deliveries SET user_id = $user, destination_id = $destination, conv_id = $conv, kind = 'conversion', status = '$status',
            attempts = 8, next_attempt_at = 0, last_status_code = 400, last_error = " . ($error === null ? 'NULL' : self::str($error)) . ', created_at = ' . (time() - $age));
    }

    /** @return array<string, mixed> */
    private static function deliveryRow(int $id): array
    {
        return self::$db->query("SELECT status, attempts, last_error FROM 202_destination_deliveries WHERE delivery_id = $id")->fetch_assoc();
    }

    public function testAnEditSavesWhatTheFormShowsAndKeepsTheSecretLeftBlank(): void
    {
        $form = self::editForm(self::page(), $this->ga4);
        self::assertStringContainsString('value="G-ABC123"', $form, 'the stored settings are offered');
        self::assertStringNotContainsString(self::SECRET, $form, 'the secret is never shown');
        self::assertStringNotContainsString(' open', substr($form, 0, 80), 'closed until asked for');

        self::page([], ['destination_id' => (string) $this->ga4] + self::ga4Form([
            'name' => 'Store GA4 (EU)', 'measurement_id' => 'G-NEW4567', 'event_name' => 'order_paid', 'client_id_source' => 'c2', 'user_id_source' => 'customer',
            'param_name' => ['source', ''], 'param_field' => ['utm_source', ''],
            'all_campaigns' => '', 'campaign_ids' => [(string) self::CAMPAIGN], 'send_user_data' => '1', 'ad_user_data' => 'granted', 'ad_personalization' => 'denied',
        ]));
        $saved = self::store()->get(self::USER, $this->ga4);
        self::assertSame('Store GA4 (EU)', $saved['name']);
        self::assertSame([
            'measurement_id' => 'G-NEW4567', 'event_name' => 'order_paid', 'client_id_source' => 'c2', 'user_id_source' => 'customer', 'params' => ['source' => 'utm_source'],
            'campaign_ids' => [self::CAMPAIGN], 'send_user_data' => true, 'consent' => ['ad_user_data' => 'granted', 'ad_personalization' => 'denied'],
        ], $saved['settings']);
        self::assertSame(self::SECRET, self::store()->forSending(self::USER, $this->ga4)['credentials']['api_secret'], 'a blank secret keeps the stored one');

        $form = self::editForm(self::page(), $this->ga4);
        self::assertStringContainsString('<option value="' . self::CAMPAIGN . '" selected>Online store › Running shoes</option>', $form);
        self::assertStringContainsString('<option value="' . self::CAMPAIGN_B . '">Online store › Trail packs</option>', $form);

        // A typed secret replaces it; a row set to Not sent removes its parameter.
        self::page([], ['destination_id' => (string) $this->ga4] + self::ga4Form(['api_secret' => 'A-new-secret-9', 'param_name' => ['source', ''], 'param_field' => ['', '']]));
        self::assertSame('A-new-secret-9', self::store()->forSending(self::USER, $this->ga4)['credentials']['api_secret']);
        self::assertSame([[], []], [self::store()->get(self::USER, $this->ga4)['settings']['params'], self::store()->get(self::USER, $this->ga4)['settings']['campaign_ids']]);
    }

    public function testARefusedEditSavesNothingAndIsShownInItsOwnForm(): void
    {
        $html = self::page([], ['destination_id' => (string) $this->ga4] + self::ga4Form(['name' => 'Typed name', 'measurement_id' => 'UA-12345']));
        $form = self::editForm($html, $this->ga4);
        self::assertStringContainsString(' open', substr($form, 0, 80), 'the refused form is open, where the page lands');
        self::assertStringContainsString('measurement_id must be', $form);
        self::assertStringContainsString('value="Typed name"', $form, 'what was typed is kept');
        self::assertStringNotContainsString('Typed name', self::editForm($html, $this->ads), 'and only in the form that was posted');
        self::assertSame(['Store GA4', 'G-ABC123'], [self::store()->get(self::USER, $this->ga4)['name'], self::store()->get(self::USER, $this->ga4)['settings']['measurement_id']]);

        $html = self::page([], ['destination_id' => (string) $this->ga4] + self::ga4Form(['all_campaigns' => '']));
        self::assertStringContainsString('Pick the campaigns it sends, or tick Every campaign.', self::editForm($html, $this->ga4), 'no campaign is not read as every campaign');

        self::assertStringContainsString('not one of yours', self::page([], ['destination_id' => (string) $this->theirs] + self::ga4Form(['name' => 'Taken'])));
        self::assertSame('Their GA4', self::store()->get(self::OTHER, $this->theirs)['name']);

        self::assertStringContainsString('security token did not match', self::page([], ['destination_id' => (string) $this->ga4] + self::ga4Form(['name' => 'No token']), false));
        self::assertSame('Store GA4', self::store()->get(self::USER, $this->ga4)['name']);
    }

    public function testAGoogleAdsEditSetsAndRemovesPerCampaignActionsAndKeepsItsCredentials(): void
    {
        $post = [
            'action' => 'update', 'destination_id' => (string) $this->ads, 'name' => 'Search', 'customer_id' => '111-222-3333', 'login_customer_id' => '', 'conversion_action' => '987654',
            'cca_campaign' => [(string) self::CAMPAIGN, ''], 'cca_action' => ['555001', ''], 'all_campaigns' => '1', 'ad_user_data' => '', 'ad_personalization' => '',
            'developer_token' => '', 'oauth_client_id' => '', 'oauth_client_secret' => '',
        ];
        self::page([], $post);
        $saved = self::store()->get(self::USER, $this->ads);
        self::assertSame(['1112223333', [self::CAMPAIGN => '555001']], [$saved['settings']['customer_id'], $saved['settings']['campaign_conversion_actions']]);
        self::assertSame(self::ADS, self::store()->forSending(self::USER, $this->ads)['credentials'], 'three blank credentials keep the stored three');
        self::assertStringContainsString('value="555001"', self::editForm(self::page(), $this->ads));

        self::page([], ['developer_token' => 'devTOKEN_replaced99', 'cca_action' => ['', '']] + $post);
        self::assertSame(['developer_token' => 'devTOKEN_replaced99'] + self::ADS, self::store()->forSending(self::USER, $this->ads)['credentials'], 'one typed credential replaces that one');
        self::assertArrayNotHasKey('campaign_conversion_actions', self::store()->get(self::USER, $this->ads)['settings'], 'a cleared action removes the campaign\'s row');
    }

    public function testAChatGptAdsDestinationIsAddedListedAndEditedOnThePage(): void
    {
        $key = 'oai-capi-Key_0123456789abcdef';
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN (990091, 990092)');
        self::q("SET SESSION sql_mode=''");
        self::q('INSERT INTO 202_ppc_accounts SET ppc_account_id = 990091, user_id = ' . self::USER . ", ppc_network_id = 0, ppc_account_name = 'ChatGPT', ppc_account_deleted = 0");
        self::q('INSERT INTO 202_ppc_accounts SET ppc_account_id = 990092, user_id = ' . self::OTHER . ", ppc_network_id = 0, ppc_account_name = 'Theirs', ppc_account_deleted = 0");
        self::q(self::$strict);
        try {
            $html = self::page();
            self::assertStringContainsString('Add ChatGPT Ads', $html);
            self::assertStringContainsString('<option value="990091">ChatGPT</option>', $html, 'the account\'s own traffic sources are offered');
            self::assertStringNotContainsString('Theirs', $html);

            $add = ['action' => 'create_openai_ads', 'oai_name' => 'ChatGPT Ads', 'oai_pixel_id' => 'px_4821', 'oai_conversions_api_key' => $key,
                'oai_event_type' => 'order_created', 'oai_custom_event_name' => '', 'oai_scope' => 'oppref', 'oai_ppc_account_id' => ''];
            self::page([], ['oai_ppc_account_id' => '990092', 'oai_scope' => 'source'] + $add);
            self::assertSame(2, count(self::store()->list(self::USER)), 'another account\'s traffic source is refused, nothing made');

            self::page([], $add);
            $made = array_values(array_filter(self::store()->list(self::USER), static fn (array $d): bool => $d['type'] === 'openai_ads'));
            self::assertCount(1, $made);
            $id = (int) $made[0]['destination_id'];
            self::assertSame(['pixel_id' => 'px_4821', 'event_type' => 'order_created', 'scope' => 'oppref', 'event_types' => [], 'campaign_ids' => [], 'send_user_data' => false, 'consent' => []], $made[0]['settings']);
            self::assertSame($key, self::store()->forSending(self::USER, $id)['credentials']['conversions_api_key']);

            $html = self::page();
            self::assertStringContainsString('ChatGPT Ads pixel px_4821', $html, 'the list names its pixel');
            $form = self::editForm($html, $id);
            self::assertStringNotContainsString($key, $html, 'the key is never shown');
            self::assertStringNotContainsString('name="ad_user_data"', $form, 'no consent fields for a platform that takes none');

            self::page([], [
                'action' => 'update', 'destination_id' => (string) $id, 'name' => 'ChatGPT Ads', 'pixel_id' => 'px_4821', 'conversions_api_key' => '',
                'event_type' => 'trial_started', 'custom_event_name' => '', 'scope' => 'source', 'ppc_account_id' => '990091',
                'et_campaign' => [(string) self::CAMPAIGN, ''], 'et_type' => ['lead_created', ''], 'all_campaigns' => '1', 'send_user_data' => '1',
            ]);
            $saved = self::store()->get(self::USER, $id)['settings'];
            self::assertSame(['trial_started', 'source', 990091, [self::CAMPAIGN => 'lead_created'], true], [$saved['event_type'], $saved['scope'], $saved['ppc_account_id'], $saved['event_types'], $saved['send_user_data']]);
            self::assertSame($key, self::store()->forSending(self::USER, $id)['credentials']['conversions_api_key'], 'a blank key keeps the stored one');
        } finally {
            self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN (990091, 990092)');
        }
    }

    /** Start the stand-in Advertiser API once, point the page's process at it and put its account back. */
    private function openAi(): void
    {
        if (self::$openAi === null) {
            $unique = getmypid() . '-' . bin2hex(random_bytes(3));
            self::$openAiTls = sys_get_temp_dir() . '/p202-fake-openai-page-' . $unique;
            $command = ['python3', dirname(__DIR__) . '/fixtures/openai-ads/fake_openai_ads.py', '--port', '0',
                '--tls-dir', self::$openAiTls, '--api-key', self::AD_KEY];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process) || preg_match('/^READY (\d+)/', (string) fgets($pipes[1]), $m) !== 1) {
                self::markTestSkipped('The fake Advertiser API did not start (python3 and openssl are needed).');
            }
            self::$openAi = $process;
            self::$openAiOrigin = 'https://127.0.0.1:' . $m[1];
        }
        // page() hands this process's environment to the page's.
        putenv(AdvertiserApi::ENV_ENDPOINT . '=' . self::$openAiOrigin);
        putenv(AdvertiserApi::ENV_CA_FILE . '=' . self::$openAiTls . '/cert.pem');
        self::openAiControl('reset', []);
    }

    /** @param array<string, mixed> $body */
    private static function openAiControl(string $what, array $body): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'content' => (string) json_encode((object) $body),
                'header' => 'Content-Type: application/json',
            ],
            'ssl' => ['cafile' => self::$openAiTls . '/cert.pem', 'verify_peer' => true],
        ]);
        $answer = file_get_contents(self::$openAiOrigin . '/control/' . $what, false, $context);
        self::assertNotFalse($answer, 'the stand-in took /control/' . $what);
    }

    /** A connection made by the page's own Connect form; its id. */
    private function connected(): int
    {
        $this->openAi();
        self::page([], ['action' => 'ad_connect', 'api_key' => self::AD_KEY, 'ppc_account_id' => '']);
        $found = self::$db->query('SELECT connection_id FROM 202_ad_connections WHERE user_id = ' . self::USER);
        $rows = $found->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, 'the Connect form made the connection');

        return (int) $rows[0]['connection_id'];
    }

    /** The stored (sealed) credentials of a connection: what a write changes and a refusal leaves. */
    private static function sealed(int $connection): string
    {
        $found = self::$db->query('SELECT credentials FROM 202_ad_connections WHERE connection_id = ' . $connection);

        return (string) $found->fetch_row()[0];
    }

    /** One connection's block of the panel, as the page printed it. */
    private static function connectionBlock(string $html, int $connection): string
    {
        $from = strpos($html, '<div class="mb-4" id="ac' . $connection . '">');
        self::assertNotFalse($from, 'the connection has a block');
        $to = strpos($html, 'value="ad_connect"', $from);
        self::assertNotFalse($to);

        return substr($html, $from, $to - $from);
    }

    /** The Replace key disclosure of a connection's block, from its opening tag. */
    private static function replaceKeyForm(string $html, int $connection): string
    {
        $block = self::connectionBlock($html, $connection);
        $at = strpos($block, '<summary>Replace key');
        self::assertNotFalse($at, 'the block offers Replace key');
        $from = (int) strrpos(substr($block, 0, $at), '<details');

        return substr($block, $from);
    }

    /** @return array<string, string> the Replace key form's post */
    private static function replaceKeyPost(int $connection, string $key = self::AD_KEY): array
    {
        return ['action' => 'ad_replace_key', 'connection_id' => (string) $connection, 'api_key' => $key];
    }

    public function testAnAdvertiserKeyIsReplacedOnThePage(): void
    {
        $id = $this->connected();
        $closed = '<details class="p202-disclosure mt-3">';
        $open = '<details class="p202-disclosure mt-3" open>';
        $form = self::replaceKeyForm(self::page(), $id);
        self::assertStringStartsWith($closed, $form, 'closed while the stored key works');
        self::assertStringContainsString('name="token"', $form);
        self::assertStringContainsString('<input type="hidden" name="action" value="ad_replace_key">', $form);
        self::assertStringContainsString('<input type="hidden" name="connection_id" value="' . $id . '">', $form);
        $input = '<input type="password" class="form-control" id="ac' . $id . '_api_key" name="api_key">';
        self::assertStringContainsString($input, $form, 'typed into a password field with no value');

        // Credentials that no longer open: the block says so, and the form that mends it is open.
        self::q("UPDATE 202_ad_connections SET credentials = 'no-longer-opens' WHERE connection_id = $id");
        $html = self::page();
        self::assertStringContainsString('credentials unreadable', self::connectionBlock($html, $id));
        self::assertStringStartsWith($open, self::replaceKeyForm($html, $id));

        self::assertSame('', trim(self::page([], self::replaceKeyPost($id))), 'a replaced key redirects');
        $stored = (new AdConnectionStore(new Connection(self::requireScratch())))->forCalling(self::USER, $id);
        self::assertSame(self::AD_KEY, $stored['credentials']['advertiser_api_key'], 'the new key is the stored one');
        $html = self::page(['done' => 'ad_key_replaced']);
        self::assertStringContainsString('Advertiser API key replaced', $html);
        self::assertStringContainsString('p202-pill--accent">connected<', self::connectionBlock($html, $id));
        self::assertStringNotContainsString(self::AD_KEY, $html, 'the key is never shown');

        // A working key replaced by itself is sealed again: the stored bytes change, which no refusal may do.
        $before = self::sealed($id);
        self::page([], self::replaceKeyPost($id));
        self::assertNotSame($before, self::sealed($id));
    }

    public function testAKeyThatIsNotThisConnectionsIsRefusedInItsBlock(): void
    {
        $id = $this->connected();
        $before = self::sealed($id);
        $refused = '<div class="invalid-feedback d-block">';

        self::openAiControl('account', ['id' => 'adacct_999']);
        $html = self::page([], self::replaceKeyPost($id));
        $form = self::replaceKeyForm($html, $id);
        $open = '<details class="p202-disclosure mt-3" open>';
        self::assertStringStartsWith($open, $form, 'the refused form is open, where the page lands');
        $sentence = 'That key reaches ad account adacct_999, not adacct_123, which this connection is for';
        self::assertStringContainsString($refused . $sentence, $form);
        self::assertStringNotContainsString(self::AD_KEY, $html, 'the refused key is not printed back');
        self::assertStringNotContainsString('needs attention', $html, 'no banner for a sentence somewhere else');
        self::assertSame($before, self::sealed($id), 'nothing stored');
        self::openAiControl('reset', []);

        $html = self::page([], self::replaceKeyPost($id, 'sk-ads-live-not-the-key-9'));
        self::assertStringContainsString($refused, self::replaceKeyForm($html, $id), 'a key OpenAI refuses');
        $html = self::page([], self::replaceKeyPost($id, ''));
        self::assertStringContainsString($refused . 'api_key is required', self::replaceKeyForm($html, $id));
        self::assertSame($before, self::sealed($id));

        // Without the session's token nothing is stored.
        $html = self::page([], self::replaceKeyPost($id), false);
        self::assertStringContainsString('security token did not match', $html);
        self::assertSame($before, self::sealed($id), 'no token, no write');

        // Another account's connection.
        self::q('UPDATE 202_ad_connections SET user_id = ' . self::OTHER . " WHERE connection_id = $id");
        $html = self::page([], self::replaceKeyPost($id));
        self::assertStringContainsString('That connection is not one of yours', $html);
        self::assertSame($before, self::sealed($id), 'another account\'s key is not replaced');
    }

    public function testAFailedCheckOrSetupSaysWhyInTheConnectionsBlock(): void
    {
        $id = $this->connected();
        $open = '<details class="p202-disclosure mt-3" open>';
        $check = ['action' => 'ad_check', 'connection_id' => (string) $id];
        $setup = ['action' => 'ad_setup', 'connection_id' => (string) $id, 'steps' => ['pixel']];

        // The stored key now reaches another ad account: the check says so on the page that posted it.
        self::openAiControl('account', ['id' => 'adacct_999']);
        $html = self::page([], $check);
        $block = self::connectionBlock($html, $id);
        $sentence = 'The stored key now reaches ad account adacct_999, not adacct_123';
        self::assertStringContainsString('The check did not pass: ' . $sentence, $block);
        self::assertSame(1, substr_count($block, 'The stored key now reaches'), 'said once');
        self::assertStringContainsString('>key refused<', $block);
        self::assertStringStartsWith($open, self::replaceKeyForm($html, $id));
        self::assertStringNotContainsString('Nothing was saved', $html, 'a check saves nothing by design');
        self::assertStringNotContainsString('accepted the stored key', $html);
        $later = self::connectionBlock(self::page(), $id);
        self::assertStringContainsString('Last check: ' . $sentence, $later, 'and it is kept on the connection');
        self::openAiControl('reset', []);

        // Credentials that do not open: check and setup each say the store's sentence and where to mend it.
        self::q("UPDATE 202_ad_connections SET credentials = 'no-longer-opens' WHERE connection_id = $id");
        $mend = '[^<]+p202 ad-connection update --api-key-file\)\. On this page: Replace key, below\.</div>~';
        foreach (['The check did not pass: ' => $check, 'Setup did not run: ' => $setup] as $says => $post) {
            $html = self::page([], $post);
            $block = self::connectionBlock($html, $id);
            self::assertMatchesRegularExpression('~<div class="p202-flash__body">' . $says . $mend, $block);
            self::assertStringNotContainsString('needs attention', $html, $says . 'no banner for a sentence nowhere');
            self::assertStringNotContainsString('Nothing was saved', $html, $says);
            self::assertStringStartsWith($open, self::replaceKeyForm($html, $id), $says);
        }

        // A refused setup option has no field of its own to sit under: said in the block too.
        self::page([], self::replaceKeyPost($id));
        $block = self::connectionBlock(self::page([], ['source_id' => 'clidsrc_901'] + $setup), $id);
        self::assertStringContainsString('Setup did not run: source_id goes with pixel_id', $block);
    }

    /** OpenAI not reached is said under the connection as that, never as a bad key and never as a failed page. */
    public function testOpenAiNotReachedIsSaidAsThatUnderTheConnection(): void
    {
        $id = $this->connected();
        $sql = 'SELECT status, credentials FROM 202_ad_connections WHERE user_id = ' . self::USER
            . ' ORDER BY connection_id';
        $row = static fn (): array => (array) self::$db->query($sql)->fetch_all(MYSQLI_ASSOC);
        $before = $row();
        putenv(AdvertiserApi::ENV_ENDPOINT . '=https://127.0.0.1:1');
        try {
            $posts = [
                'check' => ['action' => 'ad_check', 'connection_id' => (string) $id],
                'setup' => ['action' => 'ad_setup', 'connection_id' => (string) $id, 'steps' => ['pixel']],
                'replace key' => self::replaceKeyPost($id),
            ];
            foreach ($posts as $what => $post) {
                $html = self::page([], $post);
                $block = self::connectionBlock($html, $id);
                self::assertStringContainsString('OpenAI could not be reached, so ', $block, $what);
                self::assertStringContainsString('Try again in a minute', $block, $what);
                self::assertStringNotContainsString('refused that key', $html, $what);
                self::assertStringNotContainsString('That could not be done', $html, $what . ': not a failed page');
                self::assertStringNotContainsString('did not pass: OpenAI', $block, $what . ': its own sentence');
            }
            self::assertSame($before, $row(), 'the status and the stored key are as they were');

            $html = self::page([], ['action' => 'ad_connect', 'api_key' => self::AD_KEY]);
            $said = 'OpenAI could not be reached, so the key was not checked and nothing was saved';
            self::assertStringContainsString($said, $html);
            self::assertStringNotContainsString('needs attention', $html, 'not a field to mend');
            self::assertSame($before, $row(), 'nothing more stored');
        } finally {
            $this->openAi();
        }
    }

    public function testTheCampaignsViewSaysOpenAiWasNotAskedRatherThanFailing(): void
    {
        $id = $this->connected();
        $html = self::page(['ad_campaigns' => (string) $id]);
        self::assertStringContainsString('Spring sale', $html, 'the campaigns read from the stand-in');
        // Done is said from what OpenAI shows after the pause, not from what was asked.
        self::page(['ad_campaigns' => (string) $id], ['action' => 'ad_campaign_pause', 'connection_id' => (string) $id, 'level' => 'campaigns', 'object_id' => 'cmpn_101']);
        // The page redirects; the flash waits in the session for the next page.
        $session = implode('', array_map('file_get_contents', glob(self::$sessions . '/sess_*') ?: []));
        self::assertStringContainsString('cmpn_101 is now paused at OpenAI (it was active).', $session);
        self::openAiControl('reset', []);

        self::openAiControl('answer', ['status' => 503, 'body' => '{"error":{"message":"overloaded"}}']);
        try {
            $html = self::page(['ad_campaigns' => (string) $id, 'preview_pause' => 'cmpn_101']);
            self::assertStringContainsString('The campaigns were not read: Could not list the campaigns: OpenAI answered 503', $html);
            self::assertStringContainsString('Spend was not read: ', $html);
            self::assertStringContainsString('The campaign to pause was not read: ', $html);
            self::assertStringNotContainsString('Spring sale', $html, 'nothing shown as if it were the account');

            $html = self::page(['ad_campaigns' => (string) $id], ['action' => 'ad_campaign_pause', 'connection_id' => (string) $id, 'level' => 'campaigns', 'object_id' => 'cmpn_101']);
            // A pause reads the campaign first: OpenAI not answering that read stops it before anything is sent.
            self::assertStringContainsString('Could not read cmpn_101: OpenAI answered 503', $html);
            self::assertStringNotContainsString('That could not be done', $html, 'OpenAI\'s failure, not the page\'s');
        } finally {
            $this->openAi();
        }
    }

    public function testFailuresAreCountedWithTheirReasonsAndSentAgain(): void
    {
        $failed = $this->delivery($this->ga4, 'failed', 'HTTP 400: the API secret is not valid', 501);
        $this->delivery($this->ga4, 'failed', 'HTTP 400: the API secret is not valid', 502);
        $old = $this->delivery($this->ga4, 'failed', 'HTTP 400: the API secret is not valid', 503, 20 * 86400);
        $withheld = $this->delivery($this->ga4, 'withheld', 'withheld: your privacy setting holds back visitors who may be in Europe', 504);
        $sent = $this->delivery($this->ga4, 'sent', null, 505);
        $foreign = $this->delivery($this->theirs, 'failed', 'theirs', 506, 0, self::OTHER);

        $html = self::page();
        self::assertStringContainsString('2 failed</span>', $html, 'the week\'s failures');
        self::assertStringContainsString('1 withheld</span>', $html);
        self::assertStringContainsString('2 failed: HTTP 400: the API secret is not valid', $html);
        self::assertStringContainsString('1 withheld: your privacy setting holds back visitors who may be in Europe', $html);
        self::assertStringContainsString('Send the 3 failed again', $html, 'the older failure can be sent again too');
        self::assertStringContainsString('?log=' . $this->ga4 . '&amp;status=failed', $html);

        self::page([], ['action' => 'resend_all', 'destination_id' => (string) $this->ga4, 'status' => 'failed']);
        foreach ([$failed, $old] as $id) {
            self::assertSame(['status' => 'pending', 'attempts' => '0', 'last_error' => null], self::deliveryRow($id));
        }
        self::assertSame('withheld', self::deliveryRow($withheld)['status'], 'only the status asked for');
        self::assertSame('sent', self::deliveryRow($sent)['status']);
        self::assertStringNotContainsString('failed again', self::page(), 'nothing failed is left to send again');

        // One row, from the log.
        self::page(['log' => (string) $this->ga4], ['action' => 'resend', 'destination_id' => (string) $this->ga4, 'delivery_id' => (string) $withheld]);
        self::assertSame('pending', self::deliveryRow($withheld)['status']);
        self::assertStringContainsString('not a failed or withheld conversion', self::page(['log' => (string) $this->ga4], ['action' => 'resend', 'destination_id' => (string) $this->ga4, 'delivery_id' => (string) $sent]));
        self::assertSame('sent', self::deliveryRow($sent)['status'], 'a sent conversion is never sent twice');
        self::page([], ['action' => 'resend', 'destination_id' => (string) $this->ga4, 'delivery_id' => (string) $foreign]);
        self::page([], ['action' => 'resend_all', 'destination_id' => (string) $this->theirs, 'status' => 'failed']);
        self::assertSame('failed', self::deliveryRow($foreign)['status'], 'another account\'s delivery stays as it was');
    }

    public function testTheLogListsADestinationsDeliveriesByStatus(): void
    {
        $failed = $this->delivery($this->ga4, 'failed', 'HTTP 400: refused', 601);
        $this->delivery($this->ga4, 'sent', null, 602);
        $this->delivery($this->ads, 'failed', 'another destination', 603);

        $html = self::page(['log' => (string) $this->ga4, 'status' => 'failed']);
        self::assertStringContainsString('Delivery log: Store GA4', $html);
        self::assertStringContainsString('HTTP 400: refused', $html);
        self::assertStringContainsString('name="delivery_id" value="' . $failed . '"', $html, 'a failed row has its send-again button');
        self::assertStringNotContainsString('another destination', $html);
        self::assertStringNotContainsString('>602<', $html, 'the sent row is not a failure');
        self::assertStringContainsString('>602<', self::page(['log' => (string) $this->ga4]));

        $html = self::page(['log' => (string) $this->theirs]);
        self::assertStringContainsString('not one of yours', $html);
        self::assertStringNotContainsString('Delivery log', $html);
    }

    public function testEarlierConversionsAreQueuedFromThePage(): void
    {
        self::q("SET SESSION sql_mode=''");
        $conv = self::insert('INSERT INTO 202_conversion_logs SET click_id = 990091001, campaign_id = ' . self::CAMPAIGN . ', click_payout = 20, user_id = ' . self::USER
            . ', click_time = ' . (time() - 7200) . ', conv_time = ' . (time() - 3600) . ", time_difference = '', pixel_type = 0, user_agent = '', dedupe_key = 'tx:ORD-9001', match_method = 'click_id'");
        self::q(self::$strict);
        self::page([], ['action' => 'backfill', 'destination_id' => (string) $this->ga4, 'days' => '3']);
        $rows = self::$db->query('SELECT conv_id, status, attempts FROM 202_destination_deliveries WHERE destination_id = ' . $this->ga4)->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['conv_id' => (string) $conv, 'status' => 'pending', 'attempts' => '0']], $rows);

        self::assertStringContainsString('days must be 1 to 3', self::page([], ['action' => 'backfill', 'destination_id' => (string) $this->ga4, 'days' => '30']), 'GA4 takes nothing older than 72 hours');
        self::assertStringContainsString('not one of yours', self::page([], ['action' => 'backfill', 'destination_id' => (string) $this->theirs, 'days' => '1']));
        self::assertSame('0', self::$db->query('SELECT COUNT(*) FROM 202_destination_deliveries WHERE destination_id = ' . $this->theirs)->fetch_row()[0]);
    }

    public function testTheConsentWordingFollowsThePrivacySetting(): void
    {
        $html = self::page();
        self::assertStringContainsString('holds back every visitor who may be in the EEA or UK, whatever is stated here', $html, 'eu, the default: consent does not send them');
        self::assertStringContainsString('202-account/account.php#user_pref_privacy', $html);
        self::assertStringNotContainsString('Both consent signals are Granted, but', $html, 'no warning for a destination that states no consent');

        self::store()->update(self::USER, $this->ga4, ['settings' => ['measurement_id' => 'G-ABC123', 'consent' => ['ad_user_data' => 'granted', 'ad_personalization' => 'granted']]], time());
        self::assertSame(1, substr_count(self::page(), 'Both consent signals are Granted, but'), 'the destination stating both granted is told they are still withheld');

        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'disabled' WHERE user_id = " . self::USER);
        $html = self::page();
        self::assertStringContainsString('is sent only when both signals are Granted', $html);
        self::assertStringNotContainsString('Both consent signals are Granted, but', $html);
        self::assertStringNotContainsString('whatever is stated here', $html);

        self::q("UPDATE 202_users_pref SET user_pref_privacy = 'all' WHERE user_id = " . self::USER);
        self::assertSame(2, substr_count(self::page(), 'Nothing is sent: your privacy setting (Enabled for All Traffic) holds back every visitor.'), 'said under each destination');
    }

    public function testAQuietDestinationsRowsShowBesideABusyOneAndWhatIsQueuedSaysHowLong(): void
    {
        $this->delivery($this->ads, 'sent', null, 700);
        $values = [];
        for ($conv = 1000; $conv < 1450; $conv++) {
            $values[] = '(' . self::USER . ', ' . $this->ga4 . ", $conv, 'conversion', 'pending', 0, 0, " . time() . ')';
        }
        self::q('INSERT INTO 202_destination_deliveries (user_id, destination_id, conv_id, kind, status, attempts, next_attempt_at, created_at) VALUES ' . implode(', ', $values));

        $html = self::page();
        self::assertStringContainsString('<td>700</td>', $html, 'the quiet destination\'s row is listed under it, 450 newer rows of another notwithstanding');
        self::assertStringContainsString('450 still to send: about 3 minutes', $html);
        self::assertSame(1, substr_count($html, 'still to send'), 'only under the destination that has some');

        // Queued from the page: the notice says how many and how long.
        self::q("UPDATE 202_destination_deliveries SET status = 'failed' WHERE destination_id = " . $this->ga4 . ' AND conv_id < 1400');
        self::page([], ['action' => 'resend_all', 'destination_id' => (string) $this->ga4, 'status' => 'failed']);
        $sessions = glob(self::$sessions . '/sess_*') ?: [];
        self::assertCount(1, $sessions);
        self::assertStringContainsString('"queued";i:400', (string) file_get_contents($sessions[0]), 'the count is kept for the page the browser lands on');
    }
}

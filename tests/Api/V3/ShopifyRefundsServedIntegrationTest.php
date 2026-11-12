<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\DataSeeder;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Tests\PaymentNotification\ShopifyAdapterTest;

/**
 * The Shopify refunds routes and the inbound log through api/v3/index.php
 * itself, served by `php -S` over the scratch database, with real API keys
 * of a role that is not an admin's, over rows the real webhook receiver made.
 *
 * @group integration
 */
final class ShopifyRefundsServedIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990541;
    private const OTHER = 990542;
    private const ROLE = 990541;
    private const SETUP_ONLY = 990543;
    private const SETUP_ONLY_ROLE = 990543;
    /** The role that runs the Shopify connection on Account › API Integrations, without Setup or Update. */
    private const INTEGRATIONS_ROLE = 990544;
    /** A role with campaign data and nothing the refunds ask for. */
    private const NEITHER_ROLE = 990545;
    private const CLICK = 99054101;
    private const CAMPAIGN = 990591;
    private const SECRET = 'demo-store-webhook-secret-0041';
    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
        '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks', '202_dataengine',
        '202_click_latest_visits',
    ];

    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';
    private static string $state = '';
    /** @var array<string, string> */
    private static array $keys = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/202-config.php')) {
            self::$db = null; // no config for the served tree: skipped, as without a database

            return;
        }
        self::seed();

        self::$state = sys_get_temp_dir() . '/p202-uo-shopify-state-' . bin2hex(random_bytes(6));
        mkdir(self::$state, 0700);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        self::$log = (string) tempnam(sys_get_temp_dir(), 'p202srv');
        $env = getenv();
        $env['P202_SERVER_STATE_DIR'] = self::$state;
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log !== '') {
            @unlink(self::$log);
        }
        if (self::$state !== '') {
            exec('rm -rf ' . escapeshellarg(self::$state));
        }
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanRows();
        self::recordClick(self::CLICK);
    }

    private static function cleanRows(): void
    {
        $users = self::USER . ', ' . self::OTHER;
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q("DELETE FROM 202_shopify_pending_refunds WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_shopify_refund_transactions WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_inbound_log WHERE endpoint = 'shopify' AND user_id IN (0, $users)");
    }

    private static function cleanUp(): void
    {
        self::cleanRows();
        foreach ([self::USER, self::OTHER, self::SETUP_ONLY] as $user) {
            foreach (['202_api_keys', '202_user_role', '202_aff_campaigns', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $user");
            }
        }
        foreach ([self::ROLE, self::SETUP_ONLY_ROLE, self::INTEGRATIONS_ROLE, self::NEITHER_ROLE] as $role) {
            self::q('DELETE FROM 202_role_permission WHERE role_id = ' . $role);
            self::q('DELETE FROM 202_roles WHERE role_id = ' . $role);
        }
    }

    private static function seed(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/static-endpoint-helpers.php';
        self::cleanUp();
        $seeder = new DataSeeder(self::requireScratch());
        $seeder->seedRoles();
        $seeder->seedPermissions();
        $seeder->seedRolePermissions();
        // A role that opens Setup and Update and may not add users: not an admin.
        self::q('INSERT INTO 202_roles SET role_id = ' . self::ROLE . ", role_name = 'shopify refunds api test'");
        self::q('INSERT INTO 202_role_permission (role_id, permission_id) SELECT ' . self::ROLE . ', permission_id'
            . " FROM 202_permissions WHERE permission_description IN ('access_to_setup_section',"
            . " 'access_to_update_section')");
        self::q('INSERT INTO 202_roles SET role_id = ' . self::SETUP_ONLY_ROLE
            . ", role_name = 'shopify setup only test'");
        self::q('INSERT INTO 202_role_permission (role_id, permission_id) SELECT ' . self::SETUP_ONLY_ROLE
            . ", permission_id FROM 202_permissions WHERE permission_description = 'access_to_setup_section'");
        $roles = [
            self::INTEGRATIONS_ROLE => 'access_to_api_integrations',
            self::NEITHER_ROLE => 'access_to_campaign_data',
        ];
        foreach ($roles as $role => $permission) {
            self::q("INSERT INTO 202_roles SET role_id = $role, role_name = 'shopify refunds role $role'");
            self::q("INSERT INTO 202_role_permission (role_id, permission_id) SELECT $role, permission_id"
                . " FROM 202_permissions WHERE permission_description = '$permission'");
        }
        foreach ([self::USER => 'mine', self::OTHER => 'theirs', self::SETUP_ONLY => 'setup_only'] as $user => $name) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
            self::q("INSERT INTO 202_user_role (user_id, role_id) VALUES ($user, "
                . ($user === self::SETUP_ONLY ? self::SETUP_ONLY_ROLE : self::ROLE) . ')');
            $key = bin2hex(random_bytes(32));
            self::q("INSERT INTO 202_api_keys (user_id, api_key, scope, created_at) VALUES ($user, "
                . self::str($key) . ", '', " . time() . ')');
            self::$keys[$name] = $key;
        }
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . ' = ' . self::str(self::SECRET)
            . ' WHERE user_id = ' . self::USER);
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ", aff_network_id = 1, aff_campaign_name = 'Demo coffee store',"
            . " aff_campaign_url = 'https://demo-coffee.example/products/beans',"
            . ' aff_campaign_payout = 0, aff_campaign_time = 1, aff_campaign_foreign_payout = 0,'
            . " payout_mode = 'accumulate'");
    }

    private static function recordClick(int $click): void
    {
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(new ClickRecord(
            clickId: $click,
            userId: self::USER,
            clickTime: strtotime('2026-11-01T14:00:00Z'),
            affCampaignId: self::CAMPAIGN,
            clickPayout: '0',
            recordsCampaignRows: false,
        ));
    }

    /** @param array<string, mixed> $body */
    private static function deliver(string $topic, array $body, string $secret = self::SECRET): void
    {
        $db = self::requireScratch();
        $previous = (string) ini_get('error_log');
        $sink = (string) tempnam(sys_get_temp_dir(), 'p202-uo-shopify-served-log');
        ini_set('error_log', $sink);
        try {
            (new Receiver(new ShopifyAdapter($db), $db))->receive(ShopifyAdapterTest::webhook($topic, $body, $secret));
        } finally {
            ini_set('error_log', $previous);
            @unlink($sink);
        }
    }

    private static function order(int $click = self::CLICK): void
    {
        self::deliver('orders/paid', ShopifyAdapterTest::order(['line_items' => [[
            'id' => 13001, 'title' => 'Single-origin coffee beans, 1 kg', 'quantity' => 1, 'price' => '54.00',
            'properties' => [['name' => '_t202subid', 'value' => (string) $click]],
        ]]]));
    }

    /** A refund of 10 and 5, both pending. */
    private static function waitingRefund(): void
    {
        $transaction = static fn (int $id, string $amount): array
            => ['id' => $id, 'kind' => 'refund', 'status' => 'pending', 'amount' => $amount, 'currency' => 'USD'];
        self::deliver('refunds/create', ShopifyAdapterTest::refund(['id' => 9100001, 'refund_line_items' => [],
            'transactions' => [$transaction(71001, '10.00'), $transaction(71002, '5.00')]]));
    }

    /** @return list<string> */
    private static function payouts(): array
    {
        $result = self::requireScratch()->query('SELECT click_payout FROM 202_conversion_logs WHERE click_id BETWEEN '
            . self::CLICK . ' AND ' . (self::CLICK + 99) . ' AND deleted = 0 ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'click_payout');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>, 2: string} status, decoded body, raw body
     */
    private static function request(
        string $method,
        string $path,
        string $key,
        ?array $body = null,
        array $headers = []
    ): array {
        $ch = curl_init(self::$base . '/api/v3' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                $headers
            )]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode($raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $raw];
    }

    private function context(string $raw): string
    {
        return $raw . "\nserver log:\n" . (string) @file_get_contents(self::$log);
    }

    public function testTheListOffersWhatWasActuallyRefunded(): void
    {
        self::order();
        self::waitingRefund();

        [$status, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
        self::assertSame(200, $status, $this->context($raw));
        self::assertCount(1, $body['data'], $raw);
        $refund = $body['data'][0];
        self::assertSame(['9100001', '5550001234567', 'waiting', null, 'USD', [self::CLICK]], [
            $refund['refund_id'], $refund['order_id'], $refund['state'], $refund['reason'], $refund['currency'],
            $refund['click_ids'],
        ], $raw);
        self::assertEquals(15, $refund['amount'], $raw);
        self::assertTrue($refund['stale'], $raw);
        self::assertFalse($body['transaction_webhook_seen'], $raw);
        self::assertSame(['USD', false], [$body['account_currency'] ?? null, $refund['other_currency'] ?? null], $raw);

        // Shopify says the 5 failed: the 10 is what is still to come back.
        self::deliver('order_transactions/create', ['id' => 71002, 'order_id' => 5550001234567, 'kind' => 'refund',
            'status' => 'failure', 'amount' => '5.00', 'currency' => 'USD']);
        [, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
        self::assertEquals(10, $body['data'][0]['amount'] ?? null, $raw);
        self::assertTrue($body['transaction_webhook_seen'], $raw);

        [$status, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['theirs']);
        self::assertSame([200, []], [$status, $body['data'] ?? null], $raw);
        [$status, , $raw] = self::request('GET', '/conversions/shopify-refunds?limit=5', self::$keys['mine']);
        self::assertSame(422, $status, $raw);
    }

    public function testRecordingReadsItsBodyStrictlyAndRecordsOnce(): void
    {
        self::order();
        self::waitingRefund();
        $path = '/conversions/shopify-refunds/9100001/record';

        foreach (
            [[[], 'amount'], [['amount' => '12abc'], 'amount'], [['amount' => 0], 'amount'],
            [['amount' => '10', 'click_id' => '12abc'], 'click_id'], [['amount' => '10', 'amuont' => '1'], 'amuont'],
            ] as [$payload, $field]
        ) {
            [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], $payload);
            self::assertSame(422, $status, $raw);
            self::assertArrayHasKey($field, $body['field_errors'] ?? [], $raw);
        }
        [$status, , $raw] = self::request('POST', $path, self::$keys['theirs'], ['amount' => '10']);
        self::assertSame(404, $status, $raw);
        $notAnId = '/conversions/shopify-refunds/12abc/record';
        [$status, , $raw] = self::request('POST', $notAnId, self::$keys['mine'], ['amount' => '10']);
        self::assertSame(404, $status, $raw);
        self::assertSame(['59.00000'], self::payouts());

        [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], ['amount' => 10.5]);
        self::assertSame(200, $status, $this->context($raw));
        self::assertSame('reversed', $body['data']['outcome'] ?? null, $raw);
        self::assertIsInt($body['data']['conversion_id'] ?? null, $raw);
        self::assertSame(['59.00000', '-10.50000'], self::payouts());
        [, $log, $raw] = self::request('GET', '/conversions/inbound-log?endpoint=shopify&limit=1', self::$keys['mine']);
        self::assertSame('HAND', $log['data'][0]['method'] ?? null, $raw);
        self::assertStringStartsWith('Recorded by hand from the API. Reversed', $log['data'][0]['reason'] ?? '', $raw);

        [$status, , $raw] = self::request('POST', $path, self::$keys['mine'], ['amount' => '10.5']);
        self::assertSame(404, $status, $raw);
        self::assertSame(['59.00000', '-10.50000'], self::payouts());
    }

    public function testAnOrderOnTwoClicksAsksWhichClick(): void
    {
        self::order();
        self::recordClick(self::CLICK + 1);
        self::order(self::CLICK + 1);
        self::deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));
        $path = '/conversions/shopify-refunds/9100001/record';

        [, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
        self::assertSame('refused', $body['data'][0]['state'] ?? null, $raw);
        self::assertSame([self::CLICK, self::CLICK + 1], $body['data'][0]['click_ids'] ?? null, $raw);

        [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], ['amount' => '20']);
        self::assertSame(422, $status, $raw);
        self::assertStringContainsString((string) (self::CLICK + 1), $body['field_errors']['click_id'] ?? '', $raw);

        $chosen = ['amount' => '20', 'click_id' => self::CLICK + 1];
        [$status, , $raw] = self::request('POST', $path, self::$keys['mine'], $chosen);
        self::assertSame(200, $status, $this->context($raw));
        self::assertSame(['59.00000', '59.00000', '-20.00000'], self::payouts());
    }

    public function testARefundInAnotherCurrencyNeedsTheAmountStatedInTheAccounts(): void
    {
        self::order();
        self::deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => [], 'transactions' => [
            ['id' => 77001, 'kind' => 'refund', 'status' => 'success', 'amount' => '10.00', 'currency' => 'EUR'],
        ]]));
        $path = '/conversions/shopify-refunds/9100001/record';

        [, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
        self::assertSame(['EUR', true, 'USD'], [
            $body['data'][0]['currency'] ?? null, $body['data'][0]['other_currency'] ?? null,
            $body['account_currency'] ?? null,
        ], $raw);

        // The list's own figure sent back, a currency that is not the account's, one that is not a code.
        $refusedBodies = [['amount' => 10], ['amount' => 10, 'currency' => 'EUR'],
            ['amount' => 10, 'currency' => 'dollars']];
        foreach ($refusedBodies as $payload) {
            [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], $payload);
            self::assertSame(422, $status, $raw);
            self::assertArrayHasKey('currency', $body['field_errors'] ?? [], $raw);
        }
        self::assertSame(['59.00000'], self::payouts());

        $converted = ['amount' => '11.60', 'currency' => 'USD'];
        [$status, , $raw] = self::request('POST', $path, self::$keys['mine'], $converted);
        self::assertSame(200, $status, $this->context($raw));
        self::assertSame(['59.00000', '-11.60000'], self::payouts());
    }

    public function testClosingRecordsNothingAndIsTheAccountsOwn(): void
    {
        self::order();
        self::waitingRefund();
        $path = '/conversions/shopify-refunds/9100001/close';

        [$status, , $raw] = self::request('POST', $path, self::$keys['theirs'], []);
        self::assertSame(404, $status, $raw);
        [$status, , $raw] = self::request('POST', $path, self::$keys['mine'], ['amount' => '1']);
        self::assertSame(422, $status, $raw);
        // A role that opens Setup alone reads the list and settles nothing.
        [$status, , $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['setup_only']);
        self::assertSame(200, $status, $raw);
        foreach ([$path, '/conversions/shopify-refunds/9100001/record'] as $write) {
            [$status, , $raw] = self::request('POST', $write, self::$keys['setup_only'], ['amount' => '1']);
            self::assertSame(403, $status, $raw);
        }
        [$status, , $raw] = self::request('POST', $path . '?dry_run=1', self::$keys['mine'], []);
        self::assertSame(422, $status, $raw);

        [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], []);
        self::assertSame(200, $status, $this->context($raw));
        self::assertTrue($body['data']['closed'] ?? null, $raw);
        [$status, , $raw] = self::request('POST', $path, self::$keys['mine'], []);
        self::assertSame(404, $status, $raw);
        [, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
        self::assertSame([], $body['data'] ?? null, $raw);
        self::assertSame(['59.00000'], self::payouts());
    }

    /**
     * RefundAccess, as the routes ask it: the role that runs the Shopify
     * connection sees, records and closes with neither Setup nor Update; a
     * role with neither is refused all three; Setup alone only sees (above).
     */
    public function testTheIntegrationsRoleSettlesAndARoleWithNeitherIsRefused(): void
    {
        self::order();
        self::waitingRefund();
        self::deliver('refunds/create', ShopifyAdapterTest::refund(['id' => 9100002, 'refund_line_items' => [],
            'transactions' => [
                ['id' => 71003, 'kind' => 'refund', 'status' => 'pending', 'amount' => '3.00', 'currency' => 'USD'],
            ]]));
        $as = static function (int $role): void {
            self::q('UPDATE 202_user_role SET role_id = ' . $role . ' WHERE user_id = ' . self::USER);
        };
        try {
            $as(self::NEITHER_ROLE);
            [$status, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
            self::assertSame(403, $status, $raw);
            self::assertStringContainsString("'access_to_api_integrations' permission", $body['message'] ?? '', $raw);
            foreach (['record' => ['amount' => '10'], 'close' => []] as $action => $payload) {
                $path = "/conversions/shopify-refunds/9100001/$action";
                [$status, $body, $raw] = self::request('POST', $path, self::$keys['mine'], $payload);
                self::assertSame(403, $status, $raw);
                $said = $body['message'] ?? '';
                self::assertStringContainsString('may not record or close a Shopify refund', $said, $raw);
            }
            self::assertSame(['59.00000'], self::payouts());

            $as(self::INTEGRATIONS_ROLE);
            [$status, $body, $raw] = self::request('GET', '/conversions/shopify-refunds', self::$keys['mine']);
            self::assertSame(200, $status, $this->context($raw));
            self::assertSame(['9100001', '9100002'], array_column($body['data'], 'refund_id'), $raw);
            $close = '/conversions/shopify-refunds/9100002/close';
            [$status, $body, $raw] = self::request('POST', $close, self::$keys['mine'], []);
            self::assertSame([200, true], [$status, $body['data']['closed'] ?? null], $this->context($raw));
            $record = '/conversions/shopify-refunds/9100001/record';
            [$status, $body, $raw] = self::request('POST', $record, self::$keys['mine'], ['amount' => '10']);
            self::assertSame([200, 'reversed'], [$status, $body['data']['outcome'] ?? null], $this->context($raw));
            self::assertSame(['59.00000', '-10.00000'], self::payouts());
            // The rest of the Inbound Log stays Setup's.
            [$status, , $raw] = self::request('GET', '/conversions/inbound-log', self::$keys['mine']);
            self::assertSame(403, $status, $raw);
        } finally {
            $as(self::ROLE);
        }
    }

    public function testTheInboundLogShowsAnAccountWithASecretTheUnverifiedShopifyWebhooks(): void
    {
        self::deliver('orders/paid', ShopifyAdapterTest::order(), 'a-secret-the-store-does-not-have');

        [$status, $body, $raw] = self::request('GET', '/conversions/inbound-log?endpoint=shopify', self::$keys['mine']);
        self::assertSame(200, $status, $this->context($raw));
        self::assertCount(1, $body['data'], $raw);
        self::assertSame(['unverified', null, null], [
            $body['data'][0]['outcome'], $body['data'][0]['user_id'], $body['data'][0]['fields'],
        ], $raw);

        // An account that stores no Shopify secret is not shown them.
        $log = '/conversions/inbound-log?endpoint=shopify';
        [$status, $body, $raw] = self::request('GET', $log, self::$keys['theirs']);
        self::assertSame([200, []], [$status, $body['data'] ?? null], $raw);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * LTV Settings (tracking202/ajax/ltv_settings.php) reads what its forms and
 * buttons post exactly, and refuses anything else in its own words. Delete
 * read `webhook_id` with (int), so `12abc` deleted webhook 12: one of the
 * account's own, and not the one named (CLAUDE.md #4). The page runs as the
 * account in a process of its own (tests/Setup/fixtures/page-runner.php), as
 * the web server runs it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class LtvSettingsPostedValuesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990960;
    private const HOOK = 96012;
    private const DEAD_HOOK = 96014;
    private const FIELD = 96021;
    private const INTEGRATION = 96031;

    /** What an id posted by a row's button must never be read as. */
    private const NOT_IDS = ['abc', '0', '-1', '', ' %d', '%d ', '%d.0', '0%d', '+%d', '%dabc', ['%d']];

    private const CPARAM_REFUSED = 'Customer c-param must be Off or c1–c4.';
    private const REQUIRED_REFUSED = 'Required is ticked or left clear; the form sent something else.';

    private const DEAD_HOOK_IS = 'webhook_id = ' . self::DEAD_HOOK . ' AND status = ';

    private const LTV_TABLES = [
        '202_ltv_webhook_deliveries', '202_ltv_webhooks', '202_customer_fields', '202_ltv_integrations',
    ];

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner' WHERE user_id = " . self::USER);
        self::$sessions = sys_get_temp_dir() . '/p202-ltv-settings-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $u = self::USER;
        foreach (self::LTV_TABLES as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        self::q("UPDATE 202_users_pref SET user_ltv_customer_cparam = 0 WHERE user_id = $u");
        $t = time();
        foreach ([self::HOOK => 'active', self::DEAD_HOOK => 'dead'] as $id => $status) {
            self::row('202_ltv_webhooks', [
                'webhook_id' => $id, 'user_id' => $u, 'webhook_url' => 'https://hooks.example/' . $id,
                'webhook_secret' => 's' . $id, 'subscribed_events' => '', 'status' => $status,
                'created_at' => $t, 'updated_at' => $t,
            ]);
        }
        self::row('202_customer_fields', [
            'field_id' => self::FIELD, 'user_id' => $u, 'field_key' => 'tier', 'label' => 'Tier',
            'field_type' => 'text', 'created_at' => $t, 'updated_at' => $t,
        ]);
        self::row('202_ltv_integrations', [
            'integration_id' => self::INTEGRATION, 'user_id' => $u, 'provider' => 'shopify', 'name' => 'Shop',
            'created_at' => $t, 'updated_at' => $t,
        ]);
    }

    public function testDeleteReadsTheWebhookIdExactly(): void
    {
        $this->assertEachNotIdIsRefused(
            'delete_webhook',
            'webhook_id',
            self::HOOK,
            'Pick the webhook to delete from the list.',
            fn (): bool => self::rows('202_ltv_webhooks', 'webhook_id = ' . self::HOOK) === 1
        );

        $page = self::post(['action' => 'delete_webhook', 'webhook_id' => (string) self::HOOK]);
        self::assertStringContainsString('Webhook deleted.', $page);
        self::assertSame(0, self::rows('202_ltv_webhooks', 'webhook_id = ' . self::HOOK), $page);
        self::assertSame(1, self::rows('202_ltv_webhooks', 'webhook_id = ' . self::DEAD_HOOK), 'only the one named');
    }

    public function testReviveReadsTheWebhookIdAsDeleteDoes(): void
    {
        $this->assertEachNotIdIsRefused(
            'revive_webhook',
            'webhook_id',
            self::DEAD_HOOK,
            'Pick the webhook to revive from the list.',
            fn (): bool => self::rows('202_ltv_webhooks', self::DEAD_HOOK_IS . "'dead'") === 1
        );

        $page = self::post(['action' => 'revive_webhook', 'webhook_id' => (string) self::DEAD_HOOK]);
        self::assertStringContainsString('Webhook #' . self::DEAD_HOOK . ' is active again', $page);
        self::assertSame(1, self::rows('202_ltv_webhooks', self::DEAD_HOOK_IS . "'active'"), $page);
    }

    public function testAFieldAndAnIntegrationAreDeletedOnlyByTheirExactIds(): void
    {
        $this->assertEachNotIdIsRefused(
            'delete_field',
            'field_id',
            self::FIELD,
            'Pick the field to delete from the list.',
            fn (): bool => self::rows('202_customer_fields', 'field_id = ' . self::FIELD) === 1
        );
        $this->assertEachNotIdIsRefused(
            'delete_integration',
            'integration_id',
            self::INTEGRATION,
            'Pick the integration to delete from the list.',
            fn (): bool => self::rows('202_ltv_integrations', 'integration_id = ' . self::INTEGRATION) === 1
        );

        self::assertStringContainsString(
            'Custom field and all its stored values deleted.',
            self::post(['action' => 'delete_field', 'field_id' => (string) self::FIELD])
        );
        self::assertSame(0, self::rows('202_customer_fields', 'field_id = ' . self::FIELD));
        self::assertStringContainsString(
            'Integration deleted.',
            self::post(['action' => 'delete_integration', 'integration_id' => (string) self::INTEGRATION])
        );
        self::assertSame(0, self::rows('202_ltv_integrations', 'integration_id = ' . self::INTEGRATION));
    }

    public function testTheDeliveryLogIsShownOnlyForTheWebhookNamed(): void
    {
        $page = self::post(['show_deliveries' => self::HOOK . 'abc']);
        self::assertStringContainsString('Pick the webhook whose log to show from the list.', $page);
        self::assertStringNotContainsString('Delivery Log', $page, 'no log is shown for an id nobody named');

        $page = self::post(['show_deliveries' => (string) self::HOOK]);
        self::assertStringContainsString('Delivery Log', $page);
        self::assertStringContainsString('webhook #' . self::HOOK . ', most recent 25', $page);
        self::assertStringNotContainsString('Pick the webhook', $page);

        self::assertStringNotContainsString('Pick the webhook', self::post([]), 'no log asked for is no refusal');
    }

    public function testTheCustomerCparamIsOneOfTheMenusValues(): void
    {
        $prefs = ['action' => 'save_prefs', 'p13n_fields' => '', 'rec_fatigue' => ''];
        foreach (\Prosper202\Ltv\MysqlEngagementRepository::DEFAULT_SCORE_WEIGHTS as $component => $weight) {
            $prefs['weight_' . $component] = (string) $weight;
        }
        foreach (['1abc', '5', '-1', '', ' 1', '01', '1.0'] as $cparam) {
            $page = self::post(['cparam' => $cparam] + $prefs);
            self::assertStringContainsString(self::CPARAM_REFUSED, $page, var_export($cparam, true));
            self::assertSame('0', self::cparam(), var_export($cparam, true) . ' changed the stored c-param');
        }
        $missing = $prefs;
        self::assertStringContainsString(self::CPARAM_REFUSED, self::post($missing), 'absent is not Off');

        $page = self::post(['cparam' => '2'] + $prefs);
        self::assertStringContainsString('Settings saved.', $page);
        self::assertSame('2', self::cparam());
    }

    public function testAFieldIsRequiredOnlyWhenItsBoxIsTicked(): void
    {
        $field = ['action' => 'add_field', 'field_label' => 'Plan', 'field_type' => 'text', 'field_options' => ''];
        foreach (['false', '0', 'on', '', ['1']] as $required) {
            $page = self::post(['field_key' => 'plan', 'field_required' => $required] + $field);
            self::assertStringContainsString(self::REQUIRED_REFUSED, $page, var_export($required, true));
            self::assertSame(0, self::rows('202_customer_fields', "field_key = 'plan'"), var_export($required, true));
        }

        self::assertStringContainsString('Custom field created.', self::post(['field_key' => 'plan'] + $field));
        $page = self::post(['field_key' => 'tier2', 'field_required' => '1'] + $field);
        self::assertStringContainsString('Custom field created.', $page);
        self::assertSame(1, self::rows('202_customer_fields', "field_key = 'plan' AND is_required = 0"));
        self::assertSame(1, self::rows('202_customer_fields', "field_key = 'tier2' AND is_required = 1"));
    }

    public function testAWebhookIsSentTheEventsTickedAndNoneTickedIsRefused(): void
    {
        $hook = ['action' => 'add_webhook', 'webhook_url' => 'https://hooks.example/new'];
        $before = self::rows('202_ltv_webhooks', '1');
        foreach ([[], ['webhook_events' => 'revenue.recorded'], ['webhook_events' => []]] as $events) {
            $page = self::post($events + $hook);
            self::assertStringContainsString('Tick the events this webhook is sent.', $page, var_export($events, true));
        }
        $page = self::post(['webhook_events' => [['revenue.recorded']]] + $hook);
        self::assertStringContainsString('The form sent an event as a list; each event is one name.', $page);
        self::assertSame($before, self::rows('202_ltv_webhooks', '1'), 'a refused webhook was registered');

        $page = self::post(['webhook_events' => ['revenue.recorded']] + $hook);
        self::assertStringContainsString(' registered.', $page);
        self::assertSame(1, self::rows(
            '202_ltv_webhooks',
            "webhook_url = 'https://hooks.example/new' AND subscribed_events = 'revenue.recorded'"
        ));
    }

    public function testATextFieldSentAsAListIsRefusedNotStoredAsArray(): void
    {
        $page = self::post([
            'action' => 'add_integration', 'integration_provider' => ['shopify'], 'integration_name' => 'Shop 2',
        ]);
        self::assertStringContainsString('The form sent "integration_provider" as a list; it takes one value.', $page);
        self::assertSame(1, self::rows('202_ltv_integrations', '1'), 'nothing was added');

        $page = self::post(['action' => ['delete_webhook'], 'webhook_id' => (string) self::HOOK]);
        self::assertStringContainsString('Unknown action.', $page);
        self::assertSame(1, self::rows('202_ltv_webhooks', 'webhook_id = ' . self::HOOK));
    }

    /**
     * Every NOT_IDS spelling of $id is refused with $sentence and changes
     * nothing ($untouched holds after each), and so is no id at all.
     */
    private function assertEachNotIdIsRefused(
        string $action,
        string $field,
        int $id,
        string $sentence,
        callable $untouched
    ): void {
        $posts = [['action' => $action]];
        foreach (self::NOT_IDS as $spelling) {
            $value = is_array($spelling) ? [sprintf($spelling[0], $id)] : sprintf($spelling, $id);
            $posts[] = ['action' => $action, $field => $value];
        }
        foreach ($posts as $post) {
            $page = self::post($post);
            $what = $action . ' ' . var_export($post[$field] ?? '(absent)', true);
            self::assertStringContainsString($sentence, $page, $what);
            self::assertTrue($untouched(), $what . ' changed the record');
        }
    }

    /** @param array<string, mixed> $fields the page's own token is added */
    private static function post(array $fields): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => (string) json_encode($fields + ['token' => 'SESSION']),
            'P202_TEST_AJAX' => '1',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                dirname(__DIR__) . '/Setup/fixtures/page-runner.php', 'tracking202/ajax/ltv_settings.php',
            ],
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
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning', $err, 'the page warned: ' . $err);
        self::assertStringNotContainsString('could not be loaded', $out, $err);

        return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function rows(string $table, string $where): int
    {
        $found = self::$db->query("SELECT COUNT(*) FROM $table WHERE user_id = " . self::USER . " AND ($where)");
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

        return (int) $found->fetch_row()[0];
    }

    private static function cparam(): string
    {
        $found = self::$db->query('SELECT user_ltv_customer_cparam FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $found, (string) self::$db->error);

        return (string) $found->fetch_row()[0];
    }

    private static function cleanUp(): void
    {
        $u = self::USER;
        foreach ([...self::LTV_TABLES, '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
    }
}

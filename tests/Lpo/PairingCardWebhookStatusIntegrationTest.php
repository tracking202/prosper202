<?php

declare(strict_types=1);

namespace Tests\Lpo;

use PHPUnit\Framework\TestCase;

/**
 * My Account › API Integrations' Landing Page Optimizer card says whether the
 * optimizer receives events, from the status of the webhook its pairing made
 * (lpo_bridge_config names it by id). It said "Connected" from lpo_status
 * alone, so a webhook marked dead after 6 failed sends, or one deleted in
 * LTV Settings, read as connected while the optimizer got nothing. The page
 * runs as the account in a process of its own
 * (tests/Setup/fixtures/page-runner.php), as the web server runs it.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class PairingCardWebhookStatusIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990970;
    private const ROLE = 990970;
    private const OTHER = 990971;
    private const HOOK = 97012;
    private const THEIR_HOOK = 97013;

    private const NOT_RECEIVING = 'The optimizer is not receiving events: its webhook (#' . self::HOOK . ')';
    private const PAIR_AGAIN = 'Disconnect, then connect again to pair a new one.';

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static ?int $addedPermission = null;

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
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner', install_hash = 'h"
            . self::USER . "' WHERE user_id = " . self::USER);
        $grant = "permission_description = 'access_to_api_integrations'";
        $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $grant");
        $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
        if ($permission === null) {
            self::$addedPermission = self::insert("INSERT INTO 202_permissions SET $grant");
            $permission = self::$addedPermission;
        }
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'lpo card test']);
        self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        $t = time();
        self::row('202_ltv_webhooks', [
            'webhook_id' => self::THEIR_HOOK, 'user_id' => self::OTHER, 'webhook_url' => 'https://lpo.example/theirs',
            'webhook_secret' => 'theirs', 'subscribed_events' => '', 'status' => 'active',
            'created_at' => $t, 'updated_at' => $t,
        ]);
        self::$sessions = sys_get_temp_dir() . '/p202-lpo-card-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('RENAME TABLE IF EXISTS 202_ltv_webhooks_lpo_card_test TO 202_ltv_webhooks');
            self::cleanUp();
            if (self::$addedPermission !== null) {
                self::q('DELETE FROM 202_permissions WHERE permission_id = ' . self::$addedPermission);
            }
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
        self::q('DELETE FROM 202_ltv_webhooks WHERE user_id = ' . self::USER);
        self::pair((string) json_encode(['webhook_id' => self::HOOK, 'config' => null, 'fetched_at' => 0]));
    }

    public function testAnActiveWebhookReadsAsConnected(): void
    {
        self::hook('active');
        $card = self::card();
        self::assertStringContainsString('p202-pill p202-pill--good">Connected<', $card);
        self::assertStringNotContainsString('p202-flash', $card, 'nothing to say about a webhook that receives');
        self::assertStringNotContainsString('ltv.php?view=settings', $card);
    }

    public function testADeadWebhookSaysTheOptimizerGetsNothingAndWhereToReviveIt(): void
    {
        self::hook('dead');
        $card = self::card();
        self::assertStringContainsString('p202-pill p202-pill--bad">Not receiving events<', $card);
        self::assertStringNotContainsString('>Connected<', $card);
        self::assertStringContainsString(
            'alert-danger p202-flash" role="status"><i class="bi bi-x-circle"></i><div class="p202-flash__body">'
                . self::NOT_RECEIVING . ' was stopped after a delivery to it failed 6 times.'
                . ' Fix the endpoint, then revive the webhook in LTV Settings.</div>',
            self::decoded($card)
        );
        self::assertMatchesRegularExpression(
            '#<a href="(?:[^"]*/)?tracking202/analyze/ltv\.php\?view=settings"\s+class="btn btn-primary">'
                . 'LTV Settings</a>#',
            $card
        );
    }

    public function testAWebhookThatNoLongerExistsReadsAsNotReceiving(): void
    {
        $card = self::card();
        self::assertStringContainsString('p202-pill p202-pill--bad">Not receiving events<', $card);
        self::assertStringContainsString(
            self::NOT_RECEIVING . ' no longer exists on this install. ' . self::PAIR_AGAIN,
            self::decoded($card)
        );
        self::assertStringNotContainsString('ltv.php?view=settings', $card, 'nothing there to revive');
    }

    public function testAnotherAccountsWebhookUnderThePairedIdIsNoWebhookOfThisAccount(): void
    {
        self::pair((string) json_encode(['webhook_id' => self::THEIR_HOOK]));
        $card = self::card();
        self::assertStringContainsString('p202-pill p202-pill--bad">Not receiving events<', $card);
        $gone = 'its webhook (#' . self::THEIR_HOOK . ') no longer exists';
        self::assertStringContainsString($gone, self::decoded($card));
    }

    public function testADisabledWebhookReadsAsNotReceiving(): void
    {
        self::hook('disabled');
        $card = self::card();
        self::assertStringContainsString('p202-pill p202-pill--bad">Not receiving events<', $card);
        $off = self::NOT_RECEIVING . ' is switched off. ' . self::PAIR_AGAIN;
        self::assertStringContainsString($off, self::decoded($card));
    }

    public function testAPairingRecordThatNamesNoWebhookIsNotConnected(): void
    {
        self::hook('active');
        $unnamed = [
            '', 'not json', '{}', '{"webhook_id":"' . self::HOOK . 'abc"}', '{"webhook_id":0}', '[' . self::HOOK . ']',
        ];
        foreach ($unnamed as $config) {
            self::pair($config);
            $card = self::card();
            self::assertStringContainsString('p202-pill p202-pill--warn">Status unknown<', $card, $config);
            self::assertStringContainsString(
                'This install\'s pairing record names no webhook, so whether the optimizer receives events'
                    . ' cannot be told. ' . self::PAIR_AGAIN,
                self::decoded($card),
                $config
            );
        }
    }

    public function testAWebhookThatCannotBeReadIsSaidSoNeverConnected(): void
    {
        self::hook('active');
        self::q('RENAME TABLE 202_ltv_webhooks TO 202_ltv_webhooks_lpo_card_test');
        try {
            $card = self::card();
        } finally {
            self::q('RENAME TABLE 202_ltv_webhooks_lpo_card_test TO 202_ltv_webhooks');
        }
        self::assertStringContainsString('p202-pill p202-pill--warn">Status unknown<', $card);
        self::assertStringNotContainsString('>Connected<', $card);
        self::assertStringContainsString(
            'The status of the optimizer\'s webhook could not be read just now, so whether it receives events'
                . ' cannot be told. Reload the page to try again.',
            self::decoded($card)
        );
    }

    public function testAnInstallThatIsNotPairedIsNotAskedAboutAWebhook(): void
    {
        self::q("UPDATE 202_users_pref SET lpo_status = '', lpo_bridge_config = '' WHERE user_id = " . self::USER);
        $card = self::card();
        self::assertStringContainsString('<span class="p202-pill">Not connected</span>', $card);
        self::assertStringNotContainsString('p202-flash', $card);
    }

    private static function hook(string $status): void
    {
        $t = time();
        self::row('202_ltv_webhooks', [
            'webhook_id' => self::HOOK, 'user_id' => self::USER, 'webhook_url' => 'https://lpo.example/hook',
            'webhook_secret' => 'secret', 'subscribed_events' => '', 'status' => $status,
            'created_at' => $t, 'updated_at' => $t,
        ]);
    }

    private static function pair(string $bridgeConfig): void
    {
        self::q("UPDATE 202_users_pref SET lpo_status = 'active', lpo_site_key = 'site-key', lpo_bridge_config = "
            . self::str($bridgeConfig) . ' WHERE user_id = ' . self::USER);
    }

    /** The Landing Page Optimizer card as the page draws it, from its <section> to its end. */
    private static function card(): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => '{}',
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                '-d', 'log_errors=0',
                dirname(__DIR__) . '/Setup/fixtures/page-runner.php', '202-account/api-integrations.php',
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
        $start = strpos($out, '<section class="p202-panel h-100" id="lpo">');
        self::assertIsInt($start, 'the page drew no Landing Page Optimizer card: ' . substr($out, 0, 500) . $err);
        $end = strpos($out, '</section>', $start);
        self::assertIsInt($end);

        return substr($out, $start, $end - $start);
    }

    private static function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_ltv_webhooks', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
    }
}

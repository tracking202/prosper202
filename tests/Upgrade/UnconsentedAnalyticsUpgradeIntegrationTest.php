<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.79 -> 1.9.80 rung adds a tier and a consent version to the
 * analytics queue. Before 1.9.80 analytics was recorded with no consent
 * check, so what was queued then would read as consented analytics: the
 * rung keeps the unsent analytics events and the attribute snapshot but
 * marks them withheld, which no delivery sends, a re-grant included; the
 * essential events become essential, what was sent stays, and a second run
 * marks nothing recorded since.
 *
 * @group integration
 */
final class UnconsentedAnalyticsUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 5951;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::q('DELETE FROM 202_messaging_events WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_messaging_attributes WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        // The two tables as the 1.9.62 rung made them, holding what 1.9.79 queued.
        self::q('DROP TABLE IF EXISTS 202_messaging_events, 202_messaging_attributes');
        self::q("CREATE TABLE `202_messaging_events` (
              `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
              `user_id` mediumint(8) unsigned NOT NULL,
              `event_name` varchar(255) NOT NULL,
              `metadata` json DEFAULT NULL,
              `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `client_token` varchar(64) NOT NULL,
              `delivery_status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
              `sync_attempts` int(11) NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              KEY `user_pending` (`user_id`,`delivery_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        self::q("CREATE TABLE `202_messaging_attributes` (
              `user_id` mediumint(8) unsigned NOT NULL,
              `data` json DEFAULT NULL,
              `dirty` tinyint(1) NOT NULL DEFAULT '0',
              `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        foreach ([
            ['page_viewed', 'pending'], ['tracker_created', 'failed'], ['feature_used', 'sent'],
            ['login', 'pending'], ['account_created', 'pending'],
        ] as $i => [$name, $status]) {
            self::row('202_messaging_events', [
                'user_id' => self::USER, 'event_name' => $name, 'client_token' => 'pre-' . $i, 'delivery_status' => $status,
            ]);
        }
        self::row('202_messaging_attributes', ['user_id' => self::USER, 'data' => '{"clicks_30d": 9}', 'dirty' => 1]);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::row('202_users', [
            'user_id' => self::USER, 'user_name' => 'withheld', 'user_pass' => 'x', 'user_email' => 'withheld@example.com',
            'user_dash_email' => '', 'user_time_register' => 1700000000, 'install_hash' => 'ih_withheld', 'user_hash' => '',
            'p202_customer_api_key' => 'key-withheld',
        ]);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
    }

    /** @return list<array{0: string, 1: string, 2: string}> name, status, tier */
    private static function events(): array
    {
        return self::$db->query('SELECT event_name, delivery_status, tier FROM 202_messaging_events WHERE user_id = '
            . self::USER . ' ORDER BY id')->fetch_all();
    }

    /** @return array{0: string, 1: string} what the runner printed, and what the ladder logged */
    private static function upgrade(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }

    public function testWhatWasQueuedBeforeConsentIsKeptAndNeverSent(): void
    {
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');

        self::assertSame([
            ['page_viewed', 'withheld', 'analytics'],
            ['tracker_created', 'withheld', 'analytics'],
            ['feature_used', 'sent', 'analytics'],
            ['login', 'pending', 'essential'],
            ['account_created', 'pending', 'essential'],
        ], self::events(), 'every row stays; the unsent analytics is withheld');
        [$data, $withheld, $dirty] = self::$db->query(
            'SELECT data, withheld, dirty FROM 202_messaging_attributes WHERE user_id = ' . self::USER
        )->fetch_row();
        self::assertSame([['clicks_30d' => 9], '1', '0'], [json_decode((string) $data, true), $withheld, $dirty]);

        // Delivered after an explicit grant: the essential rows and the
        // consent change go; the withheld rows and snapshot never do.
        require_once dirname(__DIR__, 2) . '/202-config/Messaging/MessagingService.class.php';
        self::assertTrue(\ConsentPolicy::rememberGeo(self::$db, self::USER, false));
        self::assertTrue(\ConsentPolicy::record(self::$db, self::USER, 'analytics', 'granted', 'settings'));
        $service = \MessagingService::forUser(self::USER, self::$db);
        self::assertNotNull($service);
        $client = new class extends \MessagingClient {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function track(array $identity, array $attributes, array $events, ?array $consent = null): ?array
            {
                $this->calls[] = ['identity' => $identity, 'attributes' => $attributes, 'events' => $events];
                return ['ok' => true, 'consent_ack' => $consent['version'] ?? null];
            }
        };
        (new \ReflectionProperty(\MessagingService::class, 'client'))->setValue($service, $client);
        self::assertSame(\MessagingService::SYNC_SENT, $service->sync(true, 10));
        $calls = $client->calls;
        self::assertSame(['login', 'account_created'], array_column(array_merge(...array_column($calls, 'events')), 'name'));
        foreach ($calls as $call) {
            self::assertSame([], $call['attributes']);
            self::assertSame([], $call['identity']['attributes'] ?? []);
        }
        self::assertSame([], \MessagingService::usersWithPendingDelivery(self::$db), 'withheld rows never bring the user back');
        self::assertSame(['withheld', 'withheld'], array_column(array_slice(self::events(), 0, 2), 1));

        // A snapshot computed after the grant replaces the withheld one.
        $service->updateAttributes(['clicks_30d' => 3]);
        [$data, $withheld] = self::$db->query(
            'SELECT data, withheld FROM 202_messaging_attributes WHERE user_id = ' . self::USER
        )->fetch_row();
        self::assertSame([['clicks_30d' => 3], '0'], [json_decode((string) $data, true), $withheld]);

        // The rung again: nothing recorded since is marked.
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('0', (string) self::$db->query('SELECT withheld FROM 202_messaging_attributes WHERE user_id = ' . self::USER)->fetch_row()[0]);
        self::assertSame(['login', 'sent', 'essential'], self::events()[3]);
    }
}

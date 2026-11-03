<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.79 -> 1.9.80 rung adds a tier and a consent version to the
 * analytics queue. Before 1.9.80 analytics was recorded with no consent
 * check, so what was queued then would read as consented analytics: the
 * rung deletes the unsent analytics events and the attribute snapshot, keeps
 * the essential events (marked essential) and what was already sent, and
 * a second run removes nothing recorded since.
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

    public function testWhatWasQueuedBeforeConsentNeverReadsAsConsented(): void
    {
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');

        self::assertSame([
            ['feature_used', 'sent', 'analytics'],
            ['login', 'pending', 'essential'],
            ['account_created', 'pending', 'essential'],
        ], self::events(), 'unsent analytics is gone; essential events and what was sent stay');
        self::assertSame('0', (string) self::$db->query('SELECT COUNT(*) FROM 202_messaging_attributes')->fetch_row()[0]);

        // Recorded after the upgrade, then the rung runs again: nothing goes.
        self::row('202_messaging_events', [
            'user_id' => self::USER, 'event_name' => 'page_viewed', 'client_token' => 'post', 'tier' => 'analytics',
        ]);
        self::row('202_messaging_attributes', ['user_id' => self::USER, 'data' => '{"clicks_30d": 3}', 'dirty' => 1]);
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertCount(4, self::events());
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_messaging_attributes')->fetch_row()[0]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Security\IntegrationSecretStore;
use Prosper202\Security\SecretsKeyring;

/**
 * The 1.9.80 -> 1.9.81 rung moves every account's Shopify webhook secret out
 * of 202_users_pref into the sealed store and drops the plaintext column,
 * leaving the table as the installer makes it. A key it cannot read moves
 * nothing and keeps the version, so the next run retries; a run after the
 * secrets moved but the column stayed (a failed drop) moves nothing twice
 * and keeps a secret saved in the store since.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php), in
 * a process of its own with the environment given.
 *
 * @group integration
 */
final class ShopifySecretUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const A = 9201;
    private const B = 9202;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::dropColumn();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::A . ',' . self::B . ')');
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::A . ',' . self::B . ')');
        self::q('DELETE FROM 202_integration_secrets WHERE user_id IN (' . self::A . ',' . self::B . ')');
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::dropColumn();
        foreach (['202_users', '202_users_pref', '202_integration_secrets', '202_integration_secret_events'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::A . ',' . self::B . ')');
        }
        foreach ([self::A, self::B] as $id) {
            self::user($id);
            self::q("INSERT INTO 202_users_pref SET user_id = $id");
        }
    }

    private static function dropColumn(): void
    {
        if (self::hasColumn()) {
            self::q('ALTER TABLE 202_users_pref DROP COLUMN shopify_webhook_secret');
        }
    }

    private static function hasColumn(): bool
    {
        $found = self::$db->query("SHOW COLUMNS FROM 202_users_pref LIKE 'shopify\\_webhook\\_secret'");
        self::assertInstanceOf(\mysqli_result::class, $found);

        return $found->num_rows > 0;
    }

    /** A 1.9.80 database: the plaintext column where 1.9.80 put it, holding $secrets, and the version. */
    private static function at1980(array $secrets): void
    {
        self::q('ALTER TABLE 202_users_pref ADD COLUMN `shopify_webhook_secret` varchar(250) DEFAULT NULL AFTER `paykickstart_ipn_secret_keys`');
        foreach ($secrets as $user => $secret) {
            self::q('UPDATE 202_users_pref SET shopify_webhook_secret = ' . ($secret === null ? 'NULL' : self::str($secret))
                . " WHERE user_id = $user");
        }
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.80']);
    }

    private static function version(): string
    {
        return (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0];
    }

    private static function shape(): string
    {
        $ddl = self::$db->query('SHOW CREATE TABLE 202_users_pref');
        self::assertInstanceOf(\mysqli_result::class, $ddl);

        return (string) $ddl->fetch_row()[1];
    }

    private static function store(): IntegrationSecretStore
    {
        return new IntegrationSecretStore(new Connection(self::$db));
    }

    public function testTheRungMovesEverySecretSealedAndDropsTheColumn(): void
    {
        $installed = self::shape();
        self::at1980([self::A => 'demo-store-webhook-secret-A1', self::B => null]);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.81', self::version());
        self::assertFalse(self::hasColumn(), 'the plaintext column is gone');
        self::assertSame($installed, self::shape(), 'the table is the installed one');
        self::assertSame('demo-store-webhook-secret-A1', self::store()->open(self::A, 'shopify_webhook_secret', time())?->current);
        self::assertNull(self::store()->open(self::B, 'shopify_webhook_secret', time()), 'none stays none');
        $row = self::$db->query('SELECT * FROM 202_integration_secrets WHERE user_id = ' . self::A)->fetch_assoc();
        self::assertStringNotContainsString('webhook-secret-A1', implode('|', $row), 'sealed at rest');
        self::assertSame([['migrated', 'upgrade']], self::$db->query('SELECT action, via FROM 202_integration_secret_events'
            . ' WHERE user_id = ' . self::A)->fetch_all(MYSQLI_NUM));
    }

    public function testAKeyItCannotReadMovesNothingAndKeepsTheVersion(): void
    {
        self::at1980([self::A => 'demo-store-webhook-secret-A1']);

        [$out, $err] = self::upgrade([SecretsKeyring::ENV_KEY => 'not-a-key']);

        self::assertStringStartsWith('RETURNED', $out, $err);
        self::assertStringContainsString('the Shopify webhook secrets could not be moved into the encrypted store', $err);
        self::assertStringContainsString('P202_SECRETS_KEY is set but is not 64 hexadecimal characters', $err);
        self::assertSame('1.9.80', self::version(), 'the next run retries');
        self::assertTrue(self::hasColumn(), 'the column stays until its secrets are moved');
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_integration_secrets WHERE user_id = ' . self::A)->fetch_row()[0]);
        self::assertSame('demo-store-webhook-secret-A1', (string) self::$db->query('SELECT shopify_webhook_secret FROM 202_users_pref'
            . ' WHERE user_id = ' . self::A)->fetch_row()[0]);
    }

    public function testARunAfterAFailedDropMovesNothingTwiceAndKeepsWhatWasSavedSince(): void
    {
        $hex = bin2hex(random_bytes(32));
        self::at1980([self::A => 'demo-store-webhook-secret-A1', self::B => 'demo-store-webhook-secret-B1']);
        // The first run moved them, then the drop failed: the column is still there, and A saved a new one since.
        $conn = new Connection(self::$db);
        $keyring = new SecretsKeyring($conn, static fn (string $n): string|false => $n === SecretsKeyring::ENV_KEY ? $hex : false,
            static fn (string $n): mixed => null);
        $store = new IntegrationSecretStore($conn, $keyring);
        $store->migrate(self::A, 'shopify_webhook_secret', 'demo-store-webhook-secret-A1', 1000);
        $store->migrate(self::B, 'shopify_webhook_secret', 'demo-store-webhook-secret-B1', 1000);
        $store->put(self::A, 'shopify_webhook_secret', 'demo-store-saved-since-A2', 0, self::A, 'page', 1100);
        $sealed = self::$db->query('SELECT user_id, sealed FROM 202_integration_secrets WHERE user_id IN (' . self::A . ',' . self::B
            . ') ORDER BY user_id')->fetch_all(MYSQLI_NUM);

        [$out, $err] = self::upgrade([SecretsKeyring::ENV_KEY => $hex]);

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringContainsString('2 account(s) already had a Shopify webhook secret in the encrypted store', $err);
        self::assertFalse(self::hasColumn());
        self::assertSame('1.9.81', self::version());
        self::assertSame($sealed, self::$db->query('SELECT user_id, sealed FROM 202_integration_secrets WHERE user_id IN (' . self::A . ','
            . self::B . ') ORDER BY user_id')->fetch_all(MYSQLI_NUM), 'nothing sealed again');
        self::assertSame('demo-store-saved-since-A2', $store->open(self::A, 'shopify_webhook_secret', time())?->current);
    }

    /**
     * @param array<string, string> $env added to this process's environment
     * @return array{string, string} what the runner printed, and what was logged
     */
    private static function upgrade(array $env = []): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }
}

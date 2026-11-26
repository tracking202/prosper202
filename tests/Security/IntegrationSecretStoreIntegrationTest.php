<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Security\IntegrationSecretStore;
use Prosper202\Security\MalformedSecretKey;
use Prosper202\Security\PreferenceSecretMigration;
use Prosper202\Security\SecretsKeyring;
use Prosper202\Security\UnreadableSecret;

/**
 * The sealed integration secrets store on a real server: what a row holds
 * (no plaintext, the key's id, a recorded change), which key opens it, what
 * happens when the configured key is missing, malformed or another one
 * (an error naming the secret, never an empty value, never the database's
 * key in its place), the overlap window, the deleted-account rule, and the
 * move of a plaintext 202_users_pref column into it.
 *
 * @group integration
 */
final class IntegrationSecretStoreIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const A = 9101;
    private const B = 9102;
    private const DELETED = 9103;
    private const NAME = 'test_webhook_secret';
    private const COLUMN = 'p202_0h6c_probe_secret';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_integration_secrets');
        self::q('DELETE FROM 202_integration_secret_events');
        self::q("DELETE FROM 202_deployment_secrets WHERE secret_name = 'integration_secrets'");
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::A . ',' . self::B . ',' . self::DELETED . ')');
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::A . ',' . self::B . ',' . self::DELETED . ')');
        foreach ([self::A, self::B, self::DELETED] as $id) {
            self::user($id);
            self::q("INSERT INTO 202_users_pref SET user_id = $id");
        }
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::DELETED);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::$db->query('DELETE FROM 202_integration_secrets');
        self::$db->query('DELETE FROM 202_integration_secret_events');
        self::$db->query('DELETE FROM 202_users WHERE user_id IN (' . self::A . ',' . self::B . ',' . self::DELETED . ')');
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id IN (' . self::A . ',' . self::B . ',' . self::DELETED . ')');
        $column = self::$db->query("SHOW COLUMNS FROM 202_users_pref LIKE '" . self::COLUMN . "'");
        if ($column instanceof \mysqli_result && $column->num_rows > 0) {
            self::$db->query('ALTER TABLE 202_users_pref DROP COLUMN ' . self::COLUMN);
        }
    }

    /** @param array<string, string> $env */
    private static function store(array $env = [], array $constants = []): IntegrationSecretStore
    {
        $conn = new Connection(self::$db);

        return new IntegrationSecretStore($conn, new SecretsKeyring(
            $conn,
            static fn (string $name): string|false => $env[$name] ?? false,
            static fn (string $name): mixed => $constants[$name] ?? null
        ));
    }

    /** @return array<string, mixed> */
    private static function stored(int $userId): array
    {
        $row = self::$db->query('SELECT * FROM 202_integration_secrets WHERE user_id = ' . $userId)->fetch_assoc();
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function events(int $userId): array
    {
        return self::$db->query('SELECT action, via, actor_user_id, key_id, previous_expires_at FROM 202_integration_secret_events'
            . ' WHERE user_id = ' . $userId . ' ORDER BY event_id')->fetch_all(MYSQLI_ASSOC);
    }

    public function testASecretIsSealedUnderTheDatabaseKeyWhenNoneIsConfiguredAndOpensBack(): void
    {
        $store = self::store();
        self::assertNull($store->open(self::A, self::NAME, 1000), 'none stored is null, not an error');
        self::assertSame(IntegrationSecretStore::SET, $store->put(self::A, self::NAME, 'shpss_the-first-secret-0001', 0, self::A, 'page', 1000));

        $row = self::stored(self::A);
        self::assertStringStartsWith('database:', $row['key_id']);
        self::assertStringNotContainsString('the-first-secret', implode('|', $row), 'nothing in the row is the value');
        self::assertStringStartsWith('v1.', $row['sealed']);
        $opened = $store->open(self::A, self::NAME, 1001);
        self::assertSame('shpss_the-first-secret-0001', $opened?->current);
        self::assertSame(['set_at' => 1000, 'previous_expires_at' => null], $store->marker(self::A, self::NAME, 1001));
        self::assertSame([['action' => 'set', 'via' => 'page', 'actor_user_id' => (string) self::A, 'key_id' => $row['key_id'],
            'previous_expires_at' => null]], self::events(self::A));
        self::assertStringNotContainsString('the-first-secret', (string) json_encode(self::events(self::A)));
    }

    public function testAnExternalKeySealsAndAProcessWithoutItCannotOpenNorSealWithTheDatabaseKey(): void
    {
        $hex = bin2hex(random_bytes(32));
        $withKey = self::store([SecretsKeyring::ENV_KEY => $hex]);
        $withKey->put(self::A, self::NAME, 'sealed-under-the-env-key', 0, null, 'api', 1000);
        self::assertSame(SecretsKeyring::keyId('external', (string) hex2bin($hex)), self::stored(self::A)['key_id']);
        self::assertSame(0, (int) self::$db->query("SELECT COUNT(*) FROM 202_deployment_secrets WHERE secret_name = 'integration_secrets'")
            ->fetch_row()[0], 'no database key is minted when an external one is configured');

        // The same key from a file opens it.
        $file = (string) tempnam(sys_get_temp_dir(), 'p202key');
        file_put_contents($file, strtoupper($hex) . "\n");
        try {
            self::assertSame('sealed-under-the-env-key', self::store([], [SecretsKeyring::ENV_FILE => $file])->open(self::A, self::NAME, 1001)?->current);
        } finally {
            unlink($file);
        }

        foreach (['no key' => [], 'another key' => [SecretsKeyring::ENV_KEY => bin2hex(random_bytes(32))]] as $case => $env) {
            try {
                self::store($env)->open(self::A, self::NAME, 1001);
                self::fail($case . ': opened');
            } catch (UnreadableSecret $e) {
                self::assertSame(self::NAME, $e->secretName, $case);
                self::assertStringContainsString('The stored ' . self::NAME . ' of account ' . self::A . ' cannot be opened', $e->getMessage());
                self::assertStringContainsString(self::stored(self::A)['key_id'], $e->getMessage(), $case . ' names the key');
            }
        }
        $refused = null;
        try {
            self::store()->put(self::B, self::NAME, 'would-be-sealed-with-the-db-key', 0, null, 'api', 1002);
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        self::assertNotNull($refused, 'a process without the external key sealed with the database key');
        self::assertStringContainsString('does not seal with the database\'s key instead', $refused->getMessage());
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_integration_secrets WHERE user_id = ' . self::B)->fetch_row()[0]);
    }

    /** @return iterable<string, array{array<string, string>, array<string, mixed>, string}> */
    public static function malformedKeys(): iterable
    {
        yield 'too short' => [[SecretsKeyring::ENV_KEY => 'abc123'], [], 'P202_SECRETS_KEY is set but is not 64 hexadecimal'];
        yield 'empty' => [[SecretsKeyring::ENV_KEY => ''], [], 'P202_SECRETS_KEY is set but is not 64 hexadecimal'];
        yield 'not hex' => [[SecretsKeyring::ENV_KEY => str_repeat('g', 64)], [], 'P202_SECRETS_KEY is set but is not 64 hexadecimal'];
        yield 'relative file' => [[SecretsKeyring::ENV_FILE => 'key.txt'], [], 'is not an absolute path'];
        yield 'missing file' => [[], [SecretsKeyring::ENV_FILE => '/nonexistent/p202.key'], 'not a file this process can read'];
        yield 'two set' => [[SecretsKeyring::ENV_KEY => str_repeat('a', 64), SecretsKeyring::ENV_FILE => '/x'], [], 'More than one'];
    }

    /**
     * @dataProvider malformedKeys
     * @param array<string, string> $env
     * @param array<string, mixed> $constants
     */
    public function testAConfiguredKeyThatCannotBeReadIsAnErrorAndNeverTheDatabaseKey(array $env, array $constants, string $why): void
    {
        self::store()->put(self::A, self::NAME, 'sealed-under-the-db-key', 0, null, 'api', 1000);
        $broken = self::store($env, $constants);
        try {
            $broken->put(self::B, self::NAME, 'not-to-be-sealed-at-all', 0, null, 'api', 1001);
            self::fail('sealed with a key it could not read');
        } catch (MalformedSecretKey $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
        try {
            $broken->open(self::A, self::NAME, 1001);
            self::fail('opened with the database key in place of the configured one');
        } catch (UnreadableSecret $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_integration_secrets WHERE user_id = ' . self::B)->fetch_row()[0]);
    }

    public function testARotationKeepsTheOldValueUntilItsWindowEndsAndTwoAtMost(): void
    {
        $store = self::store();
        $store->put(self::A, self::NAME, 'first-value-xxxxxxxx', 0, null, 'page', 1000);
        self::assertSame(IntegrationSecretStore::ROTATED, $store->put(self::A, self::NAME, 'second-value-xxxxxxx', 600, self::A, 'page', 2000));

        self::assertSame(['second-value-xxxxxxx', 'first-value-xxxxxxxx'], $store->open(self::A, self::NAME, 2599)?->accepted());
        self::assertSame(['set_at' => 2000, 'previous_expires_at' => 2600], $store->marker(self::A, self::NAME, 2599));
        self::assertSame(['second-value-xxxxxxx'], $store->open(self::A, self::NAME, 2600)?->accepted(), 'the window has ended');
        self::assertSame(['set_at' => 2000, 'previous_expires_at' => null], $store->marker(self::A, self::NAME, 2600));

        $store->put(self::A, self::NAME, 'third-value-xxxxxxxx', 600, null, 'page', 2100);
        self::assertSame(['third-value-xxxxxxxx', 'second-value-xxxxxxx'], $store->open(self::A, self::NAME, 2101)?->accepted(),
            'the first is dropped: two at most');
        self::assertSame(IntegrationSecretStore::REPLACED, $store->put(self::A, self::NAME, 'fourth-value-xxxxxxx', 0, null, 'api', 2200));
        self::assertSame(['fourth-value-xxxxxxx'], $store->open(self::A, self::NAME, 2201)?->accepted(), 'no window: the old one stops');
        self::assertSame(['set', 'rotated', 'rotated', 'replaced'], array_column(self::events(self::A), 'action'));
        self::assertSame([null, '2600', '2700', null], array_column(self::events(self::A), 'previous_expires_at'));
    }

    public function testACiphertextCopiedOntoAnotherAccountDoesNotOpen(): void
    {
        $store = self::store();
        $store->put(self::A, self::NAME, 'account-a-secret-xxx', 0, null, 'page', 1000);
        self::q('INSERT INTO 202_integration_secrets (user_id, secret_name, key_id, wrapped_key, sealed, set_at, updated_at)'
            . ' SELECT ' . self::B . ', secret_name, key_id, wrapped_key, sealed, set_at, updated_at FROM 202_integration_secrets'
            . ' WHERE user_id = ' . self::A);
        $this->expectException(UnreadableSecret::class);
        $this->expectExceptionMessage('of account ' . self::B . ' cannot be opened');
        $store->open(self::B, self::NAME, 1001);
    }

    public function testEachLiveAccountIsOpenedAndOneUnreadableSecretDoesNotStopTheOthers(): void
    {
        $store = self::store();
        $store->put(self::A, self::NAME, 'account-a-secret-xxx', 0, null, 'page', 1000);
        $store->put(self::B, self::NAME, 'account-b-secret-xxx', 0, null, 'page', 1000);
        $store->put(self::DELETED, self::NAME, 'deleted-account-secret', 0, null, 'page', 1000);
        self::q("UPDATE 202_integration_secrets SET sealed = CONCAT('v1.', 'AAAA', SUBSTRING(sealed, 8)) WHERE user_id = " . self::B);

        $each = $store->openEach(self::NAME, 1001);
        self::assertSame([self::A, self::B], array_keys($each), 'a deleted account owns no secret');
        self::assertSame('account-a-secret-xxx', $each[self::A] instanceof \Prosper202\Security\IntegrationSecret ? $each[self::A]->current : null);
        self::assertInstanceOf(UnreadableSecret::class, $each[self::B]);

        self::assertTrue($store->clear(self::A, self::NAME, self::A, 'page', 1002));
        self::assertFalse($store->clear(self::A, self::NAME, self::A, 'page', 1003), 'nothing left to clear');
        self::assertNull($store->open(self::A, self::NAME, 1004));
        self::assertSame(['set', 'cleared'], array_column(self::events(self::A), 'action'));
    }

    public function testAPlaintextColumnMovesOnceAndReadsBackAsItWas(): void
    {
        self::q('ALTER TABLE 202_users_pref ADD COLUMN ' . self::COLUMN . ' varchar(250) DEFAULT NULL');
        self::q('UPDATE 202_users_pref SET ' . self::COLUMN . " = ' kept as stored, spaces too ' WHERE user_id = " . self::A);
        self::q('UPDATE 202_users_pref SET ' . self::COLUMN . " = '   ' WHERE user_id = " . self::B);
        self::q('UPDATE 202_users_pref SET ' . self::COLUMN . " = 'a deleted account keeps its own' WHERE user_id = " . self::DELETED);
        $conn = new Connection(self::$db);

        // A key that cannot be read moves nothing: the transaction is the whole move.
        try {
            PreferenceSecretMigration::move($conn, self::store([SecretsKeyring::ENV_KEY => 'nope']), self::COLUMN, self::NAME, 1000);
            self::fail('moved under a key it could not read');
        } catch (MalformedSecretKey) {
        }
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_integration_secrets')->fetch_row()[0]);

        $store = self::store();
        self::assertSame(['moved' => 2, 'kept' => 0], PreferenceSecretMigration::move($conn, $store, self::COLUMN, self::NAME, 1000),
            'a blank value is none');
        self::assertSame(' kept as stored, spaces too ', $store->open(self::A, self::NAME, 1001)?->current);
        self::assertSame(['migrated'], array_column(self::events(self::A), 'action'));

        // Saved again after the move, then a re-run: the store's is newer and stays; nothing is sealed twice.
        $store->put(self::A, self::NAME, 'saved-after-the-move', 0, self::A, 'page', 1100);
        $sealed = self::stored(self::A)['sealed'];
        self::assertSame(['moved' => 0, 'kept' => 2], PreferenceSecretMigration::move($conn, $store, self::COLUMN, self::NAME, 1200));
        self::assertSame($sealed, self::stored(self::A)['sealed']);
        self::assertSame('saved-after-the-move', $store->open(self::A, self::NAME, 1201)?->current);
    }
}

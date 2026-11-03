<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\UsersController;
use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Tables\UserTables;
use Prosper202\User\ApiKeyStore;

/**
 * CA14: the 1.9.80 rung hashes every REST API key stored whole and empties
 * api_key; every key keeps working, matched byte for byte, and a key made
 * afterwards is never stored whole.
 *
 * @group integration
 */
final class ApiKeyHashUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 914;
    private const PLAIN = 'aB3dE5gH7jK9mN1pQ3sT5vX7zA9cE1gI';
    private const AI = '1f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a79881f2e3d4c5b6a7988';

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
        self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::user(self::USER);
        self::q('UPDATE 202_users SET user_active = 1, user_deleted = 0 WHERE user_id = ' . self::USER);
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::q('DELETE FROM 202_api_keys WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        }
    }

    public function testTheRungHashesEveryStoredKeyAndEachStillAuthenticates(): void
    {
        $fresh = self::shape();
        $this->asOf1979();
        self::row('202_api_keys', ['user_id' => self::USER, 'api_key' => self::PLAIN, 'scope' => null,
            'label' => null, 'created_at' => 1]);
        self::row('202_api_keys', ['user_id' => self::USER, 'api_key' => self::AI, 'scope' => 'reports:read',
            'label' => AiConnect::LABEL_PREFIX . 'Claude', 'created_at' => 2]);

        $before = self::connection();
        self::assertSame(self::USER, Auth::fromRequest(['Authorization' => 'Bearer ' . self::PLAIN], $before)->userId());
        $this->assertRefused(strtolower(self::PLAIN), $before, 'before the rung, the lookup is exact already');

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame($fresh, self::shape(), 'the table is the installed one');

        $rows = self::keyRows();
        self::assertSame(['', '', ApiKeyStore::hash(self::PLAIN), ApiKeyStore::hash(self::AI)],
            [$rows[0]['api_key'], $rows[1]['api_key'], $rows[0]['key_hash'], $rows[1]['key_hash']],
            'no key is left whole');
        self::assertSame(['aB3dE5gH', '1f2e3d4c'], [$rows[0]['key_prefix'], $rows[1]['key_prefix']]);
        self::assertSame([null, AiConnect::watcherToken(self::AI)], [$rows[0]['watcher_token'], $rows[1]['watcher_token']],
            'the AI connection keeps the token its watchers are known by');

        $after = self::connection();
        self::assertSame(self::USER, Auth::fromRequest(['Authorization' => 'Bearer ' . self::PLAIN], $after)->userId());
        $ai = Auth::fromRequest(['Authorization' => 'Bearer ' . self::AI], $after);
        self::assertSame(['reports:read'], $ai->scopes());
        $this->assertRefused(strtolower(self::PLAIN), $after, 'a key differing in case is another key');
        $this->assertRefused(ApiKeyStore::hash(self::PLAIN), $after, 'the stored hash is not a key');
        $this->assertRefused('', $after, 'an empty key matches no emptied row');

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame($rows, self::keyRows(), 'a second run changes nothing');
    }

    public function testAKeyMadeAfterTheUpgradeIsStoredHashedAndManagedByKeyOrRef(): void
    {
        $db = self::connection();
        $users = new UsersController($db);
        $made = $users->createApiKey(self::USER, ['scope' => 'reports:read'])['data'];
        $key = (string) $made['api_key'];

        $row = self::keyRows()[0];
        self::assertSame(['', ApiKeyStore::hash($key), substr($key, 0, 8)], [$row['api_key'], $row['key_hash'], $row['key_prefix']]);

        $listed = $users->listApiKeys(self::USER)['data'][0];
        self::assertSame(substr($key, 0, 8) . str_repeat('*', 24), $listed['api_key']);
        self::assertArrayNotHasKey('key_hash', $listed);
        self::assertSame(self::USER, Auth::fromRequest(['Authorization' => 'Bearer ' . $key], $db)->userId());

        // The legacy APIs' literal finds it too.
        $found = $db->query('SELECT k.user_id FROM 202_api_keys k WHERE ' . ApiKeyStore::matchKeyLiteral($db, $key, 'k'));
        self::assertInstanceOf(\mysqli_result::class, $found);
        self::assertSame(1, $found->num_rows);

        // The page's ref names it, and a key still stored whole the same way.
        self::row('202_api_keys', ['user_id' => self::USER, 'api_key' => self::PLAIN, 'created_at' => 3]);
        foreach ([ApiKeyStore::hash($key), ApiKeyStore::hash(self::PLAIN)] as $ref) {
            [$sql, $types, $values] = ApiKeyStore::matchRef($db, $ref);
            $conn = new Connection($db);
            $stmt = $conn->prepareRead('SELECT COUNT(*) AS n FROM 202_api_keys WHERE user_id = ? AND ' . $sql);
            $conn->bind($stmt, 'i' . $types, [self::USER, ...$values]);
            self::assertSame(1, (int) ($conn->fetchOne($stmt)['n'] ?? 0), $ref);
        }

        $users->deleteApiKey(self::USER, $key);
        $this->assertRefused($key, $db, 'a revoked key is refused');
        self::assertSame([200, ['data' => ['revoked' => true]]], AiConnect::revoke($db, self::PLAIN));
        self::assertSame([], self::keyRows());
    }

    private function asOf1979(): void
    {
        self::q('ALTER TABLE 202_api_keys DROP INDEX `202_api_keys_key_hash`, DROP COLUMN key_hash,'
            . ' DROP COLUMN key_prefix, DROP COLUMN watcher_token');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
    }

    private function assertRefused(string $key, \mysqli $db, string $why): void
    {
        try {
            Auth::fromRequest(['Authorization' => 'Bearer ' . $key], $db);
            self::fail($why);
        } catch (AuthException $e) {
            self::assertSame(401, $e->getCode(), $why);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function keyRows(): array
    {
        $found = self::$db->query('SELECT api_key, key_hash, key_prefix, watcher_token FROM 202_api_keys'
            . ' WHERE user_id = ' . self::USER . ' ORDER BY created_at');
        self::assertInstanceOf(\mysqli_result::class, $found);
        return $found->fetch_all(MYSQLI_ASSOC);
    }

    /** A connection of its own: Auth caches its column probes per connection. */
    private static function connection(): \mysqli
    {
        return new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    private static function shape(): string
    {
        $ddl = self::$db->query('SHOW CREATE TABLE 202_api_keys');
        self::assertInstanceOf(\mysqli_result::class, $ddl);
        self::assertStringContainsString('`key_hash`', UserTables::apiKeys()->createStatement);
        return (string) $ddl->fetch_row()[1];
    }

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
}

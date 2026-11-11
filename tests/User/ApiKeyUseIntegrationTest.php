<?php

declare(strict_types=1);

namespace Tests\User;

use Api\V3\Auth;
use Api\V3\AuthException;
use Api\V3\Controllers\UsersController;
use Api\V3\Support\AiConnect;
use PHPUnit\Framework\TestCase;
use Prosper202\User\ApiKeyStore;

/**
 * DT30: a key's last use is recorded where it authenticates, at most once per
 * interval and never at the request's cost; a replaced key works until its
 * ending and is refused from then on, on every API version.
 *
 * @group integration
 */
final class ApiKeyUseIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 931;
    private const OTHER = 932;
    private const KEY = '7a1c3e5f9b2d4f6a8c0e1b3d5f7a9c2e4f6a8b0c1d3e5f7a9b2c4d6e8f0a1b3c';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
        if (!function_exists('getAuth')) {
            require_once dirname(__DIR__, 2) . '/api/v2/functions.php';
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->clean();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::q('UPDATE 202_users SET user_active = 1, user_deleted = 0 WHERE user_id = ' . $user);
        }
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_dt30_refuse_stamp');
            $this->clean();
        }
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    private function clean(): void
    {
        self::q('DELETE FROM 202_api_keys WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
    }

    public function testAnAuthenticatedRequestRecordsTheKeysUseAtMostOncePerInterval(): void
    {
        ApiKeyStore::insert(self::$db, self::USER, self::KEY, null, null, 100);
        self::assertSame([null, null, null], array_slice(array_values($this->useRow(self::KEY)), 0, 3));

        $before = time();
        $auth = Auth::fromRequest(
            ['Authorization' => 'Bearer ' . self::KEY, 'User-Agent' => "store-sync/2.1\r\n" . str_repeat('é', 300)],
            self::$db
        );
        self::assertSame(self::USER, $auth->userId());
        $row = $this->useRow(self::KEY);
        self::assertGreaterThanOrEqual($before, (int) $row['last_used_at']);
        self::assertSame('v3', $row['last_used_api']);
        self::assertSame(mb_substr('store-sync/2.1 ' . str_repeat('é', 300), 0, 190), $row['last_used_client']);

        // Used a moment ago: nothing is written.
        self::q("UPDATE 202_api_keys SET last_used_at = " . (time() - 10) . ", last_used_client = 'kept'"
            . ' WHERE user_id = ' . self::USER);
        Auth::fromRequest(['Authorization' => 'Bearer ' . self::KEY, 'User-Agent' => 'other/1'], self::$db);
        self::assertSame('kept', $this->useRow(self::KEY)['last_used_client']);

        // Past the interval: written again, by the legacy API under its own name.
        $stale = time() - ApiKeyStore::USE_STAMP_INTERVAL - 1;
        self::q('UPDATE 202_api_keys SET last_used_at = ' . $stale . ' WHERE user_id = ' . self::USER);
        $_SERVER['HTTP_USER_AGENT'] = 'report-puller/3';
        self::assertSame(202, getAuth(self::$db, ['apikey' => self::KEY])['status']);
        $row = $this->useRow(self::KEY);
        self::assertGreaterThan($stale, (int) $row['last_used_at']);
        self::assertSame(['v2', 'report-puller/3'], [$row['last_used_api'], $row['last_used_client']]);

        // A refused key is not a use.
        self::q('UPDATE 202_api_keys SET last_used_at = NULL WHERE user_id = ' . self::USER);
        $this->assertRefused(strtoupper(self::KEY));
        self::assertNull($this->useRow(self::KEY)['last_used_at']);
    }

    public function testAStampThatCannotBeWrittenNeverRefusesTheRequest(): void
    {
        ApiKeyStore::insert(self::$db, self::USER, self::KEY, null, null, 100);
        self::q("CREATE TRIGGER p202_dt30_refuse_stamp BEFORE UPDATE ON 202_api_keys FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stamp refused'");
        $log = tempnam(sys_get_temp_dir(), 'uo-api-keys-log');
        $was = ini_set('error_log', (string) $log);
        try {
            $auth = Auth::fromRequest(['Authorization' => 'Bearer ' . self::KEY], self::$db);
            self::assertSame(self::USER, $auth->userId());
            self::assertSame(202, getAuth(self::$db, ['apikey' => self::KEY])['status']);
        } finally {
            ini_set('error_log', (string) $was);
            self::q('DROP TRIGGER IF EXISTS p202_dt30_refuse_stamp');
        }
        $logged = (string) file_get_contents((string) $log);
        unlink((string) $log);
        self::assertSame(2, substr_count($logged, 'last use could not be recorded'), $logged);
        self::assertStringContainsString('stamp refused', $logged);
        self::assertStringNotContainsString(self::KEY, $logged);
        self::assertNull($this->useRow(self::KEY)['last_used_at']);
    }

    public function testBeforeTheUpgradeAddsTheColumnsKeysWorkAndNothingIsStamped(): void
    {
        ApiKeyStore::insert(self::$db, self::USER, self::KEY, 'reports:read', null, 100);
        self::q('ALTER TABLE 202_api_keys DROP COLUMN last_used_at, DROP COLUMN last_used_api,'
            . ' DROP COLUMN last_used_client, DROP COLUMN ends_at');
        try {
            $db = self::connection();
            $auth = Auth::fromRequest(['Authorization' => 'Bearer ' . self::KEY], $db);
            self::assertSame(['reports:read'], $auth->scopes());
            $legacy = getAuth($db, ['apikey' => self::KEY]);
            self::assertSame(403, $legacy['status'], 'the legacy API still reads the scope');

            $listed = (new UsersController($db))->listApiKeys(self::USER)['data'][0];
            self::assertArrayNotHasKey('last_used_at', $listed);
            self::assertArrayNotHasKey('ends_at', $listed);

            require_once dirname(__DIR__, 2) . '/202-config/functions-account-ui.php';
            $shown = p202_account_api_keys($db, self::USER)[0];
            self::assertFalse($shown['use_recorded']);
            self::assertSame(
                ['used' => '', 'ending' => '', 'ended' => false, 'replaceable' => false],
                p202_account_key_use($shown, time())
            );

            try {
                ApiKeyStore::replace($db, self::USER, ApiKeyStore::hash(self::KEY), str_repeat('b', 64), time());
                self::fail('Replace needs the columns');
            } catch (\DomainException $e) {
                self::assertStringContainsString('upgrade', $e->getMessage());
            }
            self::assertSame(1, $this->keyCount(self::USER));
        } finally {
            self::q('ALTER TABLE 202_api_keys'
                . ' ADD COLUMN last_used_at int(10) unsigned DEFAULT NULL AFTER watcher_token,'
                . ' ADD COLUMN last_used_api varchar(8) DEFAULT NULL AFTER last_used_at,'
                . ' ADD COLUMN last_used_client varchar(190) DEFAULT NULL AFTER last_used_api,'
                . ' ADD COLUMN ends_at int(10) unsigned DEFAULT NULL AFTER last_used_client');
        }
    }

    public function testAReplacedKeyWorksUntilItsEndingAndTheNewKeyHasItsAccess(): void
    {
        ApiKeyStore::insert(self::$db, self::USER, self::KEY, 'reports:read', 'Nightly store sync', 100);
        $ref = ApiKeyStore::hash(self::KEY);
        $new = str_repeat('c', 64);
        $now = time();

        $others = ApiKeyStore::replace(self::$db, self::OTHER, $ref, str_repeat('d', 64), $now);
        self::assertNull($others, 'another account names nothing');
        self::assertSame(0, $this->keyCount(self::OTHER));
        self::assertNull($this->useRow(self::KEY)['ends_at']);

        $replaced = ApiKeyStore::replace(self::$db, self::USER, $ref, $new, $now);
        self::assertSame(['ends_at' => $now + ApiKeyStore::REPLACED_KEY_GRACE], $replaced);
        self::assertSame($now + 86400, (int) $this->useRow(self::KEY)['ends_at']);
        self::assertNull($this->useRow($new)['ends_at']);

        $fresh = Auth::fromRequest(['Authorization' => 'Bearer ' . $new], self::$db);
        self::assertSame([self::USER, ['reports:read']], [$fresh->userId(), $fresh->scopes()]);
        $old = Auth::fromRequest(['Authorization' => 'Bearer ' . self::KEY], self::$db);
        self::assertSame(['reports:read'], $old->scopes(), 'the old key works through its grace');

        $listed = array_column((new UsersController(self::$db))->listApiKeys(self::USER)['data'], null, 'api_key');
        $oldView = $listed[ApiKeyStore::masked(ApiKeyStore::prefix(self::KEY))];
        self::assertSame($now + 86400, $oldView['ends_at']);
        self::assertSame('v3', $oldView['last_used_api']);
        self::assertSame('Nightly store sync', $listed[ApiKeyStore::masked(ApiKeyStore::prefix($new))]['label']);

        try {
            ApiKeyStore::replace(self::$db, self::USER, $ref, str_repeat('e', 64), $now);
            self::fail('a key is replaced once');
        } catch (\DomainException $e) {
            self::assertStringContainsString('already replaced', $e->getMessage());
        }
        self::assertSame(2, $this->keyCount(self::USER));

        // At its ending the old key is refused, and told why, on each version.
        self::q('UPDATE 202_api_keys SET ends_at = ' . ($now - 60) . ', last_used_at = NULL WHERE user_id = '
            . self::USER . ' AND ends_at IS NOT NULL');
        try {
            Auth::fromRequest(['Authorization' => 'Bearer ' . self::KEY], self::$db);
            self::fail('an ended key is refused');
        } catch (AuthException $e) {
            self::assertSame(401, $e->getCode());
            $said = 'was replaced and stopped working on ' . gmdate('Y-m-d H:i', $now - 60) . ' UTC';
            self::assertStringContainsString($said, $e->getMessage());
        }
        self::q('UPDATE 202_api_keys SET scope = NULL WHERE user_id = ' . self::USER);
        $legacy = getAuth(self::$db, ['apikey' => self::KEY]);
        self::assertSame(401, $legacy['status']);
        self::assertStringContainsString('was replaced and stopped working', $legacy['msg']);
        self::assertNull($this->useRow(self::KEY)['last_used_at'], 'a refused key is not a use');
        self::assertSame(202, getAuth(self::$db, ['apikey' => $new])['status']);
    }

    public function testAnAiAppsKeyIsNotReplacedHere(): void
    {
        ApiKeyStore::insert(self::$db, self::USER, self::KEY, 'reports:read', AiConnect::LABEL_PREFIX . 'Claude', 100);
        try {
            ApiKeyStore::replace(self::$db, self::USER, ApiKeyStore::hash(self::KEY), str_repeat('f', 64), time());
            self::fail('an AI connection is replaced by connecting again');
        } catch (\DomainException $e) {
            self::assertStringContainsString('connecting the app again', $e->getMessage());
        }
        self::assertSame(1, $this->keyCount(self::USER));
        self::assertNull($this->useRow(self::KEY)['ends_at']);
    }

    private function assertRefused(string $key): void
    {
        try {
            Auth::fromRequest(['Authorization' => 'Bearer ' . $key], self::$db);
            self::fail('refused');
        } catch (AuthException $e) {
            self::assertSame(401, $e->getCode());
        }
    }

    /** @return array<string, mixed> */
    private function useRow(string $key): array
    {
        $found = self::$db->query("SELECT last_used_at, last_used_api, last_used_client, ends_at FROM 202_api_keys"
            . " WHERE key_hash = '" . ApiKeyStore::hash($key) . "'");
        self::assertInstanceOf(\mysqli_result::class, $found);
        $row = $found->fetch_assoc();
        self::assertIsArray($row);
        return $row;
    }

    private function keyCount(int $user): int
    {
        $found = self::$db->query('SELECT COUNT(*) FROM 202_api_keys WHERE user_id = ' . $user);
        self::assertInstanceOf(\mysqli_result::class, $found);
        return (int) $found->fetch_row()[0];
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
}

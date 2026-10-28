<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\SyncProfileKeys;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\SecretTables;

/**
 * The sync key against a real server: minted into 202_deployment_secrets by
 * the first seal, under strict mode, read back by every later one, and never
 * replaced, so a job sealed by one request opens in the worker's.
 *
 * @group integration
 */
final class SyncProfileKeysIntegrationTest extends TestCase
{
    private const PROFILE = ['name' => 'stage', 'url' => 'https://stage.example.com', 'api_key' => 'stage-key-123'];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            self::$db = new \mysqli(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('Set P202_TEST_DB_HOST (and friends) to a scratch database');
        }
        self::$db->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        self::$db->query('DROP TABLE IF EXISTS `202_deployment_secrets`');
        self::$db->query(SecretTables::deploymentSecrets()->createStatement);
    }

    private static function storedKey(): ?string
    {
        $sql = "SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = '"
            . SyncProfileKeys::SECRET_NAME . "'";
        $result = self::$db?->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();

        return is_array($row) ? (string) $row[0] : null;
    }

    public function testTheFirstSealMintsTheKeyAndEveryLaterRequestReadsIt(): void
    {
        self::assertNull(self::storedKey());
        $sealed = SyncProfileKeys::fromDatabase(self::$db)->seal(self::PROFILE, 'target');
        $key = self::storedKey();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $key);

        $again = SyncProfileKeys::fromDatabase(self::$db);
        self::assertSame(self::PROFILE, $again->open($sealed, 'target'), 'another request opens it');
        $again->seal(self::PROFILE, 'source');
        self::assertSame($key, self::storedKey(), 'never replaced');
    }

    public function testAKeyAlreadyStoredIsTheOneUsed(): void
    {
        $hex = str_repeat('c3', 32);
        self::$db?->query("INSERT INTO 202_deployment_secrets (secret_name, secret_value, created_at) VALUES ('"
            . SyncProfileKeys::SECRET_NAME . "', '" . $hex . "', 1)");
        $sealed = SyncProfileKeys::fromDatabase(self::$db)->seal(self::PROFILE, 'source');
        self::assertSame($hex, self::storedKey());
        $mine = SyncProfileKeys::withKey((string) hex2bin($hex));
        self::assertSame(self::PROFILE, $mine->open($sealed, 'source'));
    }
}

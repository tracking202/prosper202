<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\PublicClickId;
use Prosper202\Click\PublicClickIdKey;
use Prosper202\Database\Tables\SecretTables;

/**
 * The key cloaked clicks' public ids are made with, against a real server:
 * minted by the first click that needs it, under strict mode, read by every
 * later request and never replaced; a stored value that cannot be a key is
 * refused rather than read as "no key"; and a key that cannot be had leaves
 * the click a public id with a random suffix, not a failed redirect.
 *
 * @group integration
 */
final class PublicClickIdKeyIntegrationTest extends TestCase
{
    private static bool $available = false;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        try {
            self::connect()->close();
            self::$available = true;
        } catch (\Throwable) {
            self::$available = false;
        }
    }

    /** A connection of its own: the key is remembered per connection, as it is per request. */
    private static function connect(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $db->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

        return $db;
    }

    protected function setUp(): void
    {
        if (!self::$available) {
            self::markTestSkipped('Set P202_TEST_DB_HOST (and friends) to a scratch database');
        }
        $db = self::connect();
        $db->query('DROP TABLE IF EXISTS `202_deployment_secrets`');
        $db->query(SecretTables::deploymentSecrets()->createStatement);
        $db->close();
    }

    private static function stored(): ?string
    {
        $db = self::connect();
        $row = $db->query("SELECT secret_value FROM 202_deployment_secrets WHERE secret_name = '"
            . PublicClickIdKey::SECRET_NAME . "'")->fetch_row();
        $db->close();

        return is_array($row) ? (string) $row[0] : null;
    }

    public function testTheFirstClickMintsTheKeyAndEveryLaterOneUsesIt(): void
    {
        self::assertNull(PublicClickIdKey::load(self::connect()));
        $first = PublicClickIdKey::forNewClick(self::connect(), 4242);
        $hex = self::stored();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', (string) $hex);
        self::assertSame(PublicClickId::issue(4242, (string) hex2bin((string) $hex)), $first);

        $later = self::connect();
        self::assertSame($first, PublicClickIdKey::forNewClick($later, 4242), 'another request issues the same id');
        self::assertSame(4242, PublicClickId::clickOf($first, PublicClickIdKey::ensure($later)));
        self::assertSame($hex, self::stored(), 'never replaced');
    }

    public function testAKeyAlreadyStoredIsTheOneUsedAndAnotherSecretIsNotIt(): void
    {
        $db = self::connect();
        $hex = str_repeat('5a', 32);
        $db->query("INSERT INTO 202_deployment_secrets (secret_name, secret_value, created_at) VALUES ('"
            . PublicClickIdKey::SECRET_NAME . "', '" . $hex . "', 1),"
            . " ('android_install_token', '" . str_repeat('11', 32) . "', 1)");
        self::assertSame(PublicClickId::issue(77, (string) hex2bin($hex)), PublicClickIdKey::forNewClick($db, 77));
    }

    public function testAStoredValueThatIsNotAKeyIsRefusedNotReadAsNone(): void
    {
        $db = self::connect();
        $db->query("INSERT INTO 202_deployment_secrets (secret_name, secret_value, created_at) VALUES ('"
            . PublicClickIdKey::SECRET_NAME . "', '" . str_repeat('Z', 64) . "', 1)");
        $refused = null;
        try {
            PublicClickIdKey::ensure(self::connect());
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        self::assertNotNull($refused, 'a corrupt key was read');
        self::assertStringContainsString('64 lower-case hex', $refused->getMessage());
        self::assertSame(str_repeat('Z', 64), self::stored(), 'not replaced by a new one');
    }

    public function testAClickWithNoKeyToHaveStillGetsAnIdThatCannotBeGuessed(): void
    {
        $db = self::connect();
        $db->query('DROP TABLE `202_deployment_secrets`');
        $log = tempnam(sys_get_temp_dir(), 'p202-pci-log');
        $before = ini_set('error_log', (string) $log);
        try {
            $id = PublicClickIdKey::forNewClick($db, 909);
        } finally {
            ini_set('error_log', (string) $before);
        }
        self::assertMatchesRegularExpression('/\A1000000000909\d{6}\z/D', $id);
        $logged = (string) file_get_contents((string) $log);
        @unlink((string) $log);
        self::assertStringContainsString('click 909: the click public id key could not be read', $logged);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Prosper202\Database\SchemaInstaller;

/**
 * The scratch database the Setup integration tests write to: the one named
 * by P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 * P202_TEST_DB_PASS and P202_TEST_DB_NAME, with the schema installed (and
 * the pixel types seeded, as the installer seeds them). Without one the
 * tests skip.
 *
 * Each test class owns two user ids (its own and another account's) and
 * clears their rows itself; user 1's tracking domain is saved before and
 * restored after.
 */
trait SetupScratchDatabase
{
    private static ?\mysqli $db = null;
    private static ?string $savedDomain = null;

    private static function connectScratchDatabase(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        // As the installer seeds them (DataSeeder::seedPixelTypes()).
        $db->query("INSERT IGNORE INTO 202_pixel_types (pixel_type) VALUES ('Image'), ('Iframe'), ('Javascript'), ('Postback'), ('Raw'), ('Bot202 Facebook Pixel Assistant')");
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $row = $db->query('SELECT user_tracking_domain FROM 202_users_pref WHERE user_id = 1')->fetch_row();
        self::$savedDomain = $row === null ? null : (string) $row[0];
        self::$db = $db;
    }

    private static function restoreScratchDatabase(): void
    {
        if (self::$db === null) {
            return;
        }
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        if (self::$savedDomain === null) {
            self::$db->query('DELETE FROM 202_users_pref WHERE user_id = 1');
        } else {
            $stmt = self::$db->prepare('UPDATE 202_users_pref SET user_tracking_domain = ? WHERE user_id = 1');
            $stmt->bind_param('s', self::$savedDomain);
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function requireScratchDatabase(): \mysqli
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }

        return self::$db;
    }

    /**
     * The caller's (self::USER's) tracking domain, which the API builds the
     * caller's links on; user 1's is set to another so a read of the owner's
     * instead shows.
     */
    private static function setTrackingDomain(string $domain, ?int $user = null): void
    {
        $user ??= self::USER;
        if ($user !== 1) {
            self::assertTrue(self::$db->query("INSERT INTO 202_users_pref (user_id, user_tracking_domain) VALUES (1, 'owner.example') ON DUPLICATE KEY UPDATE user_tracking_domain = VALUES(user_tracking_domain)"));
        }
        $stmt = self::$db->prepare('INSERT INTO 202_users_pref (user_id, user_tracking_domain) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_tracking_domain = VALUES(user_tracking_domain)');
        $stmt->bind_param('is', $user, $domain);
        self::assertTrue($stmt->execute());
        $stmt->close();
    }

    /** One row, or null. @return array<string, mixed>|null */
    private static function row(string $sql): ?array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);

        return $result->fetch_assoc();
    }

    private static function exec(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), $sql);

        return (int) self::$db->insert_id;
    }

    /**
     * The request's server values: a host the stored domain overrides, the
     * repository as the document root (so the install path is '/').
     *
     * @return array<string, mixed>
     */
    private static function server(bool $https = true): array
    {
        return [
            'SERVER_NAME' => 'server.example',
            'SERVER_PORT' => $https ? 443 : 80,
            'HTTPS' => $https ? 'on' : '',
            'DOCUMENT_ROOT' => dirname(__DIR__, 3),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\PostbackVerifier;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\AttributionPostbackTables;

/**
 * The receiver guarantees only a real server can demonstrate: the dedupe
 * UNIQUE index collapsing a byte-identical retry (the unit tests fake errno
 * 1062), a forgery and the genuine postback for the same transaction both
 * landing because the hash covers the whole body, and the owner lookup
 * stamping rows for a registered app. Two sequential receive() calls per
 * case, against the shipped DDL.
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class PostbackReceiverIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private static bool $reportModeChanged = false;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return; // no DB configured; individual tests will skip
        }

        // Production report mode (PHP's default since 8.1, and what the
        // receiver documents): failures throw, so the exception branch of
        // insertPostback() is the one exercised here. mysqli_report() is
        // process-global and returns bool, not the mode it replaced — PHP
        // exposes no getter — so tearDownAfterClass restores the documented
        // 8.1+ default rather than a captured value. Leaving it set would
        // decide, by class order, whether a later suite's mysqli throws or
        // returns false.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$reportModeChanged = true;

        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return; // connection failed; tests will skip
        }
        if (!$db) {
            return;
        }

        // The same DDL the installer and the 1.9.76 upgrade step run.
        foreach (AttributionPostbackTables::getDefinitions() as $definition) {
            $db->query($definition->createStatement);
        }
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::$db->close();
            self::$db = null;
        }
        if (self::$reportModeChanged) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            self::$reportModeChanged = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        self::$db->query('TRUNCATE TABLE 202_attribution_postbacks');
        self::$db->query('TRUNCATE TABLE 202_attribution_apps');
    }

    public function testAByteIdenticalRetryIsStoredOnceAndAnsweredDuplicate(): void
    {
        $receiver = $this->receiver();
        $body = $this->postback();

        $first = $receiver->receive($body, '203.0.113.9', 1_800_000_000);
        $this->assertSame(200, $first['status']);
        $this->assertFalse($first['body']['data']['duplicate']);

        $retry = $receiver->receive($body, '203.0.113.9', 1_800_000_005);
        $this->assertSame(200, $retry['status']);
        $this->assertTrue($retry['body']['data']['duplicate']);

        $this->assertSame(1, $this->rowCount());
    }

    public function testAForgeryArrivingFirstDoesNotTakeTheGenuinePostbacksSlot(): void
    {
        $receiver = $this->receiver();
        // The same network and transaction id, which an attacker can copy;
        // a different conversion value and signature, which a forgery carries.
        $forged = $this->postback(['conversion-value' => 63, 'attribution-signature' => 'AQ==']);
        $genuine = $this->postback();

        $this->assertFalse($receiver->receive($forged, '198.51.100.7', 1_800_000_000)['body']['data']['duplicate']);

        $second = $receiver->receive($genuine, '203.0.113.9', 1_800_000_001);
        $this->assertSame(200, $second['status']);
        $this->assertFalse(
            $second['body']['data']['duplicate'],
            'the genuine postback must be stored, not collapsed onto the forgery that arrived first'
        );
        $this->assertSame(2, $this->rowCount());
    }

    public function testRowsCarryTheRegisteredAppsOwnerAndStayUnownedOtherwise(): void
    {
        self::$db->query(
            "INSERT INTO 202_attribution_apps (user_id, app_id, app_name, schema_token, created_at, updated_at)
             VALUES (7, 525463029, 'Owned app', 'integration-token', 1, 1)"
        );
        $receiver = $this->receiver();

        $receiver->receive($this->postback(), '203.0.113.9', 1_800_000_000);
        $receiver->receive(
            $this->postback(['app-id' => 999000111, 'transaction-id' => 'unregistered-app-1']),
            '203.0.113.9',
            1_800_000_000
        );

        $result = self::$db->query('SELECT app_id, user_id FROM 202_attribution_postbacks ORDER BY postback_id');
        $rows = array_map(
            static fn(array $row): array => ['app_id' => (int) $row['app_id'], 'user_id' => (int) $row['user_id']],
            $result->fetch_all(MYSQLI_ASSOC)
        );
        $this->assertSame(
            [
                ['app_id' => 525463029, 'user_id' => 7],
                ['app_id' => 999000111, 'user_id' => 0],
            ],
            $rows
        );
    }

    private function receiver(): PostbackReceiver
    {
        return new PostbackReceiver(self::$db, new PostbackVerifier());
    }

    /** @param array<string, mixed> $overrides */
    private function postback(array $overrides = []): string
    {
        $encoded = json_encode($overrides + [
            'version' => '4.0',
            'ad-network-id' => 'integration.skadnetwork',
            'source-identifier' => '4213',
            'app-id' => 525463029,
            'transaction-id' => '6aafb7a5-0170-41b5-bbe4-fe71dedf1e28',
            'redownload' => false,
            'fidelity-type' => 1,
            'did-win' => true,
            'conversion-value' => 21,
            'postback-sequence-index' => 0,
            // Not Apple's signature: stored flagged (signature_valid = 0),
            // which is the receiver's documented handling of a forgery.
            'attribution-signature' => 'AA==',
        ]);
        $this->assertNotFalse($encoded);
        return $encoded;
    }

    private function rowCount(): int
    {
        $result = self::$db->query('SELECT COUNT(*) AS c FROM 202_attribution_postbacks');
        return (int) $result->fetch_assoc()['c'];
    }
}

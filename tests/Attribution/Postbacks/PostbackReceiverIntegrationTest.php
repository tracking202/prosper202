<?php

declare(strict_types=1);

namespace Tests\Attribution\Postbacks;

use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\JwsVerifier;
use Api\V3\Attribution\PostbackReceiver;
use Api\V3\Attribution\PostbackVerifier;
use Api\V3\Attribution\SkadnetworkProtocol;
use PHPUnit\Framework\TestCase;

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
    use ConnectsToTestDatabase;

    public static function setUpBeforeClass(): void
    {
        self::connectTestDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        self::disconnectTestDatabase();
    }

    protected function setUp(): void
    {
        $this->requireEmptyAttributionTables();
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

        $result = self::$db->query(
            'SELECT app_id, user_id, protocol, signature_state, signature_valid, conversion_type, ad_interaction_type
             FROM 202_attribution_postbacks ORDER BY postback_id'
        );
        $rows = array_map(
            static fn(array $row): array => [
                'app_id' => (int) $row['app_id'],
                'user_id' => (int) $row['user_id'],
                'protocol' => $row['protocol'],
                'signature_state' => $row['signature_state'],
                'signature_valid' => $row['signature_valid'] === null ? null : (int) $row['signature_valid'],
                'conversion_type' => $row['conversion_type'],
                'ad_interaction_type' => $row['ad_interaction_type'],
            ],
            $result->fetch_all(MYSQLI_ASSOC)
        );
        $expected = [
            'protocol' => 'skadnetwork',
            'signature_state' => 'invalid',
            'signature_valid' => 0,
            'conversion_type' => 'download',
            'ad_interaction_type' => 'click',
        ];
        $this->assertSame(
            [
                ['app_id' => 525463029, 'user_id' => 7] + $expected,
                ['app_id' => 999000111, 'user_id' => 0] + $expected,
            ],
            $rows
        );
    }

    public function testAnAdAttributionKitDevelopmentPostbackIsTrustedOnlyOnceItsAppOptsIn(): void
    {
        // Apple's documented example carries a genuine development-key
        // signature (apple-development-identifier/1), so this is the real
        // verifier, the real DDL and the real opt-in column end to end.
        $receiver = new PostbackReceiver(self::$db, new AdAttributionKitProtocol(new JwsVerifier()));

        $unclaimed = $receiver->receive((string) json_encode(AdAttributionKitFixtures::exampleBody()), '203.0.113.9', 1_800_000_000);
        $this->assertSame(200, $unclaimed['status']);
        $this->assertSame('development', $unclaimed['body']['data']['signature']);

        self::$db->query(
            "INSERT INTO 202_attribution_apps (user_id, app_id, app_name, accept_development_postbacks, schema_token, created_at, updated_at)
             VALUES (7, " . AdAttributionKitFixtures::EXAMPLE_APP_ID . ", 'Opted-in app', 1, 'integration-token-aak', 1, 1)"
        );
        // A different unsigned field makes a different body, so this is a
        // second postback rather than a deduped retry of the first.
        $optedIn = $receiver->receive(
            (string) json_encode(AdAttributionKitFixtures::exampleBody(['country-code' => 'CA'])),
            '203.0.113.9',
            1_800_000_001
        );
        $this->assertSame(200, $optedIn['status']);
        $this->assertFalse($optedIn['body']['data']['duplicate']);

        $result = self::$db->query(
            'SELECT user_id, protocol, version, transaction_id, app_id, signature_state, signature_valid, key_id,
                    conversion_type, ad_interaction_type, conversion_value, source_app_id, marketplace_id, country_code
             FROM 202_attribution_postbacks ORDER BY postback_id'
        );
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(2, $rows);

        $shared = [
            'protocol' => 'adattributionkit',
            'version' => null,
            'transaction_id' => AdAttributionKitFixtures::EXAMPLE_POSTBACK_ID,
            'app_id' => (string) AdAttributionKitFixtures::EXAMPLE_APP_ID,
            'signature_state' => 'development',
            'key_id' => AdAttributionKitFixtures::EXAMPLE_KEY_ID,
            'conversion_type' => 're-engagement',
            'ad_interaction_type' => 'click',
            'conversion_value' => '24',
            'source_app_id' => '0',
            'marketplace_id' => 'com.apple.AppStore',
        ];
        $this->assertSame(
            ['user_id' => '0', 'signature_valid' => null, 'country_code' => 'US'] + $shared,
            ['user_id' => $rows[0]['user_id'], 'signature_valid' => $rows[0]['signature_valid'], 'country_code' => $rows[0]['country_code']] + array_intersect_key($rows[0], $shared),
            'before the registration: unclaimed, and nobody vouches for a development signature'
        );
        $this->assertSame(
            ['user_id' => '7', 'signature_valid' => '1', 'country_code' => 'CA'] + $shared,
            ['user_id' => $rows[1]['user_id'], 'signature_valid' => $rows[1]['signature_valid'], 'country_code' => $rows[1]['country_code']] + array_intersect_key($rows[1], $shared),
            'after the opt-in: owned and trusted'
        );
    }

    private function receiver(): PostbackReceiver
    {
        return new PostbackReceiver(self::$db, new SkadnetworkProtocol(new PostbackVerifier()));
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

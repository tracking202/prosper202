<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlPersonalizationRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Personalization token semantics: mint stores only a hash, redemption is
 * allowlist-driven, sealed snapshots replay verbatim, expiry and malformed
 * input return the uniform empty payload. Uses the shared fake connection.
 */
final class PersonalizationTest extends TestCase
{
    public function testMintStoresHashNotRawToken(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlPersonalizationRepository($conn);

        $raw = $repo->mint(7, 501, 12345, 1700000000);

        // base64url of 32 random bytes = 43 chars, URL/cookie-safe alphabet.
        self::assertSame(43, strlen($raw));
        self::assertSame(1, preg_match('/^[A-Za-z0-9_\-]+$/', $raw));

        $inserts = $write->statementsContaining('INSERT INTO 202_personalization_tokens');
        self::assertCount(1, $inserts);
        self::assertContains(hash('sha256', $raw, true), $inserts[0]->boundValues, 'hash must be stored');
        foreach ($inserts[0]->boundValues as $bound) {
            self::assertFalse($bound === $raw, 'the raw token must never be stored');
        }
        // Dual window: first use 60 min, replay 30 days.
        self::assertContains(1700000000 + 3600, $inserts[0]->boundValues);
        self::assertContains(1700000000 + 2592000, $inserts[0]->boundValues);
    }

    public function testEngagementStampingRunsWhenPersonalizationIsOff(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        $fake = new FakeMysqliConnection();
        // The beacon carries an explicit cust ref that resolves to customer 501.
        $fake->whenQueryContainsReturnRows('FROM 202_customer_aliases', [['customer_id' => 501]]);
        $fake->whenQueryContainsReturnRows('merged_into_customer_id', [['merged_into_customer_id' => null]]);
        // No personalization-fields pref row: the allowlist is empty, so the
        // account has personalization OFF — but that must only suppress the
        // token, not the ABM stamping.

        $js = p202MintPersonalizationCookieJs($fake, 7, ['cust' => 'stamp-1'], 999);

        self::assertSame('', $js, 'no token cookie when personalization is off');
        $stamps = $fake->statementsContaining('INSERT INTO 202_clicks_tracking');
        self::assertCount(1, $stamps, 'the pageview click must still be stamped for engagement/ABM');
        self::assertSame([999, 501], $stamps[0]->boundValues);
        self::assertCount(1, $fake->statementsContaining('last_activity_time = GREATEST'), 'activity recency must still update');
    }

    public function testRedeemRejectsMalformedTokensWithoutTouchingTheDatabase(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlPersonalizationRepository($conn);

        self::assertSame([], $repo->redeem('', 1700000000));
        self::assertSame([], $repo->redeem('short', 1700000000));
        self::assertSame([], $repo->redeem(str_repeat('!', 43), 1700000000));
        self::assertSame([], $repo->redeem(str_repeat('a', 100), 1700000000));
        self::assertCount(0, $write->statements, 'malformed tokens must not reach the database');
    }

    public function testRedeemUnknownOrPastReplayWindowReturnsEmpty(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlPersonalizationRepository($conn);

        // Unknown token: lookup returns no row.
        self::assertSame([], $repo->redeem(str_repeat('a', 43), 1700000000));

        // Past replay window.
        $write2 = new FakeMysqliConnection();
        $write2->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => null, 'snapshot' => null,
        ]]);
        $repo2 = new MysqlPersonalizationRepository(new Connection($write2, new FakeMysqliConnection()));
        self::assertSame([], $repo2->redeem(str_repeat('a', 43), 1702592001));
    }

    public function testFirstUseAfterDeadlineReturnsEmptyAndDoesNotSeal(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => null, 'snapshot' => null,
        ]]);
        $repo = new MysqlPersonalizationRepository(new Connection($write, new FakeMysqliConnection()));

        // After the first-use window but inside the replay window: an
        // unredeemed token is dead — fresh data may no longer be pulled.
        self::assertSame([], $repo->redeem(str_repeat('a', 43), 1700010000));
        self::assertCount(0, $write->statementsContaining('UPDATE 202_personalization_tokens'));
    }

    public function testSealedTokenReplaysSnapshotVerbatimNeverFreshData(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => 1700000100,
            'snapshot' => '{"first_name":"John"}',
        ]]);
        // The current allowlist still permits the sealed field.
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name',
        ]]);
        // Even though the customer's CRM row now says something different...
        $write->whenQueryContainsReturnRows('FROM 202_customers', [['first_name' => 'CHANGED']]);

        $repo = new MysqlPersonalizationRepository(new Connection($write, $read));
        $payload = $repo->redeem(str_repeat('a', 43), 1700100000);

        // ...the sealed snapshot wins: nothing new ever comes out of a token.
        self::assertSame(['first_name' => 'John'], $payload);
        self::assertCount(0, $write->statementsContaining('FROM 202_customers'), 'replay must not read live customer data');
    }

    public function testReplayRespectsTheCurrentAllowlist(): void
    {
        $tokenRow = [
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => 1700000100,
            'snapshot' => '{"first_name":"John","city":"Austin"}',
        ];

        // Field removed from the allowlist: the sealed value stops serving.
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [$tokenRow]);
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'city',
        ]]);
        $repo = new MysqlPersonalizationRepository(new Connection($write, $read));
        self::assertSame(['city' => 'Austin'], $repo->redeem(str_repeat('a', 43), 1700100000), 'revoked fields are filtered out of replays');

        // Allowlist cleared entirely (personalization off): replays go dark.
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [$tokenRow]);
        $repo = new MysqlPersonalizationRepository(new Connection($write, new FakeMysqliConnection()));
        self::assertSame([], $repo->redeem(str_repeat('a', 43), 1700100000), 'turning personalization off revokes sealed snapshots immediately');
    }

    public function testTokenIsUsableStates(): void
    {
        $case = static function (?array $row): MysqlPersonalizationRepository {
            $read = new FakeMysqliConnection();
            if ($row !== null) {
                $read->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [$row]);
            }
            return new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));
        };
        $token = str_repeat('a', 43);

        self::assertTrue($case(['first_use_deadline' => 1700003600, 'replay_until' => 1702592000, 'redeemed_at' => null])
            ->tokenIsUsable($token, 1700000100), 'unredeemed inside the first-use window');
        self::assertFalse($case(['first_use_deadline' => 1700003600, 'replay_until' => 1702592000, 'redeemed_at' => null])
            ->tokenIsUsable($token, 1700010000), 'expired before first use = dead');
        self::assertTrue($case(['first_use_deadline' => 1700003600, 'replay_until' => 1702592000, 'redeemed_at' => 1700000100])
            ->tokenIsUsable($token, 1701000000), 'sealed inside the replay window');
        self::assertFalse($case(['first_use_deadline' => 1700003600, 'replay_until' => 1702592000, 'redeemed_at' => 1700000100])
            ->tokenIsUsable($token, 1703000000), 'past replay_until = dead');
        self::assertFalse($case(null)->tokenIsUsable($token, 1700000100), 'unknown token = dead');
        self::assertFalse($case(null)->tokenIsUsable('not a token', 1700000100), 'malformed = dead without a lookup');
    }

    public function testDeadPageTokenDoesNotBlockReminting(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        $fake = new FakeMysqliConnection();
        // The LP reports a token that expired before first use — dead.
        $fake->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'first_use_deadline' => 1000, 'replay_until' => 2592000, 'redeemed_at' => null,
        ]]);
        // The visitor is recognized via the subid cookie's stamped click.
        $fake->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [['customer_id' => 501]]);
        $fake->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name',
        ]]);
        $fake->whenQueryContainsReturnRows('FROM 202_customers WHERE customer_id = ? AND user_id = ? LIMIT 1', [['first_name' => 'John']]);

        $_COOKIE['tracking202subid'] = '777';
        try {
            $js = p202MintPersonalizationCookieJs($fake, 7, ['p13n_have' => str_repeat('b', 43)], 999);
        } finally {
            unset($_COOKIE['tracking202subid']);
        }

        self::assertStringContainsString('createCookie', $js, 'a dead page token must not suppress the remint');
        self::assertCount(1, $fake->statementsContaining('INSERT INTO 202_personalization_tokens'), 'a replacement token is minted');
    }

    public function testUsablePageTokenStillSuppressesRemintWithoutExplicitSignal(): void
    {
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        $fake = new FakeMysqliConnection();
        // Sealed and still replayable: the page's token is fine.
        $fake->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'first_use_deadline' => 1000, 'replay_until' => PHP_INT_MAX, 'redeemed_at' => 500,
        ]]);
        $fake->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [['customer_id' => 501]]);
        $fake->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name',
        ]]);

        $_COOKIE['tracking202subid'] = '777';
        try {
            $js = p202MintPersonalizationCookieJs($fake, 7, ['p13n_have' => str_repeat('c', 43)], 999);
        } finally {
            unset($_COOKIE['tracking202subid']);
        }

        self::assertSame('', $js, 'repeat pageviews within a visit reuse the existing token');
        self::assertCount(0, $fake->statementsContaining('INSERT INTO 202_personalization_tokens'), 'no needless re-mint');
    }

    public function testFirstRedemptionBuildsAllowlistedPayloadAndSeals(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => null, 'snapshot' => null,
        ]]);
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name, city, email, cf:loyalty_tier',
        ]]);
        $read->whenQueryContainsReturnRows('FROM 202_customers', [[
            'first_name' => 'John', 'city' => 'Austin',
        ]]);
        $read->whenQueryContainsReturnRows('FROM 202_customer_fields', [[
            'field_type' => 'select', 'value_text' => 'gold', 'value_number' => null, 'value_date' => null,
        ]]);
        // The fake statement cannot report affected_rows (PHP 8.4 readonly
        // internals), so the atomic seal takes the concurrent-loser branch and
        // re-reads the winner's snapshot; feed it the sealed value.
        $write->whenQueryContainsReturnRows('SELECT snapshot FROM 202_personalization_tokens', [[
            'snapshot' => '{"first_name":"John","city":"Austin","loyalty_tier":"gold"}',
        ]]);

        $repo = new MysqlPersonalizationRepository(new Connection($write, $read));
        $payload = $repo->redeem(str_repeat('a', 43), 1700000100);

        // 'email' is in the pref but NOT in ALLOWED_CRM_FIELDS: it must be
        // silently ineligible — PII never reaches a landing page.
        self::assertSame(['first_name' => 'John', 'city' => 'Austin', 'loyalty_tier' => 'gold'], $payload);

        $seals = $write->statementsContaining('UPDATE 202_personalization_tokens SET redeemed_at');
        self::assertCount(1, $seals);
        self::assertStringContainsString('redeemed_at IS NULL', $seals[0]->sql, 'seal must be atomic (claim-once)');
        self::assertContains('{"first_name":"John","city":"Austin","loyalty_tier":"gold"}', $seals[0]->boundValues);
    }

    public function testSealRaceLoserDoesNotRecordAnImpression(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => null, 'snapshot' => null,
        ]]);
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'rec:next_offer',
        ]]);
        // The recommendation resolves via the account fallback.
        $read->whenQueryContainsReturnRows('FROM 202_conversion_logs cl', [
            ['campaign_id' => 33, 'name' => 'Top Seller', 'url' => 'https://example.com/top'],
        ]);
        // The fake statement cannot report affected_rows, so the atomic seal
        // takes the concurrent-LOSER branch — exactly the path under test.
        $write->whenQueryContainsReturnRows('SELECT snapshot FROM 202_personalization_tokens', [[
            'snapshot' => '{"next_offer_name":"Top Seller"}',
        ]]);

        $repo = new MysqlPersonalizationRepository(new Connection($write, $read));
        $payload = $repo->redeem(str_repeat('a', 43), 1700000100);

        self::assertSame(['next_offer_name' => 'Top Seller'], $payload, 'the loser renders the winner snapshot');
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_offer_recommendations'),
            'losing the seal race must not count an extra impression — one visit, one impression');
    }

    public function testAllowedFieldsFiltersDisallowedEntries(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name, email, phone, address_line1, total_revenue, cf:tier, bogus',
        ]]);
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));

        // Only the safe subset survives.
        self::assertSame(['first_name', 'cf:tier'], $repo->allowedFields(7));
    }

    public function testResolveVisitorCustomerIgnoresUnknownRefsAndUsesStampedClick(): void
    {
        $read = new FakeMysqliConnection();
        // No alias matches; the cookie click carries a stamped customer.
        $read->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [['customer_id' => 501]]);
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));

        self::assertSame(501, $repo->resolveVisitorCustomer(7, ['cust' => 'nobody-knows-this'], 12345));
    }

    public function testResolveVisitorCustomerLookupIsAliasTyped(): void
    {
        // The same alias VALUE can belong to different customers under
        // different types — an untyped lookup could seal someone else's CRM
        // data into a token. The query must carry the declared type, and an
        // unknown type must not fall back to an untyped match.
        $read = new FakeMysqliConnection();
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));

        $repo->resolveVisitorCustomer(7, ['cust' => '123', 'cust_type' => 'merchant_id'], 0);
        $lookups = $read->statementsContaining('FROM 202_customer_aliases');
        self::assertCount(1, $lookups);
        self::assertStringContainsString('alias_type = ?', $lookups[0]->sql);
        self::assertSame('iss', $lookups[0]->boundTypes);
        self::assertContains('merchant_id', $lookups[0]->boundValues);

        $untyped = new FakeMysqliConnection();
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $untyped));
        $repo->resolveVisitorCustomer(7, ['cust' => '123'], 0);
        self::assertContains('custom', $untyped->statementsContaining('FROM 202_customer_aliases')[0]->boundValues, 'untyped refs default to custom, mirroring ingest');

        $bogus = new FakeMysqliConnection();
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $bogus));
        self::assertNull($repo->resolveVisitorCustomer(7, ['cust' => '123', 'cust_type' => 'nonsense'], 0));
        self::assertCount(0, $bogus->statementsContaining('FROM 202_customer_aliases'), 'unknown types must not query at all');
    }
}

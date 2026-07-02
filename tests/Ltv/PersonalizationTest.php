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
        $write->whenQueryContainsReturnRows('FROM 202_personalization_tokens WHERE token_hash', [[
            'p13n_id' => 1, 'user_id' => 7, 'customer_id' => 501,
            'first_use_deadline' => 1700003600, 'replay_until' => 1702592000,
            'redeemed_at' => 1700000100,
            'snapshot' => '{"first_name":"John"}',
        ]]);
        // Even though the customer's CRM row now says something different...
        $write->whenQueryContainsReturnRows('FROM 202_customers', [['first_name' => 'CHANGED']]);

        $repo = new MysqlPersonalizationRepository(new Connection($write, new FakeMysqliConnection()));
        $payload = $repo->redeem(str_repeat('a', 43), 1700100000);

        // ...the sealed snapshot wins: nothing new ever comes out of a token.
        self::assertSame(['first_name' => 'John'], $payload);
        self::assertCount(0, $write->statementsContaining('FROM 202_customers'), 'replay must not read live customer data');
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
}

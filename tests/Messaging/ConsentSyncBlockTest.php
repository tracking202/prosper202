<?php
// tests/Messaging/ConsentSyncBlockTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../../202-config/Messaging/MessagingClient.class.php';
require_once __DIR__ . '/../../202-config/Messaging/MessagingService.class.php';

/**
 * Captures wire calls so the consent block can be asserted on the exact
 * identity payload that would have been transmitted — DB-less counterpart of
 * ConsentSyncFakeClient in ConsentSyncIntegrationTest.
 */
final class ConsentBlockFakeClient extends MessagingClient
{
    /** @var array<int,array{identity:array,cursor:?string}> */
    public array $pullCalls = [];
    /** @var array<int,array{identity:array,attributes:array,events:array,consent:?array}> */
    public array $trackCalls = [];

    public function pull(array $identity, ?string $cursor): ?array
    {
        $this->pullCalls[] = ['identity' => $identity, 'cursor' => $cursor];
        return ['conversations' => [], 'cursor' => 'cur-1'];
    }

    public function send(array $identity, ?string $conversationExternalId, string $body, string $clientToken): ?array
    {
        return null;
    }

    public function markRead(array $identity, array $externalIds): ?array
    {
        return ['ok' => true];
    }

    public function track(array $identity, array $attributes, array $events, ?array $consent = null): ?array
    {
        $this->trackCalls[] = ['identity' => $identity, 'attributes' => $attributes, 'events' => $events, 'consent' => $consent];
        return ['ok' => true];
    }
}

/**
 * Receiver spec §6 CONTRACT DELTA: the client must transmit its consent state
 * to the central server — attached to identity on every endpoint and as a
 * TOP-LEVEL "consent" block in the /track body. Consent state is operational
 * bookkeeping: it is sent for ALL users, including analytics-denied ones (the
 * server must learn a user is denied), so it must never ride inside the
 * attributes-strip branch.
 *
 * Failure semantics (the contract): on ANY DB failure or a genuine missing
 * row, exportForSync returns the explicit all-unset shape — absence/unset
 * must read as "no grant" server-side (fail closed), so a failure can never
 * masquerade as a grant on the wire. Failure and missing-row remain
 * distinguished inside exportForSync (a false get_result() on a SELECT is a
 * fetch FAILURE, logged as one; a genuine zero-row SELECT still yields a
 * mysqli_result) even though both export the same fail-closed shape.
 * Behavioral proof for the DB read lives in ConsentSyncIntegrationTest-style
 * DB-gated tests; these are source guards plus the DB-less fail-closed checks.
 */
final class ConsentSyncBlockTest extends TestCase
{
    private const ALL_UNSET = [
        'analytics'          => 'unset',
        'analytics_source'   => null,
        'analytics_at'       => null,
        'email_marketing'    => 'unset',
        'email_marketing_at' => null,
    ];

    private function policySrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php');
    }

    private function serviceSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingService.class.php');
    }

    private function clientSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingClient.class.php');
    }

    // ------------------------------------------------------------------
    // ConsentPolicy::exportForSync — the ONLY reader of raw consent columns
    // ------------------------------------------------------------------

    public function test_export_for_sync_exists_and_selects_all_five_columns(): void
    {
        $src = $this->policySrc();
        $this->assertStringContainsString(
            'public static function exportForSync(mysqli $db, int $userId): array',
            $src,
            'ConsentPolicy must expose exportForSync(mysqli, int): array (never null — the contract shape is total)'
        );
        // The SELECT inside exportForSync must read all five consent columns.
        // (loadPref reads only the two enum columns; record() only writes.)
        $this->assertMatchesRegularExpression(
            '/function exportForSync.*?SELECT.*?`analytics_consent`.*?`analytics_consent_source`.*?`analytics_consent_at`.*?`email_marketing_consent`.*?`email_marketing_consent_at`.*?FROM `202_users_pref`/s',
            $src,
            'exportForSync must SELECT all five consent columns from 202_users_pref'
        );
    }

    public function test_export_fails_closed_to_all_unset_on_db_failure(): void
    {
        // Same failure shape as ConsentPolicyTest: prepare() fails (mid-retry
        // migration under the UI path's non-throwing mysqli report mode).
        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn(false);

        $this->assertSame(self::ALL_UNSET, ConsentPolicy::exportForSync($db, 7),
            'a lookup failure must export the all-unset shape (fail closed: absence reads as "no grant" server-side)');
    }

    public function test_export_fails_closed_to_all_unset_on_get_result_failure(): void
    {
        // A successful SELECT always yields a mysqli_result, even with zero
        // rows. get_result() === false is therefore a fetch FAILURE
        // (server-gone-away mid-fetch, mysqlnd OOM) — NOT a missing row —
        // and must take the failure path, not the missing-row path.
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn(false);
        $stmt->method('close')->willReturn(true);

        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertSame(self::ALL_UNSET, ConsentPolicy::exportForSync($db, 7),
            'a get_result() fetch failure must export the fail-closed all-unset shape');
    }

    public function test_export_returns_all_unset_for_a_genuine_missing_row(): void
    {
        // prepare/bind/execute succeed and the SELECT yields a result set
        // with zero rows (fetch_assoc() → null): a user with no
        // 202_users_pref row genuinely never answered. Same fail-closed
        // all-unset shape, but via the missing-row path, not the failure path.
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(null);

        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn($stmt);

        $this->assertSame(self::ALL_UNSET, ConsentPolicy::exportForSync($db, 7),
            'a genuine missing row (zero-row result set) must export the all-unset shape');
    }

    public function test_export_distinguishes_fetch_failure_from_missing_row(): void
    {
        // Guard the loadPref-style raw-mysqli checks: exportForSync must not
        // route through a helper that conflates get_result() === false with a
        // zero-row result (Connection::fetchOne does). CLAUDE.md #5 — the
        // same failure class is already handled correctly in loadPref.
        $src = $this->policySrc();
        preg_match('/function exportForSync.*?\n    \}/s', $src, $m);
        $this->assertNotEmpty($m, 'exportForSync must exist');
        $body = $m[0];
        $this->assertStringContainsString('get_result()', $body,
            'exportForSync must inspect get_result() itself (mirroring loadPref)');
        $this->assertMatchesRegularExpression('/\$res === false/', $body,
            'exportForSync must treat get_result() === false as a fetch failure, distinct from a zero-row result');
        $this->assertStringNotContainsString('fetchOne', $body,
            'Connection::fetchOne conflates a get_result() failure with a missing row — exportForSync must not use it');
    }

    public function test_consent_policy_stays_the_only_reader_of_raw_consent_columns(): void
    {
        // Neither MessagingService nor MessagingClient may touch the raw
        // consent columns — they consume ConsentPolicy's export only.
        foreach (['MessagingService' => $this->serviceSrc(), 'MessagingClient' => $this->clientSrc()] as $name => $src) {
            $this->assertStringNotContainsString('analytics_consent', $src,
                "{$name} must not read raw consent columns");
            $this->assertStringNotContainsString('email_marketing_consent', $src,
                "{$name} must not read raw consent columns");
        }
    }

    // ------------------------------------------------------------------
    // MessagingService — consent attached to identity for ALL users
    // ------------------------------------------------------------------

    public function test_outbound_identity_attaches_consent_export_unconditionally(): void
    {
        $src = $this->serviceSrc();
        preg_match(
            '/private function outboundIdentity\(\): array.*?\n    \}/s',
            $src,
            $m
        );
        $this->assertNotEmpty($m, 'outboundIdentity() must exist');
        $body = $m[0];
        $this->assertStringContainsString("\$identity['consent'] = \$this->consentExport();", $body,
            'outboundIdentity() must attach the cached consent export');
        $this->assertStringNotContainsString('!== null', $body,
            'the attach must be unconditional — exportForSync is total (fail-closed all-unset), never null');
    }

    public function test_consent_is_not_stripped_with_attributes(): void
    {
        $src = $this->serviceSrc();
        // Extract the attributes-strip branch and prove consent is NOT inside
        // it: denied users must still transmit their (denied) consent state.
        preg_match('/if \(!\$this->analyticsSyncAllowed\(\)\) \{[^}]*\}/s', $src, $m);
        $this->assertNotEmpty($m, 'the attributes-strip branch must exist');
        $this->assertStringNotContainsString('consent', $m[0],
            'consent is operational bookkeeping — it must not sit under the attributes-strip branch');
        $this->assertStringContainsString("unset(\$identity['attributes'])", $m[0]);
    }

    public function test_consent_export_is_cached_per_request(): void
    {
        $src = $this->serviceSrc();
        $this->assertStringContainsString('private ?array $consentExport = null;', $src,
            'the export must be cached per instance like analyticsSyncAllowed');
        $this->assertMatchesRegularExpression(
            '/private function consentExport\(\): array\s*\{\s*if \(\$this->consentExport === null\) \{.*?ConsentPolicy::exportForSync\(\$this->db, \$this->userId\)/s',
            $src,
            'consentExport() must null-check-cache ConsentPolicy::exportForSync exactly the way analyticsSyncAllowed() caches'
        );
        $this->assertStringNotContainsString('consentExportLoaded', $src,
            'no extra loaded flag: the export is total (never null), so the null check alone guards the cache');
    }

    public function test_track_flush_passes_the_cached_export_top_level(): void
    {
        $this->assertMatchesRegularExpression(
            '/client\(\)->track\(\s*\$this->outboundIdentity\(\),\s*\$attributes,\s*\$payloadEvents,\s*\$this->consentExport\(\)\s*\)/',
            $this->serviceSrc(),
            'flushEvents must pass the same cached export as the top-level /track consent block'
        );
    }

    public function test_db_failure_still_transmits_the_fail_closed_all_unset_block(): void
    {
        // Every DB call fails (prepare → false): the transient-failure window.
        // The sync must still run, and the identity that reaches the wire must
        // carry the explicit fail-closed all-unset consent block (the contract:
        // absence/unset reads as "no grant" server-side).
        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn(false);

        $service = new MessagingService($db, 7, [
            'install_hash' => 'h',
            'api_key'      => 'k',
            'user_id'      => 7,
            'attributes'   => ['clicks_30d' => 9],
        ]);
        $fake = new ConsentBlockFakeClient();
        $prop = new ReflectionProperty(MessagingService::class, 'client');
        $prop->setAccessible(true);
        $prop->setValue($service, $fake);

        $service->sync(true);

        $this->assertCount(1, $fake->pullCalls);
        $identity = $fake->pullCalls[0]['identity'];
        $this->assertSame(self::ALL_UNSET, $identity['consent'] ?? null,
            'a DB failure must transmit the fail-closed all-unset consent block, never an accidental grant');
        // The consent lookup failing also means analyticsAllowed fails closed,
        // so the analytics-tier snapshot must be stripped as before.
        $this->assertArrayNotHasKey('attributes', $identity,
            'a consent lookup failure must still fail CLOSED for the attribute snapshot');
    }

    // ------------------------------------------------------------------
    // MessagingClient::track — optional, backward-compatible consent param
    // ------------------------------------------------------------------

    public function test_track_signature_accepts_optional_nullable_consent(): void
    {
        $method = new ReflectionMethod(MessagingClient::class, 'track');
        $params = $method->getParameters();
        $this->assertCount(4, $params, 'track() must gain exactly one extra parameter');

        $consent = $params[3];
        $this->assertSame('consent', $consent->getName());
        $this->assertTrue($consent->allowsNull(), 'consent must be nullable (?array)');
        $this->assertTrue($consent->isDefaultValueAvailable(), 'consent must default (backward-compatible)');
        $this->assertNull($consent->getDefaultValue(), 'consent must default to null');
        $type = $consent->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('array', $type->getName());
    }

    public function test_track_payload_includes_top_level_consent_when_present(): void
    {
        $src = $this->clientSrc();
        preg_match('/public function track\(.*?\n    \}/s', $src, $m);
        $this->assertNotEmpty($m, 'track() must exist');
        $body = $m[0];
        // Top-level sibling of identity/attributes/events (spec §6), only
        // when non-null so older call sites keep the old wire shape.
        $this->assertStringContainsString('if ($consent !== null) {', $body);
        $this->assertStringContainsString("\$payload['consent'] = \$consent;", $body);
    }
}

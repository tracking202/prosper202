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
 * Receiver spec §6 CONTRACT DELTA: the client transmits its consent state to
 * the central server, attached to identity and as a TOP-LEVEL "consent" block
 * in the /track body, for ALL users, including analytics-denied ones (the
 * server must learn a user is denied), so it never rides inside the
 * attributes-strip branch. The block carries its version, which the server
 * acknowledges (`consent_ack`) once stored.
 *
 * Failure semantics: a missing row is the all-unset block at version 0. A
 * failed read throws and the delivery fails: a stand-in block would overwrite
 * a stored denial with "unset" and could be acknowledged as the user's choice.
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

    private function serviceSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingService.class.php');
    }

    private function clientSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingClient.class.php');
    }

    /** A mysqli whose statement executes and then answers $result from get_result(). */
    private function dbAnswering(mysqli_result|false $result): mysqli
    {
        $stmt = $this->createMock(mysqli_stmt::class);
        $stmt->method('bind_param')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('get_result')->willReturn($result);
        $stmt->method('close')->willReturn(true);

        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn($stmt);
        return $db;
    }

    public function test_a_failed_prepare_is_an_error_not_an_unset_block(): void
    {
        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn(false);

        $this->expectException(\Throwable::class);
        ConsentPolicy::deliveryState($db, 7);
    }

    public function test_a_failed_fetch_is_an_error_not_a_missing_row(): void
    {
        // A successful SELECT always yields a result, even with zero rows:
        // get_result() === false is a fetch failure, never "no row".
        $this->expectException(\Throwable::class);
        ConsentPolicy::deliveryState($this->dbAnswering(false), 7);
    }

    public function test_a_genuine_missing_row_is_the_all_unset_block_at_version_zero(): void
    {
        $result = $this->createMock(mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(null);

        $state = ConsentPolicy::deliveryState($this->dbAnswering($result), 7);
        $this->assertSame(self::ALL_UNSET, $state['consent']);
        $this->assertSame(0, $state['version']);
        $this->assertFalse($state['dirty'], 'nothing was ever chosen, so nothing waits for delivery');
        $this->assertFalse($state['analytics_allowed'], 'no row: the location is unknown, so analytics is held');
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

    public function test_a_db_failure_fails_the_delivery_and_sends_nothing(): void
    {
        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn(false);

        $service = new MessagingService($db, 7, ['install_hash' => 'h', 'api_key' => 'k', 'user_id' => 7]);
        $fake = new ConsentBlockFakeClient();
        $prop = new ReflectionProperty(MessagingService::class, 'client');
        $prop->setValue($service, $fake);

        $this->assertSame(MessagingService::SYNC_FAILED, $service->sync(true));
        $this->assertSame([], $fake->trackCalls);
    }

    public function test_consent_rides_for_a_user_without_analytics_and_attributes_do_not(): void
    {
        $service = new MessagingService($this->createMock(mysqli::class), 7, [
            'install_hash' => 'h', 'api_key' => 'k', 'user_id' => 7, 'attributes' => ['clicks_30d' => 9],
        ]);
        $method = new ReflectionMethod(MessagingService::class, 'outboundIdentity');
        $denied = ['analytics' => 'denied'] + self::ALL_UNSET;

        $identity = $method->invoke($service, false, ['clicks_30d' => 9], $denied);
        $this->assertSame($denied, $identity['consent']);
        $this->assertArrayNotHasKey('attributes', $identity);

        $identity = $method->invoke($service, true, ['clicks_30d' => 10], self::ALL_UNSET);
        $this->assertSame(['clicks_30d' => 10], $identity['attributes'], 'the snapshot this delivery read, not the one cached at construction');
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

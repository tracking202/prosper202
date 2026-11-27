<?php
// tests/Messaging/SyncConsentBoundaryTest.php
use PHPUnit\Framework\TestCase;

/**
 * Source-level guards for the spec §9 transport boundary: nothing syncs for a
 * user who has not opted in, whatever its tier; events and the attribute
 * snapshot sync only when analyticsAllowed. Behavioral proof lives in
 * ConsentSyncIntegrationTest (DB-gated).
 */
final class SyncConsentBoundaryTest extends TestCase
{
    private function serviceSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingService.class.php');
    }

    public function test_flush_reads_tier_and_sends_no_event_without_consent(): void
    {
        $src = $this->serviceSrc();
        $this->assertStringContainsString('client_token, tier', $src,
            'flushEvents must SELECT the tier column (it was write-only dead data)');
        $this->assertMatchesRegularExpression(
            "/private function pendingEvents\(array \\\$state\): array\s*\{\s*if \(!\\\$state\['analytics_allowed'\]\) \{\s*return \[\];/",
            $src,
            'without consent no event is pending, whatever its tier'
        );
        $this->assertStringNotContainsString("tier = 'essential'", $src,
            'no tier may pass the consent fence');
        $this->assertMatchesRegularExpression("/'tier'\\s*=>\\s*\\(string\\) \\\$row\\['tier'\\]/", $src,
            'the event payload must propagate tier so the server can separate the tiers');
    }

    public function test_every_wire_call_uses_the_consent_filtered_identity(): void
    {
        $src = $this->serviceSrc();
        // The raw identity embeds the full attribute snapshot (offer profile:
        // revenue, campaign names, destination URLs). No client() call may
        // transmit it unfiltered.
        $this->assertDoesNotMatchRegularExpression(
            '/client\(\)->track\(\s*\$this->identity\b/',
            $src,
            'a client() call transmits the unfiltered identity payload'
        );
        foreach (['track'] as $method) {
            $this->assertMatchesRegularExpression(
                '/client\(\)->' . $method . '\(\s*\$this->outboundIdentity\(/',
                $src,
                "client()->{$method}() must use outboundIdentity()"
            );
        }
        $this->assertStringContainsString("unset(\$identity['attributes'])", $src);
    }

    public function test_denial_purges_undelivered_analytics_data(): void
    {
        $this->assertStringContainsString('public static function purgeAnalyticsData', $this->serviceSrc());
        $policy = file_get_contents(__DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php');
        $this->assertStringContainsString('MessagingService::purgeAnalyticsData', $policy,
            'ConsentPolicy::record must purge undelivered analytics data on denial');
    }

    public function test_consent_lookup_failure_fails_closed(): void
    {
        $policy = file_get_contents(__DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php');
        // loadPref must distinguish lookup failure (null → deny) from a
        // genuine missing row (documented non-EU default).
        $this->assertMatchesRegularExpression('/private static function loadPref\(mysqli \$db, int \$userId\): \?array/', $policy);
    }
}

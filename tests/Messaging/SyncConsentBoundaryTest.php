<?php
// tests/Messaging/SyncConsentBoundaryTest.php
use PHPUnit\Framework\TestCase;

/**
 * Source-level guards for the spec §9 transport boundary: only essential rows
 * sync for non-consented users; the attribute snapshot and analytics-tier
 * events sync only when analyticsAllowed. Behavioral proof lives in
 * ConsentSyncIntegrationTest (DB-gated).
 */
final class SyncConsentBoundaryTest extends TestCase
{
    private function serviceSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../202-config/Messaging/MessagingService.class.php');
    }

    public function test_flush_reads_tier_and_filters_non_consented_to_essential(): void
    {
        $src = $this->serviceSrc();
        $this->assertStringContainsString('client_token, tier', $src,
            'flushEvents must SELECT the tier column (it was write-only dead data)');
        $this->assertStringContainsString("AND tier = 'essential'", $src,
            'flushEvents must restrict non-consented flushes to essential rows');
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
            '/client\(\)->(pull|send|markRead|track)\(\s*\$this->identity\b/',
            $src,
            'a client() call transmits the unfiltered identity payload'
        );
        foreach (['pull', 'send', 'markRead', 'track'] as $method) {
            $this->assertMatchesRegularExpression(
                '/client\(\)->' . $method . '\(\s*\$this->outboundIdentity\(\)/',
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

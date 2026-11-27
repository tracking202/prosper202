<?php
// tests/Messaging/AnalyticsGateTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../../202-config/Messaging/Analytics.class.php';

final class AnalyticsGateTest extends TestCase
{
    // The gate Analytics applies is ConsentPolicy::decide: only a grant.
    public function test_gate_unset_is_off(): void
    {
        $this->assertFalse(Analytics::gate('unset'));
    }
    public function test_gate_denied_is_off(): void
    {
        $this->assertFalse(Analytics::gate('denied'));
    }
    public function test_gate_analytics_granted(): void
    {
        $this->assertTrue(Analytics::gate('granted'));
    }
    // Unknown tiers are labelled 'analytics', never 'essential'.
    public function test_normalize_tier_treats_unknown_as_analytics(): void
    {
        $this->assertSame('analytics', Analytics::normalizeTier('analytic'));
        $this->assertSame('analytics', Analytics::normalizeTier('marketing'));
        $this->assertSame('analytics', Analytics::normalizeTier(''));
        $this->assertSame('analytics', Analytics::normalizeTier('Essential'));
        $this->assertSame('essential', Analytics::normalizeTier('essential'));
        $this->assertSame('analytics', Analytics::normalizeTier('analytics'));
    }
    public function test_every_tier_waits_for_the_opt_in(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-config/Messaging/Analytics.class.php');
        $this->assertMatchesRegularExpression(
            '/function wouldRecord\(mysqli \$db, int \$userId\): bool\s*\{\s*return ConsentPolicy::analyticsAllowed\(\$db, \$userId\);\s*\}/',
            $src,
            'no tier is recorded before the user opts in'
        );
    }
}

<?php
// tests/Messaging/AnalyticsGateTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';
require_once __DIR__ . '/../../202-config/Messaging/Analytics.class.php';

final class AnalyticsGateTest extends TestCase
{
    // The gate Analytics applies must equal ConsentPolicy::decide for the tier.
    public function test_gate_matches_policy_for_analytics_unset_eu(): void
    {
        $this->assertFalse(Analytics::gate('unset', true, 'analytics'));
    }
    public function test_gate_essential_always_true(): void
    {
        $this->assertTrue(Analytics::gate('denied', true, 'essential'));
    }
    public function test_gate_analytics_granted(): void
    {
        $this->assertTrue(Analytics::gate('granted', true, 'analytics'));
    }
    // Unknown tiers must not bypass the gate: anything not 'essential'
    // normalizes to 'analytics' and is consent-gated (matches decide()).
    public function test_normalize_tier_treats_unknown_as_analytics(): void
    {
        $this->assertSame('analytics', Analytics::normalizeTier('analytic'));
        $this->assertSame('analytics', Analytics::normalizeTier('marketing'));
        $this->assertSame('analytics', Analytics::normalizeTier(''));
        $this->assertSame('analytics', Analytics::normalizeTier('Essential'));
        $this->assertSame('essential', Analytics::normalizeTier('essential'));
        $this->assertSame('analytics', Analytics::normalizeTier('analytics'));
    }
    public function test_gate_unknown_tier_is_gated_like_analytics(): void
    {
        $this->assertFalse(Analytics::gate('denied', false, Analytics::normalizeTier('marketing')));
        $this->assertFalse(Analytics::gate('unset', true, Analytics::normalizeTier('analytic')));
        $this->assertTrue(Analytics::gate('granted', true, Analytics::normalizeTier('marketing')));
    }
}

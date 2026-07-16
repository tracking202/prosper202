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
}

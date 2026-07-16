<?php
// tests/Messaging/ConsentPolicyTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';

final class ConsentPolicyTest extends TestCase
{
    // essential always flows regardless of consent/geo
    public function test_essential_always_true(): void
    {
        foreach (['granted','denied','unset'] as $s) {
            foreach ([true,false] as $eu) {
                $this->assertTrue(ConsentPolicy::decide($s, $eu, 'essential'));
            }
        }
    }

    public function test_analytics_granted_always_true(): void
    {
        $this->assertTrue(ConsentPolicy::decide('granted', true, 'analytics'));
        $this->assertTrue(ConsentPolicy::decide('granted', false, 'analytics'));
    }

    public function test_analytics_denied_always_false(): void
    {
        $this->assertFalse(ConsentPolicy::decide('denied', true, 'analytics'));
        $this->assertFalse(ConsentPolicy::decide('denied', false, 'analytics'));
    }

    public function test_analytics_unset_non_eu_true(): void
    {
        $this->assertTrue(ConsentPolicy::decide('unset', false, 'analytics'));
    }

    public function test_analytics_unset_eu_false(): void
    {
        $this->assertFalse(ConsentPolicy::decide('unset', true, 'analytics'));
    }
}

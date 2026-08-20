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

    // ------------------------------------------------------------------
    // Failure semantics (spec §6): a consent LOOKUP FAILURE must never
    // resolve to "granted" — the unreadable stored state might be EU or an
    // explicit denial. Simulated with a mysqli whose prepare() fails, the
    // exact shape of a mid-retry migration (consent columns missing) under
    // the UI path's non-throwing mysqli report mode.
    // ------------------------------------------------------------------

    /** @return mysqli A mysqli double whose prepare() always fails. */
    private function failingDb(): mysqli
    {
        $db = $this->createMock(mysqli::class);
        $db->method('prepare')->willReturn(false);
        return $db;
    }

    public function test_reads_fail_closed_on_db_failure(): void
    {
        $db = $this->failingDb();
        $this->assertFalse(ConsentPolicy::analyticsAllowed($db, 7),
            'analyticsAllowed must fail CLOSED on lookup failure, not fall back to the non-EU default');
        $this->assertFalse(ConsentPolicy::emailMarketingAllowed($db, 7));
        $this->assertFalse(ConsentPolicy::needsEuPrompt($db, 7));
    }

    public function test_writes_report_failure_on_db_failure(): void
    {
        $db = $this->failingDb();
        $this->assertFalse(ConsentPolicy::record($db, 7, 'analytics', 'denied', 'settings'));
        $this->assertFalse(ConsentPolicy::rememberGeo($db, 7, true));
    }

    public function test_record_rejects_invalid_args_without_touching_db(): void
    {
        $db = $this->createMock(mysqli::class);
        $db->expects($this->never())->method('prepare');
        $this->assertFalse(ConsentPolicy::record($db, 7, 'bogus_flag', 'granted', 'settings'));
        $this->assertFalse(ConsentPolicy::record($db, 7, 'analytics', 'maybe', 'settings'));
    }
}

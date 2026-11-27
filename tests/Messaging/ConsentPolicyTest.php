<?php
// tests/Messaging/ConsentPolicyTest.php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php';

final class ConsentPolicyTest extends TestCase
{
    // Opt-in: only an explicit grant allows. An unset choice is off
    // everywhere; there is no location and no tier that turns it on.
    public function test_only_an_explicit_grant_allows(): void
    {
        $this->assertTrue(ConsentPolicy::decide('granted'));
        $this->assertFalse(ConsentPolicy::decide('denied'));
        $this->assertFalse(ConsentPolicy::decide('unset'));
    }

    public function test_a_stored_value_that_is_no_state_is_held(): void
    {
        $this->assertFalse(ConsentPolicy::decide(''));
        $this->assertFalse(ConsentPolicy::decide('yes'));
        $this->assertFalse(ConsentPolicy::decide('Granted'));
    }

    public function test_the_policy_reads_no_location(): void
    {
        $src = file_get_contents(__DIR__ . '/../../202-config/Messaging/ConsentPolicy.class.php');
        $this->assertStringNotContainsString('analytics_geo_is_eu', $src,
            'whether usage data may leave must not depend on where the user signs in from');
    }

    // ------------------------------------------------------------------
    // Failure semantics (spec §6): a consent LOOKUP FAILURE must never
    // resolve to "granted" — the unreadable stored state might be an
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
            'analyticsAllowed must fail CLOSED on lookup failure');
        $this->assertFalse(ConsentPolicy::emailMarketingAllowed($db, 7));
        $this->assertFalse(ConsentPolicy::takePrompt($db, 7), 'no question when the choice cannot be read');
    }

    public function test_writes_report_failure_on_db_failure(): void
    {
        $db = $this->failingDb();
        $this->assertFalse(ConsentPolicy::record($db, 7, 'analytics', 'denied', 'settings'));
    }

    public function test_record_rejects_invalid_args_without_touching_db(): void
    {
        $db = $this->createMock(mysqli::class);
        $db->expects($this->never())->method('prepare');
        $this->assertFalse(ConsentPolicy::record($db, 7, 'bogus_flag', 'granted', 'settings'));
        $this->assertFalse(ConsentPolicy::record($db, 7, 'analytics', 'maybe', 'settings'));
    }
}

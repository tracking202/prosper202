<?php
// tests/Messaging/MilestoneWiringTest.php
use PHPUnit\Framework\TestCase;

final class MilestoneWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    // NOTE: the tracking202/ajax/{aff_networks,ppc_networks,aff_campaigns}.php
    // files are dropdown loaders with no INSERTs; the create handlers (the
    // "INSERT INTO <table>" anchor) live in tracking202/setup/, so the
    // milestone events are wired — and asserted — there.
    public function test_aff_network_event(): void
    {
        $this->assertStringContainsString("Analytics::event('aff_network_added'",
            $this->src('tracking202/setup/aff_networks.php'));
    }
    public function test_traffic_source_event(): void
    {
        $this->assertStringContainsString("Analytics::event('traffic_source_added'",
            $this->src('tracking202/setup/ppc_accounts.php'));
    }
    public function test_campaign_event(): void
    {
        $this->assertStringContainsString("Analytics::event('aff_campaign_added'",
            $this->src('tracking202/setup/aff_campaigns.php'));
    }
    public function test_tracker_event(): void
    {
        $this->assertStringContainsString("Analytics::event('tracker_created'",
            $this->src('tracking202/ajax/generate_tracking_link.php'));
    }
    public function test_ltv_and_lpo_events_wired_somewhere(): void
    {
        // Handlers are located by grep during implementation; assert the events
        // exist anywhere outside tests/ and Messaging/ (the emitting call sites).
        $hits = shell_exec('grep -rl "Analytics::event(\'ltv_integration_connected\'" '
            . escapeshellarg(dirname(__DIR__, 2)) . ' --include="*.php" | grep -v /tests/');
        $this->assertNotEmpty(trim((string) $hits), 'ltv_integration_connected not wired');
        $hits = shell_exec('grep -rl "Analytics::event(\'lpo_paired\'" '
            . escapeshellarg(dirname(__DIR__, 2)) . ' --include="*.php" | grep -v /tests/');
        $this->assertNotEmpty(trim((string) $hits), 'lpo_paired not wired');
    }
}

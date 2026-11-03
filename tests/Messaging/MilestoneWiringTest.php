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
        $hits = shell_exec('grep -rlE "Analytics::event(For)?\(.*\'ltv_integration_connected\'" '
            . escapeshellarg(dirname(__DIR__, 2)) . ' --include="*.php" | grep -v /tests/');
        $this->assertNotEmpty(trim((string) $hits), 'ltv_integration_connected not wired');
        $hits = shell_exec('grep -rl "Analytics::event(\'lpo_paired\'" '
            . escapeshellarg(dirname(__DIR__, 2)) . ' --include="*.php" | grep -v /tests/');
        $this->assertNotEmpty(trim((string) $hits), 'lpo_paired not wired');
    }

    /**
     * A milestone a page records says it came from the dashboard, as the
     * API's say cli, ai_connector or api: every Analytics::event() in the
     * served pages but the page view carries 'via' => 'ui' first.
     */
    public function test_dashboard_milestones_say_they_came_from_the_ui(): void
    {
        $root = dirname(__DIR__, 2);
        $calls = 0;
        foreach (['tracking202', '202-account', '202-config'] as $dir) {
            $files = new RecursiveIteratorIterator(\Tests\Support\SourceScan::tree($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Messaging/')) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                preg_match_all("/Analytics::event\('([a-z_]+)',\s*([^;]*?),\s*'analytics'\)/", $src, $m, PREG_SET_ORDER);
                foreach ($m as [$call, $event, $meta]) {
                    if ($event === 'page_viewed') {
                        continue;
                    }
                    $calls++;
                    $this->assertStringStartsWith("['via' => 'ui'", $meta, $file->getPathname() . ': ' . $call);
                }
            }
        }
        $this->assertGreaterThanOrEqual(9, $calls, 'the scan found the dashboard milestones');
    }
}

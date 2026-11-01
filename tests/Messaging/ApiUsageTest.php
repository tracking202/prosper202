<?php
// tests/Messaging/ApiUsageTest.php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../202-config/Messaging/ApiUsage.class.php';

final class ApiUsageTest extends TestCase
{
    public function test_creates_through_the_api_are_the_ui_milestones(): void
    {
        $this->assertSame('aff_campaign_added', ApiUsage::milestoneFor('POST', '/campaigns'));
        $this->assertSame('aff_network_added', ApiUsage::milestoneFor('post', '/aff-networks/'));
        $this->assertSame('traffic_source_added', ApiUsage::milestoneFor('POST', '/ppc-networks'));
        $this->assertSame('tracker_created', ApiUsage::milestoneFor('POST', '/trackers'));
        $this->assertSame('rotator_created', ApiUsage::milestoneFor('POST', '/rotators'));
        $this->assertSame('mobile_app_registered', ApiUsage::milestoneFor('POST', '/attribution/apps'));
        $this->assertSame('click_filter_changed', ApiUsage::milestoneFor('PUT', '/users/3/click-filter'));
        $this->assertSame('click_filter_changed', ApiUsage::milestoneFor('POST', '/users/3/click-filter/entries'));
    }

    public function test_reads_updates_and_sub_resources_are_not_milestones(): void
    {
        $this->assertNull(ApiUsage::milestoneFor('GET', '/campaigns'));
        $this->assertNull(ApiUsage::milestoneFor('PUT', '/campaigns/4'));
        $this->assertNull(ApiUsage::milestoneFor('POST', '/rotators/4/rules'));
        $this->assertNull(ApiUsage::milestoneFor('DELETE', '/users/3/click-filter/entries/9'));
        $this->assertNull(ApiUsage::milestoneFor('POST', '/conversions'));
    }

    public function test_client_kind(): void
    {
        $this->assertSame(ApiUsage::CLIENT_AI_CONNECTOR, ApiUsage::clientKind(true, 'p202-cli/2.0 (Go)'));
        $this->assertSame(ApiUsage::CLIENT_CLI, ApiUsage::clientKind(false, 'p202-cli/2.0 (Go)'));
        $this->assertSame(ApiUsage::CLIENT_API, ApiUsage::clientKind(false, 'curl/8.4'));
    }

    public function test_failed_requests_and_missing_users_record_nothing_and_read_nothing(): void
    {
        $db = $this->createMock(mysqli::class);
        $db->expects($this->never())->method('prepare');
        $kind = function (): string { $this->fail('the client kind is read only for a successful request'); };
        ApiUsage::record($db, 7, $kind, 'POST', '/campaigns', 422, false);
        ApiUsage::record($db, 0, $kind, 'POST', '/campaigns', 201, false);
        $this->addToAssertionCount(1);
    }

    public function test_the_api_records_after_the_handler_and_previews_reach_no_milestone(): void
    {
        $src = file_get_contents(__DIR__ . '/../../api/v3/index.php');
        $hook = strpos($src, 'ApiUsage::record(');
        $this->assertNotFalse($hook);
        $this->assertLessThan(strpos($src, '// ─── Send response'), $hook);
        $this->assertStringContainsString('$dryRun || $stagedWrite', substr($src, $hook, 700));
    }

    public function test_new_milestones_are_wired_and_analytics_tier(): void
    {
        $root = __DIR__ . '/../../';
        $this->assertStringContainsString("'ai_connector_connected'", file_get_contents($root . 'api/v3/Support/AiConnect.php'));
        $this->assertMatchesRegularExpression("/'click_filter_changed', \\[[^\\]]*\\], 'analytics'/", file_get_contents($root . '202-config/functions-click-filter-ui.php'));
        $integrations = file_get_contents($root . '202-account/api-integrations.php');
        $this->assertSame(3, substr_count($integrations, "Analytics::event('integration_configured'"));
        $this->assertStringContainsString("eventFor(\$analyticsDb, \$userId, 'ltv_integration_connected'", file_get_contents($root . '202-config/Ltv/MysqlIntegrationRepository.php'));
    }
}

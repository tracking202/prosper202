<?php

declare(strict_types=1);

namespace Tests\Api\V3\Controllers;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\ClicksController;
use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\LandingPagesController;
use Api\V3\Controllers\PpcAccountsController;
use Api\V3\Controllers\PpcNetworksController;
use Api\V3\Controllers\TextAdsController;
use Api\V3\Controllers\TrackersController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ControllerFieldsTest extends TestCase
{
    private \mysqli $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMysqliMock();
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    /**
     * Invoke a protected method on a controller instance via reflection.
     */
    private function invokeProtected(object $controller, string $method): mixed
    {
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);
        return $ref->invoke($controller);
    }

    // ─── CampaignsController ────────────────────────────────────────

    #[Test]
    public function testCampaignsTableName(): void
    {
        $controller = new CampaignsController($this->db, 1);
        $this->assertSame('202_aff_campaigns', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testCampaignsPrimaryKey(): void
    {
        $controller = new CampaignsController($this->db, 1);
        $this->assertSame('aff_campaign_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testCampaignsFieldsNotEmpty(): void
    {
        $controller = new CampaignsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testCampaignsFieldTypesValid(): void
    {
        $controller = new CampaignsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in CampaignsController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── AffNetworksController ──────────────────────────────────────

    #[Test]
    public function testAffNetworksTableName(): void
    {
        $controller = new AffNetworksController($this->db, 1);
        $this->assertSame('202_aff_networks', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testAffNetworksPrimaryKey(): void
    {
        $controller = new AffNetworksController($this->db, 1);
        $this->assertSame('aff_network_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testAffNetworksFieldsNotEmpty(): void
    {
        $controller = new AffNetworksController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testAffNetworksFieldTypesValid(): void
    {
        $controller = new AffNetworksController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in AffNetworksController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── LandingPagesController ─────────────────────────────────────

    #[Test]
    public function testLandingPagesTableName(): void
    {
        $controller = new LandingPagesController($this->db, 1);
        $this->assertSame('202_landing_pages', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testLandingPagesPrimaryKey(): void
    {
        $controller = new LandingPagesController($this->db, 1);
        $this->assertSame('landing_page_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testLandingPagesFieldsNotEmpty(): void
    {
        $controller = new LandingPagesController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testLandingPagesFieldTypesValid(): void
    {
        $controller = new LandingPagesController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in LandingPagesController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── TextAdsController ──────────────────────────────────────────

    #[Test]
    public function testTextAdsTableName(): void
    {
        $controller = new TextAdsController($this->db, 1);
        $this->assertSame('202_text_ads', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testTextAdsPrimaryKey(): void
    {
        $controller = new TextAdsController($this->db, 1);
        $this->assertSame('text_ad_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testTextAdsFieldsNotEmpty(): void
    {
        $controller = new TextAdsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testTextAdsFieldTypesValid(): void
    {
        $controller = new TextAdsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in TextAdsController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── PpcAccountsController ──────────────────────────────────────

    #[Test]
    public function testPpcAccountsTableName(): void
    {
        $controller = new PpcAccountsController($this->db, 1);
        $this->assertSame('202_ppc_accounts', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testPpcAccountsPrimaryKey(): void
    {
        $controller = new PpcAccountsController($this->db, 1);
        $this->assertSame('ppc_account_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testPpcAccountsFieldsNotEmpty(): void
    {
        $controller = new PpcAccountsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testPpcAccountsFieldTypesValid(): void
    {
        $controller = new PpcAccountsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in PpcAccountsController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── PpcNetworksController ──────────────────────────────────────

    #[Test]
    public function testPpcNetworksTableName(): void
    {
        $controller = new PpcNetworksController($this->db, 1);
        $this->assertSame('202_ppc_networks', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testPpcNetworksPrimaryKey(): void
    {
        $controller = new PpcNetworksController($this->db, 1);
        $this->assertSame('ppc_network_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testPpcNetworksFieldsNotEmpty(): void
    {
        $controller = new PpcNetworksController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testPpcNetworksFieldTypesValid(): void
    {
        $controller = new PpcNetworksController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in PpcNetworksController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── ClicksController ───────────────────────────────────────────

    #[Test]
    public function testClicksTableName(): void
    {
        // ClicksController does not extend the abstract Controller.
        // It uses '202_clicks' directly in its SQL queries.
        $controller = new ClicksController($this->db, 1);
        $this->assertInstanceOf(ClicksController::class, $controller);

        // Verify table name via reflection on the class's SQL strings in list()
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString('202_clicks', $source);
    }

    #[Test]
    public function testClicksPrimaryKey(): void
    {
        // ClicksController uses click_id as the primary identifier in its get() method.
        $controller = new ClicksController($this->db, 1);
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString('click_id', $source);
    }

    #[Test]
    public function testClicksFieldsNotEmpty(): void
    {
        // ClicksController is a standalone class without a fields() method.
        // Verify it has the list() and get() methods that define its readable fields.
        $controller = new ClicksController($this->db, 1);
        $this->assertTrue(method_exists($controller, 'list'));
        $this->assertTrue(method_exists($controller, 'get'));
    }

    #[Test]
    public function testClicksFieldTypesValid(): void
    {
        // ClicksController uses bind_param type strings in its SQL queries.
        // Verify the class references only valid bind types.
        $controller = new ClicksController($this->db, 1);
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());

        // All bind_param calls use 'i' (integer) types for clicks
        $this->assertStringContainsString("bind_param('ii'", $source);
    }

    // ─── ConversionsController ──────────────────────────────────────

    #[Test]
    public function testConversionsTableName(): void
    {
        // ConversionsController does not extend the abstract Controller.
        // It uses '202_conversion_logs' directly in its SQL queries.
        $controller = new ConversionsController($this->db, 1);
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString('202_conversion_logs', $source);
    }

    #[Test]
    public function testConversionsPrimaryKey(): void
    {
        // ConversionsController uses conv_id as the primary identifier.
        $controller = new ConversionsController($this->db, 1);
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());
        $this->assertStringContainsString('conv_id', $source);
    }

    #[Test]
    public function testConversionsFieldsNotEmpty(): void
    {
        // ConversionsController is a standalone class with list/get/create/delete methods.
        $controller = new ConversionsController($this->db, 1);
        $this->assertTrue(method_exists($controller, 'list'));
        $this->assertTrue(method_exists($controller, 'get'));
        $this->assertTrue(method_exists($controller, 'create'));
        $this->assertTrue(method_exists($controller, 'delete'));
    }

    #[Test]
    public function testConversionsFieldTypesValid(): void
    {
        // ConversionsController uses bind_param type strings in its SQL queries.
        $controller = new ConversionsController($this->db, 1);
        $ref = new \ReflectionClass($controller);
        $source = file_get_contents($ref->getFileName());

        // The create method uses 'isidiii' bind types — all valid types
        $this->assertStringContainsString("bind_param('isidiii'", $source);
    }

    // ─── TrackersController ─────────────────────────────────────────

    #[Test]
    public function testTrackersTableName(): void
    {
        $controller = new TrackersController($this->db, 1);
        $this->assertSame('202_trackers', $this->invokeProtected($controller, 'tableName'));
    }

    #[Test]
    public function testTrackersPrimaryKey(): void
    {
        $controller = new TrackersController($this->db, 1);
        $this->assertSame('tracker_id', $this->invokeProtected($controller, 'primaryKey'));
    }

    #[Test]
    public function testTrackersFieldsNotEmpty(): void
    {
        $controller = new TrackersController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        $this->assertNotEmpty($fields);
    }

    #[Test]
    public function testTrackersFieldTypesValid(): void
    {
        $controller = new TrackersController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');
        foreach ($fields as $name => $def) {
            $this->assertContains(
                $def['type'],
                ['i', 's', 'd'],
                "Field '$name' in TrackersController has invalid type '{$def['type']}'"
            );
        }
    }

    // ─── General Validation Tests ───────────────────────────────────

    #[Test]
    public function testCampaignsRequiredFields(): void
    {
        $controller = new CampaignsController($this->db, 1);
        $fields = $this->invokeProtected($controller, 'fields');

        $this->assertArrayHasKey('aff_campaign_name', $fields);
        $this->assertArrayHasKey('aff_campaign_url', $fields);
        $this->assertTrue(
            $fields['aff_campaign_name']['required'] ?? false,
            'aff_campaign_name must be marked as required'
        );
        $this->assertTrue(
            $fields['aff_campaign_url']['required'] ?? false,
            'aff_campaign_url must be marked as required'
        );
    }

    #[Test]
    public function testAllControllersHaveUserIdColumn(): void
    {
        $controllerClasses = [
            CampaignsController::class,
            AffNetworksController::class,
            LandingPagesController::class,
            TextAdsController::class,
            PpcAccountsController::class,
            PpcNetworksController::class,
            TrackersController::class,
        ];

        foreach ($controllerClasses as $class) {
            $controller = new $class($this->db, 1);
            $userIdColumn = $this->invokeProtected($controller, 'userIdColumn');
            $this->assertSame(
                'user_id',
                $userIdColumn,
                "$class should return 'user_id' from userIdColumn()"
            );
        }
    }

    #[Test]
    public function testDeletedColumnConsistency(): void
    {
        $controllersWithSoftDelete = [
            CampaignsController::class   => 'aff_campaign_deleted',
            AffNetworksController::class  => 'aff_network_deleted',
            LandingPagesController::class => 'landing_page_deleted',
            TextAdsController::class      => 'text_ad_deleted',
            PpcAccountsController::class  => 'ppc_account_deleted',
            PpcNetworksController::class  => 'ppc_network_deleted',
        ];

        foreach ($controllersWithSoftDelete as $class => $expectedColumn) {
            $controller = new $class($this->db, 1);
            $deletedColumn = $this->invokeProtected($controller, 'deletedColumn');
            $this->assertIsString(
                $deletedColumn,
                "$class deletedColumn() should return a non-null string"
            );
            $this->assertSame(
                $expectedColumn,
                $deletedColumn,
                "$class deletedColumn() should return '$expectedColumn'"
            );
        }
    }
}

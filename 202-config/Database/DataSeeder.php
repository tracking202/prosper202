<?php
declare(strict_types=1);

namespace Prosper202\Database;

use mysqli;
use Prosper202\Database\Schema\TableRegistry;

/**
 * Seeds initial data into newly created database tables.
 */
final readonly class DataSeeder
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Run a seed statement on THIS connection and fail loudly.
     *
     * Every statement previously used the one-arg _mysqli_query() (which
     * resolves `global $db`, not the installer's connection) and discarded the
     * result. Under MYSQLI_REPORT_STRICT a query error is a false return, and
     * seed()/seedVersion() are void, so INSTALL::install_databases() could not
     * see a failure — an install with half-seeded roles reported success and
     * locked the new super-user out.
     */
    private function run(string $sql): void
    {
        $result = _mysqli_query($this->connection, $sql);
        if ($result === false) {
            throw new \RuntimeException('Seeding failed: ' . $this->connection->error);
        }
    }

    /**
     * Seed all initial data.
     */
    public function seed(): void
    {
        $this->seedPixelTypes();
        $this->seedDeviceTypes();
        $this->seedRoles();
        $this->seedPermissions();
        $this->seedRolePermissions();
        // The default chart is per-user and created with the committed user_id by
        // the installer (install.php), not seeded here with a hard-coded id.
        $this->seedClicksTotal();
    }

    /**
     * Seed pixel types.
     */
    public function seedPixelTypes(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::PIXEL_TYPES . "` (`pixel_type`) VALUES
            ('Image'),
            ('Iframe'),
            ('Javascript'),
            ('Postback'),
            ('Raw'),
            ('Bot202 Facebook Pixel Assistant')";
        $this->run($sql);
    }

    /**
     * Seed device types.
     */
    public function seedDeviceTypes(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::DEVICE_TYPES . "` (`type_id`, `type_name`) VALUES
            (1, 'Desktop'),
            (2, 'Mobile'),
            (3, 'Tablet'),
            (4, 'Bot')";
        $this->run($sql);
    }

    /**
     * Seed user roles.
     */
    public function seedRoles(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::ROLES . "` (`role_id`, `role_name`) VALUES
            (1, 'Super user'),
            (2, 'Admin'),
            (3, 'Campaign manager'),
            (4, 'Campaign optimizer'),
            (5, 'Campaign viewer'),
            (6, 'Publisher')";
        $this->run($sql);
    }

    /**
     * Seed permissions.
     */
    public function seedPermissions(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::PERMISSIONS . "` (`permission_id`, `permission_description`) VALUES
            (1, 'add_users'),
            (2, 'add_edit_delete_admin'),
            (3, 'remove_traffic_source'),
            (4, 'remove_traffic_source_account'),
            (5, 'remove_campaign_category'),
            (6, 'remove_campaign'),
            (7, 'remove_landing_page'),
            (8, 'remove_text_ad'),
            (9, 'remove_rotator'),
            (10, 'remove_rotator_criteria'),
            (11, 'remove_rotator_rule'),
            (12, 'access_to_campaign_data'),
            (13, 'delete_individual_subids'),
            (14, 'access_to_setup_section'),
            (15, 'access_to_update_section'),
            (16, 'access_to_personal_settings'),
            (17, 'access_to_vip_perks'),
            (18, 'access_to_clickservers'),
            (19, 'access_to_api_integrations'),
            (20, 'access_to_settings'),
            (21, 'remove_tracker'),
            (22, 'view_attribution_reports'),
            (23, 'manage_attribution_models')";
        $this->run($sql);
    }

    /**
     * Seed role permissions mappings.
     */
    public function seedRolePermissions(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::ROLE_PERMISSION . "` (`role_id`, `permission_id`) VALUES
            (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 9), (1, 10),
            (1, 11), (1, 12), (1, 13), (1, 14), (1, 15), (1, 16), (1, 17), (1, 18), (1, 19), (1, 20),
            (1, 21), (1, 22), (1, 23),
            (2, 1), (2, 3), (2, 4), (2, 5), (2, 6), (2, 7), (2, 8), (2, 9), (2, 10), (2, 11),
            (2, 12), (2, 13), (2, 14), (2, 15), (2, 16), (2, 17), (2, 18), (2, 19), (2, 20), (2, 21),
            (2, 22), (2, 23),
            (3, 12), (3, 14), (3, 15), (3, 22),
            (4, 12)";
        $this->run($sql);
    }

    /**
     * Seed initial click count.
     */
    public function seedClicksTotal(): void
    {
        $sql = "INSERT IGNORE INTO `" . TableRegistry::CLICKS_TOTAL . "` (`click_count`) VALUES (0)";
        $this->run($sql);
    }

    /**
     * Insert version number.
     */
    public function seedVersion(string $version): void
    {
        // Idempotent: 202_version is read as a single row (e.g. functions-upgrade.php),
        // so don't add a second row when an install retry re-runs the seed step.
        $existing = _mysqli_query($this->connection, "SELECT 1 FROM " . TableRegistry::VERSION . " LIMIT 1");
        if ($existing instanceof \mysqli_result && $existing->num_rows > 0) {
            return;
        }

        $escapedVersion = $this->connection->real_escape_string($version);
        $sql = "INSERT INTO " . TableRegistry::VERSION . " SET version='{$escapedVersion}'";
        $this->run($sql);
    }
}

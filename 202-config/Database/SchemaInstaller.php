<?php
declare(strict_types=1);

namespace Prosper202\Database;

use mysqli;
use Prosper202\Database\Schema\SchemaDefinition;
use Prosper202\Database\Tables\CoreTables;
use Prosper202\Database\Tables\UserTables;
use Prosper202\Database\Tables\ClickTables;
use Prosper202\Database\Tables\TrackingTables;
use Prosper202\Database\Tables\CampaignTables;
use Prosper202\Database\Tables\AttributionTables;
use Prosper202\Database\Tables\RotatorTables;
use Prosper202\Database\Tables\AdNetworkTables;
use Prosper202\Database\Tables\MiscTables;
use Prosper202\Database\Exceptions\SchemaInstallException;

/**
 * Orchestrates the creation of all database tables.
 */
final class SchemaInstaller
{
    private mysqli $connection;
    /** @var array<string> */
    private array $createdTables = [];
    /** @var array<string> */
    private array $errors = [];

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Install all database tables.
     *
     * @return InstallResult
     */
    public function install(): InstallResult
    {
        $startTime = microtime(true);

        $this->disableStrictMode();

        try {
            $this->createCoreTables();
            $this->createUserTables();
            $this->createClickTables();
            $this->createTrackingTables();
            $this->createCampaignTables();
            $this->createAttributionTables();
            $this->createRotatorTables();
            $this->createAdNetworkTables();
            $this->createMiscTables();
            $this->setCollations();
        } catch (SchemaInstallException $e) {
            $this->errors[] = $e->getMessage();
        }

        $executionTime = microtime(true) - $startTime;

        if (count($this->errors) > 0) {
            return InstallResult::failure($this->errors, $this->createdTables, $executionTime);
        }

        return InstallResult::success($this->createdTables, $executionTime);
    }

    /**
     * Create core system tables.
     */
    public function createCoreTables(): void
    {
        $this->createTablesFromDefinitions(CoreTables::getDefinitions());
    }

    /**
     * Create user and role tables.
     */
    public function createUserTables(): void
    {
        $this->createTablesFromDefinitions(UserTables::getDefinitions());
    }

    /**
     * Create click tracking tables.
     */
    public function createClickTables(): void
    {
        $this->createTablesFromDefinitions(ClickTables::getDefinitions());
    }

    /**
     * Create tracking and UTM tables.
     */
    public function createTrackingTables(): void
    {
        $this->createTablesFromDefinitions(TrackingTables::getDefinitions());
    }

    /**
     * Create campaign and affiliate tables.
     */
    public function createCampaignTables(): void
    {
        $this->createTablesFromDefinitions(CampaignTables::getDefinitions());
    }

    /**
     * Create attribution tables.
     */
    public function createAttributionTables(): void
    {
        $this->createTablesFromDefinitions(AttributionTables::getDefinitions());
    }

    /**
     * Create rotator tables.
     */
    public function createRotatorTables(): void
    {
        $this->createTablesFromDefinitions(RotatorTables::getDefinitions());
    }

    /**
     * Create ad network tables.
     */
    public function createAdNetworkTables(): void
    {
        $this->createTablesFromDefinitions(AdNetworkTables::getDefinitions());
    }

    /**
     * Create miscellaneous tables.
     */
    public function createMiscTables(): void
    {
        $this->createTablesFromDefinitions(MiscTables::getDefinitions());
    }

    /**
     * Create tables from an array of schema definitions.
     *
     * @param array<SchemaDefinition> $definitions
     */
    private function createTablesFromDefinitions(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $this->executeStatement($definition->createStatement, $definition->tableName);
        }
    }

    /**
     * Execute a SQL statement and track the result.
     */
    private function executeStatement(string $sql, ?string $tableName = null): bool
    {
        $result = _mysqli_query($sql);

        if ($result !== false && $tableName !== null) {
            $this->createdTables[] = $tableName;
        }

        return $result !== false;
    }

    /**
     * Disable MySQL strict mode for compatibility.
     */
    private function disableStrictMode(): void
    {
        $sql = "SET session sql_mode= ''";
        _mysqli_query($sql);
    }

    /**
     * Set proper collations on specific tables.
     */
    private function setCollations(): void
    {
        // Set collation for IPv6 table
        $sql = "ALTER TABLE `202_ips_v6` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
        _mysqli_query($sql);
    }

    /**
     * Get the list of created tables.
     *
     * @return array<string>
     */
    public function getCreatedTables(): array
    {
        return $this->createdTables;
    }

    /**
     * Get any errors that occurred.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

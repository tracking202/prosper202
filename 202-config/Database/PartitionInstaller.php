<?php
declare(strict_types=1);

namespace Prosper202\Database;

use mysqli;
use Prosper202\Database\Schema\PartitionStrategy;

/**
 * Handles creation of database partitions for high-volume tables.
 */
final readonly class PartitionInstaller
{
    private bool $partitioningSupported;

    public function __construct(private mysqli $connection)
    {
        $this->partitioningSupported = PartitionStrategy::isSupported($this->connection);
    }

    /**
     * Install all partitions.
     */
    public function install(): void
    {
        if (!$this->partitioningSupported) {
            // Silently skip if partitioning is not supported (matches original behavior)
            return;
        }

        $this->disableStrictMode();

        $partitionStart = time();

        // Create range partitions
        foreach (PartitionStrategy::getRangePartitionTables() as $config) {
            $this->createRangePartitions(
                $config['table'],
                $config['column'],
                $config['increment']
            );
        }

        // Create time-based partitions
        foreach (PartitionStrategy::getTimePartitionTables() as $config) {
            $this->createTimePartitions(
                $config['table'],
                $config['column'],
                $partitionStart
            );
        }
    }

    /**
     * Check if partitioning is supported on this MySQL server.
     */
    public function isPartitioningSupported(): bool
    {
        return $this->partitioningSupported;
    }

    /**
     * Create range partitions for a table.
     */
    private function createRangePartitions(string $tableName, string $column, int $increment): void
    {
        $sql = PartitionStrategy::byRange($tableName, $column, 100, $increment);
        $this->executePartitionSql($tableName, $sql);
    }

    /**
     * Create time-based partitions for a table.
     */
    private function createTimePartitions(string $tableName, string $column, int $startTime): void
    {
        $sql = PartitionStrategy::byTime($tableName, $column, $startTime, 156); // 3 years
        $this->executePartitionSql($tableName, $sql);
    }

    /**
     * Execute partition SQL statement.
     */
    private function executePartitionSql(string $tableName, string $sql): void
    {
        $result = $this->connection->query($sql);

        // Partition operations may fail silently if table already partitioned
        // This matches the original behavior where errors were ignored
        if ($result === false) {
            $error = $this->connection->error;
            // Log the error but don't throw - matches original behavior
            error_log("PartitionInstaller: Failed to partition {$tableName}: {$error}");
        }
    }

    /**
     * Disable MySQL strict mode for compatibility.
     */
    private function disableStrictMode(): void
    {
        $result = _mysqli_query("SET session sql_mode= ''");
        if ($result === false) {
            $error = $this->connection->error ?: 'Unknown failure';
            error_log("PartitionInstaller: Failed to disable MySQL strict mode: {$error}");
        }
    }
}

<?php
declare(strict_types=1);

namespace Prosper202\Database\Schema;

use mysqli;

/**
 * Strategies for creating MySQL table partitions.
 */
final class PartitionStrategy
{
    /**
     * Default number of range partitions to create.
     */
    private const int DEFAULT_PARTITION_COUNT = 100;

    /**
     * Default increment for range partitions.
     */
    private const int DEFAULT_RANGE_INCREMENT = 500000;

    /**
     * Generate a RANGE partition SQL statement by ID column.
     *
     * @param string $tableName The table to partition
     * @param string $column The column to partition by
     * @param int $partitions Number of partitions to create
     * @param int $increment Value increment per partition
     * @return string The ALTER TABLE statement for partitioning
     */
    public static function byRange(
        string $tableName,
        string $column,
        int $partitions = self::DEFAULT_PARTITION_COUNT,
        int $increment = self::DEFAULT_RANGE_INCREMENT
    ): string {
        $sql = "/*!50100 ALTER TABLE `{$tableName}` PARTITION BY RANGE ({$column}) (";

        $partitionDefs = [];
        for ($i = 1; $i <= $partitions; $i++) {
            $value = $increment * $i;
            $partitionDefs[] = "PARTITION p{$i} VALUES LESS THAN ({$value}) ENGINE = InnoDB";
        }

        // Add MAXVALUE partition
        $maxPartition = $partitions + 1;
        $partitionDefs[] = "PARTITION p{$maxPartition} VALUES LESS THAN MAXVALUE ENGINE = InnoDB";

        $sql .= implode(',', $partitionDefs);
        $sql .= ") */";

        return $sql;
    }

    /**
     * Generate a RANGE partition SQL statement by time column.
     *
     * @param string $tableName The table to partition
     * @param string $column The time column to partition by
     * @param int $startTime Unix timestamp to start from
     * @param int $weeksAhead Number of weeks into the future to partition
     * @return string The ALTER TABLE statement for partitioning
     */
    public static function byTime(
        string $tableName,
        string $column,
        int $startTime,
        int $weeksAhead = 156 // 3 years
    ): string {
        $sql = "/*!50100 ALTER TABLE `{$tableName}` PARTITION BY RANGE ({$column}) (";

        $partitionDefs = [];
        $partitionTime = $startTime;
        $endTime = strtotime("+{$weeksAhead} weeks", $startTime);

        $i = 0;
        while ($partitionTime <= $endTime) {
            $partitionDefs[] = "PARTITION p{$i} VALUES LESS THAN ({$partitionTime}) ENGINE = InnoDB";
            $partitionTime = strtotime('+1 week', $partitionTime);
            $i++;
        }

        // Add MAXVALUE partition
        $partitionDefs[] = "PARTITION p{$i} VALUES LESS THAN MAXVALUE ENGINE = InnoDB";

        $sql .= implode(',', $partitionDefs);
        $sql .= ") */";

        return $sql;
    }

    /**
     * Check if MySQL partitioning is supported and enabled.
     *
     * @param mysqli $connection The database connection
     * @return bool True if partitioning is supported
     */
    public static function isSupported(mysqli $connection): bool
    {
        $sql = "SELECT PLUGIN_NAME as Name, PLUGIN_STATUS as Status
                FROM INFORMATION_SCHEMA.PLUGINS
                WHERE PLUGIN_TYPE='STORAGE ENGINE'
                AND PLUGIN_NAME='partition'
                AND PLUGIN_STATUS='ACTIVE'";

        $result = $connection->query($sql);

        if ($result === false) {
            return false;
        }

        $supported = $result->num_rows === 1;
        $result->free();

        return $supported;
    }

    /**
     * Get a list of tables that should be partitioned by range (ID).
     *
     * @return array<array{table: string, column: string, increment: int}>
     */
    public static function getRangePartitionTables(): array
    {
        return [
            ['table' => TableRegistry::CLICKS_TRACKING, 'column' => 'click_id', 'increment' => 500000],
            ['table' => TableRegistry::TRACKING_C1, 'column' => 'c1_id', 'increment' => 500000],
            ['table' => TableRegistry::TRACKING_C2, 'column' => 'c2_id', 'increment' => 500000],
            ['table' => TableRegistry::TRACKING_C3, 'column' => 'c3_id', 'increment' => 500000],
            ['table' => TableRegistry::TRACKING_C4, 'column' => 'c4_id', 'increment' => 500000],
            ['table' => TableRegistry::CLICKS_ADVANCE, 'column' => 'click_id', 'increment' => 500000],
            ['table' => TableRegistry::CLICKS_RECORD, 'column' => 'click_id', 'increment' => 500000],
            ['table' => TableRegistry::CLICKS_SITE, 'column' => 'click_id', 'increment' => 500000],
            ['table' => TableRegistry::IPS, 'column' => 'ip_id', 'increment' => 500000],
            ['table' => TableRegistry::IPS_V6, 'column' => 'ip_id', 'increment' => 500000],
            ['table' => TableRegistry::KEYWORDS, 'column' => 'keyword_id', 'increment' => 500000],
            ['table' => TableRegistry::SITE_DOMAINS, 'column' => 'site_domain_id', 'increment' => 500000],
            ['table' => TableRegistry::SITE_URLS, 'column' => 'site_url_id', 'increment' => 500000],
            ['table' => TableRegistry::CLICKS_VARIABLE, 'column' => 'click_id', 'increment' => 500000],
            ['table' => TableRegistry::CLICKS_ROTATOR, 'column' => 'click_id', 'increment' => 500000],
        ];
    }

    /**
     * Get a list of tables that should be partitioned by time.
     *
     * @return array<array{table: string, column: string}>
     */
    public static function getTimePartitionTables(): array
    {
        return [
            ['table' => TableRegistry::CLICKS, 'column' => 'click_time'],
            ['table' => TableRegistry::DATAENGINE, 'column' => 'click_time'],
        ];
    }
}

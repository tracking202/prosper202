<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\ConversionTables;

/** Checks whether an early 1.9.80 database needs its refund tables added. */
final class SettlementSchema
{
    public static function needsRepair(\mysqli $db): bool
    {
        foreach ([ConversionTables::shopifyPendingRefunds(), ConversionTables::shopifyRefundTransactions()] as $definition) {
            $table = $definition->tableName;
            try {
                $result = $db->query('SHOW COLUMNS FROM `' . $table . '`');
            } catch (\mysqli_sql_exception $e) {
                if ($e->getCode() === 1146) {
                    return true;
                }
                throw $e;
            }
            if ($result === false) {
                if ($db->errno === 1146) {
                    return true;
                }
                throw new \RuntimeException('Cannot check Shopify refund tables: ' . $db->error);
            }
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[$row['Field']] = $row;
            }
            $result->free();
            $result = $db->query('SHOW INDEX FROM `' . $table . '`');
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException('Cannot check Shopify refund indexes: ' . $db->error);
            }
            $indexes = [];
            while ($row = $result->fetch_assoc()) {
                $indexes[] = $row['Key_name'];
            }
            $result->free();
            $plan = SchemaReconciler::planChanges($definition, $columns, $indexes);
            if ($plan['statements'] !== [] || $plan['unreconciled'] !== []) {
                return true;
            }
        }
        return false;
    }
}

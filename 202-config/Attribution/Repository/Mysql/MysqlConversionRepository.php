<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Calculation\ConversionRecord;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use RuntimeException;

final class MysqlConversionRepository implements ConversionRepositoryInterface
{
    public function __construct(private readonly mysqli $connection)
    {
    }

    public function fetchForUser(int $userId, int $startTime, int $endTime, ?int $afterConversionId = null, int $limit = 5000): ConversionBatch
    {
        $sql = <<<'SQL'
SELECT
    conv.conv_id,
    conv.click_id,
    conv.campaign_id,
    conv.user_id,
    conv.conv_time,
    conv.click_time,
    conv.click_payout,
    clicks.ppc_account_id,
    clicks.click_cpc
FROM 202_conversion_logs AS conv
INNER JOIN 202_clicks AS clicks ON clicks.click_id = conv.click_id
WHERE conv.user_id = ?
  AND conv.conv_time BETWEEN ? AND ?
  AND conv.deleted = 0
  AND conv.conv_id > ?
ORDER BY conv.conv_id ASC
LIMIT ?
SQL;

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare conversion lookup statement: ' . $this->connection->error);
        }

        $lastId = $afterConversionId ?? 0;
        $stmt->bind_param('iiiii', $userId, $startTime, $endTime, $lastId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $records[] = new ConversionRecord(
                    conversionId: (int) $row['conv_id'],
                    clickId: (int) $row['click_id'],
                    userId: (int) $row['user_id'],
                    campaignId: (int) $row['campaign_id'],
                    ppcAccountId: (int) $row['ppc_account_id'],
                    convTime: (int) $row['conv_time'],
                    clickTime: (int) $row['click_time'],
                    clickPayout: (float) $row['click_payout'],
                    clickCost: (float) $row['click_cpc']
                );
            }
        }
        $stmt->close();

        return new ConversionBatch($userId, $startTime, $endTime, $records);
    }
}

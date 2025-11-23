<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_stmt;
use Prosper202\Attribution\Calculation\ConversionBatch;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use RuntimeException;

final class MysqlConversionRepository implements ConversionRepositoryInterface
{
    private readonly ConversionJourneyRepository $journeyRepository;
    private readonly ConversionHydrator $hydrator;

    public function __construct(
        private readonly mysqli $connection,
        ?ConversionJourneyRepository $journeyRepository = null,
        ?ConversionHydrator $hydrator = null
    ) {
        $this->journeyRepository = $journeyRepository ?? new ConversionJourneyRepository($connection);
        $this->hydrator = $hydrator ?? new ConversionHydrator();
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
        $conversionRows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $conversionRows[] = $row;
            }
        }
        $stmt->close();

        $conversionIds = array_map(
            static fn (array $row): int => (int) $row['conv_id'],
            $conversionRows
        );

        $journeys = $this->journeyRepository->fetchJourneysForConversions($conversionIds);
        $records = $this->hydrator->hydrate($conversionRows, $journeys);

        return new ConversionBatch($userId, $startTime, $endTime, $records);
    }
}

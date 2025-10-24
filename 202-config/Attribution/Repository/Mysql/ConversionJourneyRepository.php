<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Persists and retrieves ordered touchpoint journeys for conversions.
 */
final class ConversionJourneyRepository
{
    public const DEFAULT_LOOKBACK_WINDOW = 30 * 24 * 60 * 60; // 30 days
    public const MAX_TOUCHES = 25;

    private readonly mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Persist the click journey for the supplied conversion.
     */
    public function persistJourney(
        int $conversionId,
        int $userId,
        int $campaignId,
        int $conversionTime,
        int $primaryClickId,
        int $primaryClickTime
    ): void {
        $journeyTouches = $this->fetchCandidateTouches(
            $userId,
            $campaignId,
            $conversionTime,
            $primaryClickId,
            $primaryClickTime
        );

        if (!$this->connection->begin_transaction()) {
            throw new RuntimeException('Unable to begin conversion journey transaction: ' . $this->connection->error);
        }

        try {
            $this->deleteExistingJourney($conversionId);
            $this->insertJourney($conversionId, $journeyTouches);
            if (!$this->connection->commit()) {
                throw new RuntimeException('Unable to commit conversion journey transaction: ' . $this->connection->error);
            }
        } catch (Throwable $exception) {
            $this->connection->rollback();
            throw $exception;
        }

        $this->purgeJourneyCache($conversionId);
    }

    /**
     * @return array<int, list<array{click_id: int, click_time: int}>>
     */
    public function fetchJourneysForConversions(array $conversionIds): array
    {
        if ($conversionIds === []) {
            return [];
        }

        $sanitisedIds = array_values(array_unique(array_map('intval', $conversionIds)));
        if ($sanitisedIds === []) {
            return [];
        }

        $idList = implode(',', $sanitisedIds);
        $sql = <<<SQL
SELECT conv_id, click_id, click_time, position
FROM 202_conversion_touchpoints
WHERE conv_id IN ($idList)
ORDER BY conv_id ASC, position ASC
SQL;

        $result = $this->connection->query($sql);
        if ($result === false) {
            throw new RuntimeException('Unable to fetch conversion journeys: ' . $this->connection->error);
        }

        $journeys = [];
        while ($row = $result->fetch_assoc()) {
            $convId = (int) $row['conv_id'];
            $journeys[$convId] ??= [];
            $journeys[$convId][] = [
                'click_id' => (int) $row['click_id'],
                'click_time' => (int) $row['click_time'],
            ];
        }
        $result->free();

        return $journeys;
    }

    /**
     * @return list<array{click_id: int, click_time: int}>
     */
    private function fetchCandidateTouches(
        int $userId,
        int $campaignId,
        int $conversionTime,
        int $primaryClickId,
        int $primaryClickTime
    ): array {
        $lookbackStart = max(0, $conversionTime - self::DEFAULT_LOOKBACK_WINDOW);

        $sql = <<<SQL
SELECT click_id, click_time
FROM 202_clicks
WHERE user_id = ?
  AND aff_campaign_id = ?
  AND click_time BETWEEN ? AND ?
ORDER BY click_time DESC, click_id DESC
LIMIT ?
SQL;

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare journey lookup statement: ' . $this->connection->error);
        }

        $limit = self::MAX_TOUCHES;
        $stmt->bind_param('iiiii', $userId, $campaignId, $lookbackStart, $conversionTime, $limit);
        $stmt->execute();

        $journey = [];
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $journey[(int) $row['click_id']] = [
                    'click_id' => (int) $row['click_id'],
                    'click_time' => (int) $row['click_time'],
                ];
            }
            $result->free();
        }
        $stmt->close();

        if (!isset($journey[$primaryClickId])) {
            $journey[$primaryClickId] = [
                'click_id' => $primaryClickId,
                'click_time' => $primaryClickTime,
            ];
        }

        $journey = array_values($journey);
        usort(
            $journey,
            static function (array $a, array $b): int {
                if ($a['click_time'] === $b['click_time']) {
                    return $a['click_id'] <=> $b['click_id'];
                }

                return $a['click_time'] <=> $b['click_time'];
            }
        );

        return $journey;
    }

    /**
     * @param list<array{click_id: int, click_time: int}> $journey
     */
    private function insertJourney(int $conversionId, array $journey): void
    {
        if ($journey === []) {
            return;
        }

        $sql = <<<SQL
INSERT INTO 202_conversion_touchpoints (conv_id, click_id, click_time, position, created_at)
VALUES (?, ?, ?, ?, ?)
SQL;

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare journey insert statement: ' . $this->connection->error);
        }

        $createdAt = time();
        foreach ($journey as $position => $touch) {
            $clickId = $touch['click_id'];
            $clickTime = $touch['click_time'];
            $stmt->bind_param('iiiii', $conversionId, $clickId, $clickTime, $position, $createdAt);
            $stmt->execute();
        }

        $stmt->close();
    }

    private function deleteExistingJourney(int $conversionId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM 202_conversion_touchpoints WHERE conv_id = ?');
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare journey delete statement: ' . $this->connection->error);
        }

        $stmt->bind_param('i', $conversionId);
        $stmt->execute();
        $stmt->close();
    }

    private function purgeJourneyCache(int $conversionId): void
    {
        if (!class_exists('Memcache') && !class_exists('Memcached')) {
            return;
        }

        $hashSeed = function_exists('systemHash') ? systemHash() : '';
        $cacheKey = md5(sprintf('attribution_journey_%d_%s', $conversionId, $hashSeed));

        if (isset($GLOBALS['memcache']) && $GLOBALS['memcache'] instanceof \Memcache) {
            /** @var \Memcache $memcache */
            $memcache = $GLOBALS['memcache'];
            $memcache->delete($cacheKey);
            return;
        }

        if (isset($GLOBALS['memcache']) && $GLOBALS['memcache'] instanceof \Memcached) {
            /** @var \Memcached $memcache */
            $memcache = $GLOBALS['memcache'];
            $memcache->delete($cacheKey);
        }
    }
}

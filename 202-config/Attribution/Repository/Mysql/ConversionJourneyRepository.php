<?php

declare(strict_types=1);

namespace Prosper202\Attribution\Repository\Mysql;

use mysqli;
use mysqli_result;
use Prosper202\Attribution\Repository\JourneyMaintenanceRepositoryInterface;
use Prosper202\Attribution\ScopeType;
use RuntimeException;
use Throwable;

/**
 * Persists and retrieves ordered touchpoint journeys for conversions.
 */
final class ConversionJourneyRepository implements JourneyMaintenanceRepositoryInterface
{
    public const DEFAULT_LOOKBACK_WINDOW = 30 * 24 * 60 * 60; // 30 days
    public const MAX_TOUCHES = 25;

    private readonly mysqli $connection;
    /**
     * Cached references used for the most recent mysqli parameter binding.
     *
     * @var array<int, mixed>
     */
    private array $boundParameterValues = [];

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

    public function purgeByScope(int $userId, ScopeType $scopeType, ?int $scopeId = null): int
    {
        $afterConvId = 0;
        $purged = 0;

        while (true) {
            $conversions = $this->fetchConversions(
                userId: $userId,
                scopeType: $scopeType,
                scopeId: $scopeId,
                startTime: 0,
                endTime: time(),
                afterConvId: $afterConvId,
                limit: 500
            );

            if ($conversions === []) {
                break;
            }

            foreach ($conversions as $row) {
                $convId = (int) $row['conv_id'];
                $afterConvId = $convId;
                $this->deleteExistingJourney($convId);
                $this->purgeJourneyCache($convId);
                $purged++;
            }
        }

        return $purged;
    }

    public function hydrateScope(
        int $userId,
        ScopeType $scopeType,
        ?int $scopeId = null,
        ?int $startTime = null,
        ?int $endTime = null,
        int $batchSize = 500
    ): int {
        $start = $startTime ?? max(0, time() - self::DEFAULT_LOOKBACK_WINDOW);
        $end = $endTime ?? time();
        $afterConvId = 0;
        $hydrated = 0;

        while (true) {
            $conversions = $this->fetchConversions(
                userId: $userId,
                scopeType: $scopeType,
                scopeId: $scopeId,
                startTime: $start,
                endTime: $end,
                afterConvId: $afterConvId,
                limit: $batchSize
            );

            if ($conversions === []) {
                break;
            }

            foreach ($conversions as $row) {
                $conversionId = (int) $row['conv_id'];
                $afterConvId = $conversionId;

                $this->persistJourney(
                    conversionId: $conversionId,
                    userId: $userId,
                    campaignId: (int) $row['campaign_id'],
                    conversionTime: (int) $row['conv_time'],
                    primaryClickId: (int) $row['click_id'],
                    primaryClickTime: (int) $row['click_time']
                );
                $hydrated++;
            }
        }

        return $hydrated;
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

    /**
     * @return list<array{conv_id: int, click_id: int, campaign_id: int, conv_time: int, click_time: int}>
     */
    private function fetchConversions(
        int $userId,
        ScopeType $scopeType,
        ?int $scopeId,
        int $startTime,
        int $endTime,
        int $afterConvId,
        int $limit
    ): array {
        $sql = 'SELECT cl.conv_id, cl.click_id, cl.campaign_id, cl.conv_time, cl.click_time '
            . 'FROM 202_conversion_logs cl';
        $types = 'iiii';
        $params = [$userId, $afterConvId, $startTime, $endTime];

        if ($scopeType === ScopeType::ADVERTISER) {
            if ($scopeId === null) {
                return [];
            }

            $sql .= ' INNER JOIN 202_aff_campaigns ac ON cl.campaign_id = ac.aff_campaign_id';
        }

        $sql .= ' WHERE cl.user_id = ? AND cl.conv_id > ? AND cl.deleted = 0 AND cl.conv_time BETWEEN ? AND ?';

        if ($scopeType === ScopeType::CAMPAIGN && $scopeId !== null) {
            $sql .= ' AND cl.campaign_id = ?';
            $types .= 'i';
            $params[] = $scopeId;
        }

        if ($scopeType === ScopeType::ADVERTISER && $scopeId !== null) {
            $sql .= ' AND ac.aff_network_id = ?';
            $types .= 'i';
            $params[] = $scopeId;
        }

        $sql .= ' ORDER BY cl.conv_id ASC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare conversion lookup statement: ' . $this->connection->error);
        }

        $this->bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    'conv_id' => (int) $row['conv_id'],
                    'click_id' => (int) $row['click_id'],
                    'campaign_id' => (int) $row['campaign_id'],
                    'conv_time' => (int) $row['conv_time'],
                    'click_time' => (int) $row['click_time'],
                ];
            }
            $result->free();
        }

        $stmt->close();

        return $rows;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function bind(\mysqli_stmt $stmt, string $types, array $params): void
    {
        $this->boundParameterValues = array_values($params);

        $values = [$types];
        foreach ($this->boundParameterValues as $index => &$value) {
            $values[] = &$this->boundParameterValues[$index];
        }
        unset($value);

        if (!call_user_func_array([$stmt, 'bind_param'], $values)) {
            throw new RuntimeException('Failed to bind MySQL parameters.');
        }
    }
}

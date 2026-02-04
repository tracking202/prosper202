#!/usr/bin/env php
<?php

declare(strict_types=1);

use Prosper202\Attribution\AttributionServiceFactory;
use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;

/**
 * @param array<int, ?int> $cache
 */
function resolveAdvertiserId(\mysqli $connection, int $campaignId, array &$cache): ?int
{
    if ($campaignId <= 0) {
        return null;
    }

    if (array_key_exists($campaignId, $cache)) {
        return $cache[$campaignId];
    }

    $stmt = $connection->prepare('SELECT aff_network_id FROM 202_aff_campaigns WHERE aff_campaign_id = ? LIMIT 1');
    if ($stmt === false) {
        $cache[$campaignId] = null;
        return null;
    }

    $stmt->bind_param('i', $campaignId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        $cache[$campaignId] = null;
        return null;
    }

    $advertiserId = (int) ($row['aff_network_id'] ?? 0);
    $cache[$campaignId] = $advertiserId > 0 ? $advertiserId : null;

    return $cache[$campaignId];
}

require_once __DIR__ . '/../202-config/connect.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
} else {
    fwrite(STDERR, "Unable to locate Composer autoload file. Run composer install before executing this script.\n");
    exit(1);
}

$options = getopt('', ['user::', 'start::', 'end::', 'batch-size::']);
$batchSize = isset($options['batch-size']) ? max(1, (int) $options['batch-size']) : 500;
$endTime = isset($options['end']) ? (int) $options['end'] : time();
$startTime = isset($options['start']) ? (int) $options['start'] : $endTime - ConversionJourneyRepository::DEFAULT_LOOKBACK_WINDOW;
$userIdFilter = isset($options['user']) ? (int) $options['user'] : null;

if ($startTime > $endTime) {
    fwrite(STDERR, "Start time must be before end time.\n");
    exit(1);
}

$database = DB::getInstance();
$connection = $database?->getConnection();

if (!$connection instanceof mysqli) {
    fwrite(STDERR, "Unable to obtain MySQL connection.\n");
    exit(1);
}

$journeyRepository = new ConversionJourneyRepository($connection);
$settingsService = AttributionServiceFactory::createSettingsService();
$campaignAdvertiserCache = [];
$afterConvId = 0;
$totalProcessed = 0;
$errors = [];

$sqlBase = 'SELECT conv_id, click_id, user_id, campaign_id, conv_time, click_time FROM 202_conversion_logs ' .
    'WHERE conv_id > ? AND conv_time BETWEEN ? AND ? AND deleted = 0';

if ($userIdFilter !== null) {
    $sqlBase .= ' AND user_id = ?';
}

$sqlBase .= ' ORDER BY conv_id ASC LIMIT ?';

while (true) {
    $stmt = $connection->prepare($sqlBase);
    if ($stmt === false) {
        fwrite(STDERR, 'Failed to prepare conversion lookup statement: ' . $connection->error . "\n");
        exit(1);
    }

    if ($userIdFilter !== null) {
        $stmt->bind_param('iiiii', $afterConvId, $startTime, $endTime, $userIdFilter, $batchSize);
    } else {
        $stmt->bind_param('iiii', $afterConvId, $startTime, $endTime, $batchSize);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }

    $stmt->close();

    if ($rows === []) {
        break;
    }

    foreach ($rows as $row) {
        $conversionId = (int) $row['conv_id'];
        $afterConvId = $conversionId;

        $campaignId = (int) $row['campaign_id'];
        $scope = [
            'user_id' => (int) $row['user_id'],
            'campaign_id' => $campaignId,
        ];
        $advertiserId = resolveAdvertiserId($connection, $campaignId, $campaignAdvertiserCache);
        if ($advertiserId !== null) {
            $scope['advertiser_id'] = $advertiserId;
        }

        if (!$settingsService->isMultiTouchEnabled($scope)) {
            continue;
        }

        try {
            $journeyRepository->persistJourney(
                conversionId: $conversionId,
                userId: (int) $row['user_id'],
                campaignId: $campaignId,
                conversionTime: (int) $row['conv_time'],
                primaryClickId: (int) $row['click_id'],
                primaryClickTime: (int) $row['click_time']
            );
            $totalProcessed++;
        } catch (Throwable $throwable) {
            $errors[] = sprintf(
                'Conversion %d failed: %s',
                $conversionId,
                $throwable->getMessage()
            );
        }
    }

    fwrite(STDOUT, sprintf("Processed %d conversions...\n", $totalProcessed));
}

fwrite(STDOUT, sprintf(
    "Journey backfill complete. %d conversions processed between %s and %s%s.\n",
    $totalProcessed,
    date('c', $startTime),
    date('c', $endTime),
    $userIdFilter !== null ? ' for user ' . $userIdFilter : ''
));

if ($errors !== []) {
    fwrite(STDERR, "Encountered errors while persisting journeys:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

exit(0);

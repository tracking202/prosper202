<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Attribution\Repository\ModelRepositoryInterface;
use Prosper202\Attribution\Repository\AuditRepositoryInterface;
use Prosper202\Attribution\Repository\JourneyMaintenanceRepositoryInterface;
use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlAuditRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlConversionRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlModelRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlSnapshotRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlTouchpointRepository;
use Prosper202\Attribution\Repository\NullConversionRepository;
use Prosper202\Attribution\Repository\NullModelRepository;
use Prosper202\Attribution\Repository\NullSettingsRepository;
use Prosper202\Attribution\Repository\NullSnapshotRepository;
use Prosper202\Attribution\Repository\NullTouchpointRepository;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\Repository\NullExportJobRepository;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\Repository\Mysql\MysqlSettingRepository;
use Prosper202\Attribution\Service\AttributionSettingsService as SettingsService;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use Prosper202\Attribution\AttributionJobRunner;
use Prosper202\Attribution\Repository\ExportJobRepositoryInterface;
use Prosper202\Attribution\Repository\Mysql\MysqlExportJobRepository;
use Prosper202\Database\Connection;

/**
 * Simple factory used to wire default attribution services.
 */
final class AttributionServiceFactory
{
    public static function create(
        ?ModelRepositoryInterface $modelRepository = null,
        ?SnapshotRepositoryInterface $snapshotRepository = null,
        ?TouchpointRepositoryInterface $touchpointRepository = null,
        ?AuditRepositoryInterface $auditRepository = null,
        ?ExportJobRepositoryInterface $exportRepository = null
    ): AttributionService {
        if ($modelRepository === null || $snapshotRepository === null || $touchpointRepository === null || $auditRepository === null || $exportRepository === null) {
            $conn = self::buildConnection();

            if ($conn !== null) {
                $modelRepository ??= new MysqlModelRepository($conn);
                $snapshotRepository ??= new MysqlSnapshotRepository($conn);
                $touchpointRepository ??= new MysqlTouchpointRepository($conn);
                $auditRepository ??= new MysqlAuditRepository($conn);
                $exportRepository ??= new MysqlExportJobRepository($conn);
            }
        }

        return new AttributionService(
            $modelRepository ?? new NullModelRepository(),
            $snapshotRepository ?? new NullSnapshotRepository(),
            $touchpointRepository ?? new NullTouchpointRepository(),
            $auditRepository ?? new NullAuditRepository(),
            $exportRepository ?? new NullExportJobRepository()
        );
    }

    public static function createJobRunner(
        ?ModelRepositoryInterface $modelRepository = null,
        ?SnapshotRepositoryInterface $snapshotRepository = null,
        ?TouchpointRepositoryInterface $touchpointRepository = null,
        ?ConversionRepositoryInterface $conversionRepository = null,
        ?AuditRepositoryInterface $auditRepository = null
    ): AttributionJobRunner {
        $conn = self::buildConnection();

        if ($conn !== null) {
            $modelRepository ??= new MysqlModelRepository($conn);
            $snapshotRepository ??= new MysqlSnapshotRepository($conn);
            $touchpointRepository ??= new MysqlTouchpointRepository($conn);
            $conversionRepository ??= new MysqlConversionRepository($conn);
            $auditRepository ??= new MysqlAuditRepository($conn);
        }

        return new AttributionJobRunner(
            $modelRepository ?? new NullModelRepository(),
            $snapshotRepository ?? new NullSnapshotRepository(),
            $touchpointRepository ?? new NullTouchpointRepository(),
            $conversionRepository ?? new NullConversionRepository(),
            $auditRepository ?? new NullAuditRepository()
        );
    }

    public static function createSettingsService(
        ?SettingsRepositoryInterface $settingsRepository = null,
        ?ModelRepositoryInterface $modelRepository = null,
        ?JourneyMaintenanceRepositoryInterface $journeyRepository = null
    ): SettingsService {
        if ($settingsRepository === null || $modelRepository === null || $journeyRepository === null) {
            $conn = self::buildConnection();

            if ($conn !== null) {
                $settingsRepository ??= new MysqlSettingRepository($conn);
                $modelRepository ??= new MysqlModelRepository($conn);
                $journeyRepository ??= new ConversionJourneyRepository($conn);
            }
        }

        return new SettingsService(
            $settingsRepository ?? new NullSettingsRepository(),
            $modelRepository ?? new NullModelRepository(),
            $journeyRepository
        );
    }

    private static function buildConnection(): ?Connection
    {
        $db = \DB::getInstance();
        $writeConnection = $db?->getConnection();
        $readConnection = $db?->getConnectionro();

        if (!$writeConnection instanceof \mysqli) {
            return null;
        }

        return new Connection($writeConnection, $readConnection);
    }
}

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
use Prosper202\Attribution\Repository\ExportRepositoryInterface;
use Prosper202\Attribution\Repository\Mysql\MysqlExportRepository;
use Prosper202\Attribution\Repository\NullExportRepository;
use Prosper202\Attribution\Export\SnapshotExporter;
use Prosper202\Attribution\Export\WebhookDispatcher;
use Prosper202\Attribution\Export\ExportProcessor;
use Prosper202\Attribution\Repository\NullConversionRepository;
use Prosper202\Attribution\Repository\NullModelRepository;
use Prosper202\Attribution\Repository\NullSettingsRepository;
use Prosper202\Attribution\Repository\NullSnapshotRepository;
use Prosper202\Attribution\Repository\NullTouchpointRepository;
use Prosper202\Attribution\Repository\NullAuditRepository;
use Prosper202\Attribution\Repository\SettingsRepositoryInterface;
use Prosper202\Attribution\Repository\Mysql\MysqlSettingRepository;
use Prosper202\Attribution\Service\AttributionSettingsService as SettingsService;
use Prosper202\Attribution\Repository\SnapshotRepositoryInterface;
use Prosper202\Attribution\Repository\TouchpointRepositoryInterface;
use Prosper202\Attribution\Repository\ConversionRepositoryInterface;
use Prosper202\Attribution\AttributionJobRunner;

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
        ?ExportRepositoryInterface $exportRepository = null
    ): AttributionService {
        if ($modelRepository === null || $snapshotRepository === null || $touchpointRepository === null || $auditRepository === null) {
            $db = \DB::getInstance();
            $writeConnection = $db?->getConnection();
            $readConnection = $db?->getConnectionro();

            if ($writeConnection instanceof \mysqli) {
                $modelRepository ??= new MysqlModelRepository($writeConnection, $readConnection);
                $snapshotRepository ??= new MysqlSnapshotRepository($writeConnection, $readConnection);
                $touchpointRepository ??= new MysqlTouchpointRepository($writeConnection, $readConnection);
                $auditRepository ??= new MysqlAuditRepository($writeConnection);
                $exportRepository ??= new MysqlExportRepository($writeConnection, $readConnection);
            }
        }

        return new AttributionService(
            $modelRepository ?? new NullModelRepository(),
            $snapshotRepository ?? new NullSnapshotRepository(),
            $touchpointRepository ?? new NullTouchpointRepository(),
            $auditRepository ?? new NullAuditRepository(),
            $exportRepository ?? new NullExportRepository()
        );
    }

    public static function createJobRunner(
        ?ModelRepositoryInterface $modelRepository = null,
        ?SnapshotRepositoryInterface $snapshotRepository = null,
        ?TouchpointRepositoryInterface $touchpointRepository = null,
        ?ConversionRepositoryInterface $conversionRepository = null,
        ?AuditRepositoryInterface $auditRepository = null
    ): AttributionJobRunner {
        $db = \DB::getInstance();
        $writeConnection = $db?->getConnection();
        $readConnection = $db?->getConnectionro();

        if ($writeConnection instanceof \mysqli) {
            $modelRepository ??= new MysqlModelRepository($writeConnection, $readConnection);
            $snapshotRepository ??= new MysqlSnapshotRepository($writeConnection, $readConnection);
            $touchpointRepository ??= new MysqlTouchpointRepository($writeConnection, $readConnection);
            $conversionRepository ??= new MysqlConversionRepository($writeConnection);
            $auditRepository ??= new MysqlAuditRepository($writeConnection);
        }

        return new AttributionJobRunner(
            $modelRepository ?? new NullModelRepository(),
            $snapshotRepository ?? new NullSnapshotRepository(),
            $touchpointRepository ?? new NullTouchpointRepository(),
            $conversionRepository ?? new NullConversionRepository(),
            $auditRepository ?? new NullAuditRepository()
        );
    }

    public static function createExportProcessor(
        ?ExportRepositoryInterface $exportRepository = null,
        ?SnapshotRepositoryInterface $snapshotRepository = null,
        ?ModelRepositoryInterface $modelRepository = null,
        ?SnapshotExporter $snapshotExporter = null,
        ?WebhookDispatcher $webhookDispatcher = null
    ): ExportProcessor {
        $db = \DB::getInstance();
        $writeConnection = $db?->getConnection();
        $readConnection = $db?->getConnectionro();

        if ($writeConnection instanceof \mysqli) {
            $exportRepository ??= new MysqlExportRepository($writeConnection, $readConnection);
            $snapshotRepository ??= new MysqlSnapshotRepository($writeConnection, $readConnection);
            $modelRepository ??= new MysqlModelRepository($writeConnection, $readConnection);
        }

        $snapshotExporter ??= new SnapshotExporter();
        $webhookDispatcher ??= new WebhookDispatcher();

        return new ExportProcessor(
            $exportRepository ?? new NullExportRepository(),
            $snapshotRepository ?? new NullSnapshotRepository(),
            $modelRepository ?? new NullModelRepository(),
            $snapshotExporter,
            $webhookDispatcher
        );
    }

    public static function createSettingsService(
        ?SettingsRepositoryInterface $settingsRepository = null,
        ?ModelRepositoryInterface $modelRepository = null,
        ?JourneyMaintenanceRepositoryInterface $journeyRepository = null
    ): SettingsService {
        if ($settingsRepository === null || $modelRepository === null || $journeyRepository === null) {
            $db = \DB::getInstance();
            $writeConnection = $db?->getConnection();
            $readConnection = $db?->getConnectionro();

            if ($writeConnection instanceof \mysqli) {
                $settingsRepository ??= new MysqlSettingRepository($writeConnection, $readConnection);
                $modelRepository ??= new MysqlModelRepository($writeConnection, $readConnection);
                $journeyRepository ??= new ConversionJourneyRepository($writeConnection);
            }
        }

        return new SettingsService(
            $settingsRepository ?? new NullSettingsRepository(),
            $modelRepository ?? new NullModelRepository(),
            $journeyRepository
        );
    }
}

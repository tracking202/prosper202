#!/usr/bin/env php
<?php

declare(strict_types=1);

use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlSettingRepository;
use Prosper202\Attribution\Settings\Setting;
use Prosper202\Attribution\ScopeType;

require_once __DIR__ . '/../202-config/connect.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
} else {
    fwrite(STDERR, "Unable to locate Composer autoload file. Run composer install before executing this script.\n");
    exit(1);
}

$database = DB::getInstance();
$writeConnection = $database?->getConnection();
$readConnection = $database?->getConnectionro();

if (!$writeConnection instanceof mysqli) {
    fwrite(STDERR, "Unable to obtain MySQL connection.\n");
    exit(1);
}

$settingsRepository = new MysqlSettingRepository($writeConnection, $readConnection instanceof mysqli ? $readConnection : null);
$journeyRepository = new ConversionJourneyRepository($writeConnection);

/** @var Setting[] $disabledSettings */
$disabledSettings = $settingsRepository->findDisabledSettings();

if ($disabledSettings === []) {
    fwrite(STDOUT, "No disabled attribution scopes detected.\n");
    exit(0);
}

$totalPurged = 0;
foreach ($disabledSettings as $setting) {
    $purged = $journeyRepository->purgeByScope($setting->userId, $setting->scopeType, $setting->scopeId);
    $totalPurged += $purged;

    $scopeLabel = $setting->scopeType === ScopeType::GLOBAL
        ? 'global'
        : sprintf('%s:%s', $setting->scopeType->value, (string) ($setting->scopeId ?? '0'));

    fwrite(
        STDOUT,
        sprintf(
            "Purged %d conversion journeys for user %d scope %s.\n",
            $purged,
            $setting->userId,
            $scopeLabel
        )
    );
}

fwrite(
    STDOUT,
    sprintf(
        "Purge complete. Removed %d journey records across %d disabled scope(s).\n",
        $totalPurged,
        count($disabledSettings)
    )
);

exit(0);

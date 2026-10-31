<?php

declare(strict_types=1);

// Tracking202 switched its hosted daily email report off on 2026-10-29, and
// nothing calls this any more. The file stays so that an upgrade, which
// unpacks over the old files and removes none, replaces the old callback,
// which read the owner's email and campaigns and posted them to the service.
// It bootstraps as every cron entry point does (CronEntryPointsFailLoudlyTest).
require_once __DIR__ . '/../202-config/connect.php';

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "The daily email report was switched off on 29 October 2026.\n";

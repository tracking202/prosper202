<?php

declare(strict_types=1);

// The hosted service stopped calling this DNI offer-cache callback on
// 2026-10-12, and the endpoint it posted to answers 410. It stays, reading
// and sending nothing, so an upgrade (which deletes no files) replaces the old
// one, which looked a network up by its id alone, across accounts.
// It bootstraps as every cron entry point does (CronEntryPointsFailLoudlyTest).
require_once __DIR__ . '/../202-config/connect.php';

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "The DNI offer-cache callback was retired on 12 October 2026.\n";

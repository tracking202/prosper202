<?php

declare(strict_types=1);

// The model editor went with the old attribution engine (measurement-rewrite
// plan §6); models are managed through the v3 API and both CLIs until the
// attribution pages are rebuilt (PR 10). The Attribution page lists them.
include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

header('location: ' . get_absolute_url() . '202-account/attribution.php');
exit;

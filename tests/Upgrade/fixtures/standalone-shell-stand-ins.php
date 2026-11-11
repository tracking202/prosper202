<?php

/**
 * What the standalone shell (info_top()) asks of the app that
 * lost-connection-runner.php stands in for: the install's address, and the
 * wallpaper, which is fetched from a remote service.
 */

declare(strict_types=1);

function get_absolute_url(): string
{
    return '/';
}

/** @return array<string, string> */
function getWallpaper(): array
{
    return [];
}

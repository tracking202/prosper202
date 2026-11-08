<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsOAuth;
use Prosper202\Destinations\GoogleAdsOAuthCallback;

/*
 * Where Google's consent screen sends the browser back after Connect Google
 * Ads (Setup › Destinations, or POST /api/v3/destinations/{id}/oauth/start).
 * GoogleAdsOAuthCallback checks the state and stores the grant; this page
 * only says how it went, as a sentence on Setup › Destinations.
 */

$back = get_absolute_url() . 'tracking202/setup/destinations.php';
$finish = static function (bool $ok, string $text, string $query = '') use ($back): never {
    $_SESSION['p202_destination_oauth'] = ['ok' => $ok, 'text' => $text];
    header('location: ' . $back . $query);
    die();
};

try {
    $outcome = (new GoogleAdsOAuthCallback(new DestinationStore(new \Prosper202\Database\Connection($db)), new GoogleAdsOAuth()))
        ->handle((int) $_SESSION['user_id'], $_GET, $_SERVER, time());
} catch (\RuntimeException | \JsonException $e) {
    error_log(p202UncaughtLogLine('Setup › Destinations: connecting Google Ads failed', $e));
    $finish(false, 'Google Ads could not be connected. Try again; if it keeps failing, the server\'s error log says why.');
}
$anchor = $outcome['destination_id'] > 0 ? '#d' . $outcome['destination_id'] : '';
if (!$outcome['ok']) {
    $finish(false, $outcome['text'], $anchor);
}

header('location: ' . $back . '?done=connected' . $anchor);
die();

<?php

declare(strict_types=1);

include_once(substr(__DIR__, 0, -18) . '/202-config/connect.php');

AUTH::require_user();

if (!$userObj->hasPermission('access_to_setup_section')) {
    header('location: ' . get_absolute_url() . 'tracking202/');
    die();
}

use Prosper202\Destinations\DestinationInputException;
use Prosper202\Destinations\DestinationStore;
use Prosper202\Destinations\GoogleAdsDestination;
use Prosper202\Destinations\GoogleAdsOAuth;

/*
 * Where Google's consent screen sends the browser back after Connect Google
 * Ads (Setup › Destinations, or POST /api/v3/destinations/{id}/oauth/start).
 * The state must be one this install signed for the signed-in user, not
 * expired; the code is traded for a refresh token with the destination's own
 * OAuth client, and the token is stored sealed. Nothing here is shown back
 * but a sentence on Setup › Destinations.
 */

$userId = (int) $_SESSION['user_id'];
$back = get_absolute_url() . 'tracking202/setup/destinations.php';
$finish = static function (bool $ok, string $text, string $query = '') use ($back): never {
    $_SESSION['p202_destination_oauth'] = ['ok' => $ok, 'text' => $text];
    header('location: ' . $back . $query);
    die();
};

$conn = new \Prosper202\Database\Connection($db);
$store = new DestinationStore($conn);
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$error = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';

$destinationId = 0;
try {
    $destinationId = GoogleAdsOAuth::verifyState($state, $userId, time(), $store->stateKey());
} catch (DestinationInputException $e) {
    $finish(false, $e->getMessage());
}
if ($error !== '' || $code === '') {
    $finish(false, $error === 'access_denied'
        ? 'Google Ads was not connected: access was not allowed on Google\'s consent screen.'
        : 'Google Ads was not connected: Google answered ' . mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $error) ?: 'without a code', 0, 60) . '.', '#d' . $destinationId);
}

try {
    $destination = $store->forSending($userId, $destinationId);
    if ($destination === null || $destination['type'] !== GoogleAdsDestination::TYPE) {
        $finish(false, 'That destination is not one of yours, or was deleted.');
    }
    $credentials = $destination['credentials'];
    $answer = (new GoogleAdsOAuth())->exchange(
        $code,
        (string) ($credentials['oauth_client_id'] ?? ''),
        (string) ($credentials['oauth_client_secret'] ?? ''),
        GoogleAdsOAuth::redirectUri($_SERVER)
    );
    if ($answer['refresh_token'] === null) {
        $finish(false, 'Google Ads was not connected: ' . GoogleAdsOAuth::redact((string) $answer['error'], [$credentials['developer_token'] ?? null, $credentials['oauth_client_secret'] ?? null]), '#d' . $destinationId);
    }
    if (!$store->authorise($userId, $destinationId, $answer['refresh_token'], time())) {
        $finish(false, 'That destination is not one of yours, or was deleted.');
    }
} catch (\RuntimeException | \JsonException $e) {
    error_log(p202UncaughtLogLine('Setup › Destinations: connecting Google Ads failed', $e));
    $finish(false, 'Google Ads could not be connected. Try again; if it keeps failing, the server\'s error log says why.', '#d' . $destinationId);
}

header('location: ' . $back . '?done=connected#d' . $destinationId);
die();

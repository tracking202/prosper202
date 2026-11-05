<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * What tracking202/setup/destination-oauth.php does when Google's consent
 * screen sends the browser back after Connect Google Ads (Setup ›
 * Destinations, or POST /api/v3/destinations/{id}/oauth/start). The state
 * must be one this install signed for the signed-in user, not expired; the
 * code is traded for a refresh token with the destination's own OAuth
 * client, and the token is stored sealed (DestinationStore::authorise()).
 * The page turns the outcome into a sentence on Setup › Destinations.
 */
final class GoogleAdsOAuthCallback
{
    public function __construct(private readonly DestinationStore $store, private readonly GoogleAdsOAuth $oauth)
    {
    }

    /**
     * Connect the destination the state names. A database error or an
     * unreadable destination is thrown, for the page to log.
     *
     * @param array<string, mixed> $get the callback's query: state, code, error
     * @param array<string, mixed> $server normally $_SERVER (the redirect URI Google was given)
     * @return array{ok: bool, text: string, destination_id: int} destination_id is 0 when the state named none
     */
    public function handle(int $userId, array $get, array $server, int $now): array
    {
        $state = is_string($get['state'] ?? null) ? $get['state'] : '';
        $code = is_string($get['code'] ?? null) ? $get['code'] : '';
        $error = is_string($get['error'] ?? null) ? $get['error'] : '';

        try {
            $destinationId = GoogleAdsOAuth::verifyState($state, $userId, $now, $this->store->stateKey());
        } catch (DestinationInputException $e) {
            return ['ok' => false, 'text' => $e->getMessage(), 'destination_id' => 0];
        }
        $refused = static fn (string $text): array => ['ok' => false, 'text' => $text, 'destination_id' => $destinationId];
        if ($error !== '' || $code === '') {
            return $refused($error === 'access_denied'
                ? 'Google Ads was not connected: access was not allowed on Google\'s consent screen.'
                : 'Google Ads was not connected: Google answered ' . mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $error) ?: 'without a code', 0, 60) . '.');
        }

        $destination = $this->store->forSending($userId, $destinationId);
        if ($destination === null || $destination['type'] !== GoogleAdsDestination::TYPE) {
            return ['ok' => false, 'text' => 'That destination is not one of yours, or was deleted.', 'destination_id' => 0];
        }
        $credentials = $destination['credentials'];
        $answer = $this->oauth->exchange(
            $code,
            (string) ($credentials['oauth_client_id'] ?? ''),
            (string) ($credentials['oauth_client_secret'] ?? ''),
            GoogleAdsOAuth::redirectUri($server)
        );
        if ($answer['refresh_token'] === null) {
            return $refused('Google Ads was not connected: ' . GoogleAdsOAuth::redact((string) $answer['error'], [$credentials['developer_token'] ?? null, $credentials['oauth_client_secret'] ?? null]));
        }
        if (!$this->store->authorise($userId, $destinationId, $answer['refresh_token'], $now)) {
            return ['ok' => false, 'text' => 'That destination is not one of yours, or was deleted.', 'destination_id' => 0];
        }

        return ['ok' => true, 'text' => 'Google Ads is connected.', 'destination_id' => $destinationId];
    }
}

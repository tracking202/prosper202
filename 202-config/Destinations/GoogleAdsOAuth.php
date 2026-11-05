<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

use Prosper202\Attribution\WebhookSender;
use Prosper202\Click\TrackingBaseUrl;

/**
 * Connecting a Google Ads destination to the operator's Google account with
 * their own OAuth client: Google's consent screen sends the browser back to
 * this install's callback page, which trades the code for a refresh token
 * (DestinationStore::authorise()). Access tokens are minted from it per run
 * and never stored.
 *
 * The state Google hands back is signed with a key derived from the
 * destinations' credential key and names the user and the destination, and
 * expires: a callback for another user, another install or a stale start is
 * refused, so nobody can attach their Google account to someone else's
 * destination (OAuth CSRF).
 */
final class GoogleAdsOAuth
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_HOST = 'oauth2.googleapis.com';
    public const TOKEN_URL = 'https://' . self::TOKEN_HOST . '/token';
    public const SCOPE = 'https://www.googleapis.com/auth/adwords';
    public const STATE_SECONDS = 600;
    public const CALLBACK_PATH = 'tracking202/setup/destination-oauth.php';

    public function __construct(private ?WebhookSender $sender = null)
    {
    }

    /**
     * The callback URL on the host this request reached: the one to register
     * as the OAuth client's authorised redirect URI. A forged Host header
     * only sends the forger's own browser to a URL Google refuses.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function redirectUri(array $server): string
    {
        return TrackingBaseUrl::forRequest($server) . self::CALLBACK_PATH;
    }

    public static function state(int $userId, int $destinationId, int $now, #[\SensitiveParameter] string $key): string
    {
        $payload = 'v1.' . $userId . '.' . $destinationId . '.' . ($now + self::STATE_SECONDS) . '.' . bin2hex(random_bytes(8));

        return $payload . '.' . self::sign($payload, $key);
    }

    /**
     * The destination a state names, when this install signed it for $userId
     * and it has not expired.
     *
     * @throws DestinationInputException
     */
    public static function verifyState(string $state, int $userId, int $now, #[\SensitiveParameter] string $key): int
    {
        $parts = explode('.', $state);
        if (count($parts) !== 6 || $parts[0] !== 'v1') {
            throw new DestinationInputException('state', 'Google sent back a state this install did not make. Start the connection again from Setup › Destinations.');
        }
        $payload = implode('.', array_slice($parts, 0, 5));
        if (!hash_equals(self::sign($payload, $key), $parts[5])) {
            throw new DestinationInputException('state', 'Google sent back a state this install did not sign. Start the connection again from Setup › Destinations.');
        }
        [, $user, $destination, $expires] = $parts;
        if ((int) $user !== $userId) {
            throw new DestinationInputException('state', 'This Google connection was started by another user. Sign in as that user, or start it again yourself.');
        }
        if ((int) $expires < $now) {
            throw new DestinationInputException('state', 'The Google connection took more than ' . (self::STATE_SECONDS / 60) . ' minutes and expired. Start it again from Setup › Destinations.');
        }

        return (int) $destination;
    }

    /** Google's consent screen, asking for offline access so a refresh token comes back. */
    public static function authorisationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Where to send the browser to connect a destination: null when the
     * account has no such destination.
     *
     * @return array{authorisation_url: string, redirect_uri: string, expires_at: int}|null
     * @throws DestinationInputException when it is not a Google Ads destination, or has no OAuth client
     */
    public static function start(DestinationStore $store, int $userId, int $destinationId, string $redirectUri, int $now): ?array
    {
        $destination = $store->forSending($userId, $destinationId);
        if ($destination === null) {
            return null;
        }
        if ($destination['type'] !== GoogleAdsDestination::TYPE) {
            throw new DestinationInputException('type', 'Only a Google Ads destination is connected through Google; this one is ' . $destination['type'] . '.');
        }
        $clientId = $destination['credentials']['oauth_client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new DestinationInputException('credentials.oauth_client_id', 'Set the destination\'s OAuth client ID and secret first.');
        }

        return [
            'authorisation_url' => self::authorisationUrl($clientId, $redirectUri, self::state($userId, $destinationId, $now, $store->stateKey())),
            'redirect_uri' => $redirectUri,
            'expires_at' => $now + self::STATE_SECONDS,
        ];
    }

    /**
     * Trade the consent screen's code for a refresh token.
     *
     * @return array{refresh_token: string|null, error: string|null}
     */
    public function exchange(#[\SensitiveParameter] string $code, string $clientId, #[\SensitiveParameter] string $clientSecret, string $redirectUri): array
    {
        $answer = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);
        $redact = static fn (string $text): string => self::redact($text, [$code, $clientSecret]);
        if ($answer['error'] !== null) {
            return ['refresh_token' => null, 'error' => $redact($answer['error'])];
        }
        $refresh = $answer['json']['refresh_token'] ?? null;
        if (!is_string($refresh) || $refresh === '') {
            return ['refresh_token' => null, 'error' => 'Google gave no refresh token. Remove this app\'s access on your Google account\'s security page (Third-party apps), then connect again.'];
        }

        return ['refresh_token' => $refresh, 'error' => null];
    }

    /**
     * One request to Google's token endpoint through the guarded sender.
     * 'error' says what went wrong (null on a 200 with JSON); 'retry' whether
     * a later try could change it; 'answered' whether Google answered at all.
     *
     * @param array<string, string> $form
     * @return array{status: int, json: array<string, mixed>, error: string|null, retry: bool, answered: bool}
     */
    public function token(#[\SensitiveParameter] array $form): array
    {
        $this->sender ??= new WebhookSender();
        $sent = $this->sender->post(self::TOKEN_URL, http_build_query($form, '', '&', PHP_QUERY_RFC3986), ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: Prosper202-Destinations/1'], [443]);
        $out = ['status' => $sent['status'], 'json' => [], 'error' => null, 'retry' => false, 'answered' => true];
        if ($sent['refused'] !== null) {
            return ['error' => 'not sent: ' . $sent['refused']] + $out;
        }
        if ($sent['strayed'] !== null) {
            return ['error' => 'not sent: the connection went to ' . $sent['strayed'] . ' instead of the checked address ' . $sent['pinned']] + $out;
        }
        if ($sent['status'] === 0) {
            return ['error' => 'no answer from Google\'s token endpoint: ' . ($sent['error'] ?? 'the request failed'), 'retry' => true, 'answered' => false] + $out;
        }
        try {
            $json = json_decode($sent['body'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        $out['json'] = is_array($json) ? $json : [];
        if ($sent['status'] === 200 && is_array($json)) {
            return $out;
        }
        $error = is_string($out['json']['error'] ?? null) ? $out['json']['error'] : '';
        $description = is_string($out['json']['error_description'] ?? null) ? $out['json']['error_description'] : '';

        return [
            'error' => 'Google\'s token endpoint answered ' . $sent['status'] . ($error !== '' ? ' ' . $error : '') . ($description !== '' ? ': ' . mb_substr($description, 0, 200) : ''),
            'retry' => $sent['status'] >= 500 || $sent['status'] === 408 || $sent['status'] === 429,
        ] + $out;
    }

    /**
     * $text with every secret replaced by …, raw and URL-encoded.
     *
     * @param list<mixed> $secrets
     */
    public static function redact(string $text, #[\SensitiveParameter] array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace([$secret, rawurlencode($secret), urlencode($secret)], '…', $text);
            }
        }

        return $text;
    }

    private static function sign(string $payload, #[\SensitiveParameter] string $key): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }
}

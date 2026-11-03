<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Conversion\PostbackAuth\PostbackAuthSettings;
use Prosper202\Conversion\PostbackAuth\PostbackAuthStore;
use Prosper202\Conversion\PostbackAuth\PostbackSignature;
use Prosper202\Conversion\PostbackAuth\PostbackToken;
use Prosper202\Conversion\PostbackAuth\PresentedCredentials;
use Prosper202\Database\Connection;

/**
 * Postback authentication as REST (Prosper202\Conversion\PostbackAuth), what
 * Account › API Integrations › Postback authentication shows and changes:
 *
 * - GET /conversions/postback-auth: the account's token (minted the first
 *   time, as a postback URL handed out mints it), whether postbacks and
 *   pixels must carry it, and how to sign one;
 * - PUT /conversions/postback-auth: `{"token_required": true|false}`;
 * - POST /conversions/postback-auth/rotate: `{"grace_hours": 0-720}`, a new
 *   token, the old one working that many hours more (168 when omitted).
 *
 * The read asks for Setup (the postback URLs carry the token already), the
 * writes for what the page asks, access_to_api_integrations (index.php).
 */
final class PostbackAuthController
{
    public const MAX_GRACE_HOURS = PostbackAuthStore::MAX_GRACE_SECONDS / 3600;
    public const DEFAULT_GRACE_HOURS = PostbackAuthStore::DEFAULT_GRACE_SECONDS / 3600;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /** @return array{data: array<string, mixed>} */
    public function show(?int $now = null): array
    {
        $now ??= time();

        return ['data' => self::shape($this->store()->ensureToken($this->userId, $now), $now)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function update(array $payload, ?int $now = null): array
    {
        PayloadKeys::refuseUnknown($payload, ['token_required'], 'a postback authentication setting');
        if (!array_key_exists('token_required', $payload) || !is_bool($payload['token_required'])) {
            throw new ValidationException('token_required is required', [
                'token_required' => 'Send true (postbacks and pixels must carry the token or a signature) or false (they may come without; a wrong token is still refused).',
            ]);
        }
        $now ??= time();
        $store = $this->store();
        $store->setRequired($this->userId, $payload['token_required'], $now);

        return ['data' => self::shape($store->ensureToken($this->userId, $now), $now)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function rotate(array $payload, ?int $now = null): array
    {
        PayloadKeys::refuseUnknown($payload, ['grace_hours'], 'a postback token rotation');
        // Omitted is the default; a null sent is refused, not read as omitted.
        $hours = array_key_exists('grace_hours', $payload) ? $payload['grace_hours'] : self::DEFAULT_GRACE_HOURS;
        if (!is_int($hours) || $hours < 0 || $hours > self::MAX_GRACE_HOURS) {
            throw new ValidationException('Invalid grace_hours', [
                'grace_hours' => 'A whole number of hours from 0 to ' . self::MAX_GRACE_HOURS . ' that the old token keeps working,'
                    . ' so the URLs at your networks can be changed one by one; 0 stops it at once (a token that leaked).',
            ]);
        }
        $now ??= time();

        return ['data' => self::shape($this->store()->rotate($this->userId, $hours * 3600, $now), $now)];
    }

    /** @return array<string, mixed> */
    public static function shape(PostbackAuthSettings $settings, int $now): array
    {
        return [
            'token_required' => $settings->required,
            'token' => $settings->token,
            'token_created_at' => $settings->tokenCreatedAt,
            'previous_token_valid_until' => $settings->previousValidUntil($now),
            'parameter' => PostbackToken::PARAMETER,
            'signing' => [
                'algorithm' => 'HMAC-SHA256',
                'version' => PostbackSignature::VERSION,
                'timestamp_parameter' => PresentedCredentials::TIMESTAMP,
                'signature_parameter' => PresentedCredentials::SIGNATURE,
                'window_seconds' => PostbackSignature::WINDOW,
                'endpoints' => PostbackSignature::ENDPOINTS,
            ],
        ];
    }

    private function store(): PostbackAuthStore
    {
        return new PostbackAuthStore(new Connection($this->db));
    }
}

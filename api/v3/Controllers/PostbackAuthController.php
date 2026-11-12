<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ValidationException;
use Api\V3\Support\PayloadKeys;
use Prosper202\Conversion\PostbackAuth\PostbackAuthMode;
use Prosper202\Conversion\PostbackAuth\PostbackAuthReadiness;
use Prosper202\Conversion\PostbackAuth\PostbackAuthSettings;
use Prosper202\Conversion\PostbackAuth\PostbackAuthStore;
use Prosper202\Conversion\PostbackAuth\PostbackCredential;
use Prosper202\Conversion\PostbackAuth\PostbackSignature;
use Prosper202\Conversion\PostbackAuth\PostbackToken;
use Prosper202\Conversion\PostbackAuth\PresentedCredentials;
use Prosper202\Database\Connection;

/**
 * Postback authentication as REST (Prosper202\Conversion\PostbackAuth), what
 * Account › API Integrations › Postback authentication shows and changes:
 *
 * - GET /conversions/postback-auth: the mode, the URL token and the signing
 *   secret (each minted the first time, as a postback URL handed out mints
 *   them), and how to sign;
 * - GET /conversions/postback-auth/readiness: who a stricter mode would refuse;
 * - PUT /conversions/postback-auth: `{"mode": "off"|"token"|"signed"}`;
 * - POST /conversions/postback-auth/rotate: `{"credential": "token" |
 *   "signing_secret", "grace_hours": 0-720}`, a new one, the old one working
 *   that many hours more (token and 168 when omitted).
 *
 * Every route asks for what the page asks, access_to_api_integrations,
 * and for conversions:write, the read included (index.php): the token and
 * the secret record and reverse conversions, so a key that may only read
 * them is not given either. A credential stored but unreadable is replaced
 * by a new one on any of the three, and `recovered` says so.
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

        return ['data' => self::shape($this->store()->ensureCredentials($this->userId, $now), $now)];
    }

    /**
     * GET /conversions/postback-auth/readiness: the account's postbacks and
     * pixels of the last 7 days by what each presented, and whether a
     * stricter mode would refuse any (PostbackAuthReadiness).
     *
     * @return array{data: array<string, mixed>}
     */
    public function readiness(?int $now = null): array
    {
        $now ??= time();
        $conn = new Connection($this->db);

        return ['data' => ['mode' => (new PostbackAuthStore($conn))->mode($this->userId)->value]
            + (new PostbackAuthReadiness($conn))->forAccount($this->userId, $now)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function update(array $payload, ?int $now = null): array
    {
        PayloadKeys::refuseUnknown($payload, ['mode'], 'a postback authentication setting');
        $mode = is_string($payload['mode'] ?? null) ? PostbackAuthMode::tryFrom($payload['mode']) : null;
        if ($mode === null) {
            throw new ValidationException('mode is required', [
                'mode' => 'One of off (postbacks and pixels may come without credentials; a wrong one is still refused),'
                    . ' token (they must carry the token or a signature) or signed (only a signature: the token alone is'
                    . ' refused, and so is every pixel, which cannot sign).',
            ]);
        }
        $now ??= time();

        return ['data' => self::shape($this->store()->setMode($this->userId, $mode, $now), $now)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data: array<string, mixed>}
     */
    public function rotate(array $payload, ?int $now = null): array
    {
        PayloadKeys::refuseUnknown($payload, ['credential', 'grace_hours'], 'a postback credential rotation');
        $errors = [];
        $credential = PostbackCredential::TOKEN;
        if (array_key_exists('credential', $payload)) {
            $credential = is_string($payload['credential']) ? PostbackCredential::tryFrom($payload['credential']) : null;
            if ($credential === null) {
                $errors['credential'] = 'One of ' . implode(', ', PostbackCredential::values()) . ' (token when omitted).';
            }
        }
        // Omitted is the default; a null sent is refused, not read as omitted.
        $hours = array_key_exists('grace_hours', $payload) ? $payload['grace_hours'] : self::DEFAULT_GRACE_HOURS;
        if (!is_int($hours) || $hours < 0 || $hours > self::MAX_GRACE_HOURS) {
            $errors['grace_hours'] = 'A whole number of hours from 0 to ' . self::MAX_GRACE_HOURS . ' that the old one keeps'
                . ' working, so the URLs or senders using it can be moved one by one; 0 stops it at once (one that leaked).';
        }
        if ($errors !== [] || $credential === null || !is_int($hours)) {
            throw new ValidationException('Invalid rotation', $errors);
        }
        $now ??= time();

        return ['data' => self::shape($this->store()->rotate($this->userId, $credential, $hours * 3600, $now), $now)];
    }

    /**
     * A rotation's answer as an Idempotency-Key records it: without the token
     * and the secret, which are never kept in the server-state store (as an
     * API key's create is not). withCredentials() puts them back.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    public static function withoutCredentials(array $answer): array
    {
        if (is_array($answer['data'] ?? null)) {
            $answer['data']['token'] = null;
            $answer['data']['signing_secret'] = null;
        }

        return $answer;
    }

    /**
     * A recorded rotation's answer with the credentials as they are now: the
     * ones that rotation made, unless another has replaced them since.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    public function withCredentials(array $answer, ?int $now = null): array
    {
        $now ??= time();
        $recorded = is_array($answer['data'] ?? null) ? $answer['data'] : [];
        $answer['data'] = self::shape($this->store()->ensureCredentials($this->userId, $now), $now);
        $answer['data']['recovered'] = $recorded['recovered'] ?? $answer['data']['recovered'];

        return $answer;
    }

    /** @return array<string, mixed> */
    public static function shape(PostbackAuthSettings $settings, int $now): array
    {
        return [
            'mode' => $settings->mode->value,
            'token' => $settings->token,
            'token_created_at' => $settings->tokenCreatedAt,
            'previous_token_valid_until' => $settings->previousTokenValidUntil($now),
            'signing_secret' => $settings->secret,
            'signing_secret_created_at' => $settings->secretCreatedAt,
            'previous_signing_secret_valid_until' => $settings->previousSecretValidUntil($now),
            'recovered' => $settings->replacedUnreadable === [] ? null : [
                'credentials' => array_map(static fn (PostbackCredential $c): string => $c->value, $settings->replacedUnreadable),
                'key_recreated' => $settings->keyRecreated,
                'message' => $settings->recoveryNotice(),
            ],
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

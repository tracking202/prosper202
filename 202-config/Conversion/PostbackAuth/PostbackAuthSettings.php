<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * An account's postback authentication as stored (PostbackAuthStore): what
 * its postbacks and pixels must present, its URL token, its signing secret,
 * and for each the one a rotation replaced, which keeps working until its
 * grace period ends.
 */
final class PostbackAuthSettings
{
    public function __construct(
        public readonly PostbackAuthMode $mode,
        public readonly ?string $token,
        public readonly ?int $tokenCreatedAt,
        public readonly ?string $previousToken,
        public readonly ?int $previousTokenExpiresAt,
        public readonly ?string $secret = null,
        public readonly ?int $secretCreatedAt = null,
        public readonly ?string $previousSecret = null,
        public readonly ?int $previousSecretExpiresAt = null,
    ) {
    }

    /** An account with no row: one made from 1.9.80 on, before it was ever handed a token. */
    public static function none(): self
    {
        return new self(PostbackAuthMode::TOKEN, null, null, null, null);
    }

    /**
     * The tokens a request may present now: the current one, and the one it
     * replaced while that is inside its grace period.
     *
     * @return list<string>
     */
    public function validTokens(int $now): array
    {
        return self::valid($this->token, $this->previousToken, $this->previousTokenExpiresAt, $now);
    }

    /** @return list<string> the signing secrets a signature may be keyed with now, likewise */
    public function validSecrets(int $now): array
    {
        return self::valid($this->secret, $this->previousSecret, $this->previousSecretExpiresAt, $now);
    }

    /** When the replaced token stops working, or null when none is working. */
    public function previousTokenValidUntil(int $now): ?int
    {
        return self::until($this->previousToken, $this->previousTokenExpiresAt, $now);
    }

    public function previousSecretValidUntil(int $now): ?int
    {
        return self::until($this->previousSecret, $this->previousSecretExpiresAt, $now);
    }

    /** @return list<string> */
    private static function valid(?string $current, ?string $previous, ?int $expiresAt, int $now): array
    {
        $values = $current === null ? [] : [$current];
        if (self::until($previous, $expiresAt, $now) !== null) {
            $values[] = (string) $previous;
        }

        return $values;
    }

    private static function until(?string $previous, ?int $expiresAt, int $now): ?int
    {
        return $previous !== null && $expiresAt !== null && $expiresAt > $now ? $expiresAt : null;
    }
}

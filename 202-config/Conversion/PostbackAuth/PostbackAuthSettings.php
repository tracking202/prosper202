<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * An account's postback authentication as stored (PostbackAuthStore):
 * whether its postbacks and pixels must authenticate, its token, and the
 * token a rotation replaced, which keeps working until previousExpiresAt.
 */
final class PostbackAuthSettings
{
    public function __construct(
        public readonly bool $required,
        public readonly ?string $token,
        public readonly ?int $tokenCreatedAt,
        public readonly ?string $previousToken,
        public readonly ?int $previousExpiresAt,
    ) {
    }

    /** An account with no row: one made from 1.9.80 on, before it was ever handed a token. */
    public static function none(): self
    {
        return new self(true, null, null, null, null);
    }

    /**
     * The tokens a request may present now: the current one, and the one it
     * replaced while that is inside its grace period.
     *
     * @return list<string>
     */
    public function validTokens(int $now): array
    {
        $tokens = $this->token === null ? [] : [$this->token];
        if ($this->previousToken !== null && $this->previousExpiresAt !== null && $this->previousExpiresAt > $now) {
            $tokens[] = $this->previousToken;
        }

        return $tokens;
    }

    /** When the replaced token stops working, or null when none is working. */
    public function previousValidUntil(int $now): ?int
    {
        return $this->previousToken !== null && $this->previousExpiresAt !== null && $this->previousExpiresAt > $now
            ? $this->previousExpiresAt
            : null;
    }
}

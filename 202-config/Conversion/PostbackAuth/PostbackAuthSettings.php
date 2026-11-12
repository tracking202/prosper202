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
        /** @var list<PostbackCredential> stored ones that could not be read, replaced by new ones on this call */
        public readonly array $replacedUnreadable = [],
        /** Whether this call made a new encryption key because the stored one was missing. */
        public readonly bool $keyRecreated = false,
    ) {
    }

    /** An account with no row: one made from 1.9.80 on, before it was ever handed a token. */
    public static function none(): self
    {
        return self::modeOnly(PostbackAuthMode::TOKEN);
    }

    /** The mode with no credential read: what deciding a request that presents none needs. */
    public static function modeOnly(PostbackAuthMode $mode): self
    {
        return new self($mode, null, null, null, null);
    }

    /** These credentials under another mode: what a request would be answered if the account chose it. */
    public function withMode(PostbackAuthMode $mode): self
    {
        return new self(
            $mode,
            $this->token,
            $this->tokenCreatedAt,
            $this->previousToken,
            $this->previousTokenExpiresAt,
            $this->secret,
            $this->secretCreatedAt,
            $this->previousSecret,
            $this->previousSecretExpiresAt,
        );
    }

    /** @param list<PostbackCredential> $replaced */
    public function withRecovery(array $replaced, bool $keyRecreated): self
    {
        return new self(
            $this->mode,
            $this->token,
            $this->tokenCreatedAt,
            $this->previousToken,
            $this->previousTokenExpiresAt,
            $this->secret,
            $this->secretCreatedAt,
            $this->previousSecret,
            $this->previousSecretExpiresAt,
            $replaced,
            $keyRecreated,
        );
    }

    /** What the owner must be told when unreadable credentials were replaced, or null when none were. */
    public function recoveryNotice(): ?string
    {
        if ($this->replacedUnreadable === []) {
            return null;
        }
        $names = array_map(
            static fn (PostbackCredential $c): string => $c === PostbackCredential::TOKEN ? 'postback token' : 'signing secret',
            $this->replacedUnreadable
        );
        $what = implode(' and ', $names);

        return ($this->keyRecreated
            ? 'The key that encrypts postback credentials was missing from this install, so a new key was made and your '
            : 'Your stored ')
            . $what . ' could not be read, so new ' . (count($names) > 1 ? 'ones were' : 'one was')
            . ' made. The previous ' . (count($names) > 1 ? 'ones' : 'one') . ' stopped working: copy your postback URLs and pixels again from'
            . ' Setup › Postback / Pixel' . (in_array(PostbackCredential::SIGNING_SECRET, $this->replacedUnreadable, true)
                ? ', and give the new signing secret to everything that signs.' : '.');
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

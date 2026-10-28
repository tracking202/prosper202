<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

/**
 * Play Integrity, opt-in per Android registration (plan §5.6, §9 decision 3):
 *
 *   off      the default: a token the SDK sends is kept, never decoded;
 *   observe  every token is decoded and its verdict stored and reported;
 *            attribution and payouts are unaffected;
 *   require  an install that would be attributed waits (pending_integrity)
 *            for a passing verdict, and is attributed — and paid, and
 *            announced to its traffic source — only then.
 *
 * The registration's mode is copied onto each install when it arrives, and
 * that copy governs it: changing the mode never re-judges an install
 * already received, so nothing is ever paid and then un-paid by a setting.
 */
enum IntegrityMode: string
{
    case OFF = 'off';
    case OBSERVE = 'observe';
    case REQUIRE = 'require';

    /**
     * A stored mode. Anything that is not exactly one of the three — a NULL,
     * a truncated or unknown value — is REQUIRE (CLAUDE.md #11): an
     * unreadable setting on a security control must never resolve to the
     * mode that waves every install through. Under REQUIRE the install waits
     * and, at worst, is recorded unverified and unpaid; under OFF a
     * corrupted row would have switched the control off in silence.
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::REQUIRE) : self::REQUIRE;
    }

    public function decodes(): bool
    {
        return $this !== self::OFF;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

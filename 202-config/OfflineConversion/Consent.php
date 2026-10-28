<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * What a Google Ads file states for its Ad User Data and Ad Personalization
 * columns: Granted or Denied, the two values Google lists, or nothing (the
 * columns left empty, which Google reads as not set). Prosper202 records no
 * visitor's consent, so the account states it, per file; it is never
 * inferred from a click having an id.
 */
enum Consent: string
{
    case GRANTED = 'granted';
    case DENIED = 'denied';

    /** The value as Google's file spells it. */
    public function cell(): string
    {
        return match ($this) {
            self::GRANTED => 'Granted',
            self::DENIED => 'Denied',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

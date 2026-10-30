<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * An account's switches for the built-in checks of the click filter. The
 * defaults are the filter as it was before it had settings: every check on,
 * and a repeat visit within the daily cleanup's window.
 */
final class ClickFilterSettings
{
    /**
     * The repeat windows offered, in seconds. 24h is the default and keeps
     * the check as it always was: an address stays seen until the daily
     * cleanup removes it once it is a day old (24 to 48 hours, or longer
     * without a cron job), so it is asked without a time (repeatWithin()).
     * Seven days is the most, since 202_last_ips keeps an address for the
     * window of every account that asks for it.
     */
    public const WINDOWS = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];

    public const DEFAULT_WINDOW = '24h';

    public function __construct(
        public readonly bool $signInAddresses = true,
        public readonly bool $crawlerRanges = true,
        public readonly bool $repeatVisits = true,
        public readonly string $repeatWindow = self::DEFAULT_WINDOW,
    ) {
        if (!array_key_exists($repeatWindow, self::WINDOWS)) {
            throw new \InvalidArgumentException('Not a repeat window: ' . $repeatWindow);
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** The window key for a number of seconds, or null for one not offered. */
    public static function windowOf(int $seconds): ?string
    {
        $key = array_search($seconds, self::WINDOWS, true);

        return $key === false ? null : $key;
    }

    public function windowSeconds(): int
    {
        return self::WINDOWS[$this->repeatWindow];
    }

    /** The repeat check's time bound in seconds, or null for the daily cleanup's (the default). */
    public function repeatWithin(): ?int
    {
        return $this->repeatWindow === self::DEFAULT_WINDOW ? null : $this->windowSeconds();
    }

    public function isDefault(): bool
    {
        return $this->signInAddresses && $this->crawlerRanges && $this->repeatVisits
            && $this->repeatWindow === self::DEFAULT_WINDOW;
    }

    /** @return array{sign_in_addresses: bool, crawler_ranges: bool, repeat_visits: bool, repeat_window: string} */
    public function toArray(): array
    {
        return [
            'sign_in_addresses' => $this->signInAddresses,
            'crawler_ranges' => $this->crawlerRanges,
            'repeat_visits' => $this->repeatVisits,
            'repeat_window' => $this->repeatWindow,
        ];
    }
}

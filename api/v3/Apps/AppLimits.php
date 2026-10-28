<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * What a registration says about abuse on the Android intake (plan §7.1):
 * the click-to-install-time tails an install is flagged for, and how many
 * installs the registration, and how many events one install, may report
 * in a minute.
 *
 * - `ctit_min_seconds` (default 10): an install that began sooner than this
 *   after its click is flagged `short` — too fast for a person to have
 *   landed on the store page, tapped Install and started the download, the
 *   mark of click injection that slipped past the `implausible` timing rule.
 *   0 flags only an install that began before its click (within the
 *   classifier's clock-skew allowance).
 * - `ctit_max_seconds` (default 86 400, one day): an install that began
 *   later than this is flagged `long`. A fixed cap rather than a percentile
 *   of the app's own distribution: click spamming works by inflating that
 *   distribution's tail, so a threshold learned from it moves with the
 *   attack, and a small app has too few installs for a percentile to mean
 *   anything. Most genuine installs begin within an hour of the click; a
 *   day leaves room for the ones that wait for Wi-Fi.
 * - `install_cap_per_minute` (default 300): installs a registration may
 *   record in a minute; the rest are answered 429 with Retry-After, and the
 *   SDK queues and retries them.
 * - `event_cap_per_minute` (default 200): events one install may post in a
 *   minute, counted per event, never below one full batch
 *   (InstallEventsIntake::MAX_EVENTS) so a batch always fits.
 *
 * Read strictly, like AppPolicy, and never permissively (CLAUDE.md #11). A
 * value that is missing (a query that did not select the column) or not
 * exactly what a write stores — including a CTIT pair whose short threshold
 * is not below its long one, which names both — is named in `unreadable`,
 * and resolves to the
 * reading that trusts least: both CTIT tails flag everything (the short
 * threshold at its ceiling, the long one at its floor), and a cap is null,
 * which the intakes answer with a 503 naming the column rather than
 * admitting or refusing the request on a guess.
 */
final class AppLimits
{
    public const DEFAULT_CTIT_MIN_SECONDS = 10;
    public const DEFAULT_CTIT_MAX_SECONDS = 86400;
    public const DEFAULT_INSTALL_CAP_PER_MINUTE = 300;
    public const DEFAULT_EVENT_CAP_PER_MINUTE = 200;

    /** [min, max] each column may hold; a write outside is refused, a stored value outside is unreadable. */
    public const RANGES = [
        'ctit_min_seconds' => [0, 3600],
        'ctit_max_seconds' => [60, 31_536_000],
        'install_cap_per_minute' => [1, 60000],
        'event_cap_per_minute' => [100, 60000],
    ];

    /** The window both caps count over, seconds. */
    public const WINDOW_SECONDS = 60;

    /**
     * The registration's columns under AppPolicy::REGISTRATION_PREFIX, for a
     * query that reads them beside an install row (LockedInstall).
     */
    public const REGISTRATION_COLUMNS = 'r.ctit_min_seconds AS reg_ctit_min_seconds, r.ctit_max_seconds AS reg_ctit_max_seconds, '
        . 'r.install_cap_per_minute AS reg_install_cap_per_minute, r.event_cap_per_minute AS reg_event_cap_per_minute';

    /**
     * @param list<string> $unreadable the columns that could not be read
     */
    private function __construct(
        public readonly int $ctitMinSeconds,
        public readonly int $ctitMaxSeconds,
        public readonly ?int $installCapPerMinute,
        public readonly ?int $eventCapPerMinute,
        public readonly array $unreadable,
    ) {
    }

    /** Limits nobody could read: every column unreadable. */
    public static function unreadable(): self
    {
        return self::fromRow(null);
    }

    /** The defaults a new registration gets. */
    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_CTIT_MIN_SECONDS,
            self::DEFAULT_CTIT_MAX_SECONDS,
            self::DEFAULT_INSTALL_CAP_PER_MINUTE,
            self::DEFAULT_EVENT_CAP_PER_MINUTE,
            [],
        );
    }

    /**
     * The limits a stored registration row carries. Only an integer, or its
     * canonical digits (what mysqli hands back without native types), inside
     * the column's range is read; anything else is unreadable.
     */
    public static function fromRow(mixed $row, string $prefix = ''): self
    {
        $values = [];
        $unreadable = [];
        foreach (array_keys(self::RANGES) as $column) {
            $value = is_array($row) ? self::read($row[$prefix . $column] ?? null, $column) : null;
            if ($value === null) {
                $unreadable[] = $column;
            }
            $values[$column] = $value;
        }

        // Two readable bounds that do not order (min >= max) are no reading
        // of either: the write path refuses that pair (assertCtitOrder(),
        // under the registration's row lock), so a stored one is a row
        // nothing wrote as it stands. Both are named unreadable and read as
        // the trusting-least pair, never as "every install is short or long"
        // by accident of which bound won.
        if ($values['ctit_min_seconds'] !== null && $values['ctit_max_seconds'] !== null
            && $values['ctit_min_seconds'] >= $values['ctit_max_seconds']) {
            $values['ctit_min_seconds'] = null;
            $values['ctit_max_seconds'] = null;
            $unreadable = array_values(array_unique([...$unreadable, 'ctit_min_seconds', 'ctit_max_seconds']));
        }

        return new self(
            // The trusting-least readings: a short threshold at its ceiling
            // and a long one at its floor flag every measured install.
            $values['ctit_min_seconds'] ?? self::RANGES['ctit_min_seconds'][1],
            $values['ctit_max_seconds'] ?? self::RANGES['ctit_max_seconds'][0],
            $values['install_cap_per_minute'],
            $values['event_cap_per_minute'],
            $unreadable,
        );
    }

    /**
     * A value as a write would send it — an integer, or its canonical digits
     * — inside its column's range, or null. Shared by the write path's
     * validation and the read, so the two cannot disagree about what a
     * column may hold.
     */
    public static function read(mixed $value, string $column): ?int
    {
        [$min, $max] = self::RANGES[$column];
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            return null;
        }

        return $value;
    }
}

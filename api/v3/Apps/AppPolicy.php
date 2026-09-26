<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Apps\Android\Integrity\IntegrityMode;

/**
 * What a registration says about the signals that arrive for its app: the
 * part of a verdict's worth that is the operator's decision rather than the
 * verifier's.
 *
 * - `accept_test_signals`: whether signals that prove only that a developer
 *   produced them — AdAttributionKit postbacks signed with Apple's
 *   development key, and Android installs the SDK marks `test` — count as
 *   trusted. Anyone with a phone in Developer Mode can mint the first kind
 *   naming any app, so the default is no.
 * - `attribution_window_days` (Android): how long after its click an
 *   install may begin and still be attributed to it.
 * - `trust_client_revenue` (Android): whether a revenue value the app
 *   reports with an event may be paid (a goal valued `from_property`).
 *   The app token is public, so the default is no: the value is stored and
 *   reported, not credited.
 *
 * - `integrity_mode` (Android): Play Integrity off, observe or require
 *   (IntegrityMode). An unreadable mode is `require`, the one that trusts
 *   least.
 *
 * This class is the one place a stored policy is read (plan §4.4). A policy
 * that cannot be read — no registration, a column that came back NULL, a
 * value that is not exactly what a write stores — resolves to the
 * untrusting policy, never to the permissive one (CLAUDE.md #11): no test
 * signals, no client revenue, a window of 0 days, inside which no install
 * falls, and Play Integrity required.
 */
final class AppPolicy
{
    public const MAX_WINDOW_DAYS = 365;

    private function __construct(
        public readonly bool $acceptTestSignals,
        public readonly int $attributionWindowDays = 0,
        public readonly bool $trustClientRevenue = false,
        public readonly IntegrityMode $integrityMode = IntegrityMode::REQUIRE,
    ) {
    }

    /** The policy of a signal nobody has registered, or whose policy could not be read. */
    public static function untrusting(): self
    {
        return new self(false);
    }

    /**
     * The policy a stored registration row carries.
     *
     * Strict on purpose: only the integer 1 (or the string '1' mysqli hands
     * back without native types) turns a flag on. 0, NULL, '', true, a
     * missing key and a row that is not an array all mean "no". The window
     * is a whole number of days from 1 to MAX_WINDOW_DAYS, as an integer or
     * its canonical digits; anything else is 0.
     */
    public static function fromRow(mixed $row, string $prefix = ''): self
    {
        if (!is_array($row) || !array_key_exists($prefix . 'accept_test_signals', $row)) {
            return self::untrusting();
        }
        $window = $row[$prefix . 'attribution_window_days'] ?? null;
        if (is_string($window) && preg_match('/^[1-9][0-9]{0,2}$/D', $window) === 1) {
            $window = (int) $window;
        }
        if (!is_int($window) || $window < 1 || $window > self::MAX_WINDOW_DAYS) {
            $window = 0;
        }

        return new self(
            self::flag($row[$prefix . 'accept_test_signals']),
            $window,
            self::flag($row[$prefix . 'trust_client_revenue'] ?? null),
            // A row read without the column is not a row whose mode is off.
            IntegrityMode::fromStored($row[$prefix . 'integrity_mode'] ?? null),
        );
    }

    /**
     * The registration's policy columns under REGISTRATION_PREFIX, for a
     * query that reads them beside another table's row. 202_app_installs
     * has its own `integrity_mode` (the install's arrival-time snapshot), so
     * `SELECT i.*, r.…` leaves `integrity_mode` naming the INSTALL's: a
     * policy built from that row reads the snapshot as the registration's
     * live mode, silently (both share one domain). Aliased, every policy
     * column is the registration's and no install column can shadow one.
     */
    public const REGISTRATION_PREFIX = 'reg_';
    public const REGISTRATION_COLUMNS = 'r.accept_test_signals AS reg_accept_test_signals, r.attribution_window_days AS reg_attribution_window_days, '
        . 'r.trust_client_revenue AS reg_trust_client_revenue, r.integrity_mode AS reg_integrity_mode';

    /** A policy from a boolean already decided elsewhere (the registration write path). */
    public static function withTestSignals(bool $accept): self
    {
        return new self($accept);
    }

    private static function flag(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }
}

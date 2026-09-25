<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * What a registration says about the signals that arrive for its app: the
 * part of a verdict's worth that is the operator's decision rather than the
 * verifier's.
 *
 * One setting today, `accept_test_signals`: whether signals that prove only
 * that a developer produced them — AdAttributionKit postbacks signed with
 * Apple's development key, and (from the Android intake on) installs the
 * SDK marks `test` — count as trusted. Anyone with a phone in Developer Mode
 * can mint the first kind naming any app, so the default is no.
 *
 * This class is the one place a stored policy is read (plan §4.4). A policy
 * that cannot be read — no registration, a column that came back NULL, a
 * value that is not exactly 1 — resolves to the untrusting policy, never to
 * the permissive one (CLAUDE.md #11).
 */
final class AppPolicy
{
    private function __construct(public readonly bool $acceptTestSignals)
    {
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
     * back without native types) accepts test signals. 0, NULL, '', true, a
     * missing key and a row that is not an array all mean "no".
     */
    public static function fromRow(mixed $row): self
    {
        if (!is_array($row) || !array_key_exists('accept_test_signals', $row)) {
            return self::untrusting();
        }
        $value = $row['accept_test_signals'];
        return new self($value === 1 || $value === '1');
    }

    /** A policy from a boolean already decided elsewhere (the registration write path). */
    public static function withTestSignals(bool $accept): self
    {
        return new self($accept);
    }
}

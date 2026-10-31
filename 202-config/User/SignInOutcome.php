<?php

declare(strict_types=1);

namespace Prosper202\User;

/**
 * What became of a sign-in attempt, as 202_users_log.login_success holds it.
 * 202-login.php writes it (SignInLog); the throttle (AUTH::is_rate_limited()),
 * Account › Settings (p202_account_sign_in_attempt_pill()) and GET
 * /system/login-log read it, each through this enum.
 *
 * NotCompleted is a right password whose sign-in a database failure stopped
 * (the sign-in record, the session's owner lookup, a legacy hash's rehash):
 * the throttle counts only Failed, so a hiccup does not lock the user out as
 * a password guesser would be.
 */
enum SignInOutcome: int
{
    case Failed = 0;
    case Passed = 1;
    case NotCompleted = 2;

    /** The status GET /system/login-log answers for a value no version writes. */
    public const string UNKNOWN_STATUS = 'unknown';

    /**
     * The stored value's case, or null for one no version writes (only 0
     * and 1 were, before this one): such a row reads as none of the three,
     * and no filter or count selects it.
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (is_int($stored)) {
            return self::tryFrom($stored);
        }
        if (is_string($stored) && preg_match('/^-?[0-9]+$/D', $stored) === 1) {
            return self::tryFrom((int) $stored);
        }

        return null;
    }

    /** The case GET /system/login-log's ?status= names, or null for any other value. */
    public static function fromStatus(mixed $status): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->status() === $status) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (self $case): string => $case->status(), self::cases());
    }

    public function status(): string
    {
        return match ($this) {
            self::Failed => 'failed',
            self::Passed => 'passed',
            self::NotCompleted => 'not_completed',
        };
    }

    /** The rows of this outcome, as an exact match the throttle's indexes can range over. */
    public function sqlCondition(): string
    {
        return 'login_success = ' . $this->value;
    }
}

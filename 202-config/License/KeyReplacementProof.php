<?php

declare(strict_types=1);

namespace Prosper202\License;

/**
 * Whether a POST to api-key-required.php may replace the license key the
 * install already has.
 *
 * The page answers with nobody signed in, because AUTH sends a failed license
 * check there, so its session token is something every visitor holds. While
 * no key is saved that is the install flow, and anyone may save a key the
 * license service accepts. Once one is saved, replacing it hands the install
 * to whoever holds the new key, so the request has to show it is the
 * owner's: a signed-in session whose user may change Settings, or the
 * username and password of such a user typed on the form. The sign-in page
 * asks for no license, so an owner whose key has expired can still do
 * either.
 *
 * A typed password is held to the sign-in page's throttle and its wrong
 * guesses are logged where the throttle counts them, so this form is no way
 * around it. Nothing here asks the license service: refused, the page asks it
 * nothing, and a stranger cannot use it to test keys.
 */
final class KeyReplacementProof
{
    /** The permission the Settings pages ask; the install's license is one of its settings. */
    public const PERMISSION = 'access_to_settings';

    public const NEEDS_PROOF = 'A license key is already saved. To replace it, sign in with an account that can'
        . ' change Settings, or enter that account\'s username and password below.';
    public const WRONG_PASSWORD = 'Your username or password is incorrect.';
    public const NOT_PERMITTED = 'That account cannot change Settings, so it cannot replace the license key.';
    public const THROTTLED = 'Too many failed login attempts. Please wait a few minutes and try again.';
    public const UNCHECKED = 'Your username and password could not be checked. Please try again in a moment.';

    /**
     * @param \Closure(string, string): bool $rateLimited whether the username or address is throttled
     * @param \Closure(string, string): ?int $authenticate the active user the password is right for, or null
     * @param \Closure(string, string): void $recordFailure logs a wrong password where the throttle counts it
     * @param \Closure(int): bool $mayChangeSettings whether that user's role has PERMISSION
     */
    public function __construct(
        private readonly bool $sessionMayChangeSettings,
        private readonly \Closure $rateLimited,
        private readonly \Closure $authenticate,
        private readonly \Closure $recordFailure,
        private readonly \Closure $mayChangeSettings,
    ) {
    }

    /**
     * The sentence refusing the replacement, or null when it may go ahead.
     * A check that cannot be made refuses (CLAUDE.md #11).
     */
    public function refusal(bool $keySaved, string $userName, string $password, string $address): ?string
    {
        if (!$keySaved || $this->sessionMayChangeSettings) {
            return null;
        }
        $userName = trim($userName);
        if ($userName === '' || $password === '') {
            return self::NEEDS_PROOF;
        }

        try {
            if (($this->rateLimited)($userName, $address)) {
                return self::THROTTLED;
            }
            $userId = ($this->authenticate)($userName, $password);
            if ($userId === null) {
                ($this->recordFailure)($userName, $address);
                return self::WRONG_PASSWORD;
            }

            return ($this->mayChangeSettings)($userId) ? null : self::NOT_PERMITTED;
        } catch (\Throwable $e) {
            error_log('Prosper202 license key page: the owner\'s password could not be checked: ' . $e->getMessage());
            return self::UNCHECKED;
        }
    }

    public static function forPage(\mysqli $db, bool $sessionMayChangeSettings): self
    {
        return new self(
            $sessionMayChangeSettings,
            static fn (string $userName, string $address): bool => \AUTH::is_rate_limited($db, $userName, $address),
            static function (string $userName, string $password) use ($db): ?int {
                $result = \AUTH::authenticate($userName, $password, $db);
                if (($result['success'] ?? false) !== true) {
                    return null;
                }
                $userId = filter_var($result['user']['user_id'] ?? null, FILTER_VALIDATE_INT);
                if ($userId === false || $userId <= 0) {
                    throw new \RuntimeException('the sign-in read answered no user id');
                }

                return $userId;
            },
            static function (string $userName, string $address) use ($db): void {
                \Prosper202\User\SignInLog::on($db)->append(
                    $userName,
                    $address,
                    \Prosper202\User\SignInOutcome::Failed,
                    ['user' => self::WRONG_PASSWORD],
                    \AUTH::login_audit_snapshot()
                );
            },
            static fn (int $userId): bool => (new \User($userId))->hasPermission(self::PERMISSION),
        );
    }
}

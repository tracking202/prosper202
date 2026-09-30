<?php

declare(strict_types=1);

namespace Prosper202\Goals;

/**
 * Who owns a goal (plan §2.2, §4.5).
 *
 * - CAMPAIGN: a web or app campaign's own goal; it evaluates that campaign's
 *   clicks.
 * - REGISTRATION: an app's default set; its campaigns attach the ones they
 *   pay for, and (from PR 5) the app's installs evaluate all of them.
 * - ACCOUNT: goals every app of the account shares — what an account-wide
 *   SKAN encoding (registration 0) names.
 *
 * `after` may only name goals of the same scope, so a goal set is closed
 * under its prerequisites without crossing owners.
 */
enum GoalScope: string
{
    case CAMPAIGN = 'campaign';
    case REGISTRATION = 'registration';
    case ACCOUNT = 'account';

    /** Read the stored column; a value that is none of the three is not guessed. */
    public static function fromStored(mixed $value): self
    {
        $scope = is_string($value) ? self::tryFrom($value) : null;
        if ($scope === null) {
            throw new GoalEngineException(
                'goal scope "' . (is_scalar($value) ? (string) $value : gettype($value)) . '" is not campaign, registration or account',
                GoalEngineException::INTEGRITY
            );
        }

        return $scope;
    }
}

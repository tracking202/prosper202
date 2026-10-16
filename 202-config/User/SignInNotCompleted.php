<?php

declare(strict_types=1);

namespace Prosper202\User;

/**
 * The password was verified and a write the sign-in needs then failed:
 * 202-login.php logs the attempt as SignInOutcome::NotCompleted, which the
 * throttle does not count.
 */
final class SignInNotCompleted extends \RuntimeException
{
}

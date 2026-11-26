<?php

declare(strict_types=1);

namespace Prosper202\User;

use RuntimeException;

/**
 * A password-reset key that no longer allows a reset when the new password is
 * written: another submit of the same link used it first, or it expired or
 * was replaced since the page read it. Nothing was stored.
 */
final class ResetKeyNotUsable extends RuntimeException
{
}

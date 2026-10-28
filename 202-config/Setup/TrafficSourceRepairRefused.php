<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * A traffic source repair or split that was not made, and nothing was
 * written: the source is not one the report lists, a user it would go to
 * is not one it may go to, or it is no longer as the caller was shown.
 * $field names the request value at fault.
 */
final class TrafficSourceRepairRefused extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

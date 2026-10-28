<?php

declare(strict_types=1);

namespace Prosper202\Setup;

/**
 * A traffic source repair that was not made, and nothing was written:
 * the source is not one the report lists, or the user it would go to is
 * not one it may go to. $field names the request value at fault.
 */
final class TrafficSourceRepairRefused extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

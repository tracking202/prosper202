<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * Thrown when a subscription event references an external_sub_id that does
 * not exist for the account. Callers map this to a 404.
 */
final class SubscriptionNotFoundException extends RuntimeException
{
}

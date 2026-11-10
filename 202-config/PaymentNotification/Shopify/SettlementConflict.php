<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification\Shopify;

use RuntimeException;

/** Shopify's own data disagrees with what is stored: no retry can change it. */
final class SettlementConflict extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose 1.9.56 handler credited the click without writing a
 * conversion row (ClickBank's cb202.php). A refund naming a sale with no row
 * takes its stated amount back from the value the click carried from before
 * the upgrade, as 1.9.56 did, capped at what is left of that value.
 */
interface ReversesPreUpgradeSales
{
}

<?php

declare(strict_types=1);

namespace Prosper202\Destinations;

/**
 * A reason a delivery is withheld that something later acts on: the rows
 * withheld for it are found again and released (DeliveryRows::release()).
 *
 * The value is the row's last_error, whole, which is where a row's reason is
 * stored: the send writes this case's value and the release binds the same
 * one. It fits the column uncut (DeliveryStatusTest), so what is stored is
 * what is compared (CLAUDE.md #30). Every other reason a row is withheld for
 * is prose nothing reads back.
 */
enum WithheldFor: string
{
    /** Nothing recorded the conversion's currency; released when the owner records one (Personal settings). */
    case NoCurrency = 'withheld: nothing recorded which currency this conversion\'s amount is in';
}

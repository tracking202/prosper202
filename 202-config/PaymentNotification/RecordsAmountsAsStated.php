<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider that states every account's amounts in one currency (ClickBank's
 * totalAccountAmount is USD). An account that counts in another records them
 * as stated, unconverted, as 1.9.56 did, and the answer says so.
 */
interface RecordsAmountsAsStated
{
}

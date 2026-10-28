<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

use Prosper202\Database\Connection;

/**
 * A provider whose test notification is how the account's setup is shown to
 * work, recorded where its integration page reads it (ClickBank's
 * cb_verified). The processor calls it for a test that one account's secret
 * verified; a failure here answers the test as failed, so the provider's
 * check shows it and it can be sent again.
 */
interface ConfirmsTestNotifications
{
    public function confirmTest(Connection $conn, int $accountId): void;
}

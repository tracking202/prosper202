<?php

declare(strict_types=1);

namespace Prosper202\PaymentNotification;

/**
 * A provider whose refunds without an id of their own were keyed by their
 * whole body (Processor::bodyFingerprint()) before 1.9.80, and are keyed by
 * its fingerprint (FingerprintsNotifications) since. A refund on file under
 * the old key is still that refund when it is sent again, live or deleted,
 * so the processor looks for the old key before it records.
 */
interface KeyedRefundsByBodyBefore1980
{
}

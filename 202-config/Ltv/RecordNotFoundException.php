<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * Thrown when a targeted record (alias, integration, ...) does not exist for
 * the account. Callers map this to a 404 — and ONLY this: a genuine database
 * failure must never be reported as "not found".
 */
final class RecordNotFoundException extends RuntimeException
{
}

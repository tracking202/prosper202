<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * A write that a concurrent one got to first: the records it names changed
 * between the check and the write (a merge raced another merge). Nothing
 * was written; the same request, sent again, sees the new state. Callers
 * map this to a 409, as they map CompanyConflictException.
 */
final class LtvConflictException extends RuntimeException
{
}

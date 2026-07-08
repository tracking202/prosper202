<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use RuntimeException;

/**
 * Thrown when a strict company create collides with an existing company
 * (pre-checked by name, or the unique key firing under a concurrent create).
 * Callers map this to a 409 Conflict.
 */
final class CompanyConflictException extends RuntimeException
{
}

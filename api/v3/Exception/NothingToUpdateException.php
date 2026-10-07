<?php

declare(strict_types=1);

namespace Api\V3\Exception;

/**
 * An update whose body names nothing to change: no writable field, only
 * read-only ones sent back with the values the record holds. A PUT answers it
 * as the 422 it extends; bulk-upsert reports the row as skipped, since a row
 * that matches its record changes nothing.
 */
final class NothingToUpdateException extends ValidationException
{
}

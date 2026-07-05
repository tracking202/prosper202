<?php

declare(strict_types=1);

namespace Prosper202\Database\Exceptions;

use RuntimeException;

/**
 * Thrown by Connection when the DATABASE LAYER fails — a prepare, bind,
 * execute, or transaction control call that the driver could not complete
 * (missing table, SQL error, lost connection, deadlock, a bind-type bug).
 *
 * It extends RuntimeException so every existing `catch (RuntimeException)` /
 * `catch (Throwable)` — including the locale-independent deadlock detection in
 * Connection::isMysqlError(), which reads the message tag and previous-chain,
 * not the class — keeps working unchanged. The distinct type exists purely so
 * callers can tell an INFRASTRUCTURE failure apart from a repository VALIDATION
 * error (both were bare RuntimeExceptions before): under MYSQLI_REPORT_STRICT
 * alone a failed query returns false and Connection raises this, so an API
 * layer that maps RuntimeException to a 422 would otherwise leak raw MySQL
 * detail as a client error instead of a generic 500.
 */
class QueryException extends RuntimeException
{
}

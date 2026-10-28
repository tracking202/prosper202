<?php

declare(strict_types=1);

namespace Prosper202\Validation;

use RuntimeException;

/**
 * Thrown by OutboundUrlGuard when a URL the install would send a request to
 * is not allowed. Extends RuntimeException so existing `catch (RuntimeException)`
 * sites keep working, but callers that map the rejection to their own domain
 * exception (ValidationException, InvalidArgumentException) should catch this
 * type: a bare RuntimeException catch also swallows unrelated failures thrown
 * from inside the guard and relabels them as caller input errors.
 */
final class OutboundUrlException extends RuntimeException
{
}
